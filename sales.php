<?php
require_once __DIR__ . '/session_keepalive.php';
// sales.php - Stock check / inventory lookup only
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$message = "";
$barcode_input = "";
$product_matches = [];
$phone_matches = [];
$laptop_matches = [];
$tool_matches = [];
$recharge_matches = [];

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money_clean($value) {
    $num = is_numeric($value) ? (float)$value : 0;
    if (abs($num - round($num)) < 0.00001) {
        return number_format($num, 0);
    }
    return number_format($num, 2);
}

function parse_clean_aed_number($value) {
    $text = trim((string)($value ?? ''));
    if ($text === '') return 0;

    // Remove common currency text but keep decimals. Example: 250.00 AED => 250
    $clean = str_ireplace(['AED', 'DHS', 'DH', 'درهم', 'د.إ'], '', $text);
    $clean = str_replace([',', ' '], '', $clean);

    if (is_numeric($clean)) {
        return (float)$clean;
    }

    $digits = preg_replace('/\D+/', '', $text);
    return $digits === '' ? 0 : (float)$digits;
}

function decode_product_cost($value) {
    $text = trim((string)($value ?? ''));
    $raw = preg_replace('/\D+/', '', $text);
    if ($raw === '') return 0;

    // Product cost code style: 962909700011 = 11 AED, 962909700211 = 211 AED
    if (strpos($raw, '9629097') === 0 && strlen($raw) >= 10) {
        return (int)substr($raw, -3);
    }

    return parse_clean_aed_number($text);
}

function decode_phone_like_cost($value) {
    $text = trim((string)($value ?? ''));
    $raw = preg_replace('/\D+/', '', $text);
    if ($raw === '') return 0;

    // Old phone/laptop/tool code support: 962909703950 = 3950 AED, 962909700490 = 490 AED
    if (strpos($raw, '9629097') === 0 && strlen($raw) >= 11) {
        return (int)substr($raw, -4);
    }

    // Clean AED values must stay clean: 250.00 must show 250, not 25,000
    return parse_clean_aed_number($text);
}

