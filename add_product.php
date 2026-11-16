<?php
require_once __DIR__ . '/session_keepalive.php';
// UI_REMOVE_LEGACY_ADD_PRODUCT_HEADER_V2
// add_product.php
require_once 'db.php';

// Optional Excel reader. Put PhpSpreadsheet in /vendor if your hosting does not already have it.
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

$message = "";

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function increment_numeric_barcode($barcode) {
    $barcode = trim((string)$barcode);
    if ($barcode === '' || !preg_match('/^\d+$/', $barcode)) return '';

    $digits = str_split($barcode);
    $carry = 1;
    for ($i = count($digits) - 1; $i >= 0; $i--) {
        $n = intval($digits[$i]) + $carry;
        if ($n >= 10) {
            $digits[$i] = '0';
            $carry = 1;
        } else {
            $digits[$i] = (string)$n;
            $carry = 0;
            break;
        }
    }
    if ($carry) array_unshift($digits, '1');
    return implode('', $digits);
}

function compare_numeric_barcode_strings($a, $b) {
    $a = ltrim((string)$a, '0');
    $b = ltrim((string)$b, '0');
    if ($a === '') $a = '0';
    if ($b === '') $b = '0';
    if (strlen($a) !== strlen($b)) return strlen($a) <=> strlen($b);
    return strcmp($a, $b);
}

function last_product_barcode_info_by_category(PDO $pdo) {
    $out = [];

    try {
        $stmt = $pdo->query("
            SELECT p.category_id, p.barcode, p.product_name, p.model_name, c.category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.category_id IS NOT NULL AND p.barcode IS NOT NULL AND p.barcode <> ''
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $categoryId = (int)($row['category_id'] ?? 0);
            $barcode = trim((string)($row['barcode'] ?? ''));

            // Same as tools.php: only numeric barcodes can be increased safely.
            if ($categoryId <= 0 || $barcode === '' || !preg_match('/^\d+$/', $barcode)) {
                continue;
            }

            if (!isset($out[$categoryId]) || compare_numeric_barcode_strings($barcode, $out[$categoryId]['last']) > 0) {
                $out[$categoryId] = [
                    'last' => $barcode,
                    'next' => increment_numeric_barcode($barcode),
                    'category_name' => (string)($row['category_name'] ?? ''),
                    'product_name' => (string)($row['product_name'] ?? ''),
                    'model_name' => (string)($row['model_name'] ?? '')
                ];
            }
        }
    } catch (Exception $e) {
        return [];
    }

    return $out;
}

function clean_cell_value($value) {
    if ($value === null) return '';
    if (is_float($value) || is_int($value)) {
        return sprintf('%.0f', $value);
    }
    $value = trim((string)$value);
    $value = str_replace(["\xC2\xA0", "\t", "\r", "\n"], ['', '', '', ''], $value);
    $value = trim($value);

    // Convert values like 3304.0 or 9.62909700011E+11 to a normal integer string when possible.
    if (preg_match('/^[0-9]+\.0+$/', $value)) {
        $value = preg_replace('/\.0+$/', '', $value);
    } elseif (preg_match('/^[0-9]+(\.[0-9]+)?[Ee][+\-]?[0-9]+$/', $value)) {
        $value = sprintf('%.0f', (float)$value);
    }

    return $value;
}

function normalize_barcode_value($value, $padNumeric = true, $targetLength = 12) {
    $value = clean_cell_value($value);
    $value = preg_replace('/\s+/', '', $value);

    if ($value === '') return '';

    // Keep alphanumeric barcodes as-is. Numeric barcodes can be auto-padded to keep leading zeros.
    if ($padNumeric && preg_match('/^\d+$/', $value) && strlen($value) < $targetLength) {
        $value = str_pad($value, $targetLength, '0', STR_PAD_LEFT);
    }

    return $value;
}

function normalize_cost_code($value) {
    $value = clean_cell_value($value);
    $value = preg_replace('/[^0-9]/', '', $value);

    if ($value === '') return '';

    // Full cost code, example 962909700011. Keep exactly.
    if (strpos($value, '9629097') === 0 && strlen($value) >= 8) {
        return $value;
    }

    // Simple AED cost, example 11 or 211 -> full cost code.
    $aed = intval($value);
    if ($aed < 0) return '';
    return '9629097' . str_pad((string)$aed, 5, '0', STR_PAD_LEFT);
}

