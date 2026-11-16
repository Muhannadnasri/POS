<?php
require_once __DIR__ . '/session_keepalive.php';
// monthly_report.php - End of Month Report for Rukn Al Lotus
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$card_fee_rate =  0.025; // VISA / CARD fee = 2.5%
$messages = [];
$REPORT_VERSION = 'v2.3.5 - selected month cost beside expenses';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money_aed($amount) {
    return number_format((float)$amount, 2) . ' AED';
}

function money_clean($amount) {
    $amount = (float)$amount;
    return (abs($amount - round($amount)) < 0.005) ? number_format($amount, 0) : number_format($amount, 2);
}

function table_exists_eom(PDO $pdo, $table) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function table_columns_eom(PDO $pdo, $table) {
    try {
        if (!table_exists_eom($pdo, $table)) return [];
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Exception $e) {
        return [];
    }
}

function column_exists_eom(array $columns, $column) {
    return in_array($column, $columns, true);
}

function clean_barcode_eom($value) {
    $value = (string)($value ?? '');
    $value = str_replace(["\xC2\xA0", "\xE2\x80\x8E", "\xE2\x80\x8F", "\t", "\r", "\n", ' '], '', $value);
    return trim($value);
}

function parse_aed_number_eom($value) {
    $text = trim((string)($value ?? ''));
    if ($text === '') return 0.0;
    $text = str_ireplace(['AED', 'DHS', 'DH', 'درهم', 'د.إ'], '', $text);
    $text = str_replace([',', ' '], '', $text);
    $text = preg_replace('/[^0-9.\-]/', '', $text);
    if ($text === '' || $text === '-' || !is_numeric($text)) return 0.0;
    return (float)$text;
}

function decode_product_cost_eom($value) {
    $digits = preg_replace('/\D+/', '', (string)($value ?? ''));
    if ($digits === '') return 0.0;

    // Product/accessory cost code: 962909700011 = 11 AED, 962909700211 = 211 AED.
    if (strpos($digits, '9629097') === 0 && strlen($digits) >= 10) {
        return (float)intval(substr($digits, -3));
    }

    return parse_aed_number_eom($value);
}

function decode_phone_like_cost_eom($value) {
    $digits = preg_replace('/\D+/', '', (string)($value ?? ''));
    if ($digits === '') return 0.0;

    // Phone/laptop/tool old code support: 962909703950 = 3950 AED, 962909700490 = 490 AED.
    if (strpos($digits, '9629097') === 0 && strlen($digits) >= 11) {
        return (float)intval(substr($digits, -4));
    }

    return parse_aed_number_eom($value);
}

function cost_for_source_eom($source, $value) {
    if ($source === 'products') return decode_product_cost_eom($value);
    return decode_phone_like_cost_eom($value);
}

function is_card_payment_eom($payment_method) {
    $method = strtoupper(trim((string)$payment_method));
    return in_array($method, ['VISA', 'CARD', 'CREDIT', 'DEBIT', 'VISA / CARD'], true);
}

function is_bank_payment_eom($payment_method) {
    $method = strtoupper(trim((string)$payment_method));
    return in_array($method, ['BANK_TRANSFER', 'BANK TRANSFER', 'TRANSFER', 'BANK'], true);
}

function is_tamara_tabby_payment_eom($payment_method) {
    $method = strtoupper(trim((string)$payment_method));
    return in_array($method, ['TAMARA / TABBY', 'TAMARA/TABBY', 'TAMARA', 'TABBY'], true);
}

function sale_cost_summary_for_month_card_eom(array $sale, array $maps, $card_fee_rate) {
    $qty = max(1, (int)($sale['qty_sold'] ?? 1));
    $gross = (float)($sale['total_amount'] ?? 0);
    if ($gross <= 0 && isset($sale['sale_price'])) {
        $gross = (float)$sale['sale_price'] * $qty;
    }

    $payment = strtoupper(trim((string)($sale['payment_method'] ?? 'CASH')));
    $card_fee = is_card_payment_eom($payment) ? ($gross * (float)$card_fee_rate) : 0.0;
    $net_revenue = $gross - $card_fee;

    $source = 'unmatched';
    $unit_cost = 0.0;
    $barcode = clean_barcode_eom($sale['barcode'] ?? '');

    $has_recharge = !empty($sale['recharge_id']) || strtoupper($barcode) === 'RECHARGE';
    if (!$has_recharge && stripos((string)($sale['item_name'] ?? ''), 'Recharge') !== false) {
        $has_recharge = true;
    }

    if ($has_recharge) {
        $source = 'recharges';
    } elseif (!empty($sale['phone_id']) && isset($maps['phones']['id'][(int)$sale['phone_id']])) {
        $source = 'phones';
        $unit_cost = cost_for_source_eom('phones', $maps['phones']['id'][(int)$sale['phone_id']]['cost_price'] ?? 0);
    } elseif (!empty($sale['product_id']) && isset($maps['products']['id'][(int)$sale['product_id']])) {
        $source = 'products';
        $unit_cost = cost_for_source_eom('products', $maps['products']['id'][(int)$sale['product_id']]['cost_price'] ?? 0);
    } elseif ($barcode !== '' && isset($maps['phones']['barcode'][$barcode])) {
        $source = 'phones';
        $unit_cost = cost_for_source_eom('phones', $maps['phones']['barcode'][$barcode]['cost_price'] ?? 0);
    } elseif ($barcode !== '' && isset($maps['laptops']['barcode'][$barcode])) {
        $source = 'laptops';
        $unit_cost = cost_for_source_eom('laptops', $maps['laptops']['barcode'][$barcode]['cost_price'] ?? 0);
    } elseif ($barcode !== '' && isset($maps['tools']['barcode'][$barcode])) {
        $source = 'tools';
        $unit_cost = cost_for_source_eom('tools', $maps['tools']['barcode'][$barcode]['cost_price'] ?? 0);
    } elseif ($barcode !== '' && isset($maps['products']['barcode'][$barcode])) {
        $source = 'products';
        $unit_cost = cost_for_source_eom('products', $maps['products']['barcode'][$barcode]['cost_price'] ?? 0);
    } else {
        $source = ($barcode === '' || strtoupper($barcode) === 'LEGACY_SERVICE') ? 'services' : 'unmatched';
    }

    if (isset($sale['imported_cost']) && $sale['imported_cost'] !== null && $sale['imported_cost'] !== '' && is_numeric($sale['imported_cost'])) {
        $unit_cost = (float)$sale['imported_cost'];
    }

    $cost_total = $unit_cost * $qty;
    $profit = $net_revenue - $cost_total;

    return [
        'qty' => $qty,
        'gross' => $gross,
        'cost' => $cost_total,
        'profit' => $profit,
        'card_fee' => $card_fee,
        'source' => $source,
    ];
}

function text_contains_sultan_eom($value) {
    $text = trim((string)($value ?? ''));
    if ($text === '') return false;

    // Match Arabic سلطان and English Sultan in withdrawal names/import notes.
    if (function_exists('mb_stripos')) {
        return mb_stripos($text, 'سلطان', 0, 'UTF-8') !== false || mb_stripos($text, 'sultan', 0, 'UTF-8') !== false;
    }

    return strpos($text, 'سلطان') !== false || stripos($text, 'sultan') !== false;
}

function valid_month_eom($value) {
    $value = trim((string)$value);
    return preg_match('/^\d{4}\-\d{2}$/', $value) ? $value : date('Y-m');
}

