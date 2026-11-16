<?php
require_once __DIR__ . '/session_keepalive.php';
// view_products.php
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$message = "";
$edit_product = null;
$view_product = null;
$view_summary = null;
$view_sales_rows = [];

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money_whole($value) {
    return number_format((float)$value, 0);
}

function decode_cost_aed($costCode) {
    $digits = preg_replace('/\D+/', '', (string)$costCode);
    if ($digits === '') return 0;

    // Your cost code format: 962909700011 = 11 AED, 962909700211 = 211 AED.
    // The real cost is stored in the last 3 digits.
    if (strlen($digits) >= 3) {
        return (int)substr($digits, -3);
    }
    return (int)$digits;
}

function valid_cost_code($costCode) {
    $digits = preg_replace('/\D+/', '', (string)$costCode);
    // Expected full code: 9629097 + 5 digits, e.g. 962909700011.
    return preg_match('/^9629097\d{5}$/', $digits) === 1;
}

function product_cost_code_from_aed($value) {
    $digits = preg_replace('/\D+/', '', (string)$value);
    if ($digits === '') return '';

    $aed = intval($digits);
    if ($aed < 0 || $aed > 999) return '';

    // Product cost code format used by this page: 9629097 + 5 digits.
    // Example: 11 AED => 962909700011, 211 AED => 962909700211.
    return '9629097' . str_pad((string)$aed, 5, '0', STR_PAD_LEFT);
}

function normalize_product_cost_code($value) {
    $digits = preg_replace('/\D+/', '', (string)$value);
    if ($digits === '') return '';

    // Keep full cost code if user enters/scans it.
    if (preg_match('/^9629097\d{5}$/', $digits)) {
        return $digits;
    }

    // Also allow simple AED cost in the edit form.
    return product_cost_code_from_aed($digits);
}

function column_exists($columns, $name) {
    return in_array($name, $columns, true);
}

function build_product_sales_match($product, $sales_columns) {
    $conditions = [];
    $params = [];
    $productId = intval($product['id'] ?? 0);
    $barcode = trim((string)($product['barcode'] ?? ''));
    $costAed = decode_cost_aed($product['cost_price'] ?? 0);

    if ($productId > 0 && column_exists($sales_columns, 'product_id')) {
        $conditions[] = "sr.product_id = ?";
        $params[] = $productId;
    }

    if ($barcode !== '' && column_exists($sales_columns, 'barcode')) {
        // Imported old daily records may not have product_id, so match by barcode + cost when possible.
        if (column_exists($sales_columns, 'imported_cost')) {
            $conditions[] = "(sr.barcode = ? AND ROUND(COALESCE(sr.imported_cost, 0), 0) = ?)";
            $params[] = $barcode;
            $params[] = $costAed;
        } elseif (column_exists($sales_columns, 'cost_price')) {
            $conditions[] = "(sr.barcode = ? AND sr.cost_price = ?)";
            $params[] = $barcode;
            $params[] = $product['cost_price'] ?? '';
        } elseif (!column_exists($sales_columns, 'product_id')) {
            $conditions[] = "sr.barcode = ?";
            $params[] = $barcode;
        }
    }

    if (empty($conditions)) {
        return ['sql' => '1 = 0', 'params' => []];
    }

    return ['sql' => '(' . implode(' OR ', $conditions) . ')', 'params' => $params];
}

