<?php
require_once __DIR__ . '/session_keepalive.php';
// dashboard.php - POS Rukn Al Lotus dashboard
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

if (empty($_SESSION['logged_in']) || empty($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

$role = ($_SESSION['role'] ?? 'viewer') === 'admin' ? 'admin' : 'viewer';
$displayName = trim((string)($_SESSION['display_name'] ?? ''));
if ($displayName === '') $displayName = (string)($_SESSION['username'] ?? ucfirst($role));

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function money_dash($value) {
    $value = (float)$value;
    return number_format($value, 2);
}
function money_clean_dash($value) {
    $value = (float)$value;
    return abs($value - round($value)) < 0.005 ? number_format($value, 0) : number_format($value, 2);
}
function table_exists_dash(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function column_exists_dash(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function safe_scalar_dash(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Throwable $e) { return $default; }
}
function normalize_payment_dash($method): string {
    $m = strtoupper(trim((string)$method));
    if (in_array($m, ['VISA','CARD','CREDIT','DEBIT','VISA / CARD'], true)) return 'Visa / Card';
    if (in_array($m, ['BANK','BANK_TRANSFER','BANK TRANSFER','TRANSFER'], true)) return 'Bank Transfer';
    if (strpos($m, 'TAMARA') !== false || strpos($m, 'TABBY') !== false) return 'Tamara / Tabby';
    return $m === '' || $m === 'CASH' ? 'Cash' : 'Other';
}
function product_cost_dash($value): float {
    $text = trim((string)$value);
    if ($text === '') return 0.0;
    $digits = preg_replace('/\D+/', '', $text);
    if ($digits !== '' && strpos($digits, '9629097') === 0) return (float)intval(substr($digits, -3));
    $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $text));
    return is_numeric($clean) ? (float)$clean : 0.0;
}
function phone_cost_dash($value): float {
    $text = trim((string)$value);
    if ($text === '') return 0.0;
    $digits = preg_replace('/\D+/', '', $text);
    if ($digits !== '' && strpos($digits, '9629097') === 0 && strlen($digits) >= 11) return (float)intval(substr($digits, -4));
    $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $text));
    return is_numeric($clean) ? (float)$clean : 0.0;
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$sevenStart = date('Y-m-d', strtotime('-6 days'));
$messages = [];

$kpi = [
    'today_sales' => 0.0,
    'today_profit' => 0.0,
    'today_transactions' => 0,
    'month_sales' => 0.0,
    'month_profit' => 0.0,
    'inventory_units' => 0,
    'inventory_value' => 0.0,
    'withdrawals_today' => 0.0,
    'deposits_today' => 0.0,
];

$dailyMap = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $dailyMap[$d] = ['label' => date('D', strtotime($d)), 'sales' => 0.0, 'profit' => 0.0];
}
$paymentMix = ['Cash' => 0.0, 'Visa / Card' => 0.0, 'Tamara / Tabby' => 0.0, 'Bank Transfer' => 0.0, 'Other' => 0.0];
$recentSales = [];

