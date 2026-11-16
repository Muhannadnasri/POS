<?php
require_once __DIR__ . '/session_keepalive.php';
// supplier_report.php - Supplier / Supply Cost Report v1.1 - shift click range select
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$messages = [];

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money_aed_sr($amount) {
    return number_format((float)$amount, 2) . ' AED';
}

function money_clean_sr($amount) {
    $amount = (float)$amount;
    return (abs($amount - round($amount)) < 0.005) ? number_format($amount, 0) : number_format($amount, 2);
}

function table_exists_sr(PDO $pdo, $table) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function table_columns_sr(PDO $pdo, $table) {
    try {
        if (!table_exists_sr($pdo, $table)) return [];
        $safe = str_replace('`', '``', $table);
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$safe}`");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Exception $e) {
        return [];
    }
}

function column_exists_sr(array $columns, $column) {
    return in_array($column, $columns, true);
}

function fetch_all_sr(PDO $pdo, $sql, array $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function parse_aed_number_sr($value) {
    $text = trim((string)($value ?? ''));
    if ($text === '') return 0.0;
    $text = str_ireplace(['AED', 'DHS', 'DH', 'درهم', 'د.إ'], '', $text);
    $text = str_replace([',', ' '], '', $text);
    $text = preg_replace('/[^0-9.\-]/', '', $text);
    if ($text === '' || $text === '-' || !is_numeric($text)) return 0.0;
    return (float)$text;
}

function decode_product_cost_sr($value) {
    $digits = preg_replace('/\D+/', '', (string)($value ?? ''));
    if ($digits === '') return 0.0;

    // Product/accessory cost code format: 962909700011 = 11 AED, 962909700211 = 211 AED.
    if (strpos($digits, '9629097') === 0 && strlen($digits) >= 10) {
        return (float)intval(substr($digits, -3));
    }

    return parse_aed_number_sr($value);
}

function decode_phone_like_cost_sr($value) {
    $digits = preg_replace('/\D+/', '', (string)($value ?? ''));
    if ($digits === '') return 0.0;

    // Phone/laptop/tool old cost code support: 962909703950 = 3950 AED, 962909700490 = 490 AED.
    if (strpos($digits, '9629097') === 0 && strlen($digits) >= 11) {
        return (float)intval(substr($digits, -4));
    }

    return parse_aed_number_sr($value);
}

function cost_for_source_sr($source, $value) {
    return ($source === 'products') ? decode_product_cost_sr($value) : decode_phone_like_cost_sr($value);
}

function clean_search_text_sr($value) {
    $value = (string)($value ?? '');
    $value = str_replace(["\xC2\xA0", "\xE2\x80\x8E", "\xE2\x80\x8F", "\t", "\r", "\n"], ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function str_contains_ci_sr($haystack, $needle) {
    $haystack = clean_search_text_sr($haystack);
    $needle = clean_search_text_sr($needle);
    if ($needle === '') return true;
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return stripos($haystack, $needle) !== false;
}

function ensure_supplier_tables_sr(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_name VARCHAR(191) NOT NULL,
        phone VARCHAR(80) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL,
        UNIQUE KEY uniq_supplier_name (supplier_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_supplier_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        item_source VARCHAR(40) NOT NULL,
        item_id INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_supplier_item (item_source, item_id),
        INDEX idx_supplier_items_supplier (supplier_id),
        INDEX idx_supplier_items_source (item_source, item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function get_suppliers_sr(PDO $pdo) {
    return fetch_all_sr($pdo, "SELECT * FROM suppliers ORDER BY supplier_name ASC");
}

function get_supplier_assignment_map_sr(PDO $pdo) {
    $rows = fetch_all_sr($pdo, "
        SELECT isi.item_source, isi.item_id, isi.supplier_id, s.supplier_name
        FROM inventory_supplier_items isi
        LEFT JOIN suppliers s ON s.id = isi.supplier_id
    ");
    $map = [];
    foreach ($rows as $row) {
        $key = ($row['item_source'] ?? '') . ':' . (int)($row['item_id'] ?? 0);
        $map[$key] = [
            'supplier_id' => (int)($row['supplier_id'] ?? 0),
            'supplier_name' => (string)($row['supplier_name'] ?? '')
        ];
    }
    return $map;
}

function source_label_sr($source) {
    $labels = [
        'products' => 'Products',
        'phones' => 'Phones',
        'laptops' => 'Laptops',
        'tools' => 'Tools'
    ];
    return $labels[$source] ?? ucfirst($source);
}

function status_badge_sr($status, $qty) {
    $status = trim((string)$status);
    $lower = strtolower($status);
    if ($lower === 'sold' || (int)$qty <= 0) return '<span class="badge bg-danger">Sold / Out</span>';
    if ($lower === 'reserved') return '<span class="badge bg-warning text-dark">Reserved</span>';
    if ($lower === 'available' || $status === '') return '<span class="badge bg-success">Available</span>';
    return '<span class="badge bg-secondary">' . h($status) . '</span>';
}

function normalize_item_key_sr($value) {
    $value = trim((string)$value);
    if (!preg_match('/^(products|phones|laptops|tools):(\d+)$/', $value, $m)) {
        return null;
    }
    return [$m[1], (int)$m[2]];
}

function get_inventory_items_sr(PDO $pdo, array $assignmentMap) {
    $items = [];

    // Products
    if (table_exists_sr($pdo, 'products')) {
        $pCols = table_columns_sr($pdo, 'products');
        $hasCategories = table_exists_sr($pdo, 'categories') && column_exists_sr($pCols, 'category_id');
        if ($hasCategories) {
            $rows = fetch_all_sr($pdo, "SELECT p.*, c.category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.id DESC");
        } else {
            $rows = fetch_all_sr($pdo, "SELECT * FROM products ORDER BY id DESC");
        }
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;
            $qty = max(0, (int)($row['quantity'] ?? 0));
            $unitCost = cost_for_source_sr('products', $row['cost_price'] ?? 0);
            $key = 'products:' . $id;
            $assign = $assignmentMap[$key] ?? ['supplier_id' => 0, 'supplier_name' => ''];
            $nameParts = array_filter([
                $row['product_name'] ?? '',
                $row['model_name'] ?? ''
            ], function($v) { return trim((string)$v) !== ''; });
            $items[] = [
                'key' => $key,
                'source' => 'products',
                'source_label' => source_label_sr('products'),
                'id' => $id,
                'barcode' => $row['barcode'] ?? '',
                'name' => $nameParts ? implode(' - ', $nameParts) : 'Product #' . $id,
                'brand' => '',
                'model' => $row['model_name'] ?? '',
                'type' => $row['category_name'] ?? 'Product',
                'quality' => '',
                'status' => $qty > 0 ? 'Available' : 'Sold',
                'quantity' => $qty,
                'original_quantity' => $qty,
                'unit_cost' => $unitCost,
                'left_cost' => $unitCost * $qty,
                'original_cost' => $unitCost * $qty,
                'supplier_id' => $assign['supplier_id'],
                'supplier_name' => $assign['supplier_name']
            ];
        }
    }

    // Phones
    if (table_exists_sr($pdo, 'phones')) {
        $rows = fetch_all_sr($pdo, "SELECT * FROM phones ORDER BY id DESC");
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;
            $qty = max(0, (int)($row['quantity'] ?? 0));
            $unitCost = cost_for_source_sr('phones', $row['cost_price'] ?? 0);
            $key = 'phones:' . $id;
            $assign = $assignmentMap[$key] ?? ['supplier_id' => 0, 'supplier_name' => ''];
            $brand = trim((string)($row['brand'] ?? ''));
            $details = trim((string)($row['details'] ?? ''));
            $items[] = [
                'key' => $key,
                'source' => 'phones',
                'source_label' => source_label_sr('phones'),
                'id' => $id,
                'barcode' => $row['barcode'] ?? '',
                'name' => trim($brand . ($details !== '' ? ' - ' . $details : '')) ?: 'Phone #' . $id,
                'brand' => $brand,
                'model' => '',
                'type' => $row['phone_condition'] ?? 'Phone',
                'quality' => '',
                'status' => $row['status'] ?? ($qty > 0 ? 'Available' : 'Sold'),
                'quantity' => $qty,
                'original_quantity' => $qty,
                'unit_cost' => $unitCost,
                'left_cost' => $unitCost * $qty,
                'original_cost' => $unitCost * $qty,
                'supplier_id' => $assign['supplier_id'],
                'supplier_name' => $assign['supplier_name']
            ];
        }
    }

    // Laptops
    if (table_exists_sr($pdo, 'laptops')) {
        $rows = fetch_all_sr($pdo, "SELECT * FROM laptops ORDER BY id DESC");
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;
            $qty = max(0, (int)($row['quantity'] ?? 0));
            $origQty = isset($row['original_quantity']) && (int)$row['original_quantity'] > 0 ? (int)$row['original_quantity'] : $qty;
            $unitCost = cost_for_source_sr('laptops', $row['cost_price'] ?? 0);
            $key = 'laptops:' . $id;
            $assign = $assignmentMap[$key] ?? ['supplier_id' => 0, 'supplier_name' => ''];
            $brand = trim((string)($row['brand'] ?? ''));
            $model = trim((string)($row['model_name'] ?? ''));
            $items[] = [
                'key' => $key,
                'source' => 'laptops',
                'source_label' => source_label_sr('laptops'),
                'id' => $id,
                'barcode' => $row['barcode'] ?? '',
                'name' => trim($brand . ' ' . $model) ?: 'Laptop #' . $id,
                'brand' => $brand,
                'model' => $model,
                'type' => $row['laptop_condition'] ?? 'Laptop',
                'quality' => '',
                'status' => $row['status'] ?? ($qty > 0 ? 'Available' : 'Sold'),
                'quantity' => $qty,
                'original_quantity' => $origQty,
                'unit_cost' => $unitCost,
                'left_cost' => $unitCost * $qty,
                'original_cost' => $unitCost * $origQty,
                'supplier_id' => $assign['supplier_id'],
                'supplier_name' => $assign['supplier_name']
            ];
        }
    }

    // Tools / Spare parts
    if (table_exists_sr($pdo, 'tool_items')) {
        $rows = fetch_all_sr($pdo, "SELECT * FROM tool_items ORDER BY id DESC");
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;
            $qty = max(0, (int)($row['quantity'] ?? 0));
            $origQty = isset($row['original_quantity']) && (int)$row['original_quantity'] > 0 ? (int)$row['original_quantity'] : $qty;
            $unitCost = cost_for_source_sr('tools', $row['cost_price'] ?? 0);
            $key = 'tools:' . $id;
            $assign = $assignmentMap[$key] ?? ['supplier_id' => 0, 'supplier_name' => ''];
            $brand = trim((string)($row['brand'] ?? ''));
            $model = trim((string)($row['model_name'] ?? ''));
            $type = trim((string)($row['item_type'] ?? ''));
            $quality = trim((string)($row['quality'] ?? ''));
            $name = trim($brand . ' ' . $model . ($type !== '' ? ' - ' . $type : ''));
            $items[] = [
                'key' => $key,
                'source' => 'tools',
                'source_label' => source_label_sr('tools'),
                'id' => $id,
                'barcode' => $row['barcode'] ?? '',
                'name' => $name ?: 'Tool #' . $id,
                'brand' => $brand,
                'model' => $model,
                'type' => $type ?: 'Tool',
                'quality' => $quality,
                'status' => $row['status'] ?? ($qty > 0 ? 'Available' : 'Sold'),
                'quantity' => $qty,
                'original_quantity' => $origQty,
                'unit_cost' => $unitCost,
                'left_cost' => $unitCost * $qty,
                'original_cost' => $unitCost * $origQty,
                'supplier_id' => $assign['supplier_id'],
                'supplier_name' => $assign['supplier_name']
            ];
        }
    }

    return $items;
}

try {
    ensure_supplier_tables_sr($pdo);
} catch (Exception $e) {
    $messages[] = ['danger', 'Supplier tables error: ' . $e->getMessage()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_supplier'])) {
            $supplierName = clean_search_text_sr($_POST['supplier_name'] ?? '');
            $phone = clean_search_text_sr($_POST['supplier_phone'] ?? '');
            $notes = trim((string)($_POST['supplier_notes'] ?? ''));
            if ($supplierName === '') {
                throw new Exception('Supplier name is required.');
            }
            $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_name, phone, notes, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE phone=VALUES(phone), notes=VALUES(notes), updated_at=NOW()");
            $stmt->execute([$supplierName, $phone, $notes]);
            $messages[] = ['success', 'Supplier saved successfully.'];
        }

        if (isset($_POST['assign_supplier'])) {
            $supplierId = (int)($_POST['bulk_supplier_id'] ?? 0);
            $selected = $_POST['selected_items'] ?? [];
            if ($supplierId <= 0) throw new Exception('Choose a supplier first.');
            if (!is_array($selected) || count($selected) === 0) throw new Exception('Select at least one item.');

            $check = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE id = ?");
            $check->execute([$supplierId]);
            if ((int)$check->fetchColumn() === 0) throw new Exception('Supplier was not found.');

            $stmt = $pdo->prepare("INSERT INTO inventory_supplier_items (supplier_id, item_source, item_id, assigned_at)
                                   VALUES (?, ?, ?, NOW())
                                   ON DUPLICATE KEY UPDATE supplier_id = VALUES(supplier_id), assigned_at = NOW()");
            $count = 0;
            foreach ($selected as $key) {
                $parts = normalize_item_key_sr($key);
                if (!$parts) continue;
                [$source, $itemId] = $parts;
                $stmt->execute([$supplierId, $source, $itemId]);
                $count++;
            }
            $messages[] = ['success', $count . ' item(s) assigned to supplier.'];
        }

        if (isset($_POST['clear_supplier'])) {
            $selected = $_POST['selected_items'] ?? [];
            if (!is_array($selected) || count($selected) === 0) throw new Exception('Select at least one item.');
            $stmt = $pdo->prepare("DELETE FROM inventory_supplier_items WHERE item_source = ? AND item_id = ?");
            $count = 0;
            foreach ($selected as $key) {
                $parts = normalize_item_key_sr($key);
                if (!$parts) continue;
                [$source, $itemId] = $parts;
                $stmt->execute([$source, $itemId]);
                $count += $stmt->rowCount();
            }
            $messages[] = ['success', $count . ' supplier assignment(s) cleared.'];
        }
    } catch (Exception $e) {
        $messages[] = ['danger', $e->getMessage()];
    }
}

$suppliers = get_suppliers_sr($pdo);
$assignmentMap = get_supplier_assignment_map_sr($pdo);
$allItems = get_inventory_items_sr($pdo, $assignmentMap);

$supplierFilter = trim((string)($_GET['supplier_id'] ?? 'all'));
$sourceFilter = trim((string)($_GET['source'] ?? 'all'));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$search = trim((string)($_GET['search'] ?? ''));

$filteredItems = [];
foreach ($allItems as $item) {
    if ($supplierFilter === 'unassigned') {
        if ((int)$item['supplier_id'] > 0) continue;
    } elseif ($supplierFilter !== 'all') {
        if ((int)$item['supplier_id'] !== (int)$supplierFilter) continue;
    }

    if ($sourceFilter !== 'all' && $item['source'] !== $sourceFilter) continue;

    if ($statusFilter === 'available' && ((int)$item['quantity'] <= 0 || strtolower((string)$item['status']) === 'sold')) continue;
    if ($statusFilter === 'sold' && ((int)$item['quantity'] > 0 && strtolower((string)$item['status']) !== 'sold')) continue;

    if ($search !== '') {
        $hay = implode(' ', [
            $item['barcode'], $item['name'], $item['brand'], $item['model'], $item['type'], $item['quality'],
            $item['source_label'], $item['supplier_name'], $item['status']
        ]);
        if (!str_contains_ci_sr($hay, $search)) continue;
    }

    $filteredItems[] = $item;
}

$summary = [
    'items' => count($filteredItems),
    'qty_left' => 0,
    'original_qty' => 0,
    'left_cost' => 0.0,
    'original_cost' => 0.0,
    'sold_or_missing_qty' => 0,
    'sold_or_missing_cost' => 0.0
];
$breakdown = [];
foreach (['products', 'phones', 'laptops', 'tools'] as $src) {
    $breakdown[$src] = ['items' => 0, 'qty_left' => 0, 'original_qty' => 0, 'left_cost' => 0.0, 'original_cost' => 0.0];
}

foreach ($filteredItems as $item) {
    $src = $item['source'];
    $summary['qty_left'] += (int)$item['quantity'];
    $summary['original_qty'] += (int)$item['original_quantity'];
    $summary['left_cost'] += (float)$item['left_cost'];
    $summary['original_cost'] += (float)$item['original_cost'];

    $breakdown[$src]['items']++;
    $breakdown[$src]['qty_left'] += (int)$item['quantity'];
    $breakdown[$src]['original_qty'] += (int)$item['original_quantity'];
    $breakdown[$src]['left_cost'] += (float)$item['left_cost'];
    $breakdown[$src]['original_cost'] += (float)$item['original_cost'];
}
$summary['sold_or_missing_qty'] = max(0, $summary['original_qty'] - $summary['qty_left']);
$summary['sold_or_missing_cost'] = max(0, $summary['original_cost'] - $summary['left_cost']);

$selectedSupplierName = 'All suppliers';
if ($supplierFilter === 'unassigned') {
    $selectedSupplierName = 'Unassigned items';
} elseif ($supplierFilter !== 'all') {
    foreach ($suppliers as $s) {
        if ((int)$s['id'] === (int)$supplierFilter) {
            $selectedSupplierName = $s['supplier_name'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Cost Report - Rukn Al Lotus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .page-title { letter-spacing: -0.02em; }
        .metric-card { border: 0; border-radius: 18px; box-shadow: 0 8px 24px rgba(0,0,0,0.06); }
        .metric-label { color: #6c757d; font-size: 0.84rem; font-weight: 700; text-transform: uppercase; }
        .metric-value { font-size: 1.45rem; font-weight: 800; }
        .table thead th { white-space: nowrap; background: #111827; color: #fff; }
        .sticky-actions { position: sticky; top: 0; z-index: 2; background: #fff; border-radius: 14px; }
        .item-name { min-width: 260px; }
        .small-muted { color: #6c757d; font-size: 0.85rem; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .card { box-shadow: none !important; }
        }
    </style>
<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body class="has-shared-sidebar">
<?php include __DIR__ . '/sidebar.php'; ?>

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-4">
        <div>
            <h2 class="page-title fw-bold text-primary mb-1">Supplier Cost Report <span class="badge bg-dark">v1.1 shift-select</span></h2>
            <p class="text-muted mb-0">Assign inventory items to suppliers and filter total cost by supplier.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 no-print">
            <a href="view_products.php" class="btn btn-outline-secondary">Products</a>
            <a href="phones.php" class="btn btn-outline-secondary">Phones</a>
            <a href="laptops.php" class="btn btn-outline-secondary">Laptops</a>
            <a href="tools.php" class="btn btn-outline-secondary">Tools</a>
            <button class="btn btn-dark" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>

    <?php foreach ($messages as $msg): ?>
        <div class="alert alert-<?php echo h($msg[0]); ?> alert-dismissible fade show" role="alert">
            <?php echo h($msg[1]); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card metric-card p-3"><div class="metric-label">Report Supplier</div><div class="metric-value text-primary"><?php echo h($selectedSupplierName); ?></div></div></div>
        <div class="col-md-3"><div class="card metric-card p-3"><div class="metric-label">Total Left Cost</div><div class="metric-value text-success"><?php echo money_aed_sr($summary['left_cost']); ?></div></div></div>
        <div class="col-md-3"><div class="card metric-card p-3"><div class="metric-label">Total Original Cost</div><div class="metric-value text-danger"><?php echo money_aed_sr($summary['original_cost']); ?></div></div></div>
        <div class="col-md-3"><div class="card metric-card p-3"><div class="metric-label">Items / Qty Left</div><div class="metric-value"><?php echo number_format($summary['items']); ?> / <?php echo number_format($summary['qty_left']); ?></div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card metric-card p-3"><div class="metric-label">Products Left Cost</div><div class="metric-value"><?php echo money_aed_sr($breakdown['products']['left_cost']); ?></div><div class="small-muted">Qty: <?php echo number_format($breakdown['products']['qty_left']); ?></div></div></div>
        <div class="col-md-3"><div class="card metric-card p-3"><div class="metric-label">Phones Left Cost</div><div class="metric-value"><?php echo money_aed_sr($breakdown['phones']['left_cost']); ?></div><div class="small-muted">Qty: <?php echo number_format($breakdown['phones']['qty_left']); ?></div></div></div>
        <div class="col-md-3"><div class="card metric-card p-3"><div class="metric-label">Laptops Left Cost</div><div class="metric-value"><?php echo money_aed_sr($breakdown['laptops']['left_cost']); ?></div><div class="small-muted">Qty: <?php echo number_format($breakdown['laptops']['qty_left']); ?></div></div></div>
        <div class="col-md-3"><div class="card metric-card p-3"><div class="metric-label">Tools Left Cost</div><div class="metric-value"><?php echo money_aed_sr($breakdown['tools']['left_cost']); ?></div><div class="small-muted">Qty: <?php echo number_format($breakdown['tools']['qty_left']); ?></div></div></div>
    </div>

    <div class="row g-3 mb-4 no-print">
        <div class="col-lg-4">
            <div class="card metric-card p-3 h-100">
                <h5 class="fw-bold mb-3">Add / Update Supplier</h5>
                <form method="POST" class="row g-2">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Supplier Name</label>
                        <input type="text" name="supplier_name" class="form-control" placeholder="Example: Ahmed Supplier" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Phone</label>
                        <input type="text" name="supplier_phone" class="form-control" placeholder="Optional">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="supplier_notes" class="form-control" rows="2" placeholder="Optional"></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="add_supplier" class="btn btn-primary w-100 fw-bold">Save Supplier</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card metric-card p-3 h-100">
                <h5 class="fw-bold mb-3">Filter Report</h5>
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Supplier</label>
                        <select name="supplier_id" class="form-select">
                            <option value="all" <?php echo $supplierFilter === 'all' ? 'selected' : ''; ?>>All suppliers</option>
                            <option value="unassigned" <?php echo $supplierFilter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>" <?php echo ((string)$supplierFilter === (string)$s['id']) ? 'selected' : ''; ?>><?php echo h($s['supplier_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Source</label>
                        <select name="source" class="form-select">
                            <option value="all" <?php echo $sourceFilter === 'all' ? 'selected' : ''; ?>>All</option>
                            <?php foreach (['products','phones','laptops','tools'] as $src): ?>
                                <option value="<?php echo h($src); ?>" <?php echo $sourceFilter === $src ? 'selected' : ''; ?>><?php echo h(source_label_sr($src)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="available" <?php echo $statusFilter === 'available' ? 'selected' : ''; ?>>Available only</option>
                            <option value="sold" <?php echo $statusFilter === 'sold' ? 'selected' : ''; ?>>Sold / Out only</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Search</label>
                        <input type="text" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="barcode, model, supplier...">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-dark fw-bold">Apply</button>
                    </div>
                </form>
                <div class="mt-3 small-muted">Tip: filter to <strong>Unassigned</strong>, select items, then assign them to a supplier. You can click one item, then <strong>Shift + click</strong> another item to select everything between them.</div>
            </div>
        </div>
    </div>

    <form method="POST">
        <div class="card metric-card p-3 mb-3 sticky-actions no-print">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Assign selected items to supplier</label>
                    <select name="bulk_supplier_id" class="form-select">
                        <option value="">Choose supplier...</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['supplier_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-grid"><button type="submit" name="assign_supplier" class="btn btn-primary fw-bold">Assign Selected</button></div>
                <div class="col-md-2 d-grid"><button type="submit" name="clear_supplier" class="btn btn-outline-danger fw-bold" onclick="return confirm('Clear supplier from selected items?')">Clear Supplier</button></div>
                <div class="col-md-2 d-grid"><button type="button" class="btn btn-outline-secondary fw-bold" onclick="selectVisibleItems(true)">Select Visible</button></div>
                <div class="col-md-2 d-grid"><button type="button" class="btn btn-outline-secondary fw-bold" onclick="selectVisibleItems(false)">Unselect All</button></div>
                <div class="col-12"><span id="selected-count-box" class="badge bg-info text-dark">0 selected</span> <span class="small-muted">Click one checkbox, then Shift + click another checkbox to select the full range.</span></div>
            </div>
        </div>

        <div class="card metric-card overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="no-print"><input type="checkbox" onclick="selectVisibleItems(this.checked)"></th>
                            <th>Source</th>
                            <th>Supplier</th>
                            <th>Barcode</th>
                            <th class="item-name">Item</th>
                            <th>Type / Quality</th>
                            <th>Status</th>
                            <th class="text-end">Qty Left</th>
                            <th class="text-end">Unit Cost</th>
                            <th class="text-end">Left Cost</th>
                            <th class="text-end">Original Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$filteredItems): ?>
                        <tr><td colspan="11" class="text-center text-muted py-5">No items found for this filter.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($filteredItems as $item): ?>
                        <tr>
                            <td class="no-print"><input type="checkbox" class="item-check" name="selected_items[]" value="<?php echo h($item['key']); ?>"></td>
                            <td><span class="badge bg-dark"><?php echo h($item['source_label']); ?></span></td>
                            <td>
                                <?php if ((int)$item['supplier_id'] > 0): ?>
                                    <span class="fw-bold text-primary"><?php echo h($item['supplier_name']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold"><?php echo h($item['barcode']); ?></td>
                            <td>
                                <div class="fw-bold"><?php echo h($item['name']); ?></div>
                                <div class="small-muted">ID: <?php echo (int)$item['id']; ?><?php echo $item['model'] ? ' • ' . h($item['model']) : ''; ?></div>
                            </td>
                            <td><?php echo h(trim($item['type'] . ($item['quality'] ? ' / ' . $item['quality'] : ''))); ?></td>
                            <td><?php echo status_badge_sr($item['status'], $item['quantity']); ?></td>
                            <td class="text-end fw-bold"><?php echo number_format((int)$item['quantity']); ?></td>
                            <td class="text-end"><?php echo money_aed_sr($item['unit_cost']); ?></td>
                            <td class="text-end fw-bold text-success"><?php echo money_aed_sr($item['left_cost']); ?></td>
                            <td class="text-end fw-bold text-danger"><?php echo money_aed_sr($item['original_cost']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-dark">
                            <th class="no-print"></th>
                            <th colspan="6">TOTAL</th>
                            <th class="text-end"><?php echo number_format($summary['qty_left']); ?></th>
                            <th></th>
                            <th class="text-end"><?php echo money_aed_sr($summary['left_cost']); ?></th>
                            <th class="text-end"><?php echo money_aed_sr($summary['original_cost']); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let lastCheckedItem = null;

function getVisibleItemChecks() {
    return Array.from(document.querySelectorAll('.item-check')).filter(function(cb) {
        return cb.offsetParent !== null;
    });
}

function updateSelectedCount() {
    const selectedCount = document.querySelectorAll('.item-check:checked').length;
    const box = document.getElementById('selected-count-box');
    if (box) {
        box.textContent = selectedCount + ' selected';
    }
}

function selectVisibleItems(checked) {
    getVisibleItemChecks().forEach(function(cb) { cb.checked = checked; });
    lastCheckedItem = null;
    updateSelectedCount();
}

document.addEventListener('DOMContentLoaded', function() {
    const checks = getVisibleItemChecks();
    checks.forEach(function(cb) {
        cb.addEventListener('click', function(e) {
            const visibleChecks = getVisibleItemChecks();

            if (e.shiftKey && lastCheckedItem) {
                const start = visibleChecks.indexOf(lastCheckedItem);
                const end = visibleChecks.indexOf(cb);

                if (start !== -1 && end !== -1) {
                    const from = Math.min(start, end);
                    const to = Math.max(start, end);
                    const shouldCheck = cb.checked;
                    for (let i = from; i <= to; i++) {
                        visibleChecks[i].checked = shouldCheck;
                    }
                }
            }

            lastCheckedItem = cb;
            updateSelectedCount();
        });
    });

    updateSelectedCount();
});
</script>
</body>
</html>
