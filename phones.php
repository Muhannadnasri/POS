<?php
require_once __DIR__ . '/session_keepalive.php';
// phones.php - Phone Inventory Upload / Management
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}


$message = "";

// Safely extend existing phone installations with a model field.
try {
    if (phone_db_table_exists($pdo, 'phones') && !phone_db_column_exists($pdo, 'phones', 'model_name')) {
        $pdo->exec("ALTER TABLE phones ADD COLUMN model_name VARCHAR(180) DEFAULT NULL AFTER brand");
    }
} catch (Throwable $e) {
    // Keep the page usable if the server account cannot ALTER TABLE.
}

// Load active Mobile Models for the Add/Edit Phone form.
$phoneMobileModels = [];
$phoneMobileBrands = [];
try {
    if (phone_db_table_exists($pdo, 'mobile_models') && phone_db_table_exists($pdo, 'mobile_brands')) {
        $stmtMM = $pdo->query("
            SELECT
                b.brand_name,
                m.model_name,
                m.series,
                m.device_type
            FROM mobile_models m
            INNER JOIN mobile_brands b ON b.id = m.brand_id
            WHERE m.is_active = 1
              AND b.is_active = 1
              AND (m.device_type = 'Phone' OR m.device_type = '' OR m.device_type IS NULL)
            ORDER BY b.brand_name ASC, m.series ASC, m.model_name ASC
        ");
        $phoneMobileModels = $stmtMM->fetchAll(PDO::FETCH_ASSOC);

        foreach ($phoneMobileModels as $mmRow) {
            $brandName = trim((string)($mmRow['brand_name'] ?? ''));
            if ($brandName !== '') $phoneMobileBrands[$brandName] = true;
        }
        $phoneMobileBrands = array_keys($phoneMobileBrands);
        natcasesort($phoneMobileBrands);
        $phoneMobileBrands = array_values($phoneMobileBrands);
    }
} catch (Throwable $e) {
    $phoneMobileModels = [];
    $phoneMobileBrands = [];
}


function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalize_cost_code($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === '') return '';

    // Phones now store clean AED cost only.
    // If an old/full cost code is entered, decode the LAST 4 digits as AED.
    // Examples: 962909703950 = 3950 AED, 962909700490 / 0490 = 490 AED.
    if (strpos($digits, '9629097') === 0 && strlen($digits) >= 11) {
        return (string) intval(substr($digits, -4));
    }

    // If user enters normal AED price like 300, keep it clean as 300.
    return (string) intval($digits);
}

function decode_cost_aed($costCode) {
    $digits = preg_replace('/\D+/', '', (string)$costCode);
    if ($digits === '') return 0;

    // Old phone cost codes are decoded by LAST 4 digits, not last 3.
    if (strpos($digits, '9629097') === 0 && strlen($digits) >= 11) {
        return intval(substr($digits, -4));
    }

    return intval($digits);
}

function format_aed($value) {
    return number_format((float)$value, 0) . ' AED';
}


function phone_db_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function phone_db_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
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

    // Use first worksheet by default.
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        // Fallback: find the first worksheet path.
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

function create_phone_table_if_needed($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS phones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barcode VARCHAR(120) NOT NULL,
        cost_price VARCHAR(50) NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        brand VARCHAR(120) NOT NULL DEFAULT '',
        details TEXT NULL,
        phone_condition VARCHAR(80) NOT NULL DEFAULT '',
        status VARCHAR(80) NOT NULL DEFAULT 'Available',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_phones_barcode (barcode),
        INDEX idx_phones_brand (brand),
        INDEX idx_phones_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

try {
    create_phone_table_if_needed($pdo);
} catch (Exception $e) {
    $message = "<div class='alert alert-danger'>Database setup error: " . h($e->getMessage()) . "</div>";
}

// Delete phone
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
    try {
        $stmt = $pdo->prepare("DELETE FROM phones WHERE id = ?");
        $stmt->execute([intval($_GET['id'])]);
        header("Location: phones.php?deleted=1");
        exit;
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Delete failed: " . h($e->getMessage()) . "</div>";
    }
}

if (isset($_GET['deleted'])) {
    $message = "<div class='alert alert-success'>Phone item deleted.</div>";
}

