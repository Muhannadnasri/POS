<?php
require_once __DIR__ . '/session_keepalive.php';
// reports.php - Rukn Al Lotus business reports
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$card_fee_rate =  0.025; // VISA / CARD fee = 2.5%

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money2($amount) {
    return number_format((float)$amount, 2);
}

function money_clean($amount) {
    $amount = (float)$amount;
    return (fmod($amount, 1) == 0) ? number_format($amount, 0) : number_format($amount, 2);
}

function decode_cost_aed($cost_price) {
    $cost_price = trim((string)$cost_price);
    if ($cost_price === '') {
        return 0.0;
    }

    // Your cost code format: 962909700011 => 11 AED, 962909700211 => 211 AED
    if (substr($cost_price, 0, 7) === '9629097') {
        return (float)intval(substr($cost_price, -3));
    }

    return is_numeric($cost_price) ? (float)$cost_price : 0.0;
}

function is_card_payment($payment_method) {
    $method = strtoupper(trim((string)$payment_method));
    return in_array($method, ['VISA', 'CARD', 'CREDIT', 'DEBIT'], true);
}

function is_bank_transfer_payment($payment_method) {
    $method = strtoupper(trim((string)$payment_method));
    return in_array($method, ['BANK_TRANSFER', 'BANK TRANSFER', 'TRANSFER', 'BANK'], true);
}

function is_tamara_tabby_payment($payment_method) {
    $method = strtoupper(trim((string)$payment_method));
    return in_array($method, ['TAMARA / TABBY', 'TAMARA/TABBY', 'TAMARA', 'TABBY'], true);
}