if (table_exists_dash($pdo, 'sales_records')) {
    try {
        $hasImportedCost = column_exists_dash($pdo, 'sales_records', 'imported_cost');
        $hasImportedProfit = column_exists_dash($pdo, 'sales_records', 'imported_profit');
        $sql = "SELECT id, barcode, item_name, qty_sold, total_amount, sale_price, payment_method, sold_at" .
               ($hasImportedCost ? ", imported_cost" : ", NULL AS imported_cost") .
               ($hasImportedProfit ? ", imported_profit" : ", NULL AS imported_profit") .
               " FROM sales_records WHERE sold_at >= ? ORDER BY sold_at ASC, id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$monthStart . ' 00:00:00']);
        $monthRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($monthRows as $r) {
            $date = date('Y-m-d', strtotime($r['sold_at']));
            $qty = max(1, (int)($r['qty_sold'] ?? 1));
            $gross = (float)($r['total_amount'] ?? 0);
            if ($gross == 0 && isset($r['sale_price'])) $gross = (float)$r['sale_price'] * $qty;
            $payment = normalize_payment_dash($r['payment_method'] ?? 'Cash');
            $fee = $payment === 'Visa / Card' ? $gross * 0.025 : 0.0;
            $cost = ($r['imported_cost'] !== null && $r['imported_cost'] !== '' && is_numeric($r['imported_cost'])) ? (float)$r['imported_cost'] * $qty : 0.0;
            $profit = ($r['imported_profit'] !== null && $r['imported_profit'] !== '' && is_numeric($r['imported_profit'])) ? (float)$r['imported_profit'] : ($gross - $fee - $cost);

            $kpi['month_sales'] += $gross;
            $kpi['month_profit'] += $profit;
            if ($date === $today) {
                $kpi['today_sales'] += $gross;
                $kpi['today_profit'] += $profit;
                $kpi['today_transactions']++;
                $paymentMix[$payment] = ($paymentMix[$payment] ?? 0) + $gross;
            }
            if (isset($dailyMap[$date])) {
                $dailyMap[$date]['sales'] += $gross;
                $dailyMap[$date]['profit'] += $profit;
            }
        }

        $recentStmt = $pdo->query("SELECT id, barcode, item_name, qty_sold, total_amount, payment_method, sold_at FROM sales_records ORDER BY sold_at DESC, id DESC LIMIT 8");
        $recentSales = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $messages[] = 'Sales dashboard data could not be loaded.';
    }
}

if (table_exists_dash($pdo, 'drawer_withdrawals')) {
    try {
        $stmt = $pdo->prepare("SELECT amount FROM drawer_withdrawals WHERE withdrawn_at >= ? AND withdrawn_at < ?");
        $stmt->execute([$today . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($today . ' +1 day'))]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $amount) {
            $amount = (float)$amount;
            if ($amount > 0) $kpi['withdrawals_today'] += $amount;
            elseif ($amount < 0) $kpi['deposits_today'] += abs($amount);
        }
    } catch (Throwable $e) {}
}

$inventory = [
    'Products' => ['units' => 0, 'value' => 0.0],
    'Phones' => ['units' => 0, 'value' => 0.0],
    'Laptops' => ['units' => 0, 'value' => 0.0],
    'Tools' => ['units' => 0, 'value' => 0.0],
];
try {
    if (table_exists_dash($pdo, 'products')) {
        foreach ($pdo->query("SELECT quantity, cost_price FROM products")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $q = max(0, (int)$r['quantity']);
            $inventory['Products']['units'] += $q;
            $inventory['Products']['value'] += $q * product_cost_dash($r['cost_price']);
        }
    }
    if (table_exists_dash($pdo, 'phones')) {
        foreach ($pdo->query("SELECT quantity, cost_price FROM phones")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $q = max(0, (int)$r['quantity']);
            $inventory['Phones']['units'] += $q;
            $inventory['Phones']['value'] += $q * phone_cost_dash($r['cost_price']);
        }
    }
    if (table_exists_dash($pdo, 'laptops')) {
        foreach ($pdo->query("SELECT quantity, cost_price FROM laptops")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $q = max(0, (int)$r['quantity']);
            $inventory['Laptops']['units'] += $q;
            $inventory['Laptops']['value'] += $q * phone_cost_dash($r['cost_price']);
        }
    }
    if (table_exists_dash($pdo, 'tool_items')) {
        foreach ($pdo->query("SELECT quantity, cost_price FROM tool_items")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $q = max(0, (int)$r['quantity']);
            $inventory['Tools']['units'] += $q;
            $inventory['Tools']['value'] += $q * phone_cost_dash($r['cost_price']);
        }
    }
} catch (Throwable $e) {
    $messages[] = 'Some inventory totals could not be loaded.';
}
foreach ($inventory as $part) {
    $kpi['inventory_units'] += $part['units'];
    $kpi['inventory_value'] += $part['value'];
}