function ensure_monthly_expenses_table(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expense_name VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        expense_date DATE NOT NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL,
        INDEX idx_monthly_expenses_date (expense_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // New optional columns let the report safely convert a drawer withdrawal into a shop expense once.
    $cols = table_columns_eom($pdo, 'monthly_expenses');
    if (!column_exists_eom($cols, 'source_type')) {
        $pdo->exec("ALTER TABLE monthly_expenses ADD COLUMN source_type VARCHAR(50) DEFAULT NULL AFTER notes");
    }
    if (!column_exists_eom($cols, 'source_id')) {
        $pdo->exec("ALTER TABLE monthly_expenses ADD COLUMN source_id INT DEFAULT NULL AFTER source_type");
    }
    try { $pdo->exec("CREATE INDEX idx_monthly_expenses_source ON monthly_expenses (source_type, source_id)"); } catch (Exception $e) {}
}

function fetch_all_safe(PDO $pdo, $sql, array $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function map_by_id_and_barcode(array $rows) {
    $byId = [];
    $byBarcode = [];
    foreach ($rows as $row) {
        if (isset($row['id'])) {
            $byId[(int)$row['id']] = $row;
        }
        $barcode = clean_barcode_eom($row['barcode'] ?? '');
        if ($barcode !== '') {
            // Keep newest/highest id as preferred barcode match.
            if (!isset($byBarcode[$barcode]) || (int)($row['id'] ?? 0) > (int)($byBarcode[$barcode]['id'] ?? 0)) {
                $byBarcode[$barcode] = $row;
            }
        }
    }
    return ['id' => $byId, 'barcode' => $byBarcode];
}

function inventory_left_value(array $rows, $source) {
    $units = 0;
    $value = 0.0;
    $items = 0;
    foreach ($rows as $row) {
        $qty = max(0, (int)($row['quantity'] ?? 0));
        $cost = cost_for_source_eom($source, $row['cost_price'] ?? 0);
        $items++;
        $units += $qty;
        $value += ($qty * $cost);
    }
    return ['items' => $items, 'units' => $units, 'value' => $value];
}

ensure_monthly_expenses_table($pdo);

$month = valid_month_eom($_GET['month'] ?? date('Y-m'));
$month_start = $month . '-01';
$start_date = date('Y-m-01', strtotime($month_start));
$end_date = date('Y-m-t', strtotime($month_start));
$start_dt = $start_date . ' 00:00:00';
$end_dt_exclusive = date('Y-m-d 00:00:00', strtotime($end_date . ' +1 day'));

$movement_filter = strtolower(trim((string)($_GET['movement_filter'] ?? 'all')));
if (!in_array($movement_filter, ['all', 'withdrawals', 'deposits'], true)) {
    $movement_filter = 'all';
}
$movement_search = trim((string)($_GET['movement_search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_month = valid_month_eom($_POST['month'] ?? $month);

    try {
        if (isset($_POST['add_expense'])) {
            $expense_name = trim((string)($_POST['expense_name'] ?? ''));
            $expense_amount = parse_aed_number_eom($_POST['expense_amount'] ?? 0);
            $expense_date = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));
            $notes = trim((string)($_POST['expense_notes'] ?? ''));

            $dt = DateTime::createFromFormat('Y-m-d', $expense_date);
            if (!$dt || $dt->format('Y-m-d') !== $expense_date) {
                $expense_date = date('Y-m-d');
            }

            if ($expense_name === '' || $expense_amount <= 0) {
                throw new Exception('Please enter expense name and amount greater than 0.');
            }

            $stmt = $pdo->prepare("INSERT INTO monthly_expenses (expense_name, amount, expense_date, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$expense_name, $expense_amount, $expense_date, $notes]);
            header('Location: monthly_report.php?month=' . urlencode(substr($expense_date, 0, 7)) . '&expense_added=1');
            exit;
        }

        if (isset($_POST['delete_expense'])) {
            $id = (int)($_POST['expense_id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM monthly_expenses WHERE id = ?");
                $stmt->execute([$id]);
            }
            header('Location: monthly_report.php?month=' . urlencode($post_month) . '&expense_deleted=1');
            exit;
        }

        if (isset($_POST['add_withdrawal_expense'])) {
            $movement_id = (int)($_POST['movement_id'] ?? 0);
            if ($movement_id <= 0 || !table_exists_eom($pdo, 'drawer_withdrawals')) {
                throw new Exception('Withdrawal row not found.');
            }

            $stmt = $pdo->prepare("SELECT * FROM drawer_withdrawals WHERE id = ? LIMIT 1");
            $stmt->execute([$movement_id]);
            $movement = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$movement) {
                throw new Exception('Withdrawal row not found.');
            }

            $amount = (float)($movement['amount'] ?? 0);
            if ($amount <= 0) {
                throw new Exception('Only withdrawal rows can be added as shop expenses.');
            }

            $movement_date = date('Y-m-d', strtotime((string)($movement['withdrawn_at'] ?? date('Y-m-d'))));
            $expense_name = trim((string)($movement['name'] ?? 'Withdrawal'));
            if ($expense_name === '') $expense_name = 'Withdrawal';

            $check = $pdo->prepare("SELECT id FROM monthly_expenses WHERE source_type = 'drawer_withdrawal' AND source_id = ? LIMIT 1");
            $check->execute([$movement_id]);
            if (!$check->fetchColumn()) {
                $notes = 'Created from drawer withdrawal #' . $movement_id;
                $stmt = $pdo->prepare("INSERT INTO monthly_expenses (expense_name, amount, expense_date, notes, source_type, source_id) VALUES (?, ?, ?, ?, 'drawer_withdrawal', ?)");
                $stmt->execute([$expense_name, $amount, $movement_date, $notes, $movement_id]);
            }

            header('Location: monthly_report.php?month=' . urlencode(substr($movement_date, 0, 7)) . '&expense_added=1');
            exit;
        }


        if (isset($_POST['add_filtered_withdrawals_expense']) || isset($_POST['add_selected_withdrawals_expense'])) {
            if (!table_exists_eom($pdo, 'drawer_withdrawals')) {
                throw new Exception('drawer_withdrawals table not found.');
            }

            $movement_ids = $_POST['movement_ids'] ?? [];
            if (!is_array($movement_ids)) $movement_ids = [];
            $movement_ids = array_values(array_unique(array_filter(array_map('intval', $movement_ids))));
            if (empty($movement_ids)) {
                throw new Exception('No selected withdrawal rows to add.');
            }

            $added_count = 0;
            $first_month = $post_month;
            $selectStmt = $pdo->prepare("SELECT * FROM drawer_withdrawals WHERE id = ? LIMIT 1");
            $checkStmt = $pdo->prepare("SELECT id FROM monthly_expenses WHERE source_type = 'drawer_withdrawal' AND source_id = ? LIMIT 1");
            $insertStmt = $pdo->prepare("INSERT INTO monthly_expenses (expense_name, amount, expense_date, notes, source_type, source_id) VALUES (?, ?, ?, ?, 'drawer_withdrawal', ?)");

            foreach ($movement_ids as $movement_id) {
                $selectStmt->execute([$movement_id]);
                $movement = $selectStmt->fetch(PDO::FETCH_ASSOC);
                if (!$movement) continue;

                $amount = (float)($movement['amount'] ?? 0);
                if ($amount <= 0) continue;

                $checkStmt->execute([$movement_id]);
                if ($checkStmt->fetchColumn()) continue;

                $movement_date = date('Y-m-d', strtotime((string)($movement['withdrawn_at'] ?? date('Y-m-d'))));
                $first_month = substr($movement_date, 0, 7);
                $expense_name = trim((string)($movement['name'] ?? 'Withdrawal'));
                if ($expense_name === '') $expense_name = 'Withdrawal';
                $notes = 'Created from filtered drawer withdrawal #' . $movement_id;
                $insertStmt->execute([$expense_name, $amount, $movement_date, $notes, $movement_id]);
                $added_count++;
            }

            header('Location: monthly_report.php?month=' . urlencode($first_month) . '&bulk_expense_added=' . intval($added_count));
            exit;
        }
    } catch (Exception $e) {
        $messages[] = ['danger', $e->getMessage()];
    }
}

if (isset($_GET['expense_added'])) $messages[] = ['success', 'Expense added successfully.'];
if (isset($_GET['expense_deleted'])) $messages[] = ['success', 'Expense deleted successfully.'];
if (isset($_GET['bulk_expense_added'])) $messages[] = ['success', intval($_GET['bulk_expense_added']) . ' withdrawal row(s) added to shop expenses.'];

// -----------------------------
// Load inventory tables
// -----------------------------
$products = table_exists_eom($pdo, 'products') ? fetch_all_safe($pdo, "SELECT * FROM products") : [];
$phones   = table_exists_eom($pdo, 'phones') ? fetch_all_safe($pdo, "SELECT * FROM phones") : [];
$laptops  = table_exists_eom($pdo, 'laptops') ? fetch_all_safe($pdo, "SELECT * FROM laptops") : [];
$tools    = table_exists_eom($pdo, 'tool_items') ? fetch_all_safe($pdo, "SELECT * FROM tool_items") : [];

$maps = [
    'products' => map_by_id_and_barcode($products),
    'phones'   => map_by_id_and_barcode($phones),
    'laptops'  => map_by_id_and_barcode($laptops),
    'tools'    => map_by_id_and_barcode($tools),
];

// -----------------------------
// Current stock left in shop
// -----------------------------
$left = [
    'products' => inventory_left_value($products, 'products'),
    'phones'   => inventory_left_value($phones, 'phones'),
    'laptops'  => inventory_left_value($laptops, 'laptops'),
    'tools'    => inventory_left_value($tools, 'tools'),
];
$total_left_cost = $left['products']['value'] + $left['phones']['value'] + $left['laptops']['value'] + $left['tools']['value'];
$total_left_units = $left['products']['units'] + $left['phones']['units'] + $left['laptops']['units'] + $left['tools']['units'];

// -----------------------------
// Sales and sold costs
// -----------------------------
$sales = [];
$sales_columns = table_columns_eom($pdo, 'sales_records');
if (!empty($sales_columns) && column_exists_eom($sales_columns, 'sold_at')) {
    $sales = fetch_all_safe($pdo, "SELECT * FROM sales_records WHERE sold_at >= ? AND sold_at < ? ORDER BY sold_at ASC, id ASC", [$start_dt, $end_dt_exclusive]);
} else {
    $messages[] = ['warning', 'sales_records table or sold_at column not found. Sales/profit totals may show zero.'];
}

$sold_cost_by_source = [
    'products' => ['qty' => 0, 'cost' => 0.0, 'sales' => 0.0, 'profit' => 0.0],
    'phones'   => ['qty' => 0, 'cost' => 0.0, 'sales' => 0.0, 'profit' => 0.0],
    'laptops'  => ['qty' => 0, 'cost' => 0.0, 'sales' => 0.0, 'profit' => 0.0],
    'tools'    => ['qty' => 0, 'cost' => 0.0, 'sales' => 0.0, 'profit' => 0.0],
    'recharges'=> ['qty' => 0, 'cost' => 0.0, 'sales' => 0.0, 'profit' => 0.0],
    'services' => ['qty' => 0, 'cost' => 0.0, 'sales' => 0.0, 'profit' => 0.0],
    'unmatched'=> ['qty' => 0, 'cost' => 0.0, 'sales' => 0.0, 'profit' => 0.0],
];

$total_sales = 0.0;
$total_units_sold = 0;
$total_sold_cost = 0.0;
$total_card_fees = 0.0;
$total_gross_profit_before_expense = 0.0;
$cash_total = 0.0;
$card_total = 0.0;
$tamara_total = 0.0;
$bank_total = 0.0;
$other_total = 0.0;

foreach ($sales as $sale) {
    $qty = max(1, (int)($sale['qty_sold'] ?? 1));
    $gross = (float)($sale['total_amount'] ?? 0);
    if ($gross <= 0 && isset($sale['sale_price'])) {
        $gross = (float)$sale['sale_price'] * $qty;
    }

    $payment = strtoupper(trim((string)($sale['payment_method'] ?? 'CASH')));
    $card_fee = is_card_payment_eom($payment) ? ($gross * $card_fee_rate) : 0.0;
    $net_revenue = $gross - $card_fee;

    if ($payment === 'CASH' || $payment === '') {
        $cash_total += $gross;
    } elseif (is_card_payment_eom($payment)) {
        $card_total += $gross;
    } elseif (is_tamara_tabby_payment_eom($payment)) {
        $tamara_total += $gross;
    } elseif (is_bank_payment_eom($payment)) {
        $bank_total += $gross;
    } else {
        $other_total += $gross;
    }

    $source = 'unmatched';
    $unit_cost = 0.0;
    $barcode = clean_barcode_eom($sale['barcode'] ?? '');

    $has_recharge = !empty($sale['recharge_id']) || strtoupper($barcode) === 'RECHARGE';
    if (!$has_recharge && stripos((string)($sale['item_name'] ?? ''), 'Recharge') !== false) {
        $has_recharge = true;
    }

    if ($has_recharge) {
        $source = 'recharges';
    } elseif (!empty($sale['phone_id']) && isset($maps['phones']['id'][(int)$sale['phone_id']])) {
        $source = 'phones';
        $unit_cost = cost_for_source_eom('phones', $maps['phones']['id'][(int)$sale['phone_id']]['cost_price'] ?? 0);
    } elseif (!empty($sale['product_id']) && isset($maps['products']['id'][(int)$sale['product_id']])) {
        $source = 'products';
        $unit_cost = cost_for_source_eom('products', $maps['products']['id'][(int)$sale['product_id']]['cost_price'] ?? 0);
    } elseif ($barcode !== '' && isset($maps['phones']['barcode'][$barcode])) {
        $source = 'phones';
        $unit_cost = cost_for_source_eom('phones', $maps['phones']['barcode'][$barcode]['cost_price'] ?? 0);
    } elseif ($barcode !== '' && isset($maps['laptops']['barcode'][$barcode])) {
        $source = 'laptops';
        $unit_cost = cost_for_source_eom('laptops', $maps['laptops']['barcode'][$barcode]['cost_price'] ?? 0);
    } elseif ($barcode !== '' && isset($maps['tools']['barcode'][$barcode])) {
        $source = 'tools';
        $unit_cost = cost_for_source_eom('tools', $maps['tools']['barcode'][$barcode]['cost_price'] ?? 0);
    } elseif ($barcode !== '' && isset($maps['products']['barcode'][$barcode])) {
        $source = 'products';
        $unit_cost = cost_for_source_eom('products', $maps['products']['barcode'][$barcode]['cost_price'] ?? 0);
    } else {
        $source = ($barcode === '' || strtoupper($barcode) === 'LEGACY_SERVICE') ? 'services' : 'unmatched';
    }

    if (isset($sale['imported_cost']) && $sale['imported_cost'] !== null && $sale['imported_cost'] !== '' && is_numeric($sale['imported_cost'])) {
        $unit_cost = (float)$sale['imported_cost'];
    }

    $cost_total = $unit_cost * $qty;
    $profit = $net_revenue - $cost_total;

    $total_sales += $gross;
    $total_units_sold += $qty;
    $total_sold_cost += $cost_total;
    $total_card_fees += $card_fee;
    $total_gross_profit_before_expense += $profit;

    if (!isset($sold_cost_by_source[$source])) {
        $sold_cost_by_source[$source] = ['qty' => 0, 'cost' => 0.0, 'sales' => 0.0, 'profit' => 0.0];
    }
    $sold_cost_by_source[$source]['qty'] += $qty;
    $sold_cost_by_source[$source]['cost'] += $cost_total;
    $sold_cost_by_source[$source]['sales'] += $gross;
    $sold_cost_by_source[$source]['profit'] += $profit;
}

$total_inventory_sold_cost = $sold_cost_by_source['products']['cost'] + $sold_cost_by_source['phones']['cost'] + $sold_cost_by_source['laptops']['cost'] + $sold_cost_by_source['tools']['cost'];

// -----------------------------
// Withdrawals / deposits
// Convention already used in reports.php: amount > 0 withdrawal, amount < 0 deposit.
// -----------------------------
$drawer_movements = table_exists_eom($pdo, 'drawer_withdrawals') ? fetch_all_safe($pdo, "SELECT * FROM drawer_withdrawals WHERE withdrawn_at >= ? AND withdrawn_at < ? ORDER BY withdrawn_at ASC, id ASC", [$start_dt, $end_dt_exclusive]) : [];
$withdraw_total = 0.0;
$deposit_total = 0.0;
$filtered_drawer_movements = [];
$filtered_withdraw_total = 0.0;
$filtered_deposit_total = 0.0;
$filtered_movement_count = 0;
$sultan_withdrawals = [];
$sultan_withdrawal_total = 0.0;
$sultan_withdrawal_count = 0;
$sultan_deposits = [];
$sultan_deposit_total = 0.0;
$sultan_deposit_count = 0;
foreach ($drawer_movements as $movement) {
    $amount = (float)($movement['amount'] ?? 0);
    if ($amount > 0) $withdraw_total += $amount;
    if ($amount < 0) $deposit_total += abs($amount);

    $movement_text_for_sultan = (string)($movement['name'] ?? '') . ' ' . (string)($movement['import_source'] ?? '') . ' ' . (string)($movement['legacy_import_key'] ?? '');
    if ($amount > 0 && text_contains_sultan_eom($movement_text_for_sultan)) {
        $sultan_withdrawals[] = $movement;
        $sultan_withdrawal_total += $amount;
        $sultan_withdrawal_count++;
    }
    if ($amount < 0 && text_contains_sultan_eom($movement_text_for_sultan)) {
        $sultan_deposits[] = $movement;
        $sultan_deposit_total += abs($amount);
        $sultan_deposit_count++;
    }

    $include = true;
    if ($movement_filter === 'withdrawals' && $amount <= 0) $include = false;
    if ($movement_filter === 'deposits' && $amount >= 0) $include = false;
    if ($movement_search !== '') {
        $haystack = strtolower((string)($movement['name'] ?? '') . ' ' . (string)($movement['withdrawn_at'] ?? '') . ' ' . (string)($movement['amount'] ?? ''));
        if (strpos($haystack, strtolower($movement_search)) === false) $include = false;
    }

    if ($include) {
        $filtered_drawer_movements[] = $movement;
        $filtered_movement_count++;
        if ($amount > 0) $filtered_withdraw_total += $amount;
        if ($amount < 0) $filtered_deposit_total += abs($amount);
    }
}

// -----------------------------
// Expenses
// -----------------------------
$expenses = fetch_all_safe($pdo, "SELECT * FROM monthly_expenses WHERE expense_date >= ? AND expense_date <= ? ORDER BY expense_date ASC, id ASC", [$start_date, $end_date]);
$expense_total = 0.0;
$manual_expense_total = 0.0;
$withdrawal_expense_total = 0.0;
foreach ($expenses as $expense) {
    $amount = (float)($expense['amount'] ?? 0);
    $expense_total += $amount;
    if (($expense['source_type'] ?? '') === 'drawer_withdrawal') {
        $withdrawal_expense_total += $amount;
    } else {
        $manual_expense_total += $amount;
    }
}

$total_net_profit_after_expense = $total_gross_profit_before_expense - $expense_total;
$cash_drawer_expected = $cash_total + $deposit_total - $withdraw_total;
$cash_after_shop_expenses = $cash_drawer_expected - $expense_total;

// -----------------------------
// Recharges Etisalat / Du
// -----------------------------
$recharge_summary = ['count' => 0, 'amount_paid' => 0.0, 'cost' => 0.0, 'profit' => 0.0];
$recharges = [];
if (table_exists_eom($pdo, 'recharges')) {
    $recharge_cols = table_columns_eom($pdo, 'recharges');
    if (column_exists_eom($recharge_cols, 'created_at')) {
        $recharges = fetch_all_safe($pdo, "SELECT * FROM recharges WHERE created_at >= ? AND created_at < ? ORDER BY created_at ASC, id ASC", [$start_dt, $end_dt_exclusive]);
        foreach ($recharges as $r) {
            $provider = trim((string)($r['provider'] ?? ''));
            $provider_l = strtolower($provider);
            $is_du_etisalat = ($provider === '' || strpos($provider_l, 'du') !== false || strpos($provider_l, 'etisalat') !== false || strpos($provider, 'دو') !== false || strpos($provider, 'اتصالات') !== false);
            $status = trim((string)($r['status'] ?? 'Completed'));
            if (!$is_du_etisalat || strcasecmp($status, 'Completed') !== 0) {
                continue;
            }
            $paid = (float)($r['amount_paid'] ?? 0);
            $cost = (float)($r['cost_amount'] ?? 0);
            $recharge_summary['count']++;
            $recharge_summary['amount_paid'] += $paid;
            $recharge_summary['cost'] += $cost;
            $recharge_summary['profit'] += ($paid - $cost);
        }
    }
}


// -----------------------------
// Total cost from each month card (added only; existing cards remain unchanged)
// -----------------------------
$monthly_cost_rows = [];
$selected_month_cost_row = ['rows' => 0, 'qty' => 0, 'sales' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'card_fee' => 0.0];
if (!empty($sales_columns) && column_exists_eom($sales_columns, 'sold_at')) {
    $all_sales_for_monthly_cost = fetch_all_safe($pdo, "SELECT * FROM sales_records WHERE sold_at IS NOT NULL ORDER BY sold_at DESC, id DESC");
    foreach ($all_sales_for_monthly_cost as $sale_row_for_month) {
        $sold_at_value = (string)($sale_row_for_month['sold_at'] ?? '');
        if ($sold_at_value === '') continue;
        $month_key = date('Y-m', strtotime($sold_at_value));
        if (!$month_key || $month_key === '1970-01') continue;

        if (!isset($monthly_cost_rows[$month_key])) {
            $monthly_cost_rows[$month_key] = [
                'month' => $month_key,
                'rows' => 0,
                'qty' => 0,
                'sales' => 0.0,
                'cost' => 0.0,
                'profit' => 0.0,
                'card_fee' => 0.0,
            ];
        }

        $calc = sale_cost_summary_for_month_card_eom($sale_row_for_month, $maps, $card_fee_rate);
        $monthly_cost_rows[$month_key]['rows']++;
        $monthly_cost_rows[$month_key]['qty'] += (int)$calc['qty'];
        $monthly_cost_rows[$month_key]['sales'] += (float)$calc['gross'];
        $monthly_cost_rows[$month_key]['cost'] += (float)$calc['cost'];
        $monthly_cost_rows[$month_key]['profit'] += (float)$calc['profit'];
        $monthly_cost_rows[$month_key]['card_fee'] += (float)$calc['card_fee'];
    }
    krsort($monthly_cost_rows);
}
if (isset($monthly_cost_rows[$month])) {
    $selected_month_cost_row = $monthly_cost_rows[$month];
}

$source_labels = [
    'products' => 'Products / Accessories',
    'phones' => 'Phones',
    'laptops' => 'Laptops',
    'tools' => 'Tools / Spare Parts',
    'recharges' => 'Etisalat & Du Recharges',
    'services' => 'Services / Repairs',
    'unmatched' => 'Unmatched Barcode / Other',
];


// Visual analytics data (presentation only; all existing report calculations remain unchanged).
$chart_daily = [];
foreach ($sales as $sale) {
    $soldAt = (string)($sale['sold_at'] ?? '');
    if ($soldAt === '') continue;
    $day = date('Y-m-d', strtotime($soldAt));
    if (!isset($chart_daily[$day])) $chart_daily[$day] = ['sales'=>0.0,'cost'=>0.0,'profit'=>0.0,'withdraw'=>0.0,'deposit'=>0.0,'expense'=>0.0];
    $calc = sale_cost_summary_for_month_card_eom($sale, $maps, $card_fee_rate);
    $chart_daily[$day]['sales'] += (float)$calc['gross'];
    $chart_daily[$day]['cost'] += (float)$calc['cost'];
    $chart_daily[$day]['profit'] += (float)$calc['profit'];
}
foreach ($drawer_movements as $movement) {
    $day = date('Y-m-d', strtotime((string)($movement['withdrawn_at'] ?? $start_date)));
    if (!isset($chart_daily[$day])) $chart_daily[$day] = ['sales'=>0.0,'cost'=>0.0,'profit'=>0.0,'withdraw'=>0.0,'deposit'=>0.0,'expense'=>0.0];
    $amount = (float)($movement['amount'] ?? 0);
    if ($amount > 0) $chart_daily[$day]['withdraw'] += $amount;
    if ($amount < 0) $chart_daily[$day]['deposit'] += abs($amount);
}
foreach ($expenses as $expense) {
    $day = (string)($expense['expense_date'] ?? $start_date);
    if (!isset($chart_daily[$day])) $chart_daily[$day] = ['sales'=>0.0,'cost'=>0.0,'profit'=>0.0,'withdraw'=>0.0,'deposit'=>0.0,'expense'=>0.0];
    $chart_daily[$day]['expense'] += (float)($expense['amount'] ?? 0);
}
ksort($chart_daily);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>End of Month Report <?php echo h($REPORT_VERSION); ?> - Rukn Al Lotus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
:root{--rl-sidebar:#101828;--rl-sidebar-soft:#1d2939;--rl-bg:#f5f7fb;--rl-muted:#667085;--rl-border:#e4e7ec}
*{box-sizing:border-box}body{margin:0;background:var(--rl-bg);color:#101828;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.rl-app{min-height:100vh;display:flex}.rl-sidebar{width:258px;min-width:258px;background:var(--rl-sidebar);color:#fff;position:fixed;inset:0 auto 0 0;overflow-y:auto;z-index:1040;padding:20px 14px}
.rl-brand{display:flex;align-items:center;gap:12px;padding:4px 8px 20px;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:16px}.rl-brand-mark{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#3b82f6,#7c3aed);display:grid;place-items:center;font-weight:800}.rl-brand-title{font-weight:800}.rl-brand-sub{font-size:.72rem;color:#98a2b3}
.rl-nav-label{padding:13px 10px 6px;color:#667085;font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;font-weight:800}.rl-side-link{display:flex;align-items:center;gap:11px;color:#d0d5dd;text-decoration:none;padding:10px 11px;border-radius:10px;margin:2px 0;font-size:.88rem;font-weight:600}.rl-side-link:hover{background:var(--rl-sidebar-soft);color:#fff}.rl-side-link.active{background:#2563eb;color:#fff}.rl-side-link i{width:20px;text-align:center}.rl-role{margin-left:auto;font-size:.58rem;background:rgba(255,255,255,.12);padding:3px 6px;border-radius:6px}
.rl-main{margin-left:258px;width:calc(100% - 258px);min-height:100vh}.rl-topbar{min-height:78px;background:#fff;border-bottom:1px solid var(--rl-border);padding:15px 28px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1030}.rl-welcome h1{font-size:1.12rem;font-weight:800;margin:0}.rl-welcome p{margin:3px 0 0;color:var(--rl-muted);font-size:.78rem}.rl-user-chip{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid var(--rl-border);border-radius:12px;padding:7px 10px}.rl-avatar{width:34px;height:34px;border-radius:9px;background:#e8efff;color:#2457c5;display:grid;place-items:center;font-weight:800}.rl-mobile-toggle,.rl-overlay{display:none}
.report-card{border:1px solid var(--rl-border)!important;border-radius:16px!important;box-shadow:0 8px 24px rgba(16,24,40,.05)!important;background:#fff}.metric-label{font-size:.78rem;font-weight:800;letter-spacing:.02em;text-transform:uppercase;color:#667085}.metric-value{font-size:1.65rem;font-weight:900;margin:0}.small-muted{color:#667085;font-size:.88rem}.form-control,.form-select{border-color:#d0d5dd;border-radius:10px;min-height:42px}.btn{border-radius:9px}.table thead th{white-space:nowrap;font-size:.75rem;text-transform:uppercase;letter-spacing:.03em;color:#667085;background:#f9fafb}
.rl-chart-card{min-height:360px}.rl-chart-head{display:flex;justify-content:space-between;gap:12px;margin-bottom:16px}.rl-chart-title{font-size:1rem;font-weight:800;margin:0}.rl-chart-sub{font-size:.76rem;color:#667085;margin-top:3px}.rl-chart-icon{width:38px;height:38px;border-radius:10px;background:#eef4ff;color:#2563eb;display:grid;place-items:center}.rl-chart-wrap{height:285px;position:relative}.rl-chart-wrap.tall{height:320px}.rl-visual-title{display:flex;align-items:center;gap:10px;margin-bottom:14px}.rl-visual-title .icon{width:40px;height:40px;border-radius:11px;background:#eaf2ff;color:#2563eb;display:grid;place-items:center}
@media(max-width:991.98px){.rl-sidebar{transform:translateX(-100%);transition:.2s}.rl-sidebar.show{transform:translateX(0)}.rl-main{margin-left:0;width:100%}.rl-mobile-toggle{display:inline-flex}.rl-overlay{position:fixed;inset:0;background:rgba(16,24,40,.45);z-index:1035}.rl-overlay.show{display:block}.rl-topbar{padding:12px 16px}.rl-user-text{display:none}.container-fluid{padding-left:14px!important;padding-right:14px!important}}
@media print{.no-print,.rl-sidebar,.rl-topbar,.rl-overlay,.rl-visual-title,.rl-chart-card{display:none!important}.rl-main{margin-left:0;width:100%}body{background:#fff}.report-card{box-shadow:none!important;border:1px solid #ddd!important}}
</style>
<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body>
<div class="rl-overlay" id="rlOverlay"></div><div class="rl-app"><?php include __DIR__ . '/sidebar.php'; ?><main class="rl-main"><div class="rl-topbar"><div class="d-flex gap-2 align-items-start"><button class="btn btn-light border rl-mobile-toggle" id="rlSidebarToggle" type="button"><i class="bi bi-list"></i></button><div class="rl-welcome"><h1>Monthly Report</h1><p><?=h(date('F Y', strtotime($month_start)))?> · <?=h($start_date)?> to <?=h($end_date)?></p></div></div><div class="d-flex align-items-center gap-2"><button onclick="window.print()" class="btn btn-outline-primary no-print"><i class="bi bi-printer me-1"></i>Print</button><div class="rl-user-chip"><div class="rl-avatar"><?=h(strtoupper(substr((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'A'),0,1)))?></div><div class="rl-user-text"><div class="fw-bold small"><?=h((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Admin'))?></div><div class="text-muted" style="font-size:.7rem"><?=h(ucfirst((string)($_SESSION['role'] ?? 'admin')))?></div></div></div></div></div><div class="rl-page-content"><div class="container-fluid px-4 py-4">
    <?php foreach ($messages as $msg): ?>
        <div class="alert alert-<?php echo h($msg[0]); ?> no-print"><?php echo h($msg[1]); ?></div>
    <?php endforeach; ?>

    <div class="card report-card mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Report Month</label>
                    <input type="month" name="month" class="form-control" value="<?php echo h($month); ?>">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary fw-bold w-100">Load Month</button>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-info mb-0 py-2">
                        Net profit below = sales/recharge profit after card fees minus entered expenses. Withdrawals/deposits are shown separately as drawer cash movements.
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card report-card h-100"><div class="card-body">
                <div class="metric-label">Total Net Profit</div>
                <p class="metric-value <?php echo $total_net_profit_after_expense >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo money_aed($total_net_profit_after_expense); ?></p>
                <div class="small-muted">After card fees and expenses</div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card report-card h-100"><div class="card-body">
                <div class="metric-label">Profit Before Expenses</div>
                <p class="metric-value text-primary"><?php echo money_aed($total_gross_profit_before_expense); ?></p>
                <div class="small-muted">Sales - cost - card fees</div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card report-card h-100"><div class="card-body">
                <div class="metric-label">Total Shop Expenses</div>
                <p class="metric-value text-danger"><?php echo money_aed($expense_total); ?></p>
                <div class="small-muted">Manual <?php echo money_aed($manual_expense_total); ?> + withdrawals <?php echo money_aed($withdrawal_expense_total); ?></div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card report-card h-100"><div class="card-body">
                <div class="metric-label">Total Sales</div>
                <p class="metric-value"><?php echo money_aed($total_sales); ?></p>
                <div class="small-muted"><?php echo (int)$total_units_sold; ?> units/rows quantity</div>
            </div></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Total Withdrawal</div><p class="metric-value text-warning"><?php echo money_aed($withdraw_total); ?></p><div class="small-muted">From drawer_withdrawals, amount &gt; 0</div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Total Deposit</div><p class="metric-value text-success"><?php echo money_aed($deposit_total); ?></p><div class="small-muted">From drawer_withdrawals, amount &lt; 0</div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Expected Cash Drawer</div><p class="metric-value"><?php echo money_aed($cash_drawer_expected); ?></p><div class="small-muted">Cash sales + deposits - withdrawals</div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Card Fees</div><p class="metric-value text-danger"><?php echo money_aed($total_card_fees); ?></p><div class="small-muted"><?php echo money_clean($card_fee_rate * 100); ?>% on card sales</div>
        </div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Total Shop Expenses</div><p class="metric-value text-danger"><?php echo money_aed($expense_total); ?></p><div class="small-muted">Entered in monthly_expenses</div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Cash After Shop Expenses</div><p class="metric-value <?php echo $cash_after_shop_expenses >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo money_aed($cash_after_shop_expenses); ?></p><div class="small-muted">Expected cash drawer - expenses</div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Filtered Withdrawals</div><p class="metric-value text-warning"><?php echo money_aed($filtered_withdraw_total); ?></p><div class="small-muted"><?php echo (int)$filtered_movement_count; ?> filtered movement row(s)</div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Filtered Deposits</div><p class="metric-value text-success"><?php echo money_aed($filtered_deposit_total); ?></p><div class="small-muted">Based on filter below</div>
        </div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Withdrawal from Sultan</div><p class="metric-value text-warning"><?php echo money_aed($sultan_withdrawal_total); ?></p><div class="small-muted"><?php echo (int)$sultan_withdrawal_count; ?> row(s) containing سلطان / Sultan</div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Deposit from Sultan</div><p class="metric-value text-success"><?php echo money_aed($sultan_deposit_total); ?></p><div class="small-muted"><?php echo (int)$sultan_deposit_count; ?> row(s) containing سلطان / Sultan</div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Manual Expenses Added</div><p class="metric-value text-danger"><?php echo money_aed($manual_expense_total); ?></p><div class="small-muted">Expenses typed in this page</div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Withdrawals Added as Expenses</div><p class="metric-value text-danger"><?php echo money_aed($withdrawal_expense_total); ?></p><div class="small-muted">From drawer withdrawals added to shop expenses</div>
        </div></div></div>
        <div class="col-xl-3 col-md-12"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Total Expenses to Shop</div><p class="metric-value text-danger"><?php echo money_aed($expense_total); ?></p><div class="small-muted">This is deducted from net profit</div>
        </div></div></div>
        <!-- SELECTED_MONTH_TOTAL_COST_NEXT_TO_EXPENSES_V2_3_5 -->
        <div class="col-xl-3 col-md-12"><div class="card report-card h-100"><div class="card-body">
            <div class="metric-label">Selected Month Total Cost</div><p class="metric-value text-danger"><?php echo money_aed($total_sold_cost); ?></p><div class="small-muted"><?php echo h(date('F Y', strtotime($month_start))); ?> sold items + recharges/services cost</div>
        </div></div></div>
    </div>

    <div class="card report-card mb-4">
        <div class="card-header bg-white border-0 pt-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="fw-bold mb-0">Withdrawal from Sultan</h5>
                <div class="small-muted">Automatically shows drawer withdrawals from Daily Sales where the withdrawal name contains <strong>سلطان</strong> or <strong>Sultan</strong>.</div>
            </div>
            <div class="text-end">
                <div class="metric-label">Total</div>
                <div class="h4 fw-bold text-warning mb-0"><?php echo money_aed($sultan_withdrawal_total); ?></div>
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead><tr><th>Date</th><th>Name</th><th class="text-end">Amount</th><th>Source</th></tr></thead>
                    <tbody>
                    <?php if (empty($sultan_withdrawals)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No Sultan withdrawal rows found for this month.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sultan_withdrawals as $movement): ?>
                            <tr>
                                <td><?php echo h(date('Y-m-d h:i A', strtotime((string)($movement['withdrawn_at'] ?? 'now')))); ?></td>
                                <td class="fw-bold"><?php echo h($movement['name'] ?? ''); ?></td>
                                <td class="text-end fw-bold text-warning"><?php echo money_aed(abs((float)($movement['amount'] ?? 0))); ?></td>
                                <td><?php echo h($movement['import_source'] ?? 'drawer_withdrawals'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <tfoot><tr class="table-warning"><th colspan="2">Withdrawal from Sultan Total</th><th class="text-end"><?php echo money_aed($sultan_withdrawal_total); ?></th><th><?php echo (int)$sultan_withdrawal_count; ?> row(s)</th></tr></tfoot>
                </table>
            </div>
        </div>
    </div>


    <div class="card report-card mb-4">
        <div class="card-header bg-white border-0 pt-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="fw-bold mb-0">Deposit from Sultan</h5>
                <div class="small-muted">Automatically shows drawer deposits from Daily Sales where the deposit name contains <strong>سلطان</strong> or <strong>Sultan</strong>.</div>
            </div>
            <div class="text-end">
                <div class="metric-label">Total</div>
                <div class="h4 fw-bold text-success mb-0"><?php echo money_aed($sultan_deposit_total); ?></div>
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead><tr><th>Date</th><th>Name</th><th class="text-end">Amount</th><th>Source</th></tr></thead>
                    <tbody>
                    <?php if (empty($sultan_deposits)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No Sultan deposit rows found for this month.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sultan_deposits as $movement): ?>
                            <tr>
                                <td><?php echo h(date('Y-m-d h:i A', strtotime((string)($movement['withdrawn_at'] ?? 'now')))); ?></td>
                                <td class="fw-bold"><?php echo h($movement['name'] ?? ''); ?></td>
                                <td class="text-end fw-bold text-success"><?php echo money_aed(abs((float)($movement['amount'] ?? 0))); ?></td>
                                <td><?php echo h($movement['import_source'] ?? 'drawer_withdrawals'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <tfoot><tr class="table-success"><th colspan="2">Deposit from Sultan Total</th><th class="text-end"><?php echo money_aed($sultan_deposit_total); ?></th><th><?php echo (int)$sultan_deposit_count; ?> row(s)</th></tr></tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="card report-card mb-4">
        <div class="card-header bg-white border-0 pt-3">
            <h5 class="fw-bold mb-0">Withdrawal / Deposit Filter</h5>
            <div class="small-muted">Filter drawer movements for this month. Positive amount = withdrawal, negative amount = deposit.</div>
        </div>
        <div class="card-body pt-0">
            <form method="GET" class="row g-3 align-items-end no-print mb-3">
                <input type="hidden" name="month" value="<?php echo h($month); ?>">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Movement Type</label>
                    <select name="movement_filter" class="form-select">
                        <option value="all" <?php echo $movement_filter === 'all' ? 'selected' : ''; ?>>All withdrawals & deposits</option>
                        <option value="withdrawals" <?php echo $movement_filter === 'withdrawals' ? 'selected' : ''; ?>>Withdrawals only</option>
                        <option value="deposits" <?php echo $movement_filter === 'deposits' ? 'selected' : ''; ?>>Deposits only</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-bold">Search Name / Date / Amount</label>
                    <input type="text" name="movement_search" class="form-control" value="<?php echo h($movement_search); ?>" placeholder="rent, recharge, 2026-06-01, 500...">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary fw-bold w-100">Filter</button>
                </div>
                <div class="col-md-2">
                    <a href="monthly_report.php?month=<?php echo urlencode($month); ?>" class="btn btn-outline-secondary fw-bold w-100">Clear</a>
                </div>
            </form>

            <div class="row g-3 mb-3">
                <div class="col-md-4"><div class="alert alert-warning mb-0"><strong>Filtered Withdrawals:</strong><br><?php echo money_aed($filtered_withdraw_total); ?></div></div>
                <div class="col-md-4"><div class="alert alert-success mb-0"><strong>Filtered Deposits:</strong><br><?php echo money_aed($filtered_deposit_total); ?></div></div>
                <div class="col-md-4"><div class="alert alert-secondary mb-0"><strong>Rows:</strong><br><?php echo (int)$filtered_movement_count; ?></div></div>
            </div>

            <form method="POST" class="no-print mb-3" onsubmit="return confirm('Add all filtered withdrawal rows to monthly shop expenses? Duplicate rows will be skipped.');">
                <input type="hidden" name="month" value="<?php echo h($month); ?>">
                <?php $bulk_withdraw_count = 0; foreach ($filtered_drawer_movements as $bulk_movement): ?>
                    <?php if ((float)($bulk_movement['amount'] ?? 0) > 0): $bulk_withdraw_count++; ?>
                        <input type="hidden" name="movement_ids[]" value="<?php echo (int)$bulk_movement['id']; ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <button type="submit" name="add_filtered_withdrawals_expense" value="1" class="btn btn-danger fw-bold" <?php echo $bulk_withdraw_count <= 0 ? 'disabled' : ''; ?>>Add All Filtered Withdrawals to Shop Expenses</button>
                <span class="small-muted ms-2"><?php echo (int)$bulk_withdraw_count; ?> filtered withdrawal row(s). Already-added rows are skipped automatically.</span>
            </form>

            <div class="no-print small-muted mb-2"><span id="movement_selected_count">0</span> selected from this list.</div>

            <form method="POST" id="selected_movements_form" class="no-print mb-3" onsubmit="return confirm('Add selected withdrawal rows to monthly shop expenses? Deposits and duplicate rows will be skipped.');">
                <input type="hidden" name="month" value="<?php echo h($month); ?>">
                <button type="submit" name="add_selected_withdrawals_expense" value="1" id="add_selected_movements_btn" class="btn btn-danger fw-bold" disabled>Add Selected to Expenses</button>
                <span class="small-muted ms-2">Only selected withdrawals will be added. Deposits and already-added withdrawals are skipped automatically.</span>
            </form>

            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead><tr><th class="no-print text-center" style="width:42px;"><input type="checkbox" id="movement_select_all" title="Select all visible rows"></th><th>Date</th><th>Name</th><th>Type</th><th class="text-end">Amount</th><th class="text-end no-print">Shop Expense</th></tr></thead>
                    <tbody>
                    <?php if (empty($filtered_drawer_movements)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No drawer movements match this filter.</td></tr>
                    <?php else: ?>
                        <?php foreach ($filtered_drawer_movements as $movement): ?>
                            <?php
                                $movement_amount = (float)($movement['amount'] ?? 0);
                                $is_withdrawal = $movement_amount > 0;
                                $movement_id = (int)($movement['id'] ?? 0);
                                $is_already_expense = false;
                                if ($is_withdrawal && $movement_id > 0) {
                                    foreach ($expenses as $existing_expense) {
                                        if (($existing_expense['source_type'] ?? '') === 'drawer_withdrawal' && (int)($existing_expense['source_id'] ?? 0) === $movement_id) {
                                            $is_already_expense = true;
                                            break;
                                        }
                                    }
                                }
                            ?>
                            <tr>
                                <td class="no-print text-center"><input type="checkbox" class="movement-row-check" name="movement_ids[]" value="<?php echo $movement_id; ?>" form="selected_movements_form"></td>
                                <td><?php echo h(date('Y-m-d h:i A', strtotime((string)($movement['withdrawn_at'] ?? 'now')))); ?></td>
                                <td class="fw-bold"><?php echo h($movement['name'] ?? ''); ?></td>
                                <td><?php echo $is_withdrawal ? '<span class="badge bg-warning text-dark">Withdrawal</span>' : '<span class="badge bg-success">Deposit</span>'; ?></td>
                                <td class="text-end fw-bold <?php echo $is_withdrawal ? 'text-warning' : 'text-success'; ?>"><?php echo money_aed(abs($movement_amount)); ?></td>
                                <td class="text-end no-print">
                                    <?php if ($is_withdrawal): ?>
                                        <?php if ($is_already_expense): ?>
                                            <span class="badge bg-secondary">Already in expenses</span>
                                        <?php else: ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Add this withdrawal to monthly shop expenses?');">
                                                <input type="hidden" name="month" value="<?php echo h($month); ?>">
                                                <input type="hidden" name="movement_id" value="<?php echo $movement_id; ?>">
                                                <button type="submit" name="add_withdrawal_expense" value="1" class="btn btn-sm btn-outline-danger">Add to Expenses</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-dark"><th class="no-print"></th><th colspan="3">Filtered Total</th><th class="text-end">Withdrawals <?php echo money_aed($filtered_withdraw_total); ?> / Deposits <?php echo money_aed($filtered_deposit_total); ?></th><th class="no-print"></th></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>


    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card report-card h-100">
                <div class="card-header bg-white border-0 pt-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h5 class="fw-bold mb-0">Total Cost From Each Month</h5>
                    <span class="badge bg-dark"><?php echo count($monthly_cost_rows); ?> month(s)</span>
                </div>
                <div class="card-body pt-0">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="border rounded-3 p-3 bg-light h-100">
                                <div class="metric-label">Selected Month Cost</div>
                                <p class="metric-value text-danger"><?php echo money_aed($selected_month_cost_row['cost']); ?></p>
                                <div class="small-muted"><?php echo h(date('F Y', strtotime($month_start))); ?> sold item/recharge cost</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded-3 p-3 bg-light h-100">
                                <div class="metric-label">Selected Month Sales</div>
                                <p class="metric-value text-primary"><?php echo money_aed($selected_month_cost_row['sales']); ?></p>
                                <div class="small-muted"><?php echo (int)$selected_month_cost_row['qty']; ?> qty from <?php echo (int)$selected_month_cost_row['rows']; ?> sale row(s)</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded-3 p-3 bg-light h-100">
                                <div class="metric-label">Selected Month Profit</div>
                                <p class="metric-value <?php echo $selected_month_cost_row['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo money_aed($selected_month_cost_row['profit']); ?></p>
                                <div class="small-muted">After cost and card fees, before expenses</div>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th class="text-end">Rows</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Sales</th>
                                    <th class="text-end">Card Fees</th>
                                    <th class="text-end">Total Cost</th>
                                    <th class="text-end">Profit Before Expenses</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($monthly_cost_rows)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">No sales records found to calculate monthly cost.</td></tr>
                            <?php else: ?>
                                <?php foreach ($monthly_cost_rows as $month_key => $row): ?>
                                    <tr class="<?php echo $month_key === $month ? 'table-info' : ''; ?>">
                                        <td class="fw-bold"><?php echo h(date('F Y', strtotime($month_key . '-01'))); ?><?php echo $month_key === $month ? ' <span class="badge bg-primary">Selected</span>' : ''; ?></td>
                                        <td class="text-end"><?php echo (int)$row['rows']; ?></td>
                                        <td class="text-end"><?php echo (int)$row['qty']; ?></td>
                                        <td class="text-end"><?php echo money_aed($row['sales']); ?></td>
                                        <td class="text-end text-danger"><?php echo money_aed($row['card_fee']); ?></td>
                                        <td class="text-end fw-bold text-danger"><?php echo money_aed($row['cost']); ?></td>
                                        <td class="text-end fw-bold <?php echo $row['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo money_aed($row['profit']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="small-muted mt-2">Total Cost = matched cost from products, phones, laptops, tools, recharges, services/imported cost in <code>sales_records</code>.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="rl-visual-title"><div class="icon"><i class="bi bi-graph-up-arrow"></i></div><div><h4 class="fw-bold mb-0">Visual Analytics</h4><div class="text-muted small">Charts use the same selected month and calculations shown below.</div></div></div>
    <div class="row g-4 mb-4">
      <div class="col-xl-8"><div class="card report-card rl-chart-card p-4 h-100"><div class="rl-chart-head"><div><h5 class="rl-chart-title">Daily Sales, Cost & Profit</h5><div class="rl-chart-sub">Performance through the selected month.</div></div><div class="rl-chart-icon"><i class="bi bi-graph-up"></i></div></div><div class="rl-chart-wrap tall"><canvas id="monthlyDailyChart"></canvas></div></div></div>
      <div class="col-xl-4"><div class="card report-card rl-chart-card p-4 h-100"><div class="rl-chart-head"><div><h5 class="rl-chart-title">Payment Mix</h5><div class="rl-chart-sub">Cash, card, bank and other sales.</div></div><div class="rl-chart-icon"><i class="bi bi-credit-card"></i></div></div><div class="rl-chart-wrap tall"><canvas id="monthlyPaymentChart"></canvas></div></div></div>
      <div class="col-xl-6"><div class="card report-card rl-chart-card p-4 h-100"><div class="rl-chart-head"><div><h5 class="rl-chart-title">Sales & Profit by Source</h5><div class="rl-chart-sub">Inventory and service contribution.</div></div><div class="rl-chart-icon"><i class="bi bi-boxes"></i></div></div><div class="rl-chart-wrap"><canvas id="monthlySourceChart"></canvas></div></div></div>
      <div class="col-xl-6"><div class="card report-card rl-chart-card p-4 h-100"><div class="rl-chart-head"><div><h5 class="rl-chart-title">Drawer & Expenses</h5><div class="rl-chart-sub">Withdrawals, deposits and shop expenses by day.</div></div><div class="rl-chart-icon"><i class="bi bi-wallet2"></i></div></div><div class="rl-chart-wrap"><canvas id="monthlyDrawerChart"></canvas></div></div></div>
      <div class="col-xl-7"><div class="card report-card rl-chart-card p-4 h-100"><div class="rl-chart-head"><div><h5 class="rl-chart-title">Monthly Sales / Cost / Profit History</h5><div class="rl-chart-sub">Comparison across available months.</div></div><div class="rl-chart-icon"><i class="bi bi-calendar3"></i></div></div><div class="rl-chart-wrap"><canvas id="monthlyHistoryChart"></canvas></div></div></div>
      <div class="col-xl-5"><div class="card report-card rl-chart-card p-4 h-100"><div class="rl-chart-head"><div><h5 class="rl-chart-title">Current Inventory Cost Value</h5><div class="rl-chart-sub">Stock left by source.</div></div><div class="rl-chart-icon"><i class="bi bi-pie-chart"></i></div></div><div class="rl-chart-wrap"><canvas id="monthlyInventoryChart"></canvas></div></div></div>
      <div class="col-xl-6"><div class="card report-card rl-chart-card p-4 h-100">
        <div class="rl-chart-head"><div><h5 class="rl-chart-title">Expense Breakdown</h5><div class="rl-chart-sub">Manual expenses compared with withdrawal-based expenses.</div></div><div class="rl-chart-icon"><i class="bi bi-receipt-cutoff"></i></div></div>
        <div class="rl-chart-wrap"><canvas id="monthlyExpenseChart"></canvas></div>
      </div></div>

      <div class="col-xl-6"><div class="card report-card rl-chart-card p-4 h-100">
        <div class="rl-chart-head"><div><h5 class="rl-chart-title">Daily Profit Margin</h5><div class="rl-chart-sub">Profit as a percentage of sales for each active day.</div></div><div class="rl-chart-icon"><i class="bi bi-percent"></i></div></div>
        <div class="rl-chart-wrap"><canvas id="monthlyMarginChart"></canvas></div>
      </div></div>

      <div class="col-xl-8"><div class="card report-card rl-chart-card p-4 h-100">
        <div class="rl-chart-head"><div><h5 class="rl-chart-title">Cumulative Sales & Profit</h5><div class="rl-chart-sub">Running total through the selected month.</div></div><div class="rl-chart-icon"><i class="bi bi-graph-up-arrow"></i></div></div>
        <div class="rl-chart-wrap tall"><canvas id="monthlyCumulativeChart"></canvas></div>
      </div></div>

      <div class="col-xl-4"><div class="card report-card rl-chart-card p-4 h-100">
        <div class="rl-chart-head"><div><h5 class="rl-chart-title">Inventory Units Left</h5><div class="rl-chart-sub">Current quantity by inventory source.</div></div><div class="rl-chart-icon"><i class="bi bi-box-seam"></i></div></div>
        <div class="rl-chart-wrap tall"><canvas id="monthlyInventoryUnitsChart"></canvas></div>
      </div></div>

      <div class="col-xl-12"><div class="card report-card rl-chart-card p-4 h-100">
        <div class="rl-chart-head"><div><h5 class="rl-chart-title">Drawer Movement Summary</h5><div class="rl-chart-sub">Total withdrawals and deposits for the selected month.</div></div><div class="rl-chart-icon"><i class="bi bi-arrow-left-right"></i></div></div>
        <div class="rl-chart-wrap"><canvas id="monthlyMovementSummaryChart"></canvas></div>
      </div></div>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card report-card h-100">
                <div class="card-header bg-white border-0 pt-3"><h5 class="fw-bold mb-0">Total Cost Sold This Month</h5></div>
                <div class="card-body pt-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead><tr><th>Source</th><th class="text-end">Qty</th><th class="text-end">Sales</th><th class="text-end">Cost</th><th class="text-end">Profit</th></tr></thead>
                            <tbody>
                            <?php foreach ($sold_cost_by_source as $source => $row): ?>
                                <tr>
                                    <td><?php echo h($source_labels[$source] ?? $source); ?></td>
                                    <td class="text-end"><?php echo (int)$row['qty']; ?></td>
                                    <td class="text-end"><?php echo money_aed($row['sales']); ?></td>
                                    <td class="text-end fw-bold"><?php echo money_aed($row['cost']); ?></td>
                                    <td class="text-end <?php echo $row['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo money_aed($row['profit']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-dark"><th>Inventory cost only: Products + Phones + Laptops + Tools</th><th></th><th></th><th class="text-end"><?php echo money_aed($total_inventory_sold_cost); ?></th><th></th></tr>
                                <tr><th>All sold cost including recharge/service imported cost</th><th></th><th></th><th class="text-end"><?php echo money_aed($total_sold_cost); ?></th><th></th></tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card report-card h-100">
                <div class="card-header bg-white border-0 pt-3"><h5 class="fw-bold mb-0">Total Cost Left in Shop</h5></div>
                <div class="card-body pt-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Source</th><th class="text-end">Items</th><th class="text-end">Units Left</th><th class="text-end">Cost Value</th></tr></thead>
                            <tbody>
                            <?php foreach (['products' => 'Products / Accessories', 'phones' => 'Phones', 'laptops' => 'Laptops', 'tools' => 'Tools / Spare Parts'] as $source => $label): ?>
                                <tr>
                                    <td><?php echo h($label); ?></td>
                                    <td class="text-end"><?php echo (int)$left[$source]['items']; ?></td>
                                    <td class="text-end"><?php echo (int)$left[$source]['units']; ?></td>
                                    <td class="text-end fw-bold"><?php echo money_aed($left[$source]['value']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr class="table-dark"><th>Total</th><th></th><th class="text-end"><?php echo (int)$total_left_units; ?></th><th class="text-end"><?php echo money_aed($total_left_cost); ?></th></tr></tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card report-card h-100">
                <div class="card-header bg-white border-0 pt-3"><h5 class="fw-bold mb-0">Etisalat & Du Recharge Total</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6"><div class="metric-label">Completed Count</div><p class="metric-value"><?php echo (int)$recharge_summary['count']; ?></p></div>
                        <div class="col-6"><div class="metric-label">Paid by Customers</div><p class="metric-value"><?php echo money_aed($recharge_summary['amount_paid']); ?></p></div>
                        <div class="col-6"><div class="metric-label">Recharge Cost</div><p class="metric-value text-danger"><?php echo money_aed($recharge_summary['cost']); ?></p></div>
                        <div class="col-6"><div class="metric-label">Recharge Profit</div><p class="metric-value text-success"><?php echo money_aed($recharge_summary['profit']); ?></p></div>
                    </div>
                    <div class="small-muted mt-3">Uses the <code>recharges</code> table for provider Du / Etisalat and status Completed.</div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card report-card h-100">
                <div class="card-header bg-white border-0 pt-3"><h5 class="fw-bold mb-0">Payment Method Summary</h5></div>
                <div class="card-body pt-0">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            <tr><td>Cash</td><td class="text-end fw-bold"><?php echo money_aed($cash_total); ?></td></tr>
                            <tr><td>Card / VISA</td><td class="text-end fw-bold"><?php echo money_aed($card_total); ?></td></tr>
                            <tr><td style="color:#f1506f;font-weight:700">Tamara / Tabby</td><td class="text-end fw-bold"><?php echo money_aed($tamara_total); ?></td></tr>
                            <tr><td>Bank Transfer</td><td class="text-end fw-bold"><?php echo money_aed($bank_total); ?></td></tr>
                            <tr><td>Other</td><td class="text-end fw-bold"><?php echo money_aed($other_total); ?></td></tr>
                            <tr class="table-dark"><th>Total</th><th class="text-end"><?php echo money_aed($cash_total + $card_total + $tamara_total + $bank_total + $other_total); ?></th></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-5 no-print">
            <div class="card report-card h-100">
                <div class="card-header bg-white border-0 pt-3"><h5 class="fw-bold mb-0">Add Monthly Expense</h5></div>
                <div class="card-body pt-0">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="month" value="<?php echo h($month); ?>">
                        <div class="col-12">
                            <label class="form-label fw-bold">Expense Name</label>
                            <input type="text" name="expense_name" class="form-control" placeholder="Rent, salary, internet, repair expense..." required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Amount AED</label>
                            <input type="number" step="0.01" min="0" name="expense_amount" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date</label>
                            <input type="date" name="expense_date" class="form-control" value="<?php echo h(date('Y-m-d')); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea name="expense_notes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" name="add_expense" value="1" class="btn btn-danger fw-bold w-100">Add Expense</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card report-card h-100">
                <div class="card-header bg-white border-0 pt-3"><h5 class="fw-bold mb-0">Expenses This Month</h5></div>
                <div class="card-body pt-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead><tr><th>Date</th><th>Name</th><th>Notes</th><th class="text-end">Amount</th><th class="text-end no-print">Action</th></tr></thead>
                            <tbody>
                            <?php if (empty($expenses)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No expenses added for this month.</td></tr>
                            <?php else: ?>
                                <?php foreach ($expenses as $expense): ?>
                                    <tr>
                                        <td><?php echo h($expense['expense_date']); ?></td>
                                        <td class="fw-bold"><?php echo h($expense['expense_name']); ?></td>
                                        <td><?php echo h($expense['notes'] ?? ''); ?></td>
                                        <td class="text-end fw-bold"><?php echo money_aed($expense['amount']); ?></td>
                                        <td class="text-end no-print">
                                            <form method="POST" onsubmit="return confirm('Delete this expense?');" class="d-inline">
                                                <input type="hidden" name="month" value="<?php echo h($month); ?>">
                                                <input type="hidden" name="expense_id" value="<?php echo (int)$expense['id']; ?>">
                                                <button type="submit" name="delete_expense" value="1" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                            <tfoot><tr class="table-dark"><th colspan="3">Total Expenses</th><th class="text-end"><?php echo money_aed($expense_total); ?></th><th class="no-print"></th></tr></tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card report-card mb-5">
        <div class="card-header bg-white border-0 pt-3"><h5 class="fw-bold mb-0">Final Summary</h5></div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <tbody>
                        <tr><th style="width: 45%">Total Sales Revenue</th><td class="text-end fw-bold"><?php echo money_aed($total_sales); ?></td></tr>
                        <tr><th>Minus Total Cost of Sold Items / Recharges</th><td class="text-end text-danger fw-bold">-<?php echo money_aed($total_sold_cost); ?></td></tr>
                        <tr><th>Minus Card Fees</th><td class="text-end text-danger fw-bold">-<?php echo money_aed($total_card_fees); ?></td></tr>
                        <tr><th>Profit Before Expenses</th><td class="text-end text-primary fw-bold"><?php echo money_aed($total_gross_profit_before_expense); ?></td></tr>
                        <tr><th>Minus Monthly / Shop Expenses</th><td class="text-end text-danger fw-bold">-<?php echo money_aed($expense_total); ?></td></tr>
                        <tr><th>Total Expenses to Shop</th><td class="text-end text-danger fw-bold"><?php echo money_aed($expense_total); ?></td></tr>
                        <tr><th>Withdrawal from Sultan</th><td class="text-end text-warning fw-bold"><?php echo money_aed($sultan_withdrawal_total); ?></td></tr>
                        <tr><th>Deposit from Sultan</th><td class="text-end text-success fw-bold"><?php echo money_aed($sultan_deposit_total); ?></td></tr>
                        <tr><th>Expected Cash Drawer After Shop Expenses</th><td class="text-end <?php echo $cash_after_shop_expenses >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold"><?php echo money_aed($cash_after_shop_expenses); ?></td></tr>
                        <tr class="table-dark"><th>Total Net Profit</th><td class="text-end fw-bold"><?php echo money_aed($total_net_profit_after_expense); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="alert alert-secondary mb-0">
                Note: withdrawals and deposits are cash drawer movements. Use the withdrawal filter section to add a withdrawal into shop expenses when it is a real expense.
            </div>
        </div>
    </div>
</div></div></main></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('movement_select_all');
    const checks = Array.from(document.querySelectorAll('.movement-row-check'));
    const counter = document.getElementById('movement_selected_count');

    function updateCounter() {
        const selected = checks.filter(cb => cb.checked).length;
        const addSelectedBtn = document.getElementById('add_selected_movements_btn');
        if (counter) counter.textContent = selected;
        if (addSelectedBtn) addSelectedBtn.disabled = selected === 0;
        if (selectAll) {
            selectAll.checked = checks.length > 0 && selected === checks.length;
            selectAll.indeterminate = selected > 0 && selected < checks.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checks.forEach(cb => { cb.checked = selectAll.checked; });
            updateCounter();
        });
    }

    checks.forEach(cb => cb.addEventListener('change', updateCounter));
    updateCounter();
});
</script>

<script>
(function(){
 const sidebar=document.getElementById('rlSidebar'),overlay=document.getElementById('rlOverlay'),toggle=document.getElementById('rlSidebarToggle');
 if(toggle)toggle.addEventListener('click',()=>{sidebar.classList.toggle('show');overlay.classList.toggle('show')});
 if(overlay)overlay.addEventListener('click',()=>{sidebar.classList.remove('show');overlay.classList.remove('show')});
 if(typeof Chart==='undefined')return;
 const daily=<?=json_encode($chart_daily, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,source=<?=json_encode($sold_cost_by_source, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,labels=<?=json_encode($source_labels, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,hist=<?=json_encode($monthly_cost_rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 const days=Object.keys(daily),money=v=>Number(v||0).toLocaleString(undefined,{maximumFractionDigits:0})+' AED',tooltip={callbacks:{label:c=>(c.dataset.label?c.dataset.label+': ':'')+Number(c.parsed.y??c.parsed??0).toLocaleString(undefined,{maximumFractionDigits:2})+' AED'}};
 new Chart(document.getElementById('monthlyDailyChart'),{type:'line',data:{labels:days,datasets:[{label:'Sales',data:days.map(d=>daily[d].sales||0),borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.10)',fill:true,tension:.35,pointRadius:2},{label:'Cost',data:days.map(d=>daily[d].cost||0),borderColor:'#ef4444',tension:.35,pointRadius:2},{label:'Profit',data:days.map(d=>daily[d].profit||0),borderColor:'#16a34a',tension:.35,pointRadius:2}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'},tooltip},scales:{y:{beginAtZero:true,ticks:{callback:money}},x:{grid:{display:false}}}}});
 new Chart(document.getElementById('monthlyPaymentChart'),{type:'doughnut',data:{labels:['Cash','Card / VISA','Tamara / Tabby','Bank','Other'],datasets:[{data:[<?=json_encode(round($cash_total,2))?>,<?=json_encode(round($card_total,2))?>,<?=json_encode(round($tamara_total,2))?>,<?=json_encode(round($bank_total,2))?>,<?=json_encode(round($other_total,2))?>],backgroundColor:['#16a34a','#2563eb','#f1506f','#7c3aed','#94a3b8'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'66%',plugins:{legend:{position:'bottom'}}}});
 const sk=Object.keys(source);
 new Chart(document.getElementById('monthlySourceChart'),{type:'bar',data:{labels:sk.map(k=>labels[k]||k),datasets:[{label:'Sales',data:sk.map(k=>source[k].sales||0),backgroundColor:'#2563eb'},{label:'Profit',data:sk.map(k=>source[k].profit||0),backgroundColor:'#16a34a'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'},tooltip},scales:{y:{beginAtZero:true,ticks:{callback:money}},x:{grid:{display:false}}}}});
 new Chart(document.getElementById('monthlyDrawerChart'),{type:'bar',data:{labels:days,datasets:[{label:'Deposits',data:days.map(d=>daily[d].deposit||0),backgroundColor:'#16a34a'},{label:'Withdrawals',data:days.map(d=>daily[d].withdraw||0),backgroundColor:'#ef4444'},{label:'Expenses',data:days.map(d=>daily[d].expense||0),backgroundColor:'#f59e0b'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'},tooltip},scales:{y:{beginAtZero:true,ticks:{callback:money}},x:{grid:{display:false}}}}});
 const hk=Object.keys(hist).sort();
 new Chart(document.getElementById('monthlyHistoryChart'),{type:'bar',data:{labels:hk,datasets:[{label:'Sales',data:hk.map(k=>hist[k].sales||0),backgroundColor:'#2563eb'},{label:'Cost',data:hk.map(k=>hist[k].cost||0),backgroundColor:'#ef4444'},{label:'Profit',data:hk.map(k=>hist[k].profit||0),backgroundColor:'#16a34a'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'},tooltip},scales:{y:{beginAtZero:true,ticks:{callback:money}},x:{grid:{display:false}}}}});
 new Chart(document.getElementById('monthlyInventoryChart'),{type:'doughnut',data:{labels:['Products','Phones','Laptops','Tools'],datasets:[{data:[<?=json_encode(round($left['products']['value'],2))?>,<?=json_encode(round($left['phones']['value'],2))?>,<?=json_encode(round($left['laptops']['value'],2))?>,<?=json_encode(round($left['tools']['value'],2))?>],backgroundColor:['#2563eb','#7c3aed','#f59e0b','#16a34a'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'58%',plugins:{legend:{position:'bottom'}}}});
})();
</script>

</body>
</html>