function decode_cost_aed($costCode) {
    $costCode = clean_cell_value($costCode);
    if (strpos($costCode, '9629097') === 0) {
        return intval(substr($costCode, -3));
    }
    return intval($costCode);
}

function google_sheet_to_csv_url($url) {
    $url = trim($url);
    if ($url === '') return '';

    // Already a CSV/export URL.
    if (stripos($url, 'format=csv') !== false || stripos($url, 'output=csv') !== false || preg_match('/\.csv($|\?)/i', $url)) {
        return $url;
    }

    if (preg_match('#docs\.google\.com/spreadsheets/d/([^/]+)#', $url, $m)) {
        $sheetId = $m[1];
        $gid = '0';
        if (preg_match('/[#&?]gid=([0-9]+)/', $url, $gm)) {
            $gid = $gm[1];
        }
        return "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";
    }

    return $url;
}

function read_csv_rows_from_string($csvText) {
    $rows = [];
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csvText);
    rewind($fp);
    while (($data = fgetcsv($fp, 10000, ',')) !== false) {
        $rows[] = $data;
    }
    fclose($fp);
    return $rows;
}

function read_rows_from_uploaded_file($tmpPath, $originalName, &$readErrors) {
    $rows = [];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === 'csv') {
        $handle = fopen($tmpPath, 'r');
        if ($handle === false) {
            $readErrors[] = 'Cannot open CSV file.';
            return [];
        }
        while (($data = fgetcsv($handle, 10000, ',')) !== false) {
            $rows[] = $data;
        }
        fclose($handle);
        return $rows;
    }

    if (in_array($ext, ['xlsx', 'xls'])) {
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            $readErrors[] = 'Excel upload needs PhpSpreadsheet. Install it with Composer or upload CSV instead.';
            return [];
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
            $sheet = $spreadsheet->getSheet(0);
            $highestRow = $sheet->getHighestDataRow();

            for ($r = 1; $r <= $highestRow; $r++) {
                $row = [];
                // A:G = ID, Model Name, Barcode, Category ID, Cost, Quantity, Name
                for ($c = 1; $c <= 7; $c++) {
                    $row[] = $sheet->getCellByColumnAndRow($c, $r)->getFormattedValue();
                }
                $rows[] = $row;
            }
        } catch (Exception $e) {
            $readErrors[] = 'Excel read error: ' . $e->getMessage();
        }
        return $rows;
    }

    $readErrors[] = 'Unsupported file type. Please upload .xlsx, .xls, or .csv.';
    return [];
}