function table_exists(PDO $pdo, $table) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function column_exists(PDO $pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function get_col($row, $key, $default = '') {
    return array_key_exists($key, $row) ? $row[$key] : $default;
}

function stock_badge($qty) {
    $qty = (int)$qty;
    if ($qty <= 0) return '<span class="badge bg-danger">Out of Stock</span>';
    if ($qty <= 3) return '<span class="badge bg-warning text-dark">Low Stock</span>';
    return '<span class="badge bg-success">Available</span>';
}

function status_badge($status) {
    $status = trim((string)$status);
    $lower = strtolower($status);
    if ($lower === 'sold') return '<span class="badge bg-danger">Sold</span>';
    if ($lower === 'available') return '<span class="badge bg-success">Available</span>';
    if ($lower === 'reserved') return '<span class="badge bg-warning text-dark">Reserved</span>';
    if ($status === '') return '<span class="badge bg-secondary">N/A</span>';
    return '<span class="badge bg-secondary">' . h($status) . '</span>';
}

function normalize_payment_method($method) {
    $m = strtoupper(trim((string)$method));
    if ($m === 'VISA' || $m === 'CARD' || $m === 'VISA / CARD') return 'CARD';
    if ($m === 'BANK' || $m === 'BANK_TRANSFER' || $m === 'BANK TRANSFER') return 'BANK_TRANSFER';
    if ($m === 'CASH' || $m === '') return 'CASH';
    return $m;
}

function payment_label($method) {
    $m = normalize_payment_method($method);
    if ($m === 'CARD') return 'VISA / CARD';
    if ($m === 'BANK_TRANSFER') return 'BANK TRANSFER';
    if ($m === 'CASH') return 'CASH';
    return $m;
}

function payment_badge_html($method) {
    $m = normalize_payment_method($method);
    if ($m === 'CASH') return '<span class="badge bg-success">CASH</span>';
    if ($m === 'CARD') return '<span class="badge bg-info text-dark">VISA / CARD</span>';
    if ($m === 'BANK_TRANSFER') return '<span class="badge bg-primary">BANK TRANSFER</span>';
    return '<span class="badge bg-secondary">' . h($m) . '</span>';
}

function sold_date_to_text($value) {
    if (empty($value)) return '—';
    return date('d/m/Y h:i A', strtotime($value));
}

// Handle barcode scan/check only. No sale, no checkout, no stock deduction.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['barcode_scan'])) {
    $barcode_input = trim($_POST['barcode_scan']);

    if ($barcode_input === '') {
        $message = "<div class='alert alert-warning'>Please scan or type a barcode.</div>";
    } else {
        try {
            // Products
            if (table_exists($pdo, 'products')) {
                $stmt = $pdo->prepare("\n                    SELECT p.*, c.category_name\n                    FROM products p\n                    LEFT JOIN categories c ON p.category_id = c.id\n                    WHERE p.barcode = ?\n                    ORDER BY p.quantity DESC, p.id ASC\n                ");
                $stmt->execute([$barcode_input]);
                $product_matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Phones
            if (table_exists($pdo, 'phones') && column_exists($pdo, 'phones', 'barcode')) {
                $stmt = $pdo->prepare("SELECT * FROM phones WHERE barcode = ? ORDER BY quantity DESC, id ASC");
                $stmt->execute([$barcode_input]);
                $phone_matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Laptops
            if (table_exists($pdo, 'laptops') && column_exists($pdo, 'laptops', 'barcode')) {
                $stmt = $pdo->prepare("SELECT * FROM laptops WHERE barcode = ? ORDER BY quantity DESC, id ASC");
                $stmt->execute([$barcode_input]);
                $laptop_matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Tools / spare parts
            if (table_exists($pdo, 'tools') && column_exists($pdo, 'tools', 'barcode')) {
                $stmt = $pdo->prepare("SELECT * FROM tools WHERE barcode = ? ORDER BY quantity DESC, id ASC");
                $stmt->execute([$barcode_input]);
                $tool_matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Recharge barcodes (Etisalat / du)
            if (table_exists($pdo, 'recharge_barcodes')) {
                $stmt = $pdo->prepare("SELECT * FROM recharge_barcodes WHERE barcode = ? AND active = 1 ORDER BY provider ASC, amount ASC, id ASC");
                $stmt->execute([$barcode_input]);
                $recharge_matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Built-in permanent recharge identifiers.
            // This fallback makes them work immediately even before opening recharges.php.
            if (empty($recharge_matches)) {
                if ($barcode_input === '629100000101') {
                    $recharge_matches[] = [
                        'provider' => 'Etisalat',
                        'barcode' => '629100000101',
                        'label' => 'Etisalat Recharge',
                        'amount' => 0,
                        'cost_amount' => 0,
                        'active' => 1
                    ];
                } elseif ($barcode_input === '629100000102') {
                    $recharge_matches[] = [
                        'provider' => 'du',
                        'barcode' => '629100000102',
                        'label' => 'du Recharge',
                        'amount' => 0,
                        'cost_amount' => 0,
                        'active' => 1
                    ];
                }
            }

            $total_found = count($product_matches) + count($phone_matches) + count($laptop_matches) + count($tool_matches) + count($recharge_matches);
            if ($total_found === 0) {
                $message = "<div class='alert alert-danger'>Barcode <strong>" . h($barcode_input) . "</strong> was not found in Products, Phones, Laptops, Tools, or Recharge Barcodes.</div>";
            } else {
                $message = "<div class='alert alert-success'>Found <strong>{$total_found}</strong> matching item(s) for barcode <strong>" . h($barcode_input) . "</strong>.</div>";
            }
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Database Error: " . h($e->getMessage()) . "</div>";
        }
    }
}

// Dashboard totals
$dash = [
    'product_count' => 0,
    'product_units' => 0,
    'product_value' => 0,
    'phone_units' => 0,
    'phone_value' => 0,
    'laptop_units' => 0,
    'laptop_value' => 0,
    'tool_units' => 0,
    'tool_value' => 0,
    'low_stock' => 0,
    'out_stock' => 0,
];

try {
    if (table_exists($pdo, 'products')) {
        $rows = $pdo->query("SELECT id, quantity, cost_price FROM products")->fetchAll(PDO::FETCH_ASSOC);
        $dash['product_count'] = count($rows);
        foreach ($rows as $r) {
            $q = (int)$r['quantity'];
            $dash['product_units'] += $q;
            $dash['product_value'] += $q * decode_product_cost($r['cost_price']);
            if ($q <= 0) $dash['out_stock']++;
            elseif ($q <= 3) $dash['low_stock']++;
        }
    }
    if (table_exists($pdo, 'phones') && column_exists($pdo, 'phones', 'quantity')) {
        $rows = $pdo->query("SELECT quantity, cost_price FROM phones")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $q = (int)($r['quantity'] ?? 0);
            $dash['phone_units'] += $q;
            $dash['phone_value'] += $q * decode_phone_like_cost($r['cost_price'] ?? 0);
        }
    }
    if (table_exists($pdo, 'laptops') && column_exists($pdo, 'laptops', 'quantity')) {
        $rows = $pdo->query("SELECT quantity, cost_price FROM laptops")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $q = (int)($r['quantity'] ?? 0);
            $dash['laptop_units'] += $q;
            $dash['laptop_value'] += $q * decode_phone_like_cost($r['cost_price'] ?? 0);
        }
    }
    if (table_exists($pdo, 'tools') && column_exists($pdo, 'tools', 'quantity')) {
        $rows = $pdo->query("SELECT quantity, cost_price FROM tools")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $q = (int)($r['quantity'] ?? 0);
            $dash['tool_units'] += $q;
            $dash['tool_value'] += $q * decode_phone_like_cost($r['cost_price'] ?? 0);
        }
    }
} catch (Exception $e) {
    // keep dashboard silent if optional tables do not exist
}

// Quick business dashboard reports for sales.php. This page is still stock-check only.
$card_fee_rate =  0.025; // VISA / CARD fee = 2.5%
$today = date('Y-m-d');
$month_start = date('Y-m-01');
$dashboard_report = [
    'today_sales' => 0.0,
    'today_profit' => 0.0,
    'today_cost' => 0.0,
    'today_card_fee' => 0.0,
    'today_transactions' => 0,
    'month_sales' => 0.0,
    'month_profit' => 0.0,
    'cash_total' => 0.0,
    'card_total' => 0.0,
    'tamara_tabby_total' => 0.0,
    'bank_total' => 0.0,
    'withdrawals' => 0.0,
    'deposits' => 0.0,
    'drawer_left_estimate' => 0.0,
];
$low_stock_preview = [];
$category_stock = [];

try {
    if (table_exists($pdo, 'sales_records')) {
        $has_imported_cost = column_exists($pdo, 'sales_records', 'imported_cost');
        $has_product_id = column_exists($pdo, 'sales_records', 'product_id') && table_exists($pdo, 'products');
        $product_cost_sql = "0 AS product_cost_price";
        if (table_exists($pdo, 'products')) {
            if ($has_product_id) {
                $product_cost_sql = "(SELECT p.cost_price FROM products p WHERE p.id = sr.product_id LIMIT 1) AS product_cost_price";
            } else {
                $product_cost_sql = "(SELECT p.cost_price FROM products p WHERE p.barcode = sr.barcode LIMIT 1) AS product_cost_price";
            }
        }
        $imported_cost_sql = $has_imported_cost ? "sr.imported_cost" : "0 AS imported_cost";

        $stmt = $pdo->prepare("\n            SELECT sr.id, sr.barcode, sr.item_name, sr.qty_sold, sr.sale_price, sr.total_amount, sr.payment_method, sr.sold_at,\n                   {$imported_cost_sql}, {$product_cost_sql}\n            FROM sales_records sr\n            WHERE DATE(sr.sold_at) = ?\n            ORDER BY sr.sold_at DESC, sr.id DESC\n        ");
        $stmt->execute([$today]);
        $today_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($today_rows as $row) {
            $qty = max(1, (int)($row['qty_sold'] ?? 1));
            $total = (float)($row['total_amount'] ?? 0);
            $method = normalize_payment_method($row['payment_method'] ?? 'CASH');
            $imported_cost = (float)($row['imported_cost'] ?? 0);
            $product_cost = decode_product_cost($row['product_cost_price'] ?? 0);
            $unit_cost = ($imported_cost > 0) ? $imported_cost : $product_cost;
            $cost_total = $unit_cost * $qty;
            $card_fee = ($method === 'CARD') ? ($total * $card_fee_rate) : 0;
            $profit = $total - $cost_total - $card_fee;

            $dashboard_report['today_sales'] += $total;
            $dashboard_report['today_profit'] += $profit;
            $dashboard_report['today_cost'] += $cost_total;
            $dashboard_report['today_card_fee'] += $card_fee;
            $dashboard_report['today_transactions']++;

            if ($method === 'CASH') {
                $dashboard_report['cash_total'] += $total;
            } elseif ($method === 'CARD') {
                $dashboard_report['card_total'] += $total;
            } elseif (
                $method === 'TAMARA_TABBY' ||
                $method === 'TABBY_TAMARA' ||
                $method === 'TAMARA' ||
                $method === 'TABBY' ||
                $method === 'تابي/تمارا' ||
                $method === 'تمارا/تابي'
            ) {
                $dashboard_report['tamara_tabby_total'] += $total;
            } elseif ($method === 'BANK_TRANSFER') {
                $dashboard_report['bank_total'] += $total;
            }
        }

        $stmt = $pdo->prepare("\n            SELECT sr.qty_sold, sr.total_amount, sr.payment_method,\n                   {$imported_cost_sql}, {$product_cost_sql}\n            FROM sales_records sr\n            WHERE DATE(sr.sold_at) >= ?\n        ");
        $stmt->execute([$month_start]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qty = max(1, (int)($row['qty_sold'] ?? 1));
            $total = (float)($row['total_amount'] ?? 0);
            $method = normalize_payment_method($row['payment_method'] ?? 'CASH');
            $unit_cost = ((float)($row['imported_cost'] ?? 0) > 0) ? (float)$row['imported_cost'] : decode_product_cost($row['product_cost_price'] ?? 0);
            $card_fee = ($method === 'CARD') ? ($total * $card_fee_rate) : 0;
            $dashboard_report['month_sales'] += $total;
            $dashboard_report['month_profit'] += ($total - ($unit_cost * $qty) - $card_fee);
        }
    }

    if (table_exists($pdo, 'drawer_withdrawals')) {
        $stmt = $pdo->prepare("SELECT amount FROM drawer_withdrawals WHERE DATE(withdrawn_at) = ?");
        $stmt->execute([$today]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = (float)($row['amount'] ?? 0);
            if ($amount >= 0) $dashboard_report['withdrawals'] += $amount;
            else $dashboard_report['deposits'] += abs($amount);
        }
    }

    $opening_balance = isset($_SESSION['cash_drawer_start']) ? (float)$_SESSION['cash_drawer_start'] : 0.0;
    $dashboard_report['drawer_left_estimate'] = $opening_balance + $dashboard_report['cash_total'] + $dashboard_report['deposits'] - $dashboard_report['withdrawals'];

    if (table_exists($pdo, 'products')) {
        $lowStmt = $pdo->query("\n            SELECT p.id, p.barcode, p.product_name, p.model_name, p.quantity, p.cost_price, c.category_name\n            FROM products p\n            LEFT JOIN categories c ON p.category_id = c.id\n            WHERE p.quantity <= 3\n            ORDER BY p.quantity ASC, p.product_name ASC\n            LIMIT 8\n        ");
        $low_stock_preview = $lowStmt->fetchAll(PDO::FETCH_ASSOC);

        $catStmt = $pdo->query("\n            SELECT COALESCE(c.category_name, 'Uncategorized') AS category_name, COUNT(*) AS rows_count, COALESCE(SUM(p.quantity),0) AS units_count\n            FROM products p\n            LEFT JOIN categories c ON p.category_id = c.id\n            GROUP BY COALESCE(c.category_name, 'Uncategorized')\n            ORDER BY units_count DESC, category_name ASC\n            LIMIT 8\n        ");
        $category_stock = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Keep dashboard visible even if one optional report fails.
}

$role = strtolower((string)($_SESSION['pos_role'] ?? 'admin'));
$displayName = trim((string)($_SESSION['pos_name'] ?? $_SESSION['username'] ?? 'Admin'));
if ($displayName === '') $displayName = 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stock Check - POS Rukn Al Lotus</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{--sidebar:#0b1220;--sidebar2:#111a2c;--bg:#f3f6fb;--card:#fff;--text:#172033;--muted:#7b879c;--line:#e6eaf0;--blue:#2563eb}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--text)}
.sidebar{position:fixed;inset:0 auto 0 0;width:260px;background:linear-gradient(180deg,var(--sidebar),var(--sidebar2));color:#fff;padding:20px 14px;overflow-y:auto;z-index:1040}.brand{display:flex;gap:12px;align-items:center;padding:5px 10px 20px}.brand-logo{width:42px;height:42px;border-radius:12px;background:#2563eb;display:grid;place-items:center;font-size:1.25rem}.brand small{display:block;color:#91a0b7}.nav-label{font-size:.68rem;text-transform:uppercase;letter-spacing:.12em;color:#64748b;padding:18px 12px 7px;font-weight:800}.side-link{display:flex;align-items:center;gap:11px;color:#b9c5d8;text-decoration:none;padding:10px 12px;border-radius:10px;margin:2px 0;font-size:.9rem;font-weight:650}.side-link:hover{background:#ffffff10;color:#fff}.side-link.active{background:#2563eb;color:#fff;box-shadow:0 8px 24px #2563eb35}.side-link i{width:20px;text-align:center;font-size:1rem}.role-badge{margin-left:auto;font-size:.58rem;background:#ffffff15;border-radius:20px;padding:3px 7px}.main{margin-left:260px;min-height:100vh;padding:22px 26px 40px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.welcome h1{font-size:1.5rem;margin:0;font-weight:800}.welcome p{margin:3px 0 0;color:var(--muted);font-size:.86rem}.user-chip{background:#fff;border:1px solid var(--line);border-radius:14px;padding:7px 10px;display:flex;gap:9px;align-items:center;box-shadow:0 3px 12px #17203308}.avatar{width:35px;height:35px;border-radius:10px;background:#e8efff;color:#2563eb;display:grid;place-items:center;font-weight:800}.mobile-toggle{display:none}.hero{background:linear-gradient(120deg,#172554,#2563eb 58%,#06b6d4);color:white;border-radius:20px;padding:24px;margin-bottom:20px;box-shadow:0 14px 35px #2563eb20}.hero h2{font-weight:850;margin:0 0 5px}.hero p{margin:0;color:#dbeafe}.terminal-card,.dash-card{border:1px solid var(--line);border-radius:16px;box-shadow:0 5px 18px #1720330b;background:#fff}.metric-card{padding:18px;height:100%}.metric-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:#eff6ff;color:#2563eb;font-size:1.1rem}.metric-label{font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:800}.dash-number{font-size:1.65rem;font-weight:850}.barcode-focus-box{background:#f8fbff;border:2px solid #93c5fd;box-shadow:0 0 0 4px #dbeafe70}.result-card{border:1px solid var(--line)!important;border-left:4px solid #2563eb!important;border-radius:14px;box-shadow:none}.info-label{font-size:.7rem;color:#7b879c;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.info-value{font-weight:700}.mini-table td,.mini-table th{font-size:.86rem}.table>thead th{color:#667085;font-size:.75rem;text-transform:uppercase;letter-spacing:.03em}.btn{border-radius:10px}.section-card{padding:22px}.stock-kpis .card{transition:.2s}.stock-kpis .card:hover{transform:translateY(-2px);box-shadow:0 10px 25px #17203310}.page-actions{display:flex;gap:8px;flex-wrap:wrap}.page-actions .btn{font-size:.82rem}
@media(max-width:991.98px){.sidebar{transform:translateX(-100%);transition:.25s}.sidebar.open{transform:translateX(0)}.main{margin-left:0;padding:16px}.mobile-toggle{display:inline-flex}.topbar{align-items:flex-start}.user-text{display:none}}
</style>

<style>
/* ===== FORCE COLORED STOCK CHECK CARDS ===== */
.sales-color-card{
    position:relative!important;
    overflow:hidden!important;
    border:0!important;
    color:#fff!important;
    min-height:110px!important;
    box-shadow:0 12px 28px rgba(16,24,40,.14)!important;
    transition:transform .18s ease,box-shadow .18s ease!important;
}
.sales-color-card:hover{
    transform:translateY(-3px)!important;
    box-shadow:0 17px 36px rgba(16,24,40,.19)!important;
}
.sales-color-card:after{
    content:"";
    position:absolute;
    width:100px;height:100px;border-radius:50%;
    right:-28px;bottom:-42px;
    background:rgba(255,255,255,.16);
}
.sales-color-card:before{
    content:"";
    position:absolute;
    width:52px;height:52px;border-radius:50%;
    right:28px;top:-22px;
    background:rgba(255,255,255,.09);
}
.sales-color-card>*{position:relative;z-index:2}
.sales-color-card,
.sales-color-card h1,.sales-color-card h2,.sales-color-card h3,
.sales-color-card h4,.sales-color-card h5,.sales-color-card h6,
.sales-color-card .text-primary,.sales-color-card .text-success,
.sales-color-card .text-danger,.sales-color-card .text-warning,
.sales-color-card .text-info,.sales-color-card .text-secondary,
.sales-color-card .text-dark,.sales-color-card .text-muted,
.sales-color-card .small,.sales-color-card small,
.sales-color-card .fw-bold{color:#fff!important}
.sales-color-card .text-muted,.sales-color-card small,.sales-color-card .small{opacity:.84!important}

.sales-card-blue{background:linear-gradient(135deg,#3154e8 0%,#667eea 100%)!important}
.sales-card-green{background:linear-gradient(135deg,#10986a 0%,#2bbf8a 100%)!important}
.sales-card-red{background:linear-gradient(135deg,#d94a62 0%,#f06a7d 100%)!important}
.sales-card-orange{background:linear-gradient(135deg,#d98b16 0%,#f6b73c 100%)!important}
.sales-card-purple{background:linear-gradient(135deg,#7652d9 0%,#9b72ef 100%)!important}
.sales-card-cyan{background:linear-gradient(135deg,#0e8fa8 0%,#29b8d2 100%)!important}
</style>


<style>
/* ===== Quick Dashboard colorful UI ===== */
.quick-dashboard-ui{
    border:1px solid #e6eaf0!important;
    border-radius:18px!important;
    box-shadow:0 10px 28px rgba(16,24,40,.07)!important;
    overflow:hidden;
}
.quick-dashboard-ui .quick-color-card{
    position:relative;
    overflow:hidden;
    border:0!important;
    color:#fff!important;
    border-radius:15px!important;
    box-shadow:0 8px 20px rgba(16,24,40,.11)!important;
    transition:transform .18s ease,box-shadow .18s ease;
}
.quick-dashboard-ui .quick-color-card:hover{
    transform:translateY(-3px);
    box-shadow:0 13px 27px rgba(16,24,40,.16)!important;
}
.quick-dashboard-ui .quick-color-card::after{
    content:"";
    position:absolute;
    width:78px;height:78px;border-radius:50%;
    right:-22px;bottom:-30px;
    background:rgba(255,255,255,.14);
}
.quick-dashboard-ui .quick-color-card>*{position:relative;z-index:1}
.quick-dashboard-ui .quick-color-card,
.quick-dashboard-ui .quick-color-card h1,
.quick-dashboard-ui .quick-color-card h2,
.quick-dashboard-ui .quick-color-card h3,
.quick-dashboard-ui .quick-color-card h4,
.quick-dashboard-ui .quick-color-card h5,
.quick-dashboard-ui .quick-color-card h6,
.quick-dashboard-ui .quick-color-card small,
.quick-dashboard-ui .quick-color-card .small,
.quick-dashboard-ui .quick-color-card .text-muted,
.quick-dashboard-ui .quick-color-card .fw-bold{
    color:#fff!important;
}
.quick-dashboard-ui .quick-color-card small,
.quick-dashboard-ui .quick-color-card .small,
.quick-dashboard-ui .quick-color-card .text-muted{opacity:.85}
.quick-q-blue{background:linear-gradient(135deg,#3154e8,#667eea)!important}
.quick-q-green{background:linear-gradient(135deg,#10986a,#2bbf8a)!important}
.quick-q-orange{background:linear-gradient(135deg,#d98b16,#f6b73c)!important}
.quick-q-purple{background:linear-gradient(135deg,#7652d9,#9b72ef)!important}
.quick-q-cyan{background:linear-gradient(135deg,#0e8fa8,#29b8d2)!important}
.quick-q-red{background:linear-gradient(135deg,#d94a62,#f06a7d)!important}
</style>


<style>
/* Tabby / Tamara Quick Dashboard card */
.tamara-tabby-card{
    position:relative;
    overflow:hidden;
    border:0!important;
    color:#fff!important;
    background:linear-gradient(135deg,#f1506f,#e23d61)!important;
    box-shadow:0 8px 20px rgba(241,80,111,.22)!important;
    transition:transform .18s ease,box-shadow .18s ease;
}
.tamara-tabby-card:hover{
    transform:translateY(-3px);
    box-shadow:0 13px 27px rgba(241,80,111,.29)!important;
}
.tamara-tabby-card::after{
    content:"";position:absolute;width:76px;height:76px;border-radius:50%;
    right:-20px;bottom:-29px;background:rgba(255,255,255,.15);
}
.tamara-tabby-card>*{position:relative;z-index:1;color:#fff!important}
.tamara-tabby-card small{opacity:.86}
</style>

<style>
/* ===== Remaining Quick Dashboard cards ===== */
.quick-pay-card{
    position:relative;
    overflow:hidden;
    border:0!important;
    color:#fff!important;
    box-shadow:0 8px 20px rgba(16,24,40,.12)!important;
    transition:transform .18s ease,box-shadow .18s ease;
}
.quick-pay-card:hover{
    transform:translateY(-3px);
    box-shadow:0 13px 27px rgba(16,24,40,.17)!important;
}
.quick-pay-card::after{
    content:"";position:absolute;width:76px;height:76px;border-radius:50%;
    right:-20px;bottom:-29px;background:rgba(255,255,255,.15);
}
.quick-pay-card>*{position:relative;z-index:1;color:#fff!important}
.quick-pay-card .text-muted,.quick-pay-card small{color:rgba(255,255,255,.86)!important}
.quick-cash{background:linear-gradient(135deg,#10986a,#2bbf8a)!important}
.quick-cardpay{background:linear-gradient(135deg,#3154e8,#667eea)!important}
.quick-drawer{background:linear-gradient(135deg,#7652d9,#9b72ef)!important}
</style>

<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main">
  <div class="topbar">
    <div class="d-flex gap-2 align-items-start"><button class="btn btn-light border mobile-toggle" id="sidebarToggle" type="button"><i class="bi bi-list"></i></button><div class="welcome"><h1>Stock Check</h1><p>Inventory lookup only · no sale or stock deduction</p></div></div>
    <div class="user-chip"><div class="avatar"><?php echo h(strtoupper(substr($displayName,0,1))); ?></div><div class="user-text"><div class="fw-bold small"><?php echo h($displayName); ?></div><div class="text-muted" style="font-size:.7rem"><?php echo h(ucfirst($role)); ?></div></div></div>
  </div>
  <section class="hero"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3"><div><div class="small text-uppercase fw-bold opacity-75 mb-1">Inventory Control</div><h2>Scan. Check. Confirm stock.</h2><p>Fast barcode lookup across products, phones, laptops, tools and Etisalat / du recharges.</p></div><div class="page-actions"><?php if ($role !== 'viewer'): ?><a href="add_product.php" class="btn btn-light fw-bold"><i class="bi bi-plus-lg me-1"></i>Add Product</a><a href="view_products.php" class="btn btn-outline-light fw-bold"><i class="bi bi-box-seam me-1"></i>Products</a><?php endif; ?></div></div></section>
  <div class="container-fluid px-0">
    <?php echo $message; ?>

    <div class="row g-3 mb-4 stock-kpis">
        <div class="col-md-3">
            <div class="card terminal-card metric-card text-center sales-color-card sales-card-blue">
                <div class="text-muted small fw-bold">Products Units</div>
                <div class="fs-3 fw-bold text-primary"><?php echo number_format($dash['product_units']); ?></div>
                <small><?php echo number_format($dash['product_count']); ?> product rows</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card terminal-card metric-card text-center sales-color-card sales-card-green">
                <div class="text-muted small fw-bold">Inventory Cost Value</div>
                <div class="fs-3 fw-bold text-success"><?php echo money_clean($dash['product_value']); ?> AED</div>
                <small>Products only</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card terminal-card metric-card text-center sales-color-card sales-card-red">
                <div class="text-muted small fw-bold">Phones / Laptops / Tools</div>
                <div class="fs-3 fw-bold text-dark"><?php echo number_format($dash['phone_units'] + $dash['laptop_units'] + $dash['tool_units']); ?></div>
                <small>Phones: <?php echo number_format($dash['phone_units']); ?> | Laptops: <?php echo number_format($dash['laptop_units']); ?> | Tools: <?php echo number_format($dash['tool_units']); ?></small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card terminal-card metric-card text-center sales-color-card sales-card-orange">
                <div class="text-muted small fw-bold">Stock Alerts</div>
                <div class="fs-3 fw-bold text-danger"><?php echo number_format($dash['out_stock']); ?></div>
                <small>Out: <?php echo number_format($dash['out_stock']); ?> | Low: <?php echo number_format($dash['low_stock']); ?></small>
            </div>
        </div>
    </div>

    <div class="card terminal-card p-4 mb-4 border-start border-primary border-5 sales-color-card sales-card-purple">
        <h4 class="fw-bold mb-3">🔎 Scan Barcode to Check Item Details</h4>
        <form method="POST" class="row g-3 align-items-end">
            <div class="col-md-9">
                <label for="barcode_scan" class="form-label fw-bold text-muted">Barcode / SKU / IMEI</label>
                <input type="text" class="form-control form-control-lg barcode-focus-box text-center fw-bold fs-4" id="barcode_scan" name="barcode_scan" value="<?php echo h($barcode_input); ?>" placeholder="Scan or type barcode..." autocomplete="off" autofocus required>
                <div class="form-text text-center">This page only checks stock. It does not sell, deduct, or edit stock.</div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">Check Stock</button>
            </div>
        </form>
    </div>

    <?php if (!empty($product_matches) || !empty($phone_matches) || !empty($laptop_matches) || !empty($tool_matches) || !empty($recharge_matches)): ?>
        <div class="card terminal-card p-4 mb-4 sales-color-card sales-card-cyan">
            <h4 class="fw-bold mb-3">✅ Scan Results</h4>

            <?php if (!empty($product_matches)): ?>
                <h5 class="fw-bold text-primary mb-3">📦 Product Inventory</h5>
                <div class="row g-3 mb-4">
                    <?php foreach ($product_matches as $p): ?>
                        <?php
                            $cost = decode_product_cost(get_col($p, 'cost_price'));
                            $qty = (int)get_col($p, 'quantity', 0);
                            $stockValue = $qty * $cost;
                            $salePriceText = array_key_exists('sale_price', $p) ? money_clean($p['sale_price']) . ' AED' : '—';
                        ?>
                        <div class="col-lg-6">
                            <div class="card result-card p-3 h-100">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="fw-bold mb-0"><?php echo h(get_col($p, 'product_name', 'Product')); ?></h5>
                                    <?php echo stock_badge($qty); ?>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6"><div class="info-label">Barcode</div><div class="info-value"><?php echo h(get_col($p, 'barcode')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Product ID</div><div class="info-value">#<?php echo h(get_col($p, 'id')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Model</div><div class="info-value"><?php echo h(get_col($p, 'model_name', '—')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Category</div><div class="info-value"><?php echo h(get_col($p, 'category_name', 'Uncategorized')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Quantity Available</div><div class="info-value fs-4 text-success"><?php echo number_format($qty); ?></div></div>
                                    <div class="col-6"><div class="info-label">Cost / Item</div><div class="info-value text-danger"><?php echo money_clean($cost); ?> AED</div></div>
                                    <div class="col-6"><div class="info-label">Cost Code</div><div class="info-value"><?php echo h(get_col($p, 'cost_price', '—')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Sale Price</div><div class="info-value text-primary"><?php echo h($salePriceText); ?></div></div>
                                    <div class="col-12"><div class="info-label">Stock Cost Value</div><div class="info-value"><?php echo money_clean($stockValue); ?> AED</div></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($phone_matches)): ?>
                <h5 class="fw-bold text-success mb-3">📱 Phones</h5>
                <div class="row g-3 mb-4">
                    <?php foreach ($phone_matches as $p): ?>
                        <?php $cost = decode_phone_like_cost(get_col($p, 'cost_price', get_col($p, 'cost', 0))); $qty = (int)get_col($p, 'quantity', 0); ?>
                        <div class="col-lg-6">
                            <div class="card result-card p-3 h-100 border-success">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="fw-bold mb-0"><?php echo h(trim(get_col($p, 'brand') . ' ' . get_col($p, 'model_name'))); ?></h5>
                                    <?php echo status_badge(get_col($p, 'status')); ?>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6"><div class="info-label">Barcode / IMEI</div><div class="info-value"><?php echo h(get_col($p, 'barcode')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Phone ID</div><div class="info-value">#<?php echo h(get_col($p, 'id')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Brand</div><div class="info-value"><?php echo h(get_col($p, 'brand', '—')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Model</div><div class="info-value"><?php echo h(get_col($p, 'model_name', '—')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Quantity</div><div class="info-value fs-4 text-success"><?php echo number_format($qty); ?></div></div>
                                    <div class="col-6"><div class="info-label">Cost</div><div class="info-value text-danger"><?php echo money_clean($cost); ?> AED</div></div>
                                    <?php if (array_key_exists('phone_type', $p)): ?><div class="col-6"><div class="info-label">Phone Type</div><div class="info-value"><?php echo h(get_col($p, 'phone_type', '—')); ?></div></div><?php endif; ?>
                                    <div class="col-6"><div class="info-label">Condition</div><div class="info-value"><?php echo h(get_col($p, 'condition', '—')); ?></div></div>
                                    <div class="col-12"><div class="info-label">Details</div><div class="info-value"><?php echo h(get_col($p, 'details', '—')); ?></div></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($laptop_matches)): ?>
                <h5 class="fw-bold text-secondary mb-3">💻 Laptops</h5>
                <div class="row g-3 mb-4">
                    <?php foreach ($laptop_matches as $p): ?>
                        <?php $cost = decode_phone_like_cost(get_col($p, 'cost_price', get_col($p, 'cost', 0))); $qty = (int)get_col($p, 'quantity', 0); ?>
                        <div class="col-lg-6">
                            <div class="card result-card p-3 h-100 border-secondary">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="fw-bold mb-0"><?php echo h(trim(get_col($p, 'brand') . ' ' . get_col($p, 'model_name'))); ?></h5>
                                    <?php echo status_badge(get_col($p, 'status')); ?>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6"><div class="info-label">Barcode</div><div class="info-value"><?php echo h(get_col($p, 'barcode')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Laptop ID</div><div class="info-value">#<?php echo h(get_col($p, 'id')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Brand</div><div class="info-value"><?php echo h(get_col($p, 'brand', '—')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Model</div><div class="info-value"><?php echo h(get_col($p, 'model_name', '—')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Quantity</div><div class="info-value fs-4 text-success"><?php echo number_format($qty); ?></div></div>
                                    <div class="col-6"><div class="info-label">Cost</div><div class="info-value text-danger"><?php echo money_clean($cost); ?> AED</div></div>
                                    <div class="col-6"><div class="info-label">Condition</div><div class="info-value"><?php echo h(get_col($p, 'condition', '—')); ?></div></div>
                                    <div class="col-12"><div class="info-label">Details</div><div class="info-value"><?php echo h(get_col($p, 'details', '—')); ?></div></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($tool_matches)): ?>
                <h5 class="fw-bold text-warning mb-3">🛠️ Tools / Mobile Parts</h5>
                <div class="row g-3">
                    <?php foreach ($tool_matches as $p): ?>
                        <?php $cost = decode_phone_like_cost(get_col($p, 'cost_price', get_col($p, 'cost', 0))); $qty = (int)get_col($p, 'quantity', 0); ?>
                        <div class="col-lg-6">
                            <div class="card result-card p-3 h-100 border-warning">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="fw-bold mb-0"><?php echo h(trim(get_col($p, 'brand') . ' ' . get_col($p, 'model_name'))); ?></h5>
                                    <?php echo status_badge(get_col($p, 'status')); ?>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6"><div class="info-label">Barcode</div><div class="info-value"><?php echo h(get_col($p, 'barcode')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Tool ID</div><div class="info-value">#<?php echo h(get_col($p, 'id')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Brand</div><div class="info-value"><?php echo h(get_col($p, 'brand', '—')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Model</div><div class="info-value"><?php echo h(get_col($p, 'model_name', '—')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Type</div><div class="info-value"><?php echo h(get_col($p, 'type', '—')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Quality</div><div class="info-value"><?php echo h(get_col($p, 'quality', '—')); ?></div></div>
                                    <div class="col-6"><div class="info-label">Quantity</div><div class="info-value fs-4 text-success"><?php echo number_format($qty); ?></div></div>
                                    <div class="col-6"><div class="info-label">Cost</div><div class="info-value text-danger"><?php echo money_clean($cost); ?> AED</div></div>
                                    <div class="col-6"><div class="info-label">Condition</div><div class="info-value"><?php echo h(get_col($p, 'condition', '—')); ?></div></div>
                                    <div class="col-12"><div class="info-label">Details</div><div class="info-value"><?php echo h(get_col($p, 'details', '—')); ?></div></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($recharge_matches)): ?>
                <h5 class="fw-bold text-info mb-3 mt-3">📲 Etisalat / du Recharge</h5>
                <div class="row g-3">
                    <?php foreach ($recharge_matches as $r): ?>
                        <div class="col-lg-6">
                            <div class="card result-card p-3 h-100 border-info">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="fw-bold mb-0"><?php echo h($r['label'] ?: ($r['provider'] . ' Recharge')); ?></h5>
                                    <span class="badge bg-info text-dark">RECHARGE</span>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6"><div class="info-label">Provider</div><div class="info-value"><?php echo h($r['provider']); ?></div></div>
                                    <div class="col-6"><div class="info-label">Barcode</div><div class="info-value"><?php echo h($r['barcode']); ?></div></div>
                                    <div class="col-6"><div class="info-label">Sale Amount</div><div class="info-value text-success"><?php echo ((float)$r['amount'] > 0) ? money_clean($r['amount']) . ' AED' : 'Enter each time'; ?></div></div>
                                    <div class="col-6"><div class="info-label">Cost</div><div class="info-value text-danger"><?php echo ((float)$r['cost_amount'] > 0) ? money_clean($r['cost_amount']) . ' AED' : '0 / Variable'; ?></div></div>
                                    <div class="col-12"><div class="info-label">Description</div><div class="info-value"><?php echo h($r['provider'] . ' Recharge'); ?></div></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>


        </div>
    <?php endif; ?>

    <div class="quick-dashboard-ui card terminal-card p-4 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 align-items-md-center mb-3">
            <div>
                <h4 class="fw-bold m-0">📊 Quick Dashboard</h4>
                <p class="text-muted m-0 small">Today and this month summary. This page still does not create sales.</p>
            </div>
            <a href="reports.php" class="btn btn-outline-dark fw-bold">Open Full Reports</a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="dash-card p-3 bg-success bg-opacity-10 border border-success text-success h-100 quick-color-card quick-q-blue">
                    <div class="small fw-bold">Today Sales</div>
                    <div class="dash-number"><?php echo money_clean($dashboard_report['today_sales']); ?> AED</div>
                    <small class="text-dark"><?php echo number_format($dashboard_report['today_transactions']); ?> transactions</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dash-card p-3 bg-primary bg-opacity-10 border border-primary text-primary h-100 quick-color-card quick-q-green">
                    <div class="small fw-bold">Today Profit</div>
                    <div class="dash-number"><?php echo money_clean($dashboard_report['today_profit']); ?> AED</div>
                    <small class="text-dark">Cost: <?php echo money_clean($dashboard_report['today_cost']); ?> AED</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dash-card p-3 bg-info bg-opacity-10 border border-info text-info h-100 quick-color-card quick-q-orange">
                    <div class="small fw-bold">This Month Sales</div>
                    <div class="dash-number"><?php echo money_clean($dashboard_report['month_sales']); ?> AED</div>
                    <small class="text-dark">Profit: <?php echo money_clean($dashboard_report['month_profit']); ?> AED</small>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl col-md-4 col-sm-6">
                <div class="p-3 rounded h-100 text-center quick-pay-card quick-cash">
                    <div class="text-muted small fw-bold">Cash Today</div>
                    <div class="fs-4 fw-bold text-success"><?php echo money_clean($dashboard_report['cash_total']); ?> AED</div>
                </div>
            </div>
            <div class="col-xl col-md-4 col-sm-6">
                <div class="p-3 rounded h-100 text-center quick-pay-card quick-cardpay">
                    <div class="text-muted small fw-bold">Card Today</div>
                    <div class="fs-4 fw-bold text-info"><?php echo money_clean($dashboard_report['card_total']); ?> AED</div>
                    <small>Fee: <?php echo money_clean($dashboard_report['today_card_fee']); ?> AED</small>
                </div>
            </div>
            <div class="col-xl col-md-4 col-sm-6">
                <div class="p-3 rounded h-100 text-center tamara-tabby-card">
                    <div class="small fw-bold">Tabby / Tamara Today</div>
                    <div class="fs-4 fw-bold"><?php echo money_clean($dashboard_report['tamara_tabby_total']); ?> AED</div>
                    <small>تابي / تمارا</small>
                </div>
            </div>
            
            <div class="col-xl col-md-4 col-sm-6">
                <div class="p-3 rounded h-100 text-center quick-pay-card quick-drawer">
                    <div class="text-muted small fw-bold">Withdraw / Deposit</div>
                    <div class="fw-bold text-danger">- <?php echo money_clean($dashboard_report['withdrawals']); ?> AED</div>
                    <div class="fw-bold text-success">+ <?php echo money_clean($dashboard_report['deposits']); ?> AED</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <h6 class="fw-bold">Low / Out Stock Products</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mini-table mb-0">
                        <thead class="table-light"><tr><th>Item</th><th>Category</th><th class="text-end">Qty</th></tr></thead>
                        <tbody>
                        <?php if (empty($low_stock_preview)): ?>
                            <tr><td colspan="3" class="text-center text-muted">No low-stock products.</td></tr>
                        <?php else: ?>
                            <?php foreach ($low_stock_preview as $ls): ?>
                                <tr>
                                    <td><div class="fw-semibold"><?php echo h(mb_strimwidth(($ls['product_name'] ?? '') . ' ' . ($ls['model_name'] ?? ''), 0, 32, '...')); ?></div><small><?php echo h($ls['barcode'] ?? ''); ?></small></td>
                                    <td><?php echo h($ls['category_name'] ?? 'Uncategorized'); ?></td>
                                    <td class="text-end fw-bold <?php echo ((int)$ls['quantity'] <= 0 ? 'text-danger' : 'text-warning'); ?>"><?php echo number_format((int)$ls['quantity']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-6">
                <h6 class="fw-bold">Inventory by Category</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mini-table mb-0">
                        <thead class="table-light"><tr><th>Category</th><th class="text-end">Rows</th><th class="text-end">Units</th></tr></thead>
                        <tbody>
                        <?php if (empty($category_stock)): ?>
                            <tr><td colspan="3" class="text-center text-muted">No category data.</td></tr>
                        <?php else: ?>
                            <?php foreach ($category_stock as $cs): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo h($cs['category_name']); ?></td>
                                    <td class="text-end"><?php echo number_format((int)$cs['rows_count']); ?></td>
                                    <td class="text-end fw-bold"><?php echo number_format((int)$cs['units_count']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

  </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const scan = document.getElementById('barcode_scan');
    if (scan) scan.focus();
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');
    if (toggle && sidebar) toggle.addEventListener('click', function(){ sidebar.classList.toggle('open'); });
    document.addEventListener('click', function(e){
        if (window.innerWidth < 992 && sidebar && sidebar.classList.contains('open') && !sidebar.contains(e.target) && !toggle.contains(e.target)) sidebar.classList.remove('open');
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