function product_sold_summary($pdo, $product, $sales_columns) {
    if (empty($sales_columns)) {
        return [
            'sold_qty' => 0,
            'sales_count' => 0,
            'revenue' => 0,
            'estimated_profit' => 0,
            'avg_sale' => 0,
            'last_sold' => null
        ];
    }

    $match = build_product_sales_match($product, $sales_columns);
    $qtyExpr = column_exists($sales_columns, 'qty_sold') ? 'COALESCE(sr.qty_sold, 1)' : '1';
    $totalExpr = column_exists($sales_columns, 'total_amount') ? 'COALESCE(sr.total_amount, 0)' : (column_exists($sales_columns, 'sale_price') ? '(COALESCE(sr.sale_price, 0) * ' . $qtyExpr . ')' : '0');
    $dateExpr = column_exists($sales_columns, 'sold_at') ? 'MAX(sr.sold_at)' : (column_exists($sales_columns, 'created_at') ? 'MAX(sr.created_at)' : 'NULL');

    $sql = "SELECT COALESCE(SUM($qtyExpr), 0) AS sold_qty, COUNT(*) AS sales_count, COALESCE(SUM($totalExpr), 0) AS revenue, $dateExpr AS last_sold FROM sales_records sr WHERE " . $match['sql'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($match['params']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $soldQty = (float)($row['sold_qty'] ?? 0);
    $revenue = (float)($row['revenue'] ?? 0);
    $costAed = decode_cost_aed($product['cost_price'] ?? 0);
    $estimatedProfit = $revenue - ($soldQty * $costAed);

    return [
        'sold_qty' => $soldQty,
        'sales_count' => intval($row['sales_count'] ?? 0),
        'revenue' => $revenue,
        'estimated_profit' => $estimatedProfit,
        'avg_sale' => $soldQty > 0 ? ($revenue / $soldQty) : 0,
        'last_sold' => $row['last_sold'] ?? null
    ];
}

function product_recent_sales($pdo, $product, $sales_columns, $limit = 25) {
    if (empty($sales_columns)) return [];
    $match = build_product_sales_match($product, $sales_columns);
    $orderCol = column_exists($sales_columns, 'sold_at') ? 'sr.sold_at' : (column_exists($sales_columns, 'created_at') ? 'sr.created_at' : 'sr.id');
    $sql = "SELECT sr.* FROM sales_records sr WHERE " . $match['sql'] . " ORDER BY $orderCol DESC, sr.id DESC LIMIT " . intval($limit);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($match['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Discover product columns so this page does not break if your products table has extra/missing optional fields.
$product_columns = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM products");
    $product_columns = array_column($colStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
} catch (Exception $e) {
    $message = "<div class='alert alert-danger'>Database Error reading products table: " . h($e->getMessage()) . "</div>";
}

$has_sale_price = column_exists($product_columns, 'sale_price');
$has_item_name  = column_exists($product_columns, 'item_name');

// Discover sales_records columns for the per-product details/sold count.
$sales_columns = [];
try {
    $salesColStmt = $pdo->query("SHOW COLUMNS FROM sales_records");
    $sales_columns = array_column($salesColStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
} catch (Exception $e) {
    // Keep the page working even before any sales table exists.
    $sales_columns = [];
}

// Fetch categories for filters and edit form.
$categories = [];
try {
    $catStmt = $pdo->query("SELECT id, category_name FROM categories ORDER BY category_name ASC");
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
}

// Delete product.
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);

    try {
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            $message = "<div class='alert alert-success'>Product deleted successfully.</div>";
        } else {
            $message = "<div class='alert alert-warning'>Product not found or already deleted.</div>";
        }
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Cannot delete this product. It may already be linked to sales records. Error: " . h($e->getMessage()) . "</div>";
    }
}

// Load product for viewing details.
if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
    $id = intval($_GET['id']);

    try {
        $stmt = $pdo->prepare("SELECT p.*, c.category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
        $stmt->execute([$id]);
        $view_product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($view_product) {
            $view_summary = product_sold_summary($pdo, $view_product, $sales_columns);
            $view_sales_rows = product_recent_sales($pdo, $view_product, $sales_columns, 25);
        } else {
            $message = "<div class='alert alert-warning'>Product not found.</div>";
        }
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Database Error: " . h($e->getMessage()) . "</div>";
    }
}

// Load product for editing.
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);

    try {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $edit_product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$edit_product) {
            $message = "<div class='alert alert-warning'>Product not found.</div>";
        }
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Database Error: " . h($e->getMessage()) . "</div>";
    }
}