function import_product_rows($pdo, $rows, $padNumericBarcodes = true) {
    $importedCount = 0;
    $errorCount = 0;
    $invalidCategoryRows = [];
    $skippedRows = [];

    // Expected layout:
    // A: ID (ignored), B: Model Name, C: Barcode, D: Category ID, E: Cost, F: Quantity, G: Name
    foreach ($rows as $index => $data) {
        $rowNumber = $index + 1;

        // Skip header row.
        if ($rowNumber === 1) {
            continue;
        }

        $model_name   = clean_cell_value($data[1] ?? '');
        $barcode      = normalize_barcode_value($data[2] ?? '', $padNumericBarcodes, 12);
        $category_id  = intval(clean_cell_value($data[3] ?? '0'));
        $cost         = normalize_cost_code($data[4] ?? '');
        $quantity     = intval(clean_cell_value($data[5] ?? '0'));
        $product_name = clean_cell_value($data[6] ?? '');

        // Skip fully empty rows.
        if ($model_name === '' && $barcode === '' && $category_id <= 0 && $cost === '' && $quantity === 0 && $product_name === '') {
            continue;
        }

        if ($barcode === '' || $product_name === '' || $cost === '' || $quantity < 0) {
            $skippedRows[] = $rowNumber;
            $errorCount++;
            continue;
        }

        if ($category_id <= 0) {
            $invalidCategoryRows[] = $rowNumber;
            $errorCount++;
            continue;
        }

        try {
            $cat_check = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
            $cat_check->execute([$category_id]);
            if (!$cat_check->fetchColumn()) {
                $invalidCategoryRows[] = $rowNumber;
                $errorCount++;
                continue;
            }

            // Exact duplicate rows are allowed. This lets you upload the same barcode/cost/model/name more than once as separate stock batches.

            $stmt = $pdo->prepare('INSERT INTO products (barcode, product_name, category_id, model_name, cost_price, quantity) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$barcode, $product_name, $category_id, $model_name, $cost, $quantity]);
            $importedCount++;
        } catch (Exception $e) {
            $errorCount++;
            $skippedRows[] = $rowNumber;
        }
    }

    $extraInfo = '';
    if (!empty($invalidCategoryRows)) {
        $extraInfo .= '<br><small class="text-danger">Invalid/missing Category ID rows: ' . htmlspecialchars(implode(', ', array_unique($invalidCategoryRows))) . '.</small>';
    }
    if (!empty($skippedRows)) {
        $extraInfo .= '<br><small class="text-muted">Skipped incomplete rows: ' . htmlspecialchars(implode(', ', array_unique($skippedRows))) . '.</small>';
    }
    return [$importedCount, $errorCount, $extraInfo];
}

// 1. Handle Single Product Form Submit
if (isset($_POST['add_product'])) {
    $barcode      = normalize_barcode_value($_POST['barcode'] ?? '', true, 12);
    $product_name = clean_cell_value($_POST['product_name'] ?? '');
    $model_name   = clean_cell_value($_POST['model_name'] ?? '');
    $category_id  = intval($_POST['category_id'] ?? 0);
    $quantity     = intval($_POST['quantity'] ?? 0);
    $force_add    = isset($_POST['force_add']) && $_POST['force_add'] == '1';
    $cost         = normalize_cost_code($_POST['cost'] ?? '');

    if ($barcode === '' || $product_name === '' || $category_id <= 0 || $quantity < 0 || $cost === '') {
        $message = "<div class='alert alert-danger'>Please fill in all required fields correctly.</div>";
    } else {
        try {
            if (!$force_add) {
                $check = $pdo->prepare('SELECT id FROM products WHERE barcode = ?');
                $check->execute([$barcode]);
                if ($check->rowCount() > 0) {
                    $message = "
                    <div class='alert alert-warning'>
                        <strong>Warning:</strong> The Barcode <strong>" . htmlspecialchars($barcode) . "</strong> already exists in the database.
                        <hr>
                        <form method='POST' action='add_product.php' class='mt-2'>
                            <input type='hidden' name='barcode' value='" . htmlspecialchars($barcode) . "'>
                            <input type='hidden' name='product_name' value='" . htmlspecialchars($product_name) . "'>
                            <input type='hidden' name='category_id' value='" . intval($category_id) . "'>
                            <input type='hidden' name='model_name' value='" . htmlspecialchars($model_name) . "'>
                            <input type='hidden' name='cost' value='" . htmlspecialchars($cost) . "'>
                            <input type='hidden' name='quantity' value='" . intval($quantity) . "'>
                            <input type='hidden' name='force_add' value='1'>
                            <button type='submit' name='add_product' class='btn btn-danger btn-sm fw-bold me-2'>Continue (Save Anyway)</button>
                            <a href='add_product.php' class='btn btn-secondary btn-sm fw-bold'>Back to Edit</a>
                        </form>
                    </div>";
                    goto show_form;
                }
            }

            $stmt = $pdo->prepare('INSERT INTO products (barcode, product_name, category_id, model_name, cost_price, quantity) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$barcode, $product_name, $category_id, $model_name, $cost, $quantity]);
            $message = "<div class='alert alert-success'>Product added successfully. Cost saved as <strong>" . htmlspecialchars($cost) . "</strong> (" . decode_cost_aed($cost) . " AED).</div>";
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// 2. Handle file upload: XLSX / XLS / CSV
if (isset($_POST['upload_products'])) {
    $padNumericBarcodes = isset($_POST['pad_barcodes']);

    if (isset($_FILES['product_file']) && $_FILES['product_file']['error'] == UPLOAD_ERR_OK) {
        $readErrors = [];
        $rows = read_rows_from_uploaded_file($_FILES['product_file']['tmp_name'], $_FILES['product_file']['name'], $readErrors);

        if (!empty($readErrors)) {
            $message = "<div class='alert alert-danger'>" . htmlspecialchars(implode(' ', $readErrors)) . "</div>";
        } else {
            [$importedCount, $errorCount, $extraInfo] = import_product_rows($pdo, $rows, $padNumericBarcodes);
            $message = "<div class='alert alert-success'>Upload complete. Imported <strong>{$importedCount}</strong> products. Skipped/Errors: <strong>{$errorCount}</strong>.{$extraInfo}</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>Please select a valid Excel or CSV file.</div>";
    }
}

// 3. Handle Google Sheets link import
if (isset($_POST['import_google_sheet'])) {
    $sheetUrl = trim($_POST['google_sheet_url'] ?? '');
    $padNumericBarcodes = isset($_POST['pad_barcodes_google']);

    if ($sheetUrl === '') {
        $message = "<div class='alert alert-danger'>Please paste a Google Sheets link.</div>";
    } else {
        $csvUrl = google_sheet_to_csv_url($sheetUrl);
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 20,
                    'header' => "User-Agent: Mozilla/5.0\r\n"
                ]
            ]);
            $csvText = @file_get_contents($csvUrl, false, $context);
            if ($csvText === false || trim($csvText) === '') {
                throw new Exception('Could not read the Google Sheet. Make sure it is shared as Anyone with the link or published to web.');
            }

            $rows = read_csv_rows_from_string($csvText);
            [$importedCount, $errorCount, $extraInfo] = import_product_rows($pdo, $rows, $padNumericBarcodes);
            $message = "<div class='alert alert-success'>Google Sheet import complete. Imported <strong>{$importedCount}</strong> products. Skipped/Errors: <strong>{$errorCount}</strong>.{$extraInfo}</div>";
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Google Sheet Import Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

show_form:
try {
    $cat_stmt = $pdo->query('SELECT * FROM categories ORDER BY category_name ASC');
    $all_categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all_categories = [];
}

$latest_barcodes_by_category = last_product_barcode_info_by_category($pdo);
$category_barcode_info = [];
foreach ($all_categories as $cat) {
    $catId = (int)($cat['id'] ?? 0);
    if ($catId <= 0) continue;

    $info = $latest_barcodes_by_category[$catId] ?? [
        'last' => '',
        'next' => '',
        'category_name' => (string)($cat['category_name'] ?? ''),
        'product_name' => '',
        'model_name' => ''
    ];
    $info['category_name'] = (string)($cat['category_name'] ?? $info['category_name']);
    $category_barcode_info[$catId] = $info;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rukn Al Lotus - Add Product</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: system-ui, sans-serif; }
        .form-container { max-width: 850px; margin: 35px auto; background: #fff; padding: 28px; border-radius: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); }
        .mini-note { font-size: .88rem; }
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
<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body>
<div class="rl-app"><?php include __DIR__ . '/sidebar.php'; ?><main class="rl-main"><div class="rl-topbar"><div class="d-flex gap-2 align-items-start"><button class="btn btn-light border rl-mobile-toggle" id="rlSidebarToggle" type="button"><i class="bi bi-list"></i></button><div class="rl-welcome"><h1>Add Product</h1><p><?=htmlspecialchars(date('l, d F Y'), ENT_QUOTES, 'UTF-8')?></p></div></div><div class="rl-user-chip"><div class="rl-avatar"><?=htmlspecialchars(strtoupper(substr((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'A'),0,1)), ENT_QUOTES, 'UTF-8')?></div><div class="rl-user-text"><div class="fw-bold small"><?=htmlspecialchars((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Admin'), ENT_QUOTES, 'UTF-8')?></div><div class="text-muted" style="font-size:.7rem"><?=htmlspecialchars(ucfirst((string)($_SESSION['role'] ?? 'admin')), ENT_QUOTES, 'UTF-8')?></div></div></div></div><div class="rl-page-content">
<div class="container">
    <div class="form-container">
        <?php echo $message; ?>

        <div class="alert alert-info mini-note">
            <strong>Excel without apostrophe:</strong> You do not need to add <code>`</code> before Barcode or Cost. If Excel changes barcode <code>000000003304</code> to <code>3304</code>, this importer will auto-pad it back to <code>000000003304</code> when the auto-pad option is enabled.
        </div>

        

        

        <hr class="my-4">

        <div class="alert alert-warning py-2 mini-note">
            <strong>Manual cost:</strong> Enter <code>11</code> or <code>962909700011</code>. It will save as the full cost code.
        </div>

        <form method="POST" action="add_product.php">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="barcode" class="form-label fw-bold">Barcode / SKU ID</label>
                    <input type="text" class="form-control" id="barcode" name="barcode" placeholder="Scan or type barcode..." required autocomplete="off">
                    <div id="categoryBarcodeInfoBox" class="mt-2 p-2 rounded border bg-light small d-none">
                        <div class="fw-bold text-muted mb-1">Last barcode for selected category</div>
                        <div>Category: <strong id="barcodeCategoryName">—</strong></div>
                        <div>Last: <strong id="lastCategoryBarcode">—</strong></div>
                        <div id="nextCategoryBarcodeWrap" class="mt-1 d-none">
                            <span class="text-muted">Next:</span>
                            <strong class="text-success" id="nextCategoryBarcode">—</strong>
                            <button type="button" class="btn btn-sm btn-outline-success py-0 ms-1" id="useCategoryBarcodeBtn">Use</button>
                        </div>
                        <div id="noCategoryBarcodeText" class="text-muted mt-1 d-none">No numeric barcode yet for this category.</div>
                        <div class="text-muted mt-1">Increase the next number if needed to avoid duplicate barcode.</div>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="model_name" class="form-label fw-bold">Model Name / Series</label>
                    <input type="text" class="form-control" id="model_name" name="model_name" placeholder="e.g. 13 Pro, Quick 2008">
                </div>
            </div>

            <div class="mb-3">
                <label for="product_name" class="form-label fw-bold">Product Name *</label>
                <input type="text" class="form-control" id="product_name" name="product_name" placeholder="e.g. Screen Protector, Charger, Cable" required>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="category_id" class="form-label fw-bold">Category *</label>
                    <div class="input-group">
                        <select class="form-select" id="category_id" name="category_id" required>
                            <option value="" selected disabled>-- Select Product Category --</option>
                            <?php foreach ($all_categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo h($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a href="categories.php" class="btn btn-outline-success" title="Manage Categories">+ Category</a>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="cost" class="form-label fw-bold text-danger">Cost</label>
                    <input type="text" class="form-control" id="cost" name="cost" value="0" min="0">
                </div>
                <div class="col-md-3 mb-3">
                    <label for="quantity" class="form-label fw-bold">Quantity *</label>
                    <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="0" required>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" name="add_product" class="btn btn-primary btn-lg fw-bold">Save Product</button>
                <a href="sales.php" class="btn btn-outline-secondary">Back to Dashboard</a>
            </div>
        </form>
    </div>
</div>
<script>
const categoryBarcodeInfo = <?php echo json_encode($category_barcode_info, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || {};

function updateCategoryBarcodeInfoBox() {
    const categorySelect = document.getElementById('category_id');
    const box = document.getElementById('categoryBarcodeInfoBox');
    if (!categorySelect || !box) return;

    const selectedId = categorySelect.value;
    const info = categoryBarcodeInfo[selectedId];

    if (!selectedId || !info) {
        box.classList.add('d-none');
        return;
    }

    document.getElementById('barcodeCategoryName').textContent = info.category_name || 'Selected category';
    document.getElementById('lastCategoryBarcode').textContent = info.last || 'No barcode yet';

    const nextWrap = document.getElementById('nextCategoryBarcodeWrap');
    const noText = document.getElementById('noCategoryBarcodeText');
    const nextEl = document.getElementById('nextCategoryBarcode');
    const useBtn = document.getElementById('useCategoryBarcodeBtn');

    if (info.next) {
        nextEl.textContent = info.next;
        useBtn.dataset.fillBarcode = info.next;
        nextWrap.classList.remove('d-none');
        noText.classList.add('d-none');
    } else {
        useBtn.dataset.fillBarcode = '';
        nextWrap.classList.add('d-none');
        noText.classList.remove('d-none');
    }

    box.classList.remove('d-none');
}

document.getElementById('category_id')?.addEventListener('change', updateCategoryBarcodeInfoBox);
document.getElementById('useCategoryBarcodeBtn')?.addEventListener('click', function() {
    const barcodeInput = document.getElementById('barcode');
    if (barcodeInput && this.dataset.fillBarcode) {
        barcodeInput.value = this.dataset.fillBarcode;
        barcodeInput.focus();
    }
});
updateCategoryBarcodeInfoBox();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</div></main></div>
<script>
(function(){
 const b=document.getElementById('rlSidebarToggle'), s=document.getElementById('rlSidebar');
 if(b&&s){b.addEventListener('click',()=>s.classList.toggle('open'));document.addEventListener('click',e=>{if(window.innerWidth<=991&&s.classList.contains('open')&&!s.contains(e.target)&&!b.contains(e.target))s.classList.remove('open')})}
})();
</script>
</body>
</html>