$todaySheet = null;
if (table_exists_dash($pdo, 'daily_cash_sheets')) {
    try {
        $stmt = $pdo->prepare("SELECT previous_day_balance, physical_drawer_amount, updated_at FROM daily_cash_sheets WHERE sheet_date=? LIMIT 1");
        $stmt->execute([$today]);
        $todaySheet = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
}

$dailyChart = array_values($dailyMap);
$paymentChart = [];
foreach ($paymentMix as $name => $value) {
    if ($value > 0) $paymentChart[] = ['name' => $name, 'value' => round($value, 2)];
}
if (!$paymentChart) $paymentChart[] = ['name' => 'No sales', 'value' => 1];
$inventoryChart = [];
foreach ($inventory as $name => $data) $inventoryChart[] = ['name' => $name, 'units' => $data['units']];
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard - POS Rukn Al Lotus</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<style>
:root{--sidebar:#111827;--sidebar2:#1f2937;--bg:#f5f7fb;--card:#fff;--text:#111827;--muted:#6b7280;--border:#e5e7eb;--accent:#2563eb;--tamara:#f1506f}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Inter,system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif;color:var(--text)}
.app{display:flex;min-height:100vh}.sidebar{width:258px;background:linear-gradient(180deg,var(--sidebar),#0b1220);color:#fff;position:fixed;inset:0 auto 0 0;overflow-y:auto;padding:20px 14px;z-index:1040}.brand{display:flex;gap:12px;align-items:center;padding:4px 8px 22px}.brand-mark{width:42px;height:42px;border-radius:12px;background:#fff;color:#111827;display:grid;place-items:center;font-weight:900}.brand-title{font-weight:800;line-height:1.1}.brand-sub{color:#9ca3af;font-size:.78rem;margin-top:4px}.nav-label{color:#6b7280;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.09em;padding:16px 12px 7px}.side-link{display:flex;align-items:center;gap:11px;color:#cbd5e1;text-decoration:none;padding:10px 12px;border-radius:10px;margin:3px 0;font-size:.9rem;font-weight:600}.side-link:hover{background:#ffffff10;color:#fff}.side-link.active{background:#2563eb;color:#fff}.side-link i{font-size:1.05rem;width:20px;text-align:center}.role-badge{font-size:.65rem;margin-left:auto;color:#93c5fd}.main{margin-left:258px;width:calc(100% - 258px);padding:24px}.topbar{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:24px}.welcome h1{font-size:1.7rem;font-weight:800;margin:0}.welcome p{margin:4px 0 0;color:var(--muted);font-size:.9rem}.user-chip{background:#fff;border:1px solid var(--border);border-radius:13px;padding:8px 12px;display:flex;align-items:center;gap:10px}.avatar{width:34px;height:34px;border-radius:10px;background:#e0e7ff;color:#3730a3;display:grid;place-items:center;font-weight:800}.metric-card,.panel{background:var(--card);border:1px solid var(--border);border-radius:16px}.metric-card{padding:18px;height:100%}.metric-top{display:flex;justify-content:space-between;align-items:center}.metric-label{font-size:.82rem;color:var(--muted);font-weight:700}.metric-icon{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;background:#eff6ff;color:#2563eb}.metric-value{font-size:1.65rem;font-weight:850;margin-top:12px;letter-spacing:-.03em}.metric-foot{font-size:.78rem;color:var(--muted);margin-top:4px}.panel{padding:18px;height:100%}.panel-title{font-size:1rem;font-weight:800;margin:0}.panel-sub{font-size:.78rem;color:var(--muted);margin-top:3px}.chart-box{position:relative;height:285px;margin-top:15px}.table thead th{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;border-bottom-color:#e5e7eb}.table tbody td{font-size:.86rem;vertical-align:middle;border-bottom-color:#f1f5f9}.pay{font-size:.72rem;font-weight:800;border-radius:999px;padding:5px 8px;background:#f3f4f6}.mobile-toggle{display:none}.tamara-dot{color:var(--tamara)}
@media(max-width:991px){.sidebar{transform:translateX(-100%);transition:.2s}.sidebar.open{transform:translateX(0)}.main{margin-left:0;width:100%;padding:16px}.mobile-toggle{display:inline-flex}.topbar{align-items:flex-start}.user-chip .user-text{display:none}}
html[dir="rtl"] .sidebar{inset:0 0 0 auto}html[dir="rtl"] .main{margin-left:0;margin-right:258px}html[dir="rtl"] .role-badge{margin-left:0;margin-right:auto}@media(max-width:991px){html[dir="rtl"] .sidebar{transform:translateX(100%)}html[dir="rtl"] .sidebar.open{transform:translateX(0)}html[dir="rtl"] .main{margin-right:0}}
</style>
<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body>
<div class="app">
<?php include __DIR__ . '/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div class="d-flex gap-2 align-items-start">
            <button class="btn btn-light border mobile-toggle" id="sidebarToggle" type="button"><i class="bi bi-list"></i></button>
            <div class="welcome"><h1>Dashboard</h1><p><?=h(date('l, d F Y'))?> · Live business overview</p></div>
        </div>
        <div class="user-chip"><div class="avatar"><?=h(strtoupper(substr($displayName,0,1)))?></div><div class="user-text"><div class="fw-bold small"><?=h($displayName)?></div><div class="text-muted" style="font-size:.72rem"><?=h(ucfirst($role))?></div></div></div>
    </div>

    <?php foreach ($messages as $m): ?><div class="alert alert-warning py-2"><?=h($m)?></div><?php endforeach; ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><div class="metric-card"><div class="metric-top"><div class="metric-label">Today's Sales</div><div class="metric-icon"><i class="bi bi-graph-up-arrow"></i></div></div><div class="metric-value"><?=money_clean_dash($kpi['today_sales'])?> <small class="fs-6">AED</small></div><div class="metric-foot"><?=number_format($kpi['today_transactions'])?> transactions today</div></div></div>
        <div class="col-6 col-xl-3"><div class="metric-card"><div class="metric-top"><div class="metric-label">Today's Profit</div><div class="metric-icon"><i class="bi bi-wallet2"></i></div></div><div class="metric-value"><?=money_clean_dash($kpi['today_profit'])?> <small class="fs-6">AED</small></div><div class="metric-foot">Visa fee calculated at 2.5%</div></div></div>
        <div class="col-6 col-xl-3"><div class="metric-card"><div class="metric-top"><div class="metric-label">Month Sales</div><div class="metric-icon"><i class="bi bi-calendar-check"></i></div></div><div class="metric-value"><?=money_clean_dash($kpi['month_sales'])?> <small class="fs-6">AED</small></div><div class="metric-foot">Since <?=h(date('d M', strtotime($monthStart)))?></div></div></div>
        <div class="col-6 col-xl-3"><div class="metric-card"><div class="metric-top"><div class="metric-label">Inventory</div><div class="metric-icon"><i class="bi bi-boxes"></i></div></div><div class="metric-value"><?=number_format($kpi['inventory_units'])?></div><div class="metric-foot">Approx. <?=money_clean_dash($kpi['inventory_value'])?> AED cost value</div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-8"><div class="panel"><div><h2 class="panel-title">Sales & Profit — Last 7 Days</h2><div class="panel-sub">Daily totals from sales_records</div></div><div class="chart-box"><canvas id="salesChart"></canvas></div></div></div>
        <div class="col-xl-4"><div class="panel"><div><h2 class="panel-title">Today's Payment Mix</h2><div class="panel-sub">Sales split by payment method</div></div><div class="chart-box"><canvas id="paymentChart"></canvas></div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-5"><div class="panel"><div><h2 class="panel-title">Inventory Units</h2><div class="panel-sub">Available quantity by inventory source</div></div><div class="chart-box"><canvas id="inventoryChart"></canvas></div></div></div>
        <div class="col-xl-7"><div class="panel"><div class="d-flex justify-content-between align-items-start gap-2"><div><h2 class="panel-title">Recent Transactions</h2><div class="panel-sub">Latest records in the database</div></div><?php if($role==='admin'):?><a href="view_transactions.php" class="btn btn-sm btn-outline-primary">View all</a><?php endif;?></div><div class="table-responsive mt-3"><table class="table mb-0"><thead><tr><th>Time</th><th>Item</th><th>Qty</th><th>Payment</th><th class="text-end">Amount</th></tr></thead><tbody><?php if(!$recentSales):?><tr><td colspan="5" class="text-center text-muted py-4">No transactions yet.</td></tr><?php else: foreach($recentSales as $r):?><tr><td class="text-nowrap"><?=h(date('d M H:i',strtotime($r['sold_at'])))?></td><td><div class="fw-semibold"><?=h($r['item_name'] ?: $r['barcode'])?></div><div class="text-muted" style="font-size:.72rem"><?=h($r['barcode'])?></div></td><td><?=number_format((int)$r['qty_sold'])?></td><td><span class="pay"><?=h(normalize_payment_dash($r['payment_method']))?></span></td><td class="text-end fw-bold"><?=money_clean_dash($r['total_amount'])?></td></tr><?php endforeach; endif;?></tbody></table></div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-md-4"><div class="metric-card"><div class="metric-label">Withdrawals Today</div><div class="metric-value"><?=money_clean_dash($kpi['withdrawals_today'])?> <small class="fs-6">AED</small></div><div class="metric-foot">Positive drawer movements</div></div></div>
        <div class="col-md-4"><div class="metric-card"><div class="metric-label">Deposits Today</div><div class="metric-value"><?=money_clean_dash($kpi['deposits_today'])?> <small class="fs-6">AED</small></div><div class="metric-foot">Addition/deposit movements</div></div></div>
        <div class="col-md-4"><div class="metric-card"><div class="metric-label">Physical Drawer</div><div class="metric-value"><?=money_clean_dash($todaySheet['physical_drawer_amount'] ?? 0)?> <small class="fs-6">AED</small></div><div class="metric-foot"><?= $todaySheet ? 'Daily sheet updated ' . h((string)($todaySheet['updated_at'] ?? '')) : 'No daily sheet saved for today' ?></div></div></div>
    </div>
</main>
</div>
<script>
const sidebar=document.getElementById('sidebar');document.getElementById('sidebarToggle')?.addEventListener('click',()=>sidebar.classList.toggle('open'));document.addEventListener('click',e=>{if(window.innerWidth<992&&sidebar.classList.contains('open')&&!sidebar.contains(e.target)&&!e.target.closest('#sidebarToggle'))sidebar.classList.remove('open')});
Chart.defaults.font.family='Inter, system-ui, -apple-system, Segoe UI, sans-serif';Chart.defaults.color='#6b7280';
const dailyData=<?=json_encode($dailyChart,JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK)?>;
new Chart(document.getElementById('salesChart'),{type:'line',data:{labels:dailyData.map(x=>x.label),datasets:[{label:'Sales',data:dailyData.map(x=>x.sales),borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.08)',fill:true,tension:.35,borderWidth:2,pointRadius:3},{label:'Profit',data:dailyData.map(x=>x.profit),borderColor:'#16a34a',backgroundColor:'rgba(22,163,74,.05)',fill:false,tension:.35,borderWidth:2,pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'top',align:'end'}},scales:{y:{beginAtZero:true,grid:{color:'#eef2f7'},ticks:{callback:v=>Number(v).toLocaleString()}},x:{grid:{display:false}}}}});
const paymentData=<?=json_encode($paymentChart,JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK)?>;
new Chart(document.getElementById('paymentChart'),{type:'doughnut',data:{labels:paymentData.map(x=>x.name),datasets:[{data:paymentData.map(x=>x.value),backgroundColor:['#16a34a','#84cc16','#f1506f','#2563eb','#94a3b8'],borderWidth:0,hoverOffset:5}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{position:'bottom',labels:{usePointStyle:true,boxWidth:8,padding:16}}}}});
const inventoryData=<?=json_encode($inventoryChart,JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK)?>;
new Chart(document.getElementById('inventoryChart'),{type:'bar',data:{labels:inventoryData.map(x=>x.name),datasets:[{label:'Units',data:inventoryData.map(x=>x.units),backgroundColor:['#2563eb','#7c3aed','#0ea5e9','#f59e0b'],borderRadius:7,borderSkipped:false}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'#eef2f7'},ticks:{precision:0}},x:{grid:{display:false}}}}});
</script>
</body></html>