// Update product.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $id           = intval($_POST['product_id'] ?? 0);
    $barcode      = trim($_POST['barcode'] ?? '');
    $product_name = trim($_POST['product_name'] ?? '');
    $model_name   = trim($_POST['model_name'] ?? '');
    $category_id  = intval($_POST['category_id'] ?? 0);
    $cost_aed_input = trim($_POST['cost_aed'] ?? '');
    $cost_price_input = trim($_POST['cost_price'] ?? '');
    $cost_price   = ($cost_aed_input !== '') ? product_cost_code_from_aed($cost_aed_input) : normalize_product_cost_code($cost_price_input);
    $quantity     = intval($_POST['quantity'] ?? 0);
    $sale_price   = intval($_POST['sale_price'] ?? 0);
    $item_name    = trim($_POST['item_name'] ?? '');

    if ($id <= 0 || $barcode === '' || $product_name === '' || $category_id <= 0 || $quantity < 0) {
        $message = "<div class='alert alert-danger'>Please fill all required fields correctly.</div>";
    } elseif (!valid_cost_code($cost_price)) {
        $message = "<div class='alert alert-danger'>Please enter a valid Cost AED amount from 0 to 999, or a full cost code like <strong>962909700011</strong>.</div>";
    } else {
        try {
            // Make sure category exists.
            $catCheck = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
            $catCheck->execute([$category_id]);
            if (!$catCheck->fetchColumn()) {
                throw new Exception("Category ID does not exist.");
            }

            // Avoid accidental duplicate barcode on another product.
            $barcodeCheck = $pdo->prepare("SELECT id FROM products WHERE barcode = ? AND id <> ? LIMIT 1");
            $barcodeCheck->execute([$barcode, $id]);
            // if ($barcodeCheck->fetchColumn()) {
            //     throw new Exception("This barcode already exists on another product.");
            // }

            $fields = [
                'barcode = ?',
                'product_name = ?',
                'model_name = ?',
                'category_id = ?',
                'cost_price = ?',
                'quantity = ?'
            ];
            $params = [$barcode, $product_name, $model_name, $category_id, $cost_price, $quantity];

            if ($has_sale_price) {
                $fields[] = 'sale_price = ?';
                $params[] = $sale_price;
            }

            if ($has_item_name) {
                $fields[] = 'item_name = ?';
                $params[] = $item_name;
            }

            $params[] = $id;
            $sql = "UPDATE products SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            header("Location: view_products.php?updated=1");
            exit;
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Update failed: " . h($e->getMessage()) . "</div>";

            // Keep form populated after a failed update.
            $edit_product = [
                'id' => $id,
                'barcode' => $barcode,
                'product_name' => $product_name,
                'model_name' => $model_name,
                'category_id' => $category_id,
                'cost_price' => $cost_price,
                'quantity' => $quantity,
                'sale_price' => $sale_price,
                'item_name' => $item_name
            ];
        }
    }
}

if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $message = "<div class='alert alert-success'>Product updated successfully.</div>";
}

// Search and filter.
$search = trim($_GET['search'] ?? '');
$filter_category = intval($_GET['category_id'] ?? 0);
$stock_filter = $_GET['stock_filter'] ?? 'all';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(p.barcode LIKE ? OR p.product_name LIKE ? OR p.model_name LIKE ?" . ($has_item_name ? " OR p.item_name LIKE ?" : "") . ")";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    if ($has_item_name) $params[] = $like;
}

if ($filter_category > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $filter_category;
}

if ($stock_filter === 'in_stock') {
    $where[] = "p.quantity > 0";
} elseif ($stock_filter === 'low_stock') {
    $where[] = "p.quantity > 0 AND p.quantity <= 2";
} elseif ($stock_filter === 'out_stock') {
    $where[] = "p.quantity <= 0";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$products = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, c.category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        {$whereSql}
        ORDER BY p.id DESC
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = "<div class='alert alert-danger'>Database Error: " . h($e->getMessage()) . "</div>";
}

$total_products = count($products);
$total_units = 0;
$total_sold_units = 0;
$total_cost_value = 0;
$total_sale_value = 0;
$out_of_stock_count = 0;
$low_stock_count = 0;

foreach ($products as $idx => $p) {
    $qty = intval($p['quantity'] ?? 0);
    $costAed = decode_cost_aed($p['cost_price'] ?? 0);
    $saleAed = $has_sale_price ? intval($p['sale_price'] ?? 0) : 0;
    $soldSummary = product_sold_summary($pdo, $p, $sales_columns);
    $soldQty = (float)($soldSummary['sold_qty'] ?? 0);
    $products[$idx]['_sold_summary'] = $soldSummary;
    $products[$idx]['_sold_qty'] = $soldQty;

    $total_units += $qty;
    $total_sold_units += $soldQty;
    $total_cost_value += ($qty * $costAed);
    $total_sale_value += ($qty * $saleAed);

    if ($qty <= 0) $out_of_stock_count++;
    if ($qty > 0 && $qty <= 2) $low_stock_count++;
}

