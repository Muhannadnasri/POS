<?php
require_once __DIR__ . '/session_keepalive.php';
// laptops.php - Laptop Inventory Upload / Management
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$message = "";

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_clean($value) {
    $value = (float)$value;
    if (abs($value - round($value)) < 0.00001) {
        return number_format($value, 0);
    }
    return number_format($value, 2);
}

function parse_aed_amount($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $value = str_replace([',', 'AED', 'aed', 'درهم'], '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);
    if ($value === '' || $value === '-' || !is_numeric($value)) return null;
    return (float)$value;
}

function column_index_from_ref($cellRef) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef));
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function read_csv_rows($path) {
    $rows = [];
    if (($handle = fopen($path, 'r')) !== false) {
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $rows[] = $data;
        }
        fclose($handle);
    }
    return $rows;
}

function read_xlsx_rows($path) {
    $rows = [];

    if (!class_exists('ZipArchive')) {
        throw new Exception('ZipArchive is not enabled on this server. Please upload CSV or enable PHP Zip extension.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception('Unable to open Excel file.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = simplexml_load_string($sharedXml);
        if ($xml) {
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $run) {
                        $text .= (string)$run->t;
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }

    if ($sheetXml === false) {
        $zip->close();
        throw new Exception('No worksheet found in Excel file.');
    }

    $xml = simplexml_load_string($sheetXml);
    if (!$xml) {
        $zip->close();
        throw new Exception('Unable to read worksheet XML.');
    }

    foreach ($xml->sheetData->row as $row) {
        $rowNum = intval($row['r']);
        $values = [];
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r'];
            $colIndex = column_index_from_ref($ref);
            $type = (string)$cell['t'];
            $value = '';

            if ($type === 's') {
                $idx = intval($cell->v);
                $value = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = isset($cell->is->t) ? (string)$cell->is->t : '';
            } else {
                $value = isset($cell->v) ? (string)$cell->v : '';
            }
            $values[$colIndex] = $value;
        }
        if (!empty($values)) {
            ksort($values);
            $maxCol = max(array_keys($values));
            $rowArr = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $rowArr[] = $values[$i] ?? '';
            }
            $rows[$rowNum - 1] = $rowArr;
        }
    }

    $zip->close();
    ksort($rows);
    return array_values($rows);
}

function google_sheet_to_export_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';

    if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=xlsx';
    }

    if (preg_match('#key=([a-zA-Z0-9_-]+)#', $url, $m)) {
        return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=xlsx';
    }

    return $url;
}

function download_remote_file($url) {
    $exportUrl = google_sheet_to_export_url($url);
    if ($exportUrl === '') {
        throw new Exception('Google Sheets link is empty.');
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'laptop_sheet_') . '.xlsx';
    $data = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($exportUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 LaptopInventoryImporter'
        ]);
        $data = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($data === false || $http >= 400) {
            throw new Exception('Could not download Google Sheet. Make sure it is shared as Anyone with the link. ' . $err);
        }
    } else {
        $data = @file_get_contents($exportUrl);
        if ($data === false) {
            throw new Exception('Could not download Google Sheet. Enable cURL or allow_url_fopen.');
        }
    }

    file_put_contents($tmpPath, $data);
    return $tmpPath;
}

function table_has_column($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function create_laptop_table_if_needed($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS laptops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barcode VARCHAR(120) NOT NULL,
        cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        quantity INT NOT NULL DEFAULT 0,
        original_quantity INT NOT NULL DEFAULT 0,
        brand VARCHAR(120) NOT NULL DEFAULT '',
        model_name VARCHAR(160) NOT NULL DEFAULT '',
        details TEXT NULL,
        laptop_condition VARCHAR(80) NOT NULL DEFAULT '',
        status VARCHAR(80) NOT NULL DEFAULT 'Available',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_laptops_barcode (barcode),
        INDEX idx_laptops_brand (brand),
        INDEX idx_laptops_status (status),
        INDEX idx_laptops_condition (laptop_condition)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'original_quantity' => "ALTER TABLE laptops ADD COLUMN original_quantity INT NOT NULL DEFAULT 0 AFTER quantity",
        'model_name' => "ALTER TABLE laptops ADD COLUMN model_name VARCHAR(160) NOT NULL DEFAULT '' AFTER brand",
        'details' => "ALTER TABLE laptops ADD COLUMN details TEXT NULL AFTER model_name",
        'laptop_condition' => "ALTER TABLE laptops ADD COLUMN laptop_condition VARCHAR(80) NOT NULL DEFAULT '' AFTER details",
        'status' => "ALTER TABLE laptops ADD COLUMN status VARCHAR(80) NOT NULL DEFAULT 'Available' AFTER laptop_condition"
    ];
    foreach ($columns as $col => $sql) {
        if (!table_has_column($pdo, 'laptops', $col)) {
            $pdo->exec($sql);
        }
    }
    $pdo->exec("UPDATE laptops SET original_quantity = quantity WHERE original_quantity = 0 AND quantity > 0");
}