function db_column_exists($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function safe_date($value, $fallback) {
    if (!$value) return $fallback;
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : $fallback;
}

$today = date('Y-m-d');
$month_start = date('Y-m-01');
$start_date = safe_date($_GET['start_date'] ?? '', $month_start);
$end_date = safe_date($_GET['end_date'] ?? '', $today);

if ($start_date > $end_date) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

$low_stock_limit = isset($_GET['low_stock_limit']) ? max(0, intval($_GET['low_stock_limit'])) : 2;
$start_dt = $start_date . ' 00:00:00';
$end_dt = date('Y-m-d H:i:s', strtotime($end_date . ' +1 day'));

$messages = [];
$has_imported_cost = db_column_exists($pdo, 'sales_records', 'imported_cost');
$imported_cost_select = $has_imported_cost ? 'sr.imported_cost AS imported_cost' : 'NULL AS imported_cost';

// -----------------------------
// 1. Date range sales rows
// -----------------------------
$sales = [];
try {
    $salesStmt = $pdo->prepare(" 
        SELECT
            sr.id,
            sr.product_id,
            sr.barcode,
            sr.item_name,
            sr.qty_sold,
            sr.sale_price,
            sr.total_amount,
            sr.payment_method,
            sr.sold_at,
            $imported_cost_select,
            COALESCE(p.cost_price, bp.barcode_cost_price) AS product_cost_price,
            COALESCE(p.product_name, bp.barcode_product_name) AS product_name,
            COALESCE(p.model_name, bp.barcode_model_name) AS model_name,
            COALESCE(c.category_name, bc.category_name, 'Uncategorized') AS category_name
        FROM sales_records sr
        LEFT JOIN products p ON sr.product_id = p.id
        LEFT JOIN (
            SELECT
                barcode,
                MIN(cost_price) AS barcode_cost_price,
                MIN(product_name) AS barcode_product_name,
                MIN(model_name) AS barcode_model_name,
                MIN(category_id) AS barcode_category_id
            FROM products
            GROUP BY barcode
        ) bp ON sr.barcode = bp.barcode
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN categories bc ON bp.barcode_category_id = bc.id
        WHERE sr.sold_at >= ? AND sr.sold_at < ?
        ORDER BY sr.sold_at ASC, sr.id ASC
    ");
    $salesStmt->execute([$start_dt, $end_dt]);
    $sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $messages[] = ['danger', 'Could not load sales report: ' . $e->getMessage()];
}

// -----------------------------
// 2. Drawer withdrawals / deposits
// drawer_withdrawals convention in your project:
// amount > 0 = withdraw, amount < 0 = deposit
// -----------------------------
$drawer_movements = [];
try {
    $drawerStmt = $pdo->prepare(" 
        SELECT id, name, amount, withdrawn_at
        FROM drawer_withdrawals
        WHERE withdrawn_at >= ? AND withdrawn_at < ?
        ORDER BY withdrawn_at ASC, id ASC
    ");
    $drawerStmt->execute([$start_dt, $end_dt]);
    $drawer_movements = $drawerStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $messages[] = ['warning', 'Could not load drawer movements: ' . $e->getMessage()];
}

// -----------------------------
// 3. Reconciliations
// -----------------------------
$reconciliations = [];
try {
    $reconStmt = $pdo->prepare(" 
        SELECT *
        FROM drawer_reconciliations
        WHERE reconciled_at >= ? AND reconciled_at < ?
        ORDER BY reconciled_at ASC, id ASC
    ");
    $reconStmt->execute([$start_dt, $end_dt]);
    foreach ($reconStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = date('Y-m-d', strtotime($r['reconciled_at']));
        $reconciliations[$d] = $r;
    }
} catch (Exception $e) {
    $messages[] = ['warning', 'Could not load drawer reconciliations: ' . $e->getMessage()];
}

// -----------------------------
// 4. Current inventory / stock report
// -----------------------------
$products = [];
try {
    $productsStmt = $pdo->query(" 
        SELECT p.*, COALESCE(c.category_name, 'Uncategorized') AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        ORDER BY p.quantity ASC, c.category_name ASC, p.product_name ASC, p.model_name ASC
    ");
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $messages[] = ['warning', 'Could not load inventory report: ' . $e->getMessage()];
}

// -----------------------------
// Calculations
// -----------------------------
$total_sales = 0.0;
$total_sales_count = 0;
$total_units_sold = 0;
$total_cost = 0.0;
$total_profit = 0.0;

$cash_total = 0.0;
$cash_count = 0;
$card_gross_total = 0.0;
$card_fee_total = 0.0;
$card_net_total = 0.0;
$card_count = 0;
$tamara_total = 0.0;
$tamara_count = 0;
$bank_transfer_total = 0.0;
$bank_transfer_count = 0;
$other_payment_total = 0.0;
$other_payment_count = 0;

$service_sales_total = 0.0;
$product_sales_total = 0.0;
$service_count = 0;
$product_count = 0;

$daily = [];
$top_items = [];
$category_sales = [];

foreach ($sales as $s) {
    $date_key = date('Y-m-d', strtotime($s['sold_at']));
    if (!isset($daily[$date_key])) {
        $daily[$date_key] = [
            'sales_count' => 0,
            'units' => 0,
            'sales_total' => 0.0,
            'cash' => 0.0,
            'card' => 0.0,
            'card_fee' => 0.0,
            'tamara' => 0.0,
            'bank' => 0.0,
            'cost' => 0.0,
            'profit' => 0.0,
            'withdraw' => 0.0,
            'deposit' => 0.0,
        ];
    }

    $qty = max(1, (int)($s['qty_sold'] ?? 1));
    $gross = (float)($s['total_amount'] ?? 0);
    $method = strtoupper(trim((string)($s['payment_method'] ?? '')));
    $is_card = is_card_payment($method);
    $is_tamara = is_tamara_tabby_payment($method);
    $is_bank_transfer = is_bank_transfer_payment($method);
    $card_fee = $is_card ? ($gross * $card_fee_rate) : 0.0;
    $net_revenue = $gross - $card_fee;

    $imported_cost = $s['imported_cost'];
    if ($imported_cost !== null && $imported_cost !== '' && is_numeric($imported_cost)) {
        $unit_cost = (float)$imported_cost;
    } else {
        $unit_cost = decode_cost_aed($s['product_cost_price'] ?? 0);
    }
    $cost_total = $unit_cost * $qty;
    $profit = $net_revenue - $cost_total;

    $total_sales += $gross;
    $total_sales_count++;
    $total_units_sold += $qty;
    $total_cost += $cost_total;
    $total_profit += $profit;

    if ($method === 'CASH') {
        $cash_total += $gross;
        $cash_count++;
    } elseif ($is_card) {
        $card_gross_total += $gross;
        $card_fee_total += $card_fee;
        $card_net_total += $net_revenue;
        $card_count++;
    } elseif ($is_tamara) {
        $tamara_total += $gross;
        $tamara_count++;
    } elseif ($is_bank_transfer) {
        $bank_transfer_total += $gross;
        $bank_transfer_count++;
    } else {
        $other_payment_total += $gross;
        $other_payment_count++;
    }

    $is_product_sale = !empty($s['product_id']);
    if ($is_product_sale) {
        $product_sales_total += $gross;
        $product_count++;
    } else {
        $service_sales_total += $gross;
        $service_count++;
    }

    $daily[$date_key]['sales_count']++;
    $daily[$date_key]['units'] += $qty;
    $daily[$date_key]['sales_total'] += $gross;
    $daily[$date_key]['cost'] += $cost_total;
    $daily[$date_key]['profit'] += $profit;
    if ($method === 'CASH') {
        $daily[$date_key]['cash'] += $gross;
    } elseif ($is_card) {
        $daily[$date_key]['card'] += $gross;
        $daily[$date_key]['card_fee'] += $card_fee;
    } elseif ($is_tamara) {
        $daily[$date_key]['tamara'] += $gross;
    } elseif ($is_bank_transfer) {
        $daily[$date_key]['bank'] += $gross;
    }

    $item_key = trim((string)$s['barcode']) . '|' . trim((string)$s['item_name']);
    if ($item_key === '|') {
        $item_key = 'SERVICE|' . $s['id'];
    }
    if (!isset($top_items[$item_key])) {
        $top_items[$item_key] = [
            'barcode' => $s['barcode'] ?: 'SERVICE',
            'name' => $s['item_name'] ?: ($s['product_name'] ?? 'Sale'),
            'category' => $s['category_name'] ?: 'Uncategorized',
            'qty' => 0,
            'sales' => 0.0,
            'profit' => 0.0,
        ];
    }
    $top_items[$item_key]['qty'] += $qty;
    $top_items[$item_key]['sales'] += $gross;
    $top_items[$item_key]['profit'] += $profit;

    $cat = $s['category_name'] ?: ($is_product_sale ? 'Uncategorized' : 'Services');
    if (!$is_product_sale) {
        $cat = 'Services / Custom';
    }
    if (!isset($category_sales[$cat])) {
        $category_sales[$cat] = ['qty' => 0, 'sales' => 0.0, 'profit' => 0.0];
    }
    $category_sales[$cat]['qty'] += $qty;
    $category_sales[$cat]['sales'] += $gross;
    $category_sales[$cat]['profit'] += $profit;
}

$withdraw_total = 0.0;
$withdraw_count = 0;
$deposit_total = 0.0;
$deposit_count = 0;
foreach ($drawer_movements as $m) {
    $date_key = date('Y-m-d', strtotime($m['withdrawn_at']));
    if (!isset($daily[$date_key])) {
        $daily[$date_key] = [
            'sales_count' => 0,
            'units' => 0,
            'sales_total' => 0.0,
            'cash' => 0.0,
            'card' => 0.0,
            'card_fee' => 0.0,
            'tamara' => 0.0,
            'bank' => 0.0,
            'cost' => 0.0,
            'profit' => 0.0,
            'withdraw' => 0.0,
            'deposit' => 0.0,
        ];
    }

    $amount = (float)$m['amount'];
    if ($amount > 0) {
        $withdraw_total += $amount;
        $withdraw_count++;
        $daily[$date_key]['withdraw'] += $amount;
    } elseif ($amount < 0) {
        $deposit_total += abs($amount);
        $deposit_count++;
        $daily[$date_key]['deposit'] += abs($amount);
    }
}

ksort($daily);

usort($top_items, function ($a, $b) {
    return $b['sales'] <=> $a['sales'];
});
$top_items = array_slice($top_items, 0, 10);

uasort($category_sales, function ($a, $b) {
    return $b['sales'] <=> $a['sales'];
});

// Inventory calculations
$total_skus = count($products);
$total_units_available = 0;
$total_inventory_cost_value = 0.0;
$total_inventory_retail_value = 0.0;
$low_stock_items = [];
$out_of_stock_items = [];
$category_inventory = [];

foreach ($products as $p) {
    $qty = (int)($p['quantity'] ?? 0);
    $unit_cost = decode_cost_aed($p['cost_price'] ?? 0);
    $sale_price = isset($p['sale_price']) && is_numeric($p['sale_price']) ? (float)$p['sale_price'] : 0.0;
    $cat = $p['category_name'] ?: 'Uncategorized';

    $total_units_available += $qty;
    $total_inventory_cost_value += ($qty * $unit_cost);
    $total_inventory_retail_value += ($qty * $sale_price);

    if (!isset($category_inventory[$cat])) {
        $category_inventory[$cat] = ['skus' => 0, 'units' => 0, 'cost_value' => 0.0, 'retail_value' => 0.0];
    }
    $category_inventory[$cat]['skus']++;
    $category_inventory[$cat]['units'] += $qty;
    $category_inventory[$cat]['cost_value'] += ($qty * $unit_cost);
    $category_inventory[$cat]['retail_value'] += ($qty * $sale_price);

    if ($qty <= 0) {
        $out_of_stock_items[] = $p;
    } elseif ($qty <= $low_stock_limit) {
        $low_stock_items[] = $p;
    }
}

uasort($category_inventory, function ($a, $b) {
    return $b['cost_value'] <=> $a['cost_value'];
});

$gross_margin_percent = ($total_sales > 0) ? (($total_profit / $total_sales) * 100) : 0.0;
$drawer_cash_net = $cash_total + $deposit_total - $withdraw_total;

$recommended_reports = [
    ['Daily Closing Report', 'Sales, cash drawer, card totals, deposits, withdrawals, and drawer difference for each day.'],
    ['Date Range Profit Report', 'Total sales, cost, card fees, and net profit between two dates.'],
    ['Low Stock / Reorder Report', 'Products with quantity at or below your chosen limit so you know what to buy.'],
    ['Top Selling Products Report', 'Best products by quantity sold, revenue, and profit.'],
    ['Dead Stock Report', 'Products with stock available but no sales during the selected period.'],
    ['Inventory Valuation Report', 'Total AED value of stock using cost price and estimated retail value.'],
    ['Payment Method Report', 'Cash vs card totals, card fees, and net card received.'],
    ['Service / Repair Report', 'Custom repair/software sales separated from barcode product sales.'],
    ['Drawer Difference Report', 'Compare expected drawer cash with actual reconciled drawer amount.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Rukn Al Lotus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
:root{
    --rl-sidebar:#101828;--rl-sidebar-soft:#1d2939;--rl-primary:#2563eb;
    --rl-bg:#f5f7fb;--rl-text:#101828;--rl-muted:#667085;--rl-border:#e4e7ec;
    --tamara:#f1506f;
}
*{box-sizing:border-box}
body{margin:0;background:var(--rl-bg);color:var(--rl-text);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.rl-app{min-height:100vh;display:flex}
.rl-sidebar{
    width:258px;min-width:258px;background:var(--rl-sidebar);color:#fff;
    position:fixed;inset:0 auto 0 0;overflow-y:auto;z-index:1040;padding:20px 14px
}
.rl-brand{display:flex;align-items:center;gap:12px;padding:4px 8px 20px;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:16px}
.rl-brand-mark{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#3b82f6,#7c3aed);display:grid;place-items:center;font-weight:800}
.rl-brand-title{font-weight:800;font-size:1rem}.rl-brand-sub{font-size:.72rem;color:#98a2b3}
.rl-nav-label{padding:13px 10px 6px;color:#667085;font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;font-weight:800}
.rl-side-link{display:flex;align-items:center;gap:11px;color:#d0d5dd;text-decoration:none;padding:10px 11px;border-radius:10px;margin:2px 0;font-size:.88rem;font-weight:600}
.rl-side-link:hover{background:var(--rl-sidebar-soft);color:#fff}
.rl-side-link.active{background:#2563eb;color:#fff;box-shadow:0 8px 20px rgba(37,99,235,.22)}
.rl-side-link i{font-size:1rem;width:20px;text-align:center}
.rl-role{margin-left:auto;font-size:.58rem;background:rgba(255,255,255,.12);padding:3px 6px;border-radius:6px}
.rl-main{margin-left:258px;width:calc(100% - 258px);min-height:100vh}
.rl-topbar{
    min-height:78px;background:#fff;border-bottom:1px solid var(--rl-border);
    padding:15px 28px;display:flex;align-items:center;justify-content:space-between;
    position:sticky;top:0;z-index:1030
}
.rl-welcome h1{font-size:1.12rem;font-weight:800;margin:0}
.rl-welcome p{margin:3px 0 0;color:var(--rl-muted);font-size:.78rem}
.rl-user-chip{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid var(--rl-border);border-radius:12px;padding:7px 10px}
.rl-avatar{width:34px;height:34px;border-radius:9px;background:#e8efff;color:#2457c5;display:grid;place-items:center;font-weight:800}
.rl-page-content{padding:0}.rl-mobile-toggle{display:none}.rl-overlay{display:none}
.report-card{border:1px solid var(--rl-border)!important;border-radius:16px!important;box-shadow:0 8px 24px rgba(16,24,40,.05)!important;background:#fff}
.metric-label{font-size:.78rem;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:.04em}
.metric-value{font-size:1.65rem;font-weight:800;color:#101828}
.section-title{font-weight:800;color:#101828}
.form-control,.form-select{border-color:#d0d5dd;border-radius:10px;min-height:42px}
.form-control:focus,.form-select:focus{border-color:#84adff;box-shadow:0 0 0 .2rem rgba(37,99,235,.12)}
.btn{border-radius:9px}
.table{margin-bottom:0}.table td,.table th{vertical-align:middle}
.table thead th{font-size:.75rem;text-transform:uppercase;letter-spacing:.03em;color:#667085;background:#f9fafb;border-bottom-color:#eaecf0}
.table tbody td{border-color:#f0f1f3}.table-hover tbody tr:hover{background:#f8fafc}
.rl-chart-card{min-height:360px}
.rl-chart-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:18px}
.rl-chart-title{font-size:1rem;font-weight:800;margin:0;color:#101828}
.rl-chart-sub{font-size:.76rem;color:#667085;margin-top:3px}
.rl-chart-icon{width:38px;height:38px;border-radius:10px;background:#eef4ff;color:#2563eb;display:grid;place-items:center;flex:0 0 auto}
.rl-chart-wrap{position:relative;height:285px}.rl-chart-wrap.tall{height:320px}
.rl-visual-title{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.rl-visual-title .icon{width:40px;height:40px;border-radius:11px;background:#eaf2ff;color:#2563eb;display:grid;place-items:center}
@media(max-width:991.98px){
    .rl-sidebar{transform:translateX(-100%);transition:.2s ease}.rl-sidebar.show{transform:translateX(0)}
    .rl-main{margin-left:0;width:100%}.rl-mobile-toggle{display:inline-flex}
    .rl-overlay{position:fixed;inset:0;background:rgba(16,24,40,.45);z-index:1035}.rl-overlay.show{display:block}
    .rl-topbar{padding:12px 16px}.rl-user-text{display:none}
    .container-fluid{padding-left:14px!important;padding-right:14px!important}
}
@media print{
    .no-print,.rl-sidebar,.rl-topbar,.rl-overlay{display:none!important}
    .rl-main{margin-left:0;width:100%}
    body{background:#fff}.report-card{box-shadow:none!important;border:1px solid #ddd!important}
}
</style>
<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body>
<div class="rl-overlay" id="rlOverlay"></div>
<div class="rl-app">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="rl-main">
    <div class="rl-topbar">
        <div class="d-flex gap-2 align-items-start">
            <button class="btn btn-light border rl-mobile-toggle" id="rlSidebarToggle" type="button"><i class="bi bi-list"></i></button>
            <div class="rl-welcome"><h1>Business Reports</h1><p>Sales, profit, stock and drawer analytics · <?=htmlspecialchars(date('l, d F Y'), ENT_QUOTES, 'UTF-8')?></p></div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button onclick="window.print()" class="btn btn-outline-primary no-print"><i class="bi bi-printer me-1"></i>Print</button>
            <div class="rl-user-chip">
                <div class="rl-avatar"><?=htmlspecialchars(strtoupper(substr((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'A'),0,1)), ENT_QUOTES, 'UTF-8')?></div>
                <div class="rl-user-text">
                    <div class="fw-bold small"><?=htmlspecialchars((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Admin'), ENT_QUOTES, 'UTF-8')?></div>
                    <div class="text-muted" style="font-size:.7rem"><?=htmlspecialchars(ucfirst((string)($_SESSION['role'] ?? 'admin')), ENT_QUOTES, 'UTF-8')?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="rl-page-content">
<div class="container-fluid px-4 py-4">
    <?php foreach ($messages as $msg): ?>
        <div class="alert alert-<?php echo h($msg[0]); ?>"><?php echo h($msg[1]); ?></div>
    <?php endforeach; ?>

    <div class="card report-card p-4 mb-4 no-print">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold text-muted small">Start Date</label>
                <input type="date" name="start_date" value="<?php echo h($start_date); ?>" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold text-muted small">End Date</label>
                <input type="date" name="end_date" value="<?php echo h($end_date); ?>" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold text-muted small">Low Stock Limit</label>
                <input type="number" name="low_stock_limit" value="<?php echo intval($low_stock_limit); ?>" class="form-control" min="0" step="1">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100 fw-bold">Refresh Report</button>
            </div>
        </form>
    </div>

    <div class="alert alert-light border mb-4">
        <strong>Report Range:</strong> <?php echo h($start_date); ?> to <?php echo h($end_date); ?>
        <span class="text-muted ms-2">Card fee used for profit: 1.75%</span>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="report-card p-3 bg-success bg-opacity-10 border border-success h-100">
                <div class="metric-label text-success">Total Sales</div>
                <div class="metric-value"><?php echo money2($total_sales); ?> AED</div>
                <small class="text-muted"><?php echo intval($total_sales_count); ?> sales | <?php echo intval($total_units_sold); ?> units</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-card p-3 bg-primary bg-opacity-10 border border-primary h-100">
                <div class="metric-label text-primary">Total Profit</div>
                <div class="metric-value"><?php echo money2($total_profit); ?> AED</div>
                <small class="text-muted">Margin: <?php echo number_format($gross_margin_percent, 2); ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-card p-3 bg-danger bg-opacity-10 border border-danger h-100">
                <div class="metric-label text-danger">Total Cost</div>
                <div class="metric-value"><?php echo money2($total_cost); ?> AED</div>
                <small class="text-muted">Product/service cost in selected range</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-card p-3 bg-info bg-opacity-10 border border-info h-100">
                <div class="metric-label text-info">Card Net</div>
                <div class="metric-value"><?php echo money2($card_net_total); ?> AED</div>
                <small class="text-muted">Gross: <?php echo money2($card_gross_total); ?> | Fee: <?php echo money2($card_fee_total); ?></small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-card p-3 h-100" style="background:rgba(241,80,111,.10);border:1px solid #f1506f">
                <div class="metric-label" style="color:#f1506f">Tamara / Tabby</div>
                <div class="metric-value"><?php echo money2($tamara_total); ?> AED</div>
                <small class="text-muted"><?php echo intval($tamara_count); ?> transactions · no card fee</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-card p-3 bg-dark bg-opacity-10 border border-dark h-100">
                <div class="metric-label text-dark">Bank Transfer</div>
                <div class="metric-value"><?php echo money2($bank_transfer_total); ?> AED</div>
                <small class="text-muted"><?php echo intval($bank_transfer_count); ?> transfer transactions</small>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="report-card p-3 bg-white h-100">
                <div class="metric-label">Cash Sales</div>
                <div class="metric-value"><?php echo money2($cash_total); ?> AED</div>
                <small class="text-muted"><?php echo intval($cash_count); ?> cash transactions</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-card p-3 bg-white h-100">
                <div class="metric-label">Deposits</div>
                <div class="metric-value text-success">+<?php echo money2($deposit_total); ?> AED</div>
                <small class="text-muted"><?php echo intval($deposit_count); ?> deposit entries</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-card p-3 bg-white h-100">
                <div class="metric-label">Withdrawals</div>
                <div class="metric-value text-danger">-<?php echo money2($withdraw_total); ?> AED</div>
                <small class="text-muted"><?php echo intval($withdraw_count); ?> withdraw entries</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-card p-3 bg-white h-100">
                <div class="metric-label">Net Drawer Movement</div>
                <div class="metric-value"><?php echo money2($drawer_cash_net); ?> AED</div>
                <small class="text-muted">Cash sales + deposits - withdrawals</small>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="report-card p-3 bg-warning bg-opacity-10 border border-warning h-100">
                <div class="metric-label text-warning">Stock Units Available</div>
                <div class="metric-value"><?php echo intval($total_units_available); ?></div>
                <small class="text-muted"><?php echo intval($total_skus); ?> product rows / SKUs</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-card p-3 bg-secondary bg-opacity-10 border border-secondary h-100">
                <div class="metric-label text-secondary">Inventory Cost Value</div>
                <div class="metric-value"><?php echo money2($total_inventory_cost_value); ?> AED</div>
                <small class="text-muted">Quantity × cost code</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-card p-3 bg-dark bg-opacity-10 border border-dark h-100">
                <div class="metric-label text-dark">Estimated Retail Value</div>
                <div class="metric-value"><?php echo money2($total_inventory_retail_value); ?> AED</div>
                <small class="text-muted">Quantity × sale price</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="report-card p-3 bg-danger bg-opacity-10 border border-danger h-100">
                <div class="metric-label text-danger">Low / Out of Stock</div>
                <div class="metric-value"><?php echo count($low_stock_items); ?> / <?php echo count($out_of_stock_items); ?></div>
                <small class="text-muted">Low stock limit: <?php echo intval($low_stock_limit); ?></small>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="card report-card p-4 h-100">
                <h5 class="section-title mb-3">📅 Daily Summary</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th class="text-center">Sales</th>
                                <th class="text-center">Units</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Cash</th>
                                <th class="text-end">Card</th>
                                <th class="text-end">Bank</th>
                                <th class="text-end">Cost</th>
                                <th class="text-end">Profit</th>
                                <th class="text-end">Drawer Net</th>
                                <th class="text-end">Diff</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daily)): ?>
                                <tr><td colspan="11" class="text-center text-muted py-4">No data in this date range.</td></tr>
                            <?php else: ?>
                                <?php foreach ($daily as $day => $d): ?>
                                    <?php
                                        $opening = isset($reconciliations[$day]) ? (float)$reconciliations[$day]['opening_balance'] : 0.0;
                                        $expected = $opening + $d['cash'] + $d['deposit'] - $d['withdraw'];
                                        $diff = isset($reconciliations[$day]) ? (float)$reconciliations[$day]['difference'] : null;
                                    ?>
                                    <tr>
                                        <td class="fw-bold nowrap"><?php echo h($day); ?></td>
                                        <td class="text-center"><?php echo intval($d['sales_count']); ?></td>
                                        <td class="text-center"><?php echo intval($d['units']); ?></td>
                                        <td class="text-end fw-bold text-success"><?php echo money2($d['sales_total']); ?></td>
                                        <td class="text-end"><?php echo money2($d['cash']); ?></td>
                                        <td class="text-end"><?php echo money2($d['card']); ?></td>
                                        <td class="text-end"><?php echo money2($d['bank']); ?></td>
                                        <td class="text-end text-danger"><?php echo money2($d['cost']); ?></td>
                                        <td class="text-end fw-bold text-primary"><?php echo money2($d['profit']); ?></td>
                                        <td class="text-end"><?php echo money2($expected); ?></td>
                                        <td class="text-end <?php echo ($diff === null ? 'text-muted' : ($diff < 0 ? 'text-danger' : 'text-success')); ?>">
                                            <?php echo $diff === null ? '—' : money2($diff); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card report-card p-4 h-100">
                <h5 class="section-title mb-3">💳 Payment Breakdown</h5>
                <table class="table table-sm align-middle mb-0">
                    <tr><td>Cash</td><td class="text-end fw-bold"><?php echo money2($cash_total); ?> AED</td><td class="text-end text-muted"><?php echo intval($cash_count); ?></td></tr>
                    <tr><td>Card Gross</td><td class="text-end fw-bold"><?php echo money2($card_gross_total); ?> AED</td><td class="text-end text-muted"><?php echo intval($card_count); ?></td></tr>
                    <tr><td><span style="color:#f1506f;font-weight:700">Tamara / Tabby</span></td><td class="text-end fw-bold"><?php echo money2($tamara_total); ?> AED</td><td class="text-end text-muted"><?php echo intval($tamara_count); ?></td></tr>
                    <tr><td>Card Fee 1.75%</td><td class="text-end fw-bold text-danger">-<?php echo money2($card_fee_total); ?> AED</td><td></td></tr>
                    <tr><td>Card Net</td><td class="text-end fw-bold text-success"><?php echo money2($card_net_total); ?> AED</td><td></td></tr>
                    <tr><td>Bank Transfer</td><td class="text-end fw-bold"><?php echo money2($bank_transfer_total); ?> AED</td><td class="text-end text-muted"><?php echo intval($bank_transfer_count); ?></td></tr>
                    <tr><td>Other Payment</td><td class="text-end fw-bold"><?php echo money2($other_payment_total); ?> AED</td><td class="text-end text-muted"><?php echo intval($other_payment_count); ?></td></tr>
                </table>
                <hr>
                <h6 class="fw-bold">Service vs Product</h6>
                <table class="table table-sm align-middle mb-0">
                    <tr><td>Product Sales</td><td class="text-end fw-bold"><?php echo money2($product_sales_total); ?> AED</td><td class="text-end text-muted"><?php echo intval($product_count); ?></td></tr>
                    <tr><td>Service / Repair</td><td class="text-end fw-bold"><?php echo money2($service_sales_total); ?> AED</td><td class="text-end text-muted"><?php echo intval($service_count); ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="card report-card p-4 h-100">
                <h5 class="section-title mb-3">🏆 Top 10 Sales Items</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Barcode / Ref</th>
                                <th>Item</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Sales</th>
                                <th class="text-end">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_items)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No sales found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($top_items as $item): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($item['barcode']); ?></td>
                                        <td>
                                            <?php echo h($item['name']); ?><br>
                                            <small class="text-muted"><?php echo h($item['category']); ?></small>
                                        </td>
                                        <td class="text-center fw-bold"><?php echo intval($item['qty']); ?></td>
                                        <td class="text-end fw-bold text-success"><?php echo money2($item['sales']); ?> AED</td>
                                        <td class="text-end fw-bold text-primary"><?php echo money2($item['profit']); ?> AED</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card report-card p-4 h-100">
                <h5 class="section-title mb-3">📂 Sales by Category</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Category</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Sales</th>
                                <th class="text-end">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($category_sales)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No category sales found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($category_sales as $cat => $c): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($cat); ?></td>
                                        <td class="text-center"><?php echo intval($c['qty']); ?></td>
                                        <td class="text-end fw-bold text-success"><?php echo money2($c['sales']); ?> AED</td>
                                        <td class="text-end fw-bold text-primary"><?php echo money2($c['profit']); ?> AED</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card report-card p-4 h-100">
                <h5 class="section-title mb-3">⚠️ Low Stock Items</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Barcode</th>
                                <th>Product</th>
                                <th class="text-center">Left</th>
                                <th class="text-end">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($low_stock_items)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No low stock items.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($low_stock_items, 0, 20) as $p): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($p['barcode'] ?? ''); ?></td>
                                        <td>
                                            <?php echo h(trim(($p['product_name'] ?? '') . ' ' . ($p['model_name'] ?? ''))); ?><br>
                                            <small class="text-muted"><?php echo h($p['category_name'] ?? ''); ?></small>
                                        </td>
                                        <td class="text-center fw-bold text-warning"><?php echo intval($p['quantity'] ?? 0); ?></td>
                                        <td class="text-end"><?php echo money_clean(decode_cost_aed($p['cost_price'] ?? 0)); ?> AED</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($low_stock_items) > 20): ?>
                    <small class="text-muted">Showing first 20 low-stock items.</small>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card report-card p-4 h-100">
                <h5 class="section-title mb-3">📦 Inventory by Category</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Category</th>
                                <th class="text-center">SKUs</th>
                                <th class="text-center">Units</th>
                                <th class="text-end">Cost Value</th>
                                <th class="text-end">Retail Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($category_inventory)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No inventory found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($category_inventory as $cat => $c): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($cat); ?></td>
                                        <td class="text-center"><?php echo intval($c['skus']); ?></td>
                                        <td class="text-center fw-bold"><?php echo intval($c['units']); ?></td>
                                        <td class="text-end fw-bold"><?php echo money2($c['cost_value']); ?> AED</td>
                                        <td class="text-end text-success fw-bold"><?php echo money2($c['retail_value']); ?> AED</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card report-card p-4 mb-4">
        <h5 class="section-title mb-3">✅ Recommended Reports to Keep in Your System</h5>
        <div class="row g-3">
            <?php foreach ($recommended_reports as $r): ?>
                <div class="col-md-4">
                    <div class="p-3 border rounded bg-light h-100">
                        <div class="fw-bold"><?php echo h($r[0]); ?></div>
                        <small class="text-muted"><?php echo h($r[1]); ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div></div></main></div>

<script>
(function(){
    const sidebar=document.getElementById('rlSidebar');
    const overlay=document.getElementById('rlOverlay');
    const toggle=document.getElementById('rlSidebarToggle');
    if(toggle){toggle.addEventListener('click',function(){sidebar.classList.toggle('show');overlay.classList.toggle('show');});}
    if(overlay){overlay.addEventListener('click',function(){sidebar.classList.remove('show');overlay.classList.remove('show');});}
})();
</script>

</body>
</html>