// Load edit item
$editPhone = null;
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
    $stmt = $pdo->prepare("SELECT * FROM phones WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editPhone = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Manual add/update phone
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_phone'])) {
    $id = intval($_POST['phone_id'] ?? 0);
    $barcode = trim($_POST['barcode'] ?? '');
    $cost = normalize_cost_code($_POST['cost_price'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);
    $brand = trim($_POST['brand'] ?? '');
    $modelName = trim($_POST['model_name'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $condition = trim($_POST['phone_condition'] ?? '');
    $status = trim($_POST['status'] ?? 'Available');

    if ($barcode === '' || $cost === '' || $quantity < 0 || $brand === '') {
        $message = "<div class='alert alert-danger'>Please fill Barcode, Cost Price, Quantity, and Brand correctly.</div>";
    } else {
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE phones SET barcode=?, cost_price=?, quantity=?, brand=?, model_name=?, details=?, phone_condition=?, status=? WHERE id=?");
                $stmt->execute([$barcode, $cost, $quantity, $brand, $modelName, $details, $condition, $status, $id]);
                header("Location: phones.php?updated=1");
                exit;
            } else {
                $stmt = $pdo->prepare("INSERT INTO phones (barcode, cost_price, quantity, brand, model_name, details, phone_condition, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$barcode, $cost, $quantity, $brand, $modelName, $details, $condition, $status]);
                header("Location: phones.php?added=1");
                exit;
            }
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Save failed: " . h($e->getMessage()) . "</div>";
        }
    }
}

if (isset($_GET['added'])) $message = "<div class='alert alert-success'>Phone added successfully.</div>";
if (isset($_GET['updated'])) $message = "<div class='alert alert-success'>Phone updated successfully.</div>";

// Upload Excel/CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_phones'])) {
    if (!isset($_FILES['phone_file']) || $_FILES['phone_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "<div class='alert alert-danger'>Please choose a valid Excel or CSV file.</div>";
    } else {
        $tmp = $_FILES['phone_file']['tmp_name'];
        $name = $_FILES['phone_file']['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $replace = isset($_POST['replace_phones']);

        try {
            $rows = ($ext === 'csv') ? read_csv_rows($tmp) : read_xlsx_rows($tmp);
            if (count($rows) < 2) {
                throw new Exception('The file does not contain product rows.');
            }

            $inserted = 0; $updated = 0; $skipped = 0; $errors = 0;

            $pdo->beginTransaction();
            if ($replace) {
                $pdo->exec("DELETE FROM phones");
            }

            // Layout: ID | Barcode | Cost Price (AED) | Quantity | Brand | Details | Condition | Status
            for ($r = 1; $r < count($rows); $r++) {
                $row = $rows[$r];
                $excelId = trim((string)($row[0] ?? ''));
                $barcode = trim((string)($row[1] ?? ''));
                $cost = normalize_cost_code($row[2] ?? '');
                $quantity = intval($row[3] ?? 0);
                $brand = trim((string)($row[4] ?? ''));
                $details = trim((string)($row[5] ?? ''));
                $condition = trim((string)($row[6] ?? ''));
                $status = trim((string)($row[7] ?? '')) ?: 'Available';

                if ($barcode === '' && $brand === '' && $details === '') {
                    continue;
                }

                if ($barcode === '' || $cost === '' || $quantity < 0 || $brand === '') {
                    $skipped++;
                    continue;
                }

                try {
                    if ($excelId !== '' && ctype_digit($excelId)) {
                        $check = $pdo->prepare("SELECT id FROM phones WHERE id = ?");
                        $check->execute([intval($excelId)]);
                        if ($check->fetchColumn()) {
                            $stmt = $pdo->prepare("UPDATE phones SET barcode=?, cost_price=?, quantity=?, brand=?, details=?, phone_condition=?, status=? WHERE id=?");
                            $stmt->execute([$barcode, $cost, $quantity, $brand, $details, $condition, $status, intval($excelId)]);
                            $updated++;
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO phones (id, barcode, cost_price, quantity, brand, details, phone_condition, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([intval($excelId), $barcode, $cost, $quantity, $brand, $details, $condition, $status]);
                            $inserted++;
                        }
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO phones (barcode, cost_price, quantity, brand, details, phone_condition, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$barcode, $cost, $quantity, $brand, $details, $condition, $status]);
                        $inserted++;
                    }
                } catch (Exception $e) {
                    $errors++;
                }
            }

            $pdo->commit();
            $message = "<div class='alert alert-success'>Phone upload complete. Inserted: <strong>{$inserted}</strong>, Updated: <strong>{$updated}</strong>, Skipped: <strong>{$skipped}</strong>, Errors: <strong>{$errors}</strong>.</div>";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "<div class='alert alert-danger'>Upload failed: " . h($e->getMessage()) . "</div>";
        }
    }
}

// Stats
$stats = [
    'items' => 0,
    'units' => 0,
    'cost_value' => 0,
    'available' => 0,
    'sold' => 0,
    'reserved' => 0,
];
try {
    $allForStats = $pdo->query("SELECT quantity, cost_price, status FROM phones")->fetchAll(PDO::FETCH_ASSOC);
    $stats['items'] = count($allForStats);
    foreach ($allForStats as $p) {
        $q = intval($p['quantity']);
        $stats['units'] += $q;
        $stats['cost_value'] += decode_cost_aed($p['cost_price']) * $q;
        $st = strtolower(trim($p['status']));
        if ($st === 'available') $stats['available'] += $q;
        if ($st === 'sold') $stats['sold'] += $q;
        if ($st === 'reserved') $stats['reserved'] += $q;
    }
} catch (Exception $e) {}

// Filters
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$brandFilter = trim($_GET['brand'] ?? '');

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(barcode LIKE ? OR brand LIKE ? OR details LIKE ? OR phone_condition LIKE ? OR status LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($statusFilter !== '') {
    $where[] = "status = ?";
    $params[] = $statusFilter;
}
if ($brandFilter !== '') {
    $where[] = "brand = ?";
    $params[] = $brandFilter;
}
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM phones {$sqlWhere} ORDER BY id DESC LIMIT 500");
$stmt->execute($params);
$phones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$brands = $pdo->query("SELECT DISTINCT brand FROM phones WHERE brand <> '' ORDER BY brand ASC")->fetchAll(PDO::FETCH_COLUMN);
$statuses = $pdo->query("SELECT DISTINCT status FROM phones WHERE status <> '' ORDER BY status ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Phone Inventory - Rukn Al Lotus</title>
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
/* ===== Phone top summary cards ===== */
.phone-top-card{
    position:relative;
    overflow:hidden;
    border:0!important;
    min-height:112px;
    color:#fff!important;
    box-shadow:0 10px 26px rgba(16,24,40,.12)!important;
    transition:transform .18s ease,box-shadow .18s ease;
}
.phone-top-card:hover{
    transform:translateY(-3px);
    box-shadow:0 15px 32px rgba(16,24,40,.17)!important;
}
.phone-top-card::after{
    content:"";
    position:absolute;
    width:95px;
    height:95px;
    border-radius:50%;
    right:-25px;
    bottom:-38px;
    background:rgba(255,255,255,.14);
}
.phone-top-card::before{
    content:"";
    position:absolute;
    width:50px;
    height:50px;
    border-radius:50%;
    right:28px;
    top:-22px;
    background:rgba(255,255,255,.08);
}
.phone-top-card > *{position:relative;z-index:1}
.phone-top-card .small-label{color:rgba(255,255,255,.82)!important}
.phone-top-card h3,
.phone-top-card small{color:#fff!important}
.phone-top-card.blue{background:linear-gradient(135deg,#3154e8,#667eea)!important}
.phone-top-card.cyan{background:linear-gradient(135deg,#0e8fa8,#29b8d2)!important}
.phone-top-card.red{background:linear-gradient(135deg,#d94a62,#f06a7d)!important}
.phone-top-card.green{background:linear-gradient(135deg,#10986a,#2bbf8a)!important}
.phone-top-card.orange{background:linear-gradient(135deg,#d98b16,#f6b73c)!important}
.phone-top-card.purple{background:linear-gradient(135deg,#7652d9,#9b72ef)!important}
</style>

<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body>
<div class="rl-app"><?php include __DIR__ . '/sidebar.php'; ?><main class="rl-main"><div class="rl-topbar"><div class="d-flex gap-2 align-items-start"><button class="btn btn-light border rl-mobile-toggle" id="rlSidebarToggle" type="button"><i class="bi bi-list"></i></button><div class="rl-welcome"><h1>Phones Inventory</h1><p>Manage phone inventory, conditions, stock and imports · <?=htmlspecialchars(date('l, d F Y'), ENT_QUOTES, 'UTF-8')?></p></div></div><div class="rl-user-chip"><div class="rl-avatar"><?=htmlspecialchars(strtoupper(substr((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'A'),0,1)), ENT_QUOTES, 'UTF-8')?></div><div class="rl-user-text"><div class="fw-bold small"><?=htmlspecialchars((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Admin'), ENT_QUOTES, 'UTF-8')?></div><div class="text-muted" style="font-size:.7rem"><?=htmlspecialchars(ucfirst((string)($_SESSION['role'] ?? 'admin')), ENT_QUOTES, 'UTF-8')?></div></div></div></div><div class="rl-page-content">
<div class="container-fluid py-4 px-4">
    

    <?php echo $message; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-2"><div class="card card-box phone-top-card blue p-3"><div class="small-label">Phone Rows</div><h3 class="fw-bold mb-0"><?php echo number_format($stats['items']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box phone-top-card cyan p-3"><div class="small-label">Units in Stock</div><h3 class="fw-bold text-primary mb-0"><?php echo number_format($stats['units']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box phone-top-card red p-3"><div class="small-label">Inventory Cost</div><h3 class="fw-bold text-danger mb-0"><?php echo format_aed($stats['cost_value']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box phone-top-card green p-3"><div class="small-label">Available Units</div><h3 class="fw-bold text-success mb-0"><?php echo number_format($stats['available']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box phone-top-card orange p-3"><div class="small-label">Reserved Units</div><h3 class="fw-bold text-warning mb-0"><?php echo number_format($stats['reserved']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box phone-top-card purple p-3"><div class="small-label">Sold Units</div><h3 class="fw-bold text-secondary mb-0"><?php echo number_format($stats['sold']); ?></h3></div></div>
    </div>

    <div class="row g-4 mb-4">
        

        <div class="col-12">
            <div class="card card-box p-4 h-100">
                <h5 class="fw-bold mb-3"><?php echo $editPhone ? '✏️ Edit Phone Item' : '➕ Add Phone Manually'; ?></h5>
                <form method="POST">
                    <input type="hidden" name="save_phone" value="1">
                    <input type="hidden" name="phone_id" value="<?php echo h($editPhone['id'] ?? ''); ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Barcode *</label>
                            <input type="text" name="barcode" class="form-control" value="<?php echo h($editPhone['barcode'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Cost Price (AED) *</label>
                            <input type="text" name="cost_price" class="form-control" value="<?php echo $editPhone ? h(decode_cost_aed($editPhone['cost_price'] ?? '')) : ''; ?>" placeholder="300" required>
                            <div class="form-text">Use clean AED price only. Old codes like 962909703950 will become 3950 AED.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Quantity *</label>
                            <input type="number" name="quantity" class="form-control" min="0" value="<?php echo h($editPhone['quantity'] ?? '1'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Brand *</label>
                            <input
                                type="text"
                                name="brand"
                                id="phoneBrandInput"
                                class="form-control"
                                list="phoneBrandSuggestions"
                                value="<?php echo h($editPhone['brand'] ?? ''); ?>"
                                placeholder="Apple / Samsung"
                                autocomplete="off"
                                required
                            >
                            <datalist id="phoneBrandSuggestions">
                                <?php foreach ($phoneMobileBrands as $mobileBrand): ?>
                                    <option value="<?php echo h($mobileBrand); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="form-text">Type or select a Brand to filter Mobile Models.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Mobile Model</label>
                            <select name="model_name" id="phoneModelSelect" class="form-select">
                                <option value="">-- Select Brand First --</option>
                            </select>
                            <div class="form-text" id="phoneModelHelp">Models are loaded from Mobile Models.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Condition</label>
                            <select name="phone_condition" class="form-select">
                                <?php $conditions = ['New','Used','Open Box','Refurbished','Damaged','For Parts']; $curC = $editPhone['phone_condition'] ?? ''; ?>
                                <option value="">-- Select --</option>
                                <?php foreach ($conditions as $c): ?><option value="<?php echo h($c); ?>" <?php echo ($curC === $c) ? 'selected' : ''; ?>><?php echo h($c); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <?php $statusOptions = ['Available','Sold','Reserved','Repair','Returned','Defective']; $curS = $editPhone['status'] ?? 'Available'; ?>
                                <?php foreach ($statusOptions as $s): ?><option value="<?php echo h($s); ?>" <?php echo ($curS === $s) ? 'selected' : ''; ?>><?php echo h($s); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Details</label>
                            <textarea name="details" class="form-control" rows="2" placeholder="iPhone 14 Pro Max 256GB Purple, battery health, notes..."><?php echo h($editPhone['details'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button class="btn btn-success fw-bold" type="submit"><?php echo $editPhone ? 'Update Phone' : 'Save Phone'; ?></button>
                        <?php if ($editPhone): ?><a href="phones.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card card-box p-4 mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-bold">Search</label>
                <input type="text" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="Barcode, brand, details, condition...">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Brand</label>
                <select name="brand" class="form-select">
                    <option value="">All Brands</option>
                    <?php foreach ($brands as $b): ?><option value="<?php echo h($b); ?>" <?php echo $brandFilter === $b ? 'selected' : ''; ?>><?php echo h($b); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <?php foreach ($statuses as $s): ?><option value="<?php echo h($s); ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>><?php echo h($s); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-dark fw-bold">Filter</button>
            </div>
        </form>
    </div>

    <div class="card card-box p-4">
        <h5 class="fw-bold mb-3">📋 Phone List</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Barcode</th>
                        <th>Brand</th>
                        <th>Model</th>
                        <th>Details</th>
                        <th>Condition</th>
                        <th>Status</th>
                        <th class="text-end">Cost Price</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Stock Value</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($phones)): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4">No phones found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($phones as $p): ?>
                            <?php $cost = decode_cost_aed($p['cost_price']); $qty = intval($p['quantity']); ?>
                            <tr>
                                <td class="fw-bold"><?php echo intval($p['id']); ?></td>
                                <td class="fw-semibold"><?php echo h($p['barcode']); ?></td>
                                <td><?php echo h($p['brand']); ?></td>
                                <td class="fw-semibold"><?php echo h($p['model_name'] ?? ''); ?></td>
                                <td style="max-width:320px; white-space:normal;"><?php echo h($p['details']); ?></td>
                                <td><?php echo h($p['phone_condition']); ?></td>
                                <td><span class="badge bg-<?php echo strtolower($p['status']) === 'available' ? 'success' : (strtolower($p['status']) === 'sold' ? 'secondary' : 'warning'); ?>"><?php echo h($p['status']); ?></span></td>
                                <td class="text-end fw-bold text-danger"><?php echo format_aed($cost); ?></td>
                                <td class="text-center fw-bold <?php echo $qty <= 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $qty; ?></td>
                                <td class="text-end fw-bold"><?php echo format_aed($cost * $qty); ?></td>
                                <td class="text-center">
                                    <a class="btn btn-sm btn-outline-primary" href="phones.php?action=edit&id=<?php echo intval($p['id']); ?>">Edit</a>
                                    <a class="btn btn-sm btn-outline-danger" href="phones.php?action=delete&id=<?php echo intval($p['id']); ?>" onclick="return confirm('Delete this phone item?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</div></main></div>
<script>
(function(){
 const b=document.getElementById('rlSidebarToggle'), s=document.getElementById('rlSidebar');
 if(b&&s){b.addEventListener('click',()=>s.classList.toggle('open'));document.addEventListener('click',e=>{if(window.innerWidth<=991&&s.classList.contains('open')&&!s.contains(e.target)&&!b.contains(e.target))s.classList.remove('open')})}
})();
</script>

<script>
(function(){
    const models = <?php echo json_encode($phoneMobileModels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const brandInput = document.getElementById('phoneBrandInput');
    const modelSelect = document.getElementById('phoneModelSelect');
    const modelHelp = document.getElementById('phoneModelHelp');
    const savedModel = <?php echo json_encode((string)($editPhone['model_name'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    if (!brandInput || !modelSelect) return;

    function normalize(value){
        return String(value || '').trim().toLocaleLowerCase();
    }

    function refreshPhoneModels(keepCurrent = true){
        const brand = normalize(brandInput.value);
        const previous = keepCurrent ? (modelSelect.value || savedModel) : '';

        modelSelect.innerHTML = '';

        if (!brand) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '-- Select Brand First --';
            modelSelect.appendChild(opt);
            if (modelHelp) modelHelp.textContent = 'Choose a Brand to see matching models.';
            return;
        }

        const matches = models.filter(function(row){
            return normalize(row.brand_name) === brand;
        });

        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = matches.length ? '-- Select Mobile Model --' : '-- No Models Found --';
        modelSelect.appendChild(empty);

        matches.forEach(function(row){
            const opt = document.createElement('option');
            opt.value = row.model_name || '';
            let label = row.model_name || '';
            if (row.series) label += ' · ' + row.series;
            opt.textContent = label;
            if (previous && normalize(opt.value) === normalize(previous)) opt.selected = true;
            modelSelect.appendChild(opt);
        });

        if (modelHelp) {
            modelHelp.textContent = matches.length
                ? matches.length + ' model(s) found for ' + brandInput.value.trim() + '.'
                : 'No active phone models found for this Brand in Mobile Models.';
        }
    }

    brandInput.addEventListener('input', function(){ refreshPhoneModels(false); });
    brandInput.addEventListener('change', function(){ refreshPhoneModels(false); });

    refreshPhoneModels(true);
})();
</script>

</body>
</html>