function sync_laptops_from_sales($pdo) {
    $laptops = $pdo->query("SELECT * FROM laptops ORDER BY barcode ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$laptops) {
        return ['matched' => 0, 'sold_units' => 0];
    }

    $barcodes = array_values(array_unique(array_filter(array_map(function($x) { return $x['barcode']; }, $laptops))));
    if (empty($barcodes)) {
        return ['matched' => 0, 'sold_units' => 0];
    }

    $placeholders = implode(',', array_fill(0, count($barcodes), '?'));
    $stmt = $pdo->prepare("SELECT barcode, COALESCE(SUM(qty_sold),0) AS sold_qty FROM sales_records WHERE barcode IN ($placeholders) GROUP BY barcode");
    $stmt->execute($barcodes);
    $soldByBarcode = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $soldByBarcode[$row['barcode']] = (int)$row['sold_qty'];
    }

    $byBarcode = [];
    foreach ($laptops as $laptop) {
        $byBarcode[$laptop['barcode']][] = $laptop;
    }

    $matchedRows = 0;
    $soldUnits = 0;
    $pdo->beginTransaction();
    try {
        foreach ($byBarcode as $barcode => $items) {
            $remainingSold = $soldByBarcode[$barcode] ?? 0;
            foreach ($items as $item) {
                $original = (int)($item['original_quantity'] ?: $item['quantity']);
                $deduct = min($original, max(0, $remainingSold));
                $newQty = max(0, $original - $deduct);
                $remainingSold -= $deduct;
                $soldUnits += $deduct;
                $newStatus = $item['status'];

                if ($deduct > 0) {
                    $matchedRows++;
                    $newStatus = ($newQty <= 0) ? 'Sold' : 'Available';
                } elseif ($newQty > 0 && strtolower((string)$newStatus) === 'sold') {
                    $newStatus = 'Available';
                }

                $upd = $pdo->prepare("UPDATE laptops SET original_quantity=?, quantity=?, status=? WHERE id=?");
                $upd->execute([$original, $newQty, $newStatus, (int)$item['id']]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['matched' => $matchedRows, 'sold_units' => $soldUnits];
}

try {
    create_laptop_table_if_needed($pdo);
} catch (Exception $e) {
    $message = "<div class='alert alert-danger'>Database setup error: " . h($e->getMessage()) . "</div>";
}

// Bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $ids = $_POST['selected_ids'] ?? [];
    $ids = array_values(array_filter(array_map('intval', (array)$ids)));
    if (empty($ids)) {
        $message = "<div class='alert alert-warning'>No laptops selected.</div>";
    } else {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM laptops WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $message = "<div class='alert alert-success'>Deleted <strong>" . count($ids) . "</strong> selected laptop(s).</div>";
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Bulk delete failed: " . h($e->getMessage()) . "</div>";
        }
    }
}

// Delete single
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
    try {
        $stmt = $pdo->prepare("DELETE FROM laptops WHERE id = ?");
        $stmt->execute([intval($_GET['id'])]);
        header("Location: laptops.php?deleted=1");
        exit;
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Delete failed: " . h($e->getMessage()) . "</div>";
    }
}
if (isset($_GET['deleted'])) $message = "<div class='alert alert-success'>Laptop item deleted.</div>";

// Sync button
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_laptop_sales'])) {
    try {
        $res = sync_laptops_from_sales($pdo);
        $message = "<div class='alert alert-success'>Laptop sold status synced from daily sales. Matched rows: <strong>{$res['matched']}</strong>, sold units applied: <strong>{$res['sold_units']}</strong>.</div>";
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Sync failed: " . h($e->getMessage()) . "</div>";
    }
}