$table_colspan = 12 + ($has_item_name ? 1 : 0) + ($has_sale_price ? 2 : 0);
$footer_label_colspan = 8 + ($has_item_name ? 1 : 0) + ($has_sale_price ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products Inventory - Rukn Al Lotus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: system-ui, sans-serif; }
        .page-card { border: none; border-radius: 14px; box-shadow: 0 4px 14px rgba(0,0,0,0.06); }
        .table th { white-space: nowrap; }
        .table td { vertical-align: middle; }
        .cost-code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .sticky-actions { white-space: nowrap; }
        .stat-card { border-radius: 14px; border: 1px solid #e9ecef; background: #fff; padding: 14px; }
        .detail-label { font-size: 12px; font-weight: 800; color: #6c757d; text-transform: uppercase; }
        .detail-value { font-weight: 800; color: #212529; }
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

/* ===== Products UI color enhancement ===== */
.product-kpi{
    position:relative;
    overflow:hidden;
    border:0!important;
    min-height:118px;
    color:#fff;
    box-shadow:0 12px 28px rgba(31,41,55,.10)!important;
    transition:transform .18s ease,box-shadow .18s ease;
}
.product-kpi:hover{transform:translateY(-3px);box-shadow:0 16px 34px rgba(31,41,55,.16)!important}
.product-kpi:after{
    content:"";
    position:absolute;
    width:105px;height:105px;border-radius:50%;
    right:-25px;bottom:-42px;background:rgba(255,255,255,.14);
}
.product-kpi:before{
    content:"";
    position:absolute;
    width:58px;height:58px;border-radius:50%;
    right:30px;top:-22px;background:rgba(255,255,255,.09);
}
.product-kpi>*{position:relative;z-index:1}
.product-kpi .kpi-icon{
    width:40px;height:40px;border-radius:12px;
    background:rgba(255,255,255,.18);
    display:grid;place-items:center;font-size:1.12rem;
    margin-bottom:9px
}
.product-kpi .kpi-label{font-size:.76rem;font-weight:800;color:rgba(255,255,255,.82);text-transform:uppercase;letter-spacing:.045em}
.product-kpi .kpi-value{font-size:1.65rem;font-weight:900;color:#fff;line-height:1.1;margin-top:5px}
.product-kpi.blue{background:linear-gradient(135deg,#3154e8,#667eea)}
.product-kpi.green{background:linear-gradient(135deg,#10986a,#2bbf8a)}
.product-kpi.red{background:linear-gradient(135deg,#d94a62,#f06a7d)}
.product-kpi.orange{background:linear-gradient(135deg,#d98b16,#f6b73c)}

.product-detail-card{
    position:relative;
    overflow:hidden;
    border:1px solid #e6ebf2!important;
    transition:.18s ease;
    min-height:92px;
}
.product-detail-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(34,51,84,.09)}
.product-detail-card::before{
    content:"";
    position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent,#3759e8)
}
.product-detail-card.accent-blue{--accent:#3759e8;background:linear-gradient(135deg,#f7f9ff,#eef2ff)}
.product-detail-card.accent-green{--accent:#16a36d;background:linear-gradient(135deg,#f5fffa,#ecfdf5)}
.product-detail-card.accent-red{--accent:#e05268;background:linear-gradient(135deg,#fff8f8,#fff1f2)}
.product-detail-card.accent-orange{--accent:#e69a1f;background:linear-gradient(135deg,#fffdf6,#fff7e6)}
.product-detail-card.accent-purple{--accent:#7c5ce7;background:linear-gradient(135deg,#fbf9ff,#f3efff)}
.product-detail-card.accent-cyan{--accent:#1697b7;background:linear-gradient(135deg,#f6fdff,#eaf9fd)}
.product-detail-card.accent-slate{--accent:#64748b;background:linear-gradient(135deg,#fbfcfd,#f4f6f8)}
.product-detail-card .detail-label{color:#667085}
.product-detail-card .detail-value{font-size:.98rem}

.products-table-card{
    border-top:4px solid #3759e8!important;
}
.products-table-card .table thead th{
    background:linear-gradient(180deg,#f8faff,#f1f4fb)!important;
}

</style>
<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body>
<div class="rl-app"><?php include __DIR__ . '/sidebar.php'; ?><main class="rl-main"><div class="rl-topbar"><div class="d-flex gap-2 align-items-start"><button class="btn btn-light border rl-mobile-toggle" id="rlSidebarToggle" type="button"><i class="bi bi-list"></i></button><div class="rl-welcome"><h1>Products Inventory</h1><p>Manage product stock, pricing, details and sales history · <?=htmlspecialchars(date('l, d F Y'), ENT_QUOTES, 'UTF-8')?></p></div></div><div class="rl-user-chip"><div class="rl-avatar"><?=htmlspecialchars(strtoupper(substr((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'A'),0,1)), ENT_QUOTES, 'UTF-8')?></div><div class="rl-user-text"><div class="fw-bold small"><?=htmlspecialchars((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Admin'), ENT_QUOTES, 'UTF-8')?></div><div class="text-muted" style="font-size:.7rem"><?=htmlspecialchars(ucfirst((string)($_SESSION['role'] ?? 'admin')), ENT_QUOTES, 'UTF-8')?></div></div></div></div><div class="rl-page-content">
<div class="container-fluid py-4 px-4">
<?php echo $message; ?>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="page-card product-kpi blue p-3">
                <div class="kpi-icon"><i class="bi bi-box-seam"></i></div>
                <div class="kpi-label">Products Listed</div>
                <div class="kpi-value"><?php echo money_whole($total_products); ?></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="page-card product-kpi green p-3">
                <div class="kpi-icon"><i class="bi bi-boxes"></i></div>
                <div class="kpi-label">Total Inventory Left</div>
                <div class="kpi-value"><?php echo money_whole($total_units); ?> pcs</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="page-card product-kpi red p-3">
                <div class="kpi-icon"><i class="bi bi-cash-stack"></i></div>
                <div class="kpi-label">Inventory Cost Value</div>
                <div class="kpi-value"><?php echo money_whole($total_cost_value); ?> AED</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="page-card product-kpi orange p-3">
                <div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div>
                <div class="kpi-label">Stock Alerts</div>
                <div class="fw-bold mt-1"><?php echo money_whole($low_stock_count); ?> Low Stock</div>
                <div class="fw-bold"><?php echo money_whole($out_of_stock_count); ?> Out of Stock</div>
            </div>
        </div>
    </div>

    <?php if ($view_product): ?>
        <?php
            $viewQty = intval($view_product['quantity'] ?? 0);
            $viewCostAed = decode_cost_aed($view_product['cost_price'] ?? 0);
            $viewSold = (float)($view_summary['sold_qty'] ?? 0);
            $viewTotalUnits = $viewQty + $viewSold;
        ?>
        <div class="page-card bg-white p-4 mb-4 border-start border-info border-5" id="productDetails">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h4 class="fw-bold m-0">🔎 Product Details #<?php echo h($view_product['id']); ?></h4>
                    <div class="text-muted">Sold count is matched from sales records by product ID, or barcode + cost for imported daily records.</div>
                </div>
                <a href="view_products.php" class="btn btn-outline-secondary btn-sm fw-bold">Close Details</a>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="stat-card product-detail-card accent-blue"><div class="detail-label">Model Name</div><div class="detail-value"><?php echo h($view_product['model_name'] ?? ''); ?></div></div></div>
                <div class="col-md-3"><div class="stat-card product-detail-card accent-purple"><div class="detail-label">Name</div><div class="detail-value"><?php echo h($view_product['product_name'] ?? ''); ?></div></div></div>
                <div class="col-md-3"><div class="stat-card product-detail-card accent-cyan"><div class="detail-label">Barcode</div><div class="detail-value cost-code"><?php echo h($view_product['barcode'] ?? ''); ?></div></div></div>
                <div class="col-md-3"><div class="stat-card product-detail-card accent-orange"><div class="detail-label">Category</div><div class="detail-value"><?php echo h(($view_product['category_name'] ?? 'Uncategorized') . ' (#' . ($view_product['category_id'] ?? '') . ')'); ?></div></div></div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-2"><div class="stat-card product-detail-card accent-green"><div class="detail-label">Left</div><div class="fs-4 fw-bold text-success"><?php echo money_whole($viewQty); ?> pcs</div></div></div>
                <div class="col-md-2"><div class="stat-card product-detail-card accent-blue"><div class="detail-label">Sold</div><div class="fs-4 fw-bold text-primary"><?php echo money_whole($viewSold); ?> pcs</div></div></div>
                <div class="col-md-2"><div class="stat-card product-detail-card accent-slate"><div class="detail-label">Total Units</div><div class="fs-4 fw-bold text-dark"><?php echo money_whole($viewTotalUnits); ?> pcs</div></div></div>
                <div class="col-md-2"><div class="stat-card product-detail-card accent-red"><div class="detail-label">Cost / Item</div><div class="fs-4 fw-bold text-danger"><?php echo money_whole($viewCostAed); ?> AED</div></div></div>
                <div class="col-md-2"><div class="stat-card product-detail-card accent-green"><div class="detail-label">Sales Revenue</div><div class="fs-4 fw-bold text-success"><?php echo money_whole($view_summary['revenue'] ?? 0); ?> AED</div></div></div>
                <div class="col-md-2"><div class="stat-card product-detail-card accent-orange"><div class="detail-label">Est. Profit</div><div class="fs-4 fw-bold text-warning"><?php echo money_whole($view_summary['estimated_profit'] ?? 0); ?> AED</div></div></div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3"><div class="stat-card product-detail-card accent-red"><div class="detail-label">Cost Code</div><div class="detail-value cost-code"><?php echo h($view_product['cost_price'] ?? ''); ?></div></div></div>
                <?php if ($has_sale_price): ?><div class="col-md-3"><div class="stat-card product-detail-card accent-green"><div class="detail-label">Sale Price</div><div class="detail-value text-success"><?php echo money_whole($view_product['sale_price'] ?? 0); ?> AED</div></div></div><?php endif; ?>
                <div class="col-md-3"><div class="stat-card product-detail-card accent-cyan"><div class="detail-label">Average Sale</div><div class="detail-value text-success"><?php echo money_whole($view_summary['avg_sale'] ?? 0); ?> AED</div></div></div>
                <div class="col-md-3"><div class="stat-card product-detail-card accent-purple"><div class="detail-label">Last Sold</div><div class="detail-value"><?php echo h($view_summary['last_sold'] ?? 'Not sold yet'); ?></div></div></div>
                <?php if ($has_item_name): ?><div class="col-md-12"><div class="stat-card product-detail-card accent-slate"><div class="detail-label">Description</div><div class="detail-value"><?php echo h($view_product['item_name'] ?? ''); ?></div></div></div><?php endif; ?>
            </div>

            <h5 class="fw-bold mb-3">Recent Sales For This Product</h5>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Item</th>
                            <th>Barcode</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Sale Price</th>
                            <th class="text-end">Total</th>
                            <th>Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($view_sales_rows)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No sales found for this product yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($view_sales_rows as $sr): ?>
                                <tr>
                                    <td><?php echo h($sr['sold_at'] ?? ($sr['created_at'] ?? '')); ?></td>
                                    <td><?php echo h($sr['item_name'] ?? ($view_product['product_name'] ?? '')); ?></td>
                                    <td class="cost-code"><?php echo h($sr['barcode'] ?? ''); ?></td>
                                    <td class="text-center fw-bold"><?php echo money_whole($sr['qty_sold'] ?? 1); ?></td>
                                    <td class="text-end"><?php echo money_whole($sr['sale_price'] ?? 0); ?> AED</td>
                                    <td class="text-end fw-bold"><?php echo money_whole($sr['total_amount'] ?? 0); ?> AED</td>
                                    <td><?php echo h($sr['payment_method'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($edit_product): ?>
        <div class="page-card bg-white p-4 mb-4 border-start border-warning border-5" id="editForm">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="fw-bold m-0">✏️ Edit Product #<?php echo h($edit_product['id']); ?></h4>
                <a href="view_products.php" class="btn btn-outline-secondary btn-sm fw-bold">Cancel Edit</a>
            </div>

            <form method="POST" action="view_products.php" class="row g-3">
                <input type="hidden" name="product_id" value="<?php echo h($edit_product['id']); ?>">

                <div class="col-md-3">
                    <label class="form-label fw-bold">Barcode *</label>
                    <input type="text" class="form-control" name="barcode" value="<?php echo h($edit_product['barcode'] ?? ''); ?>" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold">Model Name</label>
                    <input type="text" class="form-control" name="model_name" value="<?php echo h($edit_product['model_name'] ?? ''); ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold">Product Name *</label>
                    <input type="text" class="form-control" name="product_name" value="<?php echo h($edit_product['product_name'] ?? ''); ?>" required>
                </div>

                <?php if ($has_item_name): ?>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Item Description</label>
                        <input type="text" class="form-control" name="item_name" value="<?php echo h($edit_product['item_name'] ?? ''); ?>">
                    </div>
                <?php endif; ?>

                <div class="col-md-3">
                    <label class="form-label fw-bold">Category *</label>
                    <select class="form-select" name="category_id" required>
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo h($cat['id']); ?>" <?php echo (intval($edit_product['category_id'] ?? 0) === intval($cat['id'])) ? 'selected' : ''; ?>>
                                #<?php echo h($cat['id']); ?> - <?php echo h($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-bold text-danger">Cost AED *</label>
                    <input type="number" step="1" min="0" max="999" class="form-control fw-bold text-danger" name="cost_aed" id="edit_cost_aed" value="<?php echo h(money_whole(decode_cost_aed($edit_product['cost_price'] ?? 0))); ?>" required>
                    <div class="form-text">Edit the real product cost here.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold">Cost Code</label>
                    <input type="text" class="form-control bg-light cost-code" name="cost_price" id="edit_cost_code" value="<?php echo h($edit_product['cost_price'] ?? ''); ?>" readonly>
                    <div class="form-text">Auto-created from Cost AED. Example: 11 AED = 962909700011</div>
                </div>

                <?php if ($has_sale_price): ?>
                    <div class="col-md-2">
                        <label class="form-label fw-bold text-success">Sale Price AED</label>
                        <input type="number" step="1" min="0" class="form-control fw-bold text-success" name="sale_price" value="<?php echo h(intval($edit_product['sale_price'] ?? 0)); ?>">
                    </div>
                <?php endif; ?>

                <div class="col-md-2">
                    <label class="form-label fw-bold">Quantity Left *</label>
                    <input type="number" step="1" min="0" class="form-control fw-bold" name="quantity" value="<?php echo h(intval($edit_product['quantity'] ?? 0)); ?>" required>
                </div>

                <div class="col-md-12 d-flex gap-2">
                    <button type="submit" name="update_product" class="btn btn-success fw-bold px-4">💾 Save Changes</button>
                    <a href="view_products.php" class="btn btn-outline-secondary fw-bold px-4">Cancel</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="page-card bg-white p-4 mb-4 products-table-card">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-bold">Search</label>
                <input type="text" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="Barcode, product name, model...">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Category</label>
                <select name="category_id" class="form-select">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo h($cat['id']); ?>" <?php echo ($filter_category === intval($cat['id'])) ? 'selected' : ''; ?>>
                            #<?php echo h($cat['id']); ?> - <?php echo h($cat['category_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Stock</label>
                <select name="stock_filter" class="form-select">
                    <option value="all" <?php echo $stock_filter === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="in_stock" <?php echo $stock_filter === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                    <option value="low_stock" <?php echo $stock_filter === 'low_stock' ? 'selected' : ''; ?>>Low Stock (1-2)</option>
                    <option value="out_stock" <?php echo $stock_filter === 'out_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                </select>
            </div>
            <div class="col-md-3 d-grid gap-2 d-md-flex">
                <button type="submit" class="btn btn-primary fw-bold flex-fill">Search</button>
                <a href="view_products.php" class="btn btn-outline-secondary fw-bold flex-fill">Reset</a>
            </div>
        </form>
    </div>

    <div class="page-card bg-white p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="fw-bold m-0">All Products</h4>
            <span class="badge bg-dark fs-6">Total Left: <?php echo money_whole($total_units); ?> pcs</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted">
                    <tr>
                        <th>ID</th>
                        <th>Model Name</th>
                        <th>Name</th>
                        <?php if ($has_item_name): ?><th>Description</th><?php endif; ?>
                        <th>Barcode</th>
                        <th>Category ID</th>
                        <th>Category</th>
                        <th class="text-end">Cost Code</th>
                        <th class="text-end">Cost / Item</th>
                        <?php if ($has_sale_price): ?><th class="text-end">Sale Price</th><?php endif; ?>
                        <th class="text-center">Sold</th>
                        <th class="text-center">Inventory Left</th>
                        <th class="text-end">Cost Value</th>
                        <?php if ($has_sale_price): ?><th class="text-end">Sale Value</th><?php endif; ?>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="<?php echo $table_colspan; ?>" class="text-center text-muted py-4">No products found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $p): ?>
                            <?php
                                $qty = intval($p['quantity'] ?? 0);
                                $costAed = decode_cost_aed($p['cost_price'] ?? 0);
                                $saleAed = $has_sale_price ? intval($p['sale_price'] ?? 0) : 0;
                                $soldQty = (float)($p['_sold_qty'] ?? 0);
                                $rowClass = $qty <= 0 ? 'table-danger' : ($qty <= 2 ? 'table-warning' : '');
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td class="fw-bold">#<?php echo h($p['id'] ?? ''); ?></td>
                                <td><?php echo h($p['model_name'] ?? ''); ?></td>
                                <td class="fw-semibold"><?php echo h($p['product_name'] ?? ''); ?></td>
                                <?php if ($has_item_name): ?><td><?php echo h($p['item_name'] ?? ''); ?></td><?php endif; ?>
                                <td class="cost-code"><?php echo h($p['barcode'] ?? ''); ?></td>
                                <td class="text-center">#<?php echo h($p['category_id'] ?? ''); ?></td>
                                <td><?php echo h($p['category_name'] ?? 'Uncategorized'); ?></td>
                                <td class="text-end cost-code"><?php echo h($p['cost_price'] ?? ''); ?></td>
                                <td class="text-end fw-bold text-danger"><?php echo money_whole($costAed); ?> AED</td>
                                <?php if ($has_sale_price): ?><td class="text-end fw-bold text-success"><?php echo money_whole($saleAed); ?> AED</td><?php endif; ?>
                                <td class="text-center fw-bold text-primary"><?php echo money_whole($soldQty); ?> pcs</td>
                                <td class="text-center fw-bold <?php echo $qty <= 0 ? 'text-danger' : ($qty <= 2 ? 'text-warning' : 'text-success'); ?>">
                                    <?php echo money_whole($qty); ?> pcs
                                </td>
                                <td class="text-end fw-bold"><?php echo money_whole($qty * $costAed); ?> AED</td>
                                <?php if ($has_sale_price): ?><td class="text-end fw-bold"><?php echo money_whole($qty * $saleAed); ?> AED</td><?php endif; ?>
                                <td class="text-center sticky-actions">
                                    <a href="view_products.php?action=view&id=<?php echo h($p['id']); ?>#productDetails" class="btn btn-sm btn-outline-info fw-bold me-1">🔎 View</a>
                                    <a href="view_products.php?action=edit&id=<?php echo h($p['id']); ?>#editForm" class="btn btn-sm btn-outline-primary fw-bold me-1">✏️ Edit</a>
                                    <a href="view_products.php?action=delete&id=<?php echo h($p['id']); ?>" class="btn btn-sm btn-outline-danger fw-bold" onclick="return confirm('Delete this product permanently? This cannot be undone.');">🗑️ Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($products)): ?>
                    <tfoot class="table-light border-top border-2 border-dark">
                        <tr>
                            <td colspan="<?php echo $footer_label_colspan; ?>" class="text-end fw-bold">Totals:</td>
                            <td class="text-center fw-bold text-primary"><?php echo money_whole($total_sold_units); ?> pcs</td>
                            <td class="text-center fw-bold text-success"><?php echo money_whole($total_units); ?> pcs</td>
                            <td class="text-end fw-bold text-danger"><?php echo money_whole($total_cost_value); ?> AED</td>
                            <?php if ($has_sale_price): ?><td class="text-end fw-bold text-success"><?php echo money_whole($total_sale_value); ?> AED</td><?php endif; ?>
                            <td></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var costAedInput = document.getElementById('edit_cost_aed');
    var costCodeInput = document.getElementById('edit_cost_code');

    function makeProductCostCode(value) {
        var digits = String(value || '').replace(/\D+/g, '');
        if (digits === '') return '';
        var amount = parseInt(digits, 10);
        if (isNaN(amount) || amount < 0 || amount > 999) return '';
        return '9629097' + String(amount).padStart(5, '0');
    }

    if (costAedInput && costCodeInput) {
        costAedInput.addEventListener('input', function () {
            costCodeInput.value = makeProductCostCode(costAedInput.value);
        });
    }
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