// Load edit item
$editLaptop = null;
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
    $stmt = $pdo->prepare("SELECT * FROM laptops WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editLaptop = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Manual add/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_laptop'])) {
    $id = intval($_POST['laptop_id'] ?? 0);
    $barcode = trim($_POST['barcode'] ?? '');
    $costAmount = parse_aed_amount($_POST['cost_price'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);
    $originalQuantity = intval($_POST['original_quantity'] ?? $quantity);
    if ($originalQuantity < $quantity) $originalQuantity = $quantity;
    $brand = trim($_POST['brand'] ?? '');
    $modelName = trim($_POST['model_name'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $condition = trim($_POST['laptop_condition'] ?? '');
    $status = trim($_POST['status'] ?? 'Available');

    if ($barcode === '' || $costAmount === null || $quantity < 0 || $brand === '') {
        $message = "<div class='alert alert-danger'>Please fill Barcode, Cost Price, Quantity, and Brand correctly.</div>";
    } else {
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE laptops SET barcode=?, cost_price=?, quantity=?, original_quantity=?, brand=?, model_name=?, details=?, laptop_condition=?, status=? WHERE id=?");
                $stmt->execute([$barcode, $costAmount, $quantity, $originalQuantity, $brand, $modelName, $details, $condition, $status, $id]);
                header("Location: laptops.php?updated=1");
                exit;
            } else {
                $stmt = $pdo->prepare("INSERT INTO laptops (barcode, cost_price, quantity, original_quantity, brand, model_name, details, laptop_condition, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$barcode, $costAmount, $quantity, $quantity, $brand, $modelName, $details, $condition, $status]);
                header("Location: laptops.php?added=1");
                exit;
            }
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Save failed: " . h($e->getMessage()) . "</div>";
        }
    }
}
if (isset($_GET['added'])) $message = "<div class='alert alert-success'>Laptop added successfully.</div>";
if (isset($_GET['updated'])) $message = "<div class='alert alert-success'>Laptop updated successfully.</div>";

// Upload Excel/CSV/Google Sheet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_laptops'])) {
    $tmp = null;
    $name = '';
    $fromGoogle = false;
    try {
        $googleLink = trim($_POST['google_sheet_link'] ?? '');
        if ($googleLink !== '') {
            $tmp = download_remote_file($googleLink);
            $name = 'google_sheet.xlsx';
            $fromGoogle = true;
        } elseif (isset($_FILES['laptop_file']) && $_FILES['laptop_file']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['laptop_file']['tmp_name'];
            $name = $_FILES['laptop_file']['name'];
        } else {
            throw new Exception('Please choose a valid Excel/CSV file or paste a Google Sheets link.');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $replace = isset($_POST['replace_laptops']);
        $syncAfter = isset($_POST['sync_after_upload']);
        $rows = ($ext === 'csv') ? read_csv_rows($tmp) : read_xlsx_rows($tmp);
        if (count($rows) < 2) {
            throw new Exception('The file does not contain laptop rows.');
        }

        $inserted = 0; $updated = 0; $skipped = 0; $errors = 0;
        $pdo->beginTransaction();
        if ($replace) {
            $pdo->exec("DELETE FROM laptops");
        }

        // Layout: ID | Barcode | Cost Price (AED) | Quantity | Brand | Model Name | Details | Condition | Status
        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $excelId = trim((string)($row[0] ?? ''));
            $barcode = trim((string)($row[1] ?? ''));
            $costAmount = parse_aed_amount($row[2] ?? '');
            $quantity = intval($row[3] ?? 0);
            $brand = trim((string)($row[4] ?? ''));
            $modelName = trim((string)($row[5] ?? ''));
            $details = trim((string)($row[6] ?? ''));
            $condition = trim((string)($row[7] ?? ''));
            $status = trim((string)($row[8] ?? '')) ?: 'Available';

            if ($barcode === '' && $brand === '' && $modelName === '' && $details === '') {
                continue;
            }
            if ($barcode === '' || $costAmount === null || $quantity < 0 || $brand === '') {
                $skipped++;
                continue;
            }

            try {
                if ($excelId !== '' && ctype_digit($excelId)) {
                    $check = $pdo->prepare("SELECT id FROM laptops WHERE id = ?");
                    $check->execute([intval($excelId)]);
                    if ($check->fetchColumn()) {
                        $stmt = $pdo->prepare("UPDATE laptops SET barcode=?, cost_price=?, quantity=?, original_quantity=?, brand=?, model_name=?, details=?, laptop_condition=?, status=? WHERE id=?");
                        $stmt->execute([$barcode, $costAmount, $quantity, $quantity, $brand, $modelName, $details, $condition, $status, intval($excelId)]);
                        $updated++;
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO laptops (id, barcode, cost_price, quantity, original_quantity, brand, model_name, details, laptop_condition, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([intval($excelId), $barcode, $costAmount, $quantity, $quantity, $brand, $modelName, $details, $condition, $status]);
                        $inserted++;
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO laptops (barcode, cost_price, quantity, original_quantity, brand, model_name, details, laptop_condition, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$barcode, $costAmount, $quantity, $quantity, $brand, $modelName, $details, $condition, $status]);
                    $inserted++;
                }
            } catch (Exception $e) {
                $errors++;
            }
        }
        $pdo->commit();

        $syncMsg = '';
        if ($syncAfter) {
            $res = sync_laptops_from_sales($pdo);
            $syncMsg = " Synced sold units: <strong>{$res['sold_units']}</strong>.";
        }
        $message = "<div class='alert alert-success'>Laptop upload complete. Inserted: <strong>{$inserted}</strong>, Updated: <strong>{$updated}</strong>, Skipped: <strong>{$skipped}</strong>, Errors: <strong>{$errors}</strong>.{$syncMsg}</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger'>Upload failed: " . h($e->getMessage()) . "</div>";
    } finally {
        if ($fromGoogle && $tmp && file_exists($tmp)) @unlink($tmp);
    }
}

// Filters
$search = trim($_GET['search'] ?? '');
$brandFilter = trim($_GET['brand'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$conditionFilter = trim($_GET['condition'] ?? '');

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(barcode LIKE ? OR brand LIKE ? OR model_name LIKE ? OR details LIKE ? OR laptop_condition LIKE ? OR status LIKE ?)";
    for ($i = 0; $i < 6; $i++) $params[] = "%$search%";
}
if ($brandFilter !== '') { $where[] = "brand = ?"; $params[] = $brandFilter; }
if ($statusFilter !== '') { $where[] = "status = ?"; $params[] = $statusFilter; }
if ($conditionFilter !== '') { $where[] = "laptop_condition = ?"; $params[] = $conditionFilter; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM laptops $whereSql ORDER BY id DESC");
$stmt->execute($params);
$laptops = $stmt->fetchAll(PDO::FETCH_ASSOC);

$brands = $pdo->query("SELECT DISTINCT brand FROM laptops WHERE brand <> '' ORDER BY brand ASC")->fetchAll(PDO::FETCH_COLUMN);
$conditions = $pdo->query("SELECT DISTINCT laptop_condition FROM laptops WHERE laptop_condition <> '' ORDER BY laptop_condition ASC")->fetchAll(PDO::FETCH_COLUMN);

$stats = ['rows'=>0,'units'=>0,'original_units'=>0,'available'=>0,'sold'=>0,'cost_value'=>0,'sold_value'=>0];
$allStats = $pdo->query("SELECT * FROM laptops")->fetchAll(PDO::FETCH_ASSOC);
foreach ($allStats as $l) {
    $stats['rows']++;
    $q = (int)$l['quantity'];
    $orig = (int)($l['original_quantity'] ?: $q);
    $sold = max(0, $orig - $q);
    $cost = (float)$l['cost_price'];
    $stats['units'] += $q;
    $stats['original_units'] += $orig;
    $stats['sold'] += $sold;
    if (strtolower($l['status']) === 'available') $stats['available'] += $q;
    $stats['cost_value'] += $q * $cost;
}

function sold_summary_for_barcodes($pdo, $barcodes) {
    $barcodes = array_values(array_unique(array_filter($barcodes)));
    if (!$barcodes) return [];
    $placeholders = implode(',', array_fill(0, count($barcodes), '?'));
    $stmt = $pdo->prepare("SELECT barcode, SUM(qty_sold) AS sold_qty, SUM(total_amount) AS total_sold, MAX(sold_at) AS last_sold_at FROM sales_records WHERE barcode IN ($placeholders) GROUP BY barcode");
    $stmt->execute($barcodes);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $qty = max(1, (int)$row['sold_qty']);
        $out[$row['barcode']] = [
            'sold_qty' => (int)$row['sold_qty'],
            'total_sold' => (float)$row['total_sold'],
            'avg_price' => (float)$row['total_sold'] / $qty,
            'last_sold_at' => $row['last_sold_at']
        ];
    }
    return $out;
}
$soldSummary = sold_summary_for_barcodes($pdo, array_map(function($x){ return $x['barcode']; }, $laptops));

// Export current filtered list
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="laptops_inventory_' . date('Y-m-d_H-i') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "<html><head><meta charset='UTF-8'></head><body>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Barcode</th><th>Brand</th><th>Model Name</th><th>Cost Price (AED)</th><th>Original Qty</th><th>Qty Available</th><th>Sold Units</th><th>Condition</th><th>Status</th><th>Sold Price</th><th>Last Sold</th><th>Details</th></tr>";
    foreach ($laptops as $l) {
        $orig = (int)($l['original_quantity'] ?: $l['quantity']);
        $soldUnits = max(0, $orig - (int)$l['quantity']);
        $sum = $soldSummary[$l['barcode']] ?? null;
        echo "<tr>";
        echo "<td>" . h($l['id']) . "</td>";
        echo "<td style='mso-number-format:\"\\@\";'>" . h($l['barcode']) . "</td>";
        echo "<td>" . h($l['brand']) . "</td>";
        echo "<td>" . h($l['model_name']) . "</td>";
        echo "<td>" . h(money_clean($l['cost_price'])) . "</td>";
        echo "<td>" . h($orig) . "</td>";
        echo "<td>" . h($l['quantity']) . "</td>";
        echo "<td>" . h($soldUnits) . "</td>";
        echo "<td>" . h($l['laptop_condition']) . "</td>";
        echo "<td>" . h($l['status']) . "</td>";
        echo "<td>" . h($sum ? money_clean($sum['avg_price']) : '') . "</td>";
        echo "<td>" . h($sum['last_sold_at'] ?? '') . "</td>";
        echo "<td>" . h($l['details']) . "</td>";
        echo "</tr>";
    }
    echo "</table></body></html>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Laptop Inventory - Rukn Al Lotus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: system-ui, sans-serif; }
        .card-box { border: 0; border-radius: 14px; box-shadow: 0 4px 14px rgba(0,0,0,.06); }
        .small-label { font-size: .75rem; color: #6c757d; font-weight: 700; text-transform: uppercase; }
        .table td, .table th { vertical-align: middle; }
    </style>
<style>
/* ===== Rukn Al Lotus dashboard shell ===== */
:root{--rl-bg:#f3f6fb;--rl-card:#fff;--rl-text:#172033;--rl-muted:#778199;--rl-border:#e6ebf2;--rl-blue:#3759e8;--rl-shadow:0 8px 28px rgba(34,51,84,.07)}
*{box-sizing:border-box}body{margin:0!important;background:var(--rl-bg)!important;font-family:Inter,system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif!important;color:var(--rl-text)}
.rl-app{display:flex;min-height:100vh}.rl-sidebar{width:270px;background:linear-gradient(180deg,#0b1220 0%,#101a2e 100%);color:#fff;position:fixed;inset:0 auto 0 0;overflow-y:auto;padding:20px 14px;z-index:1040;box-shadow:8px 0 26px rgba(13,24,45,.12)}
.rl-brand{display:flex;gap:12px;align-items:center;padding:4px 8px 20px;border-bottom:1px solid rgba(255,255,255,.07);margin-bottom:10px}.rl-brand-mark{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#eef2ff,#fff);color:#263fbb;display:grid;place-items:center;font-weight:900;box-shadow:0 6px 20px #0002}.rl-brand-title{font-weight:850;line-height:1.05}.rl-brand-sub{color:#8390a8;font-size:.76rem;margin-top:5px}.rl-nav-label{color:#64748b;font-size:.67rem;font-weight:850;text-transform:uppercase;letter-spacing:.1em;padding:17px 12px 7px}.rl-side-link{display:flex;align-items:center;gap:11px;color:#b9c3d5;text-decoration:none;padding:10px 12px;border-radius:11px;margin:3px 0;font-size:.88rem;font-weight:650;transition:.15s}.rl-side-link:hover{background:#ffffff0d;color:#fff;transform:translateX(2px)}.rl-side-link.active{background:linear-gradient(135deg,#3154e8,#536df0);color:#fff;box-shadow:0 8px 20px rgba(49,84,232,.24)}.rl-side-link i{font-size:1.02rem;width:20px;text-align:center}.rl-role{font-size:.62rem;margin-left:auto;color:#b7c4ff;background:#ffffff0c;padding:3px 6px;border-radius:7px}
.rl-main{margin-left:270px;width:calc(100% - 270px);padding:22px 26px 34px}.rl-topbar{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:18px}.rl-welcome h1{font-size:1.55rem;font-weight:850;margin:0;letter-spacing:-.03em}.rl-welcome p{margin:4px 0 0;color:var(--rl-muted);font-size:.86rem}.rl-user-chip{background:#fff;border:1px solid var(--rl-border);box-shadow:var(--rl-shadow);border-radius:14px;padding:8px 11px;display:flex;align-items:center;gap:10px}.rl-avatar{width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,#e0e7ff,#eef2ff);color:#3446b4;display:grid;place-items:center;font-weight:850}.rl-mobile-toggle{display:none}.rl-page-content{min-width:0}
.rl-page-content>.container,.rl-page-content>.container-fluid{max-width:none!important;width:100%!important;padding-left:0!important;padding-right:0!important;margin-top:0!important}.rl-page-content .form-container{max-width:none!important;margin:0!important;border-radius:18px!important;border:1px solid var(--rl-border);box-shadow:var(--rl-shadow)!important}.rl-page-content .page-card,.rl-page-content .card{border-radius:18px!important;border-color:var(--rl-border)!important;box-shadow:var(--rl-shadow)}.rl-page-content .table-responsive{border-radius:14px}.rl-page-content .table thead th{font-size:.72rem;text-transform:uppercase;letter-spacing:.035em;color:#6f7a90;white-space:nowrap}.rl-page-content .table tbody td{vertical-align:middle}.rl-page-content .btn{border-radius:10px}.rl-page-content .form-control,.rl-page-content .form-select{border-radius:10px;border-color:#dce3ed}.rl-page-content .form-control:focus,.rl-page-content .form-select:focus{border-color:#8ea2ff;box-shadow:0 0 0 .2rem rgba(55,89,232,.12)}
@media(max-width:991px){.rl-sidebar{transform:translateX(-100%);transition:.2s}.rl-sidebar.open{transform:translateX(0)}.rl-main{margin-left:0;width:100%;padding:14px}.rl-mobile-toggle{display:inline-flex}.rl-topbar{align-items:flex-start}.rl-user-chip .rl-user-text{display:none}}
</style>

<style>
/* ===== Laptop top summary cards only ===== */
.laptop-top-card{
    position:relative;
    overflow:hidden;
    border:0!important;
    min-height:112px;
    color:#fff!important;
    box-shadow:0 10px 26px rgba(16,24,40,.12)!important;
    transition:transform .18s ease,box-shadow .18s ease;
}
.laptop-top-card:hover{
    transform:translateY(-3px);
    box-shadow:0 15px 32px rgba(16,24,40,.17)!important;
}
.laptop-top-card::after{
    content:"";
    position:absolute;
    width:95px;
    height:95px;
    border-radius:50%;
    right:-25px;
    bottom:-38px;
    background:rgba(255,255,255,.14);
}
.laptop-top-card::before{
    content:"";
    position:absolute;
    width:50px;
    height:50px;
    border-radius:50%;
    right:28px;
    top:-22px;
    background:rgba(255,255,255,.08);
}
.laptop-top-card > *{position:relative;z-index:1}
.laptop-top-card .small-label{color:rgba(255,255,255,.82)!important}
.laptop-top-card h3,
.laptop-top-card small{color:#fff!important}
.laptop-top-card.blue{background:linear-gradient(135deg,#3154e8,#667eea)!important}
.laptop-top-card.cyan{background:linear-gradient(135deg,#0e8fa8,#29b8d2)!important}
.laptop-top-card.red{background:linear-gradient(135deg,#d94a62,#f06a7d)!important}
.laptop-top-card.green{background:linear-gradient(135deg,#10986a,#2bbf8a)!important}
.laptop-top-card.orange{background:linear-gradient(135deg,#d98b16,#f6b73c)!important}
.laptop-top-card.purple{background:linear-gradient(135deg,#7652d9,#9b72ef)!important}
</style>

<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body>
<div class="rl-app"><?php include __DIR__ . '/sidebar.php'; ?><main class="rl-main"><div class="rl-topbar"><div class="d-flex gap-2 align-items-start"><button class="btn btn-light border rl-mobile-toggle" id="rlSidebarToggle" type="button"><i class="bi bi-list"></i></button><div class="rl-welcome"><h1>Laptops Inventory</h1><p>Manage laptop inventory, stock, models and imports · <?=htmlspecialchars(date('l, d F Y'), ENT_QUOTES, 'UTF-8')?></p></div></div><div class="rl-user-chip"><div class="rl-avatar"><?=htmlspecialchars(strtoupper(substr((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'A'),0,1)), ENT_QUOTES, 'UTF-8')?></div><div class="rl-user-text"><div class="fw-bold small"><?=htmlspecialchars((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Admin'), ENT_QUOTES, 'UTF-8')?></div><div class="text-muted" style="font-size:.7rem"><?=htmlspecialchars(ucfirst((string)($_SESSION['role'] ?? 'admin')), ENT_QUOTES, 'UTF-8')?></div></div></div></div><div class="rl-page-content">

<div class="container-fluid py-4 px-4">
    

    <?php echo $message; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-2"><div class="card card-box laptop-top-card blue p-3"><div class="small-label">Laptop Rows</div><h3 class="fw-bold mb-0"><?php echo number_format($stats['rows']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box laptop-top-card cyan p-3"><div class="small-label">Units in Stock</div><h3 class="fw-bold text-primary mb-0"><?php echo number_format($stats['units']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box laptop-top-card red p-3"><div class="small-label">Inventory Cost</div><h3 class="fw-bold text-danger mb-0"><?php echo money_clean($stats['cost_value']); ?></h3><small>AED</small></div></div>
        <div class="col-md-2"><div class="card card-box laptop-top-card green p-3"><div class="small-label">Available Units</div><h3 class="fw-bold text-success mb-0"><?php echo number_format($stats['available']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box laptop-top-card orange p-3"><div class="small-label">Sold Units</div><h3 class="fw-bold text-secondary mb-0"><?php echo number_format($stats['sold']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box laptop-top-card purple p-3"><div class="small-label">Original Units</div><h3 class="fw-bold text-dark mb-0"><?php echo number_format($stats['original_units']); ?></h3></div></div>
    </div>

    <div class="row g-4 mb-4">
        

        <div class="col-12">
            <div class="card card-box p-4 h-100">
                <h5 class="fw-bold mb-3"><?php echo $editLaptop ? '✏️ Edit Laptop Item' : '➕ Add Laptop Manually'; ?></h5>
                <form method="POST">
                    <input type="hidden" name="save_laptop" value="1">
                    <input type="hidden" name="laptop_id" value="<?php echo h($editLaptop['id'] ?? ''); ?>">
                    <input type="hidden" name="original_quantity" value="<?php echo h($editLaptop['original_quantity'] ?? ''); ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Barcode *</label>
                            <input type="text" name="barcode" class="form-control" value="<?php echo h($editLaptop['barcode'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Cost Price (AED) *</label>
                            <input type="number" step="1" min="0" name="cost_price" class="form-control" value="<?php echo h(isset($editLaptop['cost_price']) ? money_clean($editLaptop['cost_price']) : ''); ?>" placeholder="300" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Quantity *</label>
                            <input type="number" name="quantity" class="form-control" min="0" value="<?php echo h($editLaptop['quantity'] ?? '1'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Brand *</label>
                            <input type="text" name="brand" class="form-control" value="<?php echo h($editLaptop['brand'] ?? ''); ?>" placeholder="Dell / HP / Lenovo / Apple" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Model Name</label>
                            <input type="text" name="model_name" class="form-control" value="<?php echo h($editLaptop['model_name'] ?? ''); ?>" placeholder="Latitude 7420 / MacBook Pro">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Condition</label>
                            <select name="laptop_condition" class="form-select">
                                <?php $conditionOptions = ['New','Used','Open Box','Refurbished','Damaged','For Parts']; $curC = $editLaptop['laptop_condition'] ?? ''; ?>
                                <option value="">-- Select --</option>
                                <?php foreach ($conditionOptions as $c): ?><option value="<?php echo h($c); ?>" <?php echo ($curC === $c) ? 'selected' : ''; ?>><?php echo h($c); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <?php $statusOptions = ['Available','Sold','Reserved','Repair','Returned','Defective']; $curS = $editLaptop['status'] ?? 'Available'; ?>
                                <?php foreach ($statusOptions as $s): ?><option value="<?php echo h($s); ?>" <?php echo ($curS === $s) ? 'selected' : ''; ?>><?php echo h($s); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold">Details</label>
                            <textarea name="details" class="form-control" rows="2" placeholder="CPU, RAM, storage, color, notes..."><?php echo h($editLaptop['details'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button class="btn btn-success fw-bold" type="submit"><?php echo $editLaptop ? 'Update Laptop' : 'Save Laptop'; ?></button>
                        <?php if ($editLaptop): ?><a href="laptops.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card card-box p-4 mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-bold">Search</label>
                <input type="text" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="Barcode, brand, model, details...">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Brand</label>
                <select name="brand" class="form-select">
                    <option value="">All Brands</option>
                    <?php foreach ($brands as $b): ?><option value="<?php echo h($b); ?>" <?php echo ($brandFilter === $b) ? 'selected' : ''; ?>><?php echo h($b); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <?php foreach (['Available','Sold','Reserved','Repair','Returned','Defective'] as $s): ?><option value="<?php echo h($s); ?>" <?php echo ($statusFilter === $s) ? 'selected' : ''; ?>><?php echo h($s); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Condition</label>
                <select name="condition" class="form-select">
                    <option value="">All Conditions</option>
                    <?php foreach ($conditions as $c): ?><option value="<?php echo h($c); ?>" <?php echo ($conditionFilter === $c) ? 'selected' : ''; ?>><?php echo h($c); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-dark fw-bold">Filter</button>
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap mt-3">
                <a href="laptops.php" class="btn btn-sm btn-outline-secondary">All</a>
                <a href="laptops.php?status=Available" class="btn btn-sm btn-outline-success">Available</a>
                <a href="laptops.php?status=Sold" class="btn btn-sm btn-outline-dark">Sold</a>
                <a href="laptops.php?status=Reserved" class="btn btn-sm btn-outline-warning">Reserved</a>
                <a href="laptops.php?export=excel&<?php echo http_build_query($_GET); ?>" class="btn btn-sm btn-success fw-bold">⬇️ Download Excel</a>
            </div>
        </form>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card card-box p-4 h-100">
                <h5 class="fw-bold mb-3 text-success">✅ Available Laptops Preview</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light"><tr><th>Barcode</th><th>Brand/Model</th><th>Qty</th><th>Cost</th><th>Condition</th></tr></thead>
                        <tbody>
                            <?php $count=0; foreach ($laptops as $l): if (strtolower($l['status']) !== 'available' || (int)$l['quantity'] <= 0) continue; $count++; if ($count > 8) break; ?>
                                <tr><td class="fw-semibold"><?php echo h($l['barcode']); ?></td><td><?php echo h($l['brand'] . ' ' . $l['model_name']); ?></td><td><?php echo h($l['quantity']); ?></td><td><?php echo money_clean($l['cost_price']); ?> AED</td><td><?php echo h($l['laptop_condition']); ?></td></tr>
                            <?php endforeach; if ($count === 0): ?><tr><td colspan="5" class="text-muted text-center">No available laptops in current filter.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card card-box p-4 h-100">
                <h5 class="fw-bold mb-3 text-secondary">🛒 Sold Laptops Preview</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light"><tr><th>Barcode</th><th>Brand/Model</th><th>Sold Price</th><th>Last Sold</th></tr></thead>
                        <tbody>
                            <?php $count=0; foreach ($laptops as $l): $orig=(int)($l['original_quantity'] ?: $l['quantity']); $soldUnits=max(0,$orig-(int)$l['quantity']); if (strtolower($l['status']) !== 'sold' && $soldUnits <= 0) continue; $count++; if ($count > 8) break; $sum=$soldSummary[$l['barcode']] ?? null; ?>
                                <tr><td class="fw-semibold"><?php echo h($l['barcode']); ?></td><td><?php echo h($l['brand'] . ' ' . $l['model_name']); ?></td><td><?php echo $sum ? money_clean($sum['avg_price']) . ' AED' : '—'; ?></td><td><?php echo h($sum['last_sold_at'] ?? '—'); ?></td></tr>
                            <?php endforeach; if ($count === 0): ?><tr><td colspan="4" class="text-muted text-center">No sold laptops in current filter.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" onsubmit="return confirm('Delete selected laptops?');">
        <div class="card card-box p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Laptop List</h5>
                <button type="submit" name="delete_selected" class="btn btn-outline-danger btn-sm fw-bold">🗑️ Delete Selected</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th><input type="checkbox" id="selectAll"></th>
                            <th>ID</th><th>Barcode</th><th>Brand</th><th>Model</th><th>Cost</th><th>Original Qty</th><th>Qty Available</th><th>Sold Info</th><th>Sold Price</th><th>Condition</th><th>Status</th><th>Details</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$laptops): ?>
                            <tr><td colspan="14" class="text-center text-muted py-4">No laptop items found.</td></tr>
                        <?php else: foreach ($laptops as $l): ?>
                            <?php
                                $orig = (int)($l['original_quantity'] ?: $l['quantity']);
                                $soldUnits = max(0, $orig - (int)$l['quantity']);
                                $sum = $soldSummary[$l['barcode']] ?? null;
                                $status = $l['status'] ?: 'Available';
                                $badge = 'secondary';
                                if (strtolower($status) === 'available') $badge = 'success';
                                if (strtolower($status) === 'sold') $badge = 'dark';
                                if (strtolower($status) === 'reserved') $badge = 'warning';
                            ?>
                            <tr>
                                <td><input type="checkbox" name="selected_ids[]" value="<?php echo h($l['id']); ?>" class="row-check"></td>
                                <td><?php echo h($l['id']); ?></td>
                                <td class="fw-semibold"><?php echo h($l['barcode']); ?></td>
                                <td><?php echo h($l['brand']); ?></td>
                                <td><?php echo h($l['model_name']); ?></td>
                                <td class="fw-bold text-danger"><?php echo money_clean($l['cost_price']); ?> AED</td>
                                <td><?php echo h($orig); ?></td>
                                <td class="fw-bold <?php echo ((int)$l['quantity'] <= 0) ? 'text-danger' : 'text-success'; ?>"><?php echo h($l['quantity']); ?></td>
                                <td>
                                    <?php if ($soldUnits > 0 || strtolower($status) === 'sold'): ?>
                                        <span class="fw-bold"><?php echo h(max($soldUnits, (int)($sum['sold_qty'] ?? 0))); ?> sold</span><br>
                                        <small class="text-muted">Last: <?php echo h($sum['last_sold_at'] ?? 'Unknown'); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Not sold yet</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $sum ? '<span class="fw-bold text-success">' . h(money_clean($sum['avg_price'])) . ' AED</span>' : '<span class="text-muted">—</span>'; ?></td>
                                <td><?php echo h($l['laptop_condition']); ?></td>
                                <td><span class="badge bg-<?php echo $badge; ?>"><?php echo h($status); ?></span></td>
                                <td style="max-width:260px; white-space:normal;"><?php echo h($l['details']); ?></td>
                                <td class="text-end">
                                    <a href="laptops.php?action=edit&id=<?php echo h($l['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <a href="laptops.php?action=delete&id=<?php echo h($l['id']); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this laptop?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
</div>
<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
});
</script>
</div></main></div>
<script>
(function(){
 const b=document.getElementById('rlSidebarToggle'), s=document.getElementById('rlSidebar');
 if(b&&s){b.addEventListener('click',()=>s.classList.toggle('open'));document.addEventListener('click',e=>{if(window.innerWidth<=991&&s.classList.contains('open')&&!s.contains(e.target)&&!b.contains(e.target))s.classList.remove('open')})}
})();
</script>
</body>
</html>
