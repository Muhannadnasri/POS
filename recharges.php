<?php
require_once __DIR__ . '/session_keepalive.php';
// recharges.php - Recharge Management with one total device balance
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$message = '';

// Etisalat / du recharge barcode presets.
// Each barcode can have its own sale amount and device cost.
$pdo->exec("CREATE TABLE IF NOT EXISTS recharge_barcodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(30) NOT NULL,
    barcode VARCHAR(100) NOT NULL,
    label VARCHAR(120) DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    cost_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recharge_barcode (barcode),
    INDEX idx_recharge_barcode_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_recharge_barcode'])) {
    $rbProvider = trim((string)($_POST['rb_provider'] ?? 'Etisalat'));
    if (!in_array($rbProvider, ['Du / Etisalat', 'Etisalat', 'du'], true)) $rbProvider = 'Du / Etisalat';
    $rbBarcode = trim((string)($_POST['rb_barcode'] ?? ''));
    $rbLabel = trim((string)($_POST['rb_label'] ?? ''));
    $rbAmount = round((float)($_POST['rb_amount'] ?? 0), 2);
    $rbCost = round((float)($_POST['rb_cost'] ?? 0), 2);
    if ($rbCost <= 0 && $rbAmount > 0) $rbCost = $rbAmount;

    if ($rbBarcode === '') {
        $message = "<div class='alert alert-warning'>Recharge barcode is required.</div>";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO recharge_barcodes (provider, barcode, label, amount, cost_amount, active)
                VALUES (?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE provider=VALUES(provider), label=VALUES(label), amount=VALUES(amount), cost_amount=VALUES(cost_amount), active=1");
            $stmt->execute([$rbProvider, $rbBarcode, $rbLabel, $rbAmount, $rbCost]);
            header('Location: recharges.php?barcode_saved=1');
            exit;
        } catch (Throwable $e) {
            $message = "<div class='alert alert-danger'>Barcode save error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";
        }
    }
}

if (isset($_GET['delete_recharge_barcode'])) {
    $deleteId = (int)$_GET['delete_recharge_barcode'];
    $check = $pdo->prepare("SELECT barcode FROM recharge_barcodes WHERE id = ?");
    $check->execute([$deleteId]);
    $deleteBarcode = (string)($check->fetchColumn() ?: '');
    if (!in_array($deleteBarcode, ['629100000101'], true)) {
        $stmt = $pdo->prepare("DELETE FROM recharge_barcodes WHERE id = ?");
        $stmt->execute([$deleteId]);
    }
    header('Location: recharges.php?barcode_deleted=1');
    exit;
}


// Permanent 12-digit recharge barcodes.
// Cost stays 0 because the actual recharge cost/value can be different each transaction.
$permanentRechargeBarcodes = [
    ['Du / Etisalat', '629100000101', 'Du / Etisalat Recharge'],
];

$seedRechargeBarcode = $pdo->prepare("
    INSERT INTO recharge_barcodes (provider, barcode, label, amount, cost_amount, active)
    VALUES (?, ?, ?, 0, 0, 1)
    ON DUPLICATE KEY UPDATE
        provider = VALUES(provider),
        label = VALUES(label),
        cost_amount = 0,
        active = 1
");
foreach ($permanentRechargeBarcodes as $preset) {
    $seedRechargeBarcode->execute([$preset[0], $preset[1], $preset[2]]);
}

$rechargeBarcodes = $pdo->query("SELECT * FROM recharge_barcodes ORDER BY provider ASC, amount ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);


function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_smart($amount) {
    $amount = (float)$amount;
    if (abs($amount - round($amount)) < 0.005) {
        return number_format($amount, 0) . ' AED';
    }
    return number_format($amount, 2) . ' AED';
}

function recharge_providers() {
    return ['Du / Etisalat'];
}

function total_device_provider_key() {
    return '__TOTAL_DEVICE_BALANCE__';
}

function valid_provider($provider) {
    return 'Du / Etisalat';
}

function valid_status($status) {
    return in_array($status, ['Completed', 'Pending', 'Failed', 'Refunded'], true) ? $status : 'Completed';
}

function recharge_db_table_exists(PDO $pdo, string $table): bool {
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

function ensure_recharges_tables($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS recharges (
        id INT AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(40) NOT NULL DEFAULT 'Du / Etisalat',
        service_type VARCHAR(80) NOT NULL DEFAULT 'Recharge',
        customer_number VARCHAR(60) DEFAULT NULL,
        amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
        cost_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'CASH',
        status VARCHAR(30) NOT NULL DEFAULT 'Completed',
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL,
        INDEX idx_recharges_date (created_at),
        INDEX idx_recharges_provider (provider),
        INDEX idx_recharges_status (status),
        INDEX idx_recharges_payment (payment_method)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM recharges");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $columns[$col['Field']] = true;
    }

    $add = [
        'provider'        => "ALTER TABLE recharges ADD COLUMN provider VARCHAR(40) NOT NULL DEFAULT 'Du / Etisalat' AFTER id",
        'service_type'    => "ALTER TABLE recharges ADD COLUMN service_type VARCHAR(80) NOT NULL DEFAULT 'Recharge' AFTER provider",
        'customer_number' => "ALTER TABLE recharges ADD COLUMN customer_number VARCHAR(60) DEFAULT NULL AFTER service_type",
        'amount_paid'     => "ALTER TABLE recharges ADD COLUMN amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER customer_number",
        'cost_amount'     => "ALTER TABLE recharges ADD COLUMN cost_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER amount_paid",
        'payment_method'  => "ALTER TABLE recharges ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'CASH' AFTER cost_amount",
        'status'          => "ALTER TABLE recharges ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'Completed' AFTER payment_method",
        'notes'           => "ALTER TABLE recharges ADD COLUMN notes TEXT DEFAULT NULL AFTER status",
        'created_at'      => "ALTER TABLE recharges ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER notes",
        'updated_at'      => "ALTER TABLE recharges ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at",
        'source_type'     => "ALTER TABLE recharges ADD COLUMN source_type VARCHAR(50) DEFAULT NULL AFTER updated_at",
        'source_sale_id'  => "ALTER TABLE recharges ADD COLUMN source_sale_id INT DEFAULT NULL AFTER source_type"
    ];

    foreach ($add as $column => $sql) {
        if (!isset($columns[$column])) {
            $pdo->exec($sql);
        }
    }

    try { $pdo->exec("CREATE INDEX idx_recharges_source_sale_id ON recharges (source_sale_id)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX idx_recharges_source_type ON recharges (source_type)"); } catch (Exception $e) {}

    // Force all existing recharge records to cash only.
    $pdo->exec("UPDATE recharges SET payment_method='CASH' WHERE payment_method IS NULL OR payment_method <> 'CASH'");

    // This project uses one Recharge provider/type only.
    $pdo->exec("UPDATE recharges SET provider='Du / Etisalat'");
    $pdo->exec("UPDATE recharges SET service_type='رصيد' WHERE source_type='daily_transaction' OR service_type IS NULL OR service_type=''");

    $pdo->exec("CREATE TABLE IF NOT EXISTS recharge_device_balances (
        provider VARCHAR(30) PRIMARY KEY,
        available_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Track each imported شحن رصيد credit once so repeated syncs never add it twice.
    $pdo->exec("CREATE TABLE IF NOT EXISTS recharge_balance_imports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_key VARCHAR(191) NOT NULL UNIQUE,
        movement_date DATE NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_recharge_balance_import_date (movement_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Used to record adding credit to recharge devices as cash drawer withdrawals.
    $pdo->exec("CREATE TABLE IF NOT EXISTS drawer_withdrawals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        withdrawn_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_drawer_withdrawals_date (withdrawn_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // One shared device balance for all recharge providers.
    // If older provider balances exist, the first run migrates them into this total row.
    $checkTotal = $pdo->prepare("SELECT COUNT(*) FROM recharge_device_balances WHERE provider = ?");
    $checkTotal->execute([total_device_provider_key()]);
    if ((int)$checkTotal->fetchColumn() === 0) {
        $oldTotal = (float)$pdo->query("SELECT COALESCE(SUM(available_amount), 0) FROM recharge_device_balances")->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO recharge_device_balances (provider, available_amount, updated_at) VALUES (?, ?, NOW())");
        $stmt->execute([total_device_provider_key(), $oldTotal]);
    }
}

function get_device_balance($pdo) {
    // Device balance source of truth:
    // SUM(withdrawals containing "شحن رصيد")
    // MINUS SUM(cost of sales containing "رصيد").
    if (function_exists('calculate_device_balance_from_daily_source')) {
        $summary = calculate_device_balance_from_daily_source($pdo);
        return (float)$summary['balance'];
    }

    // Fallback for very early initialization.
    $stmt = $pdo->prepare("SELECT available_amount FROM recharge_device_balances WHERE provider = ?");
    $stmt->execute([total_device_provider_key()]);
    $value = $stmt->fetchColumn();
    return $value === false ? 0.0 : (float)$value;
}

function add_device_balance($pdo, $amount) {
    $stmt = $pdo->prepare("INSERT INTO recharge_device_balances (provider, available_amount, updated_at)
                           VALUES (?, ?, NOW())
                           ON DUPLICATE KEY UPDATE available_amount = available_amount + VALUES(available_amount), updated_at = NOW()");
    $stmt->execute([total_device_provider_key(), $amount]);
}

function calculate_device_balance_from_daily_source($pdo) {
    $creditTotal = 0.0;
    $salesCostTotal = 0.0;

    // 1) Every withdrawal containing "شحن رصيد" is device credit.
    if (recharge_db_table_exists($pdo, 'drawer_withdrawals')) {
        $stmt = $pdo->query("
            SELECT name, amount
            FROM drawer_withdrawals
            WHERE amount > 0
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if (!recharge_text_contains_local($name, 'شحن رصيد')) continue;
            $creditTotal += abs((float)($row['amount'] ?? 0));
        }
    }

    // 2) Every completed/real sale containing "رصيد" deducts its cost.
    // imported_cost is preferred; if unavailable/zero, use total_amount.
    if (recharge_db_table_exists($pdo, 'sales_records')) {
        $hasImportedCost = db_column_exists_recharge($pdo, 'sales_records', 'imported_cost');

        $sql = "SELECT item_name, barcode, total_amount";
        $sql .= $hasImportedCost ? ", imported_cost" : ", NULL AS imported_cost";
        $sql .= " FROM sales_records ORDER BY id ASC";

        $stmt = $pdo->query($sql);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sale) {
            $lookup = trim((string)($sale['item_name'] ?? '') . ' ' . (string)($sale['barcode'] ?? ''));
            if (!recharge_text_contains_local($lookup, 'رصيد')) continue;

            $importedCost = round((float)($sale['imported_cost'] ?? 0), 2);
            $saleAmount = round((float)($sale['total_amount'] ?? 0), 2);
            $cost = $importedCost > 0 ? $importedCost : $saleAmount;

            if ($cost > 0) {
                $salesCostTotal += $cost;
            }
        }
    }

    $balance = round($creditTotal - $salesCostTotal, 2);

    return [
        'credit_total' => round($creditTotal, 2),
        'sales_cost_total' => round($salesCostTotal, 2),
        'balance' => $balance,
    ];
}

function refresh_device_balance_from_daily_source($pdo) {
    $summary = calculate_device_balance_from_daily_source($pdo);

    // Keep the existing balance table synchronized for compatibility
    // with the rest of the Recharge page.
    set_device_balance($pdo, $summary['balance']);

    return $summary;
}

function set_device_balance($pdo, $amount) {
    $stmt = $pdo->prepare("INSERT INTO recharge_device_balances (provider, available_amount, updated_at)
                           VALUES (?, ?, NOW())
                           ON DUPLICATE KEY UPDATE available_amount = VALUES(available_amount), updated_at = NOW()");
    $stmt->execute([total_device_provider_key(), $amount]);
}

function apply_recharge_balance_effect($pdo, $provider, $costAmount, $status, $direction) {
    // Do not block or skip a رصيد sale because of the current device balance.
    // Device Balance is always recalculated as:
    // total شحن رصيد withdrawals - total cost of all رصيد sales.
    // Therefore the balance is allowed to become negative.
    return;
}

function db_column_exists_recharge($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function ensure_sales_records_for_recharges($pdo) {
    // Make sure a completed recharge can appear in view_transactions.php as a normal SALE row.
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT DEFAULT NULL,
        barcode VARCHAR(255) DEFAULT NULL,
        item_name VARCHAR(255) DEFAULT NULL,
        qty_sold INT NOT NULL DEFAULT 1,
        sale_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'CASH',
        sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sales_records_date (sold_at),
        INDEX idx_sales_records_barcode (barcode)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!db_column_exists_recharge($pdo, 'sales_records', 'imported_cost')) {
        $pdo->exec("ALTER TABLE sales_records ADD COLUMN imported_cost DECIMAL(10,2) DEFAULT NULL AFTER total_amount");
    }
    if (!db_column_exists_recharge($pdo, 'sales_records', 'recharge_id')) {
        $pdo->exec("ALTER TABLE sales_records ADD COLUMN recharge_id INT DEFAULT NULL AFTER id");
    }
}

function recharge_sale_item_name($provider, $serviceType, $customerNumber, $notes) {
    $parts = ['Recharge', trim((string)$provider), trim((string)$serviceType)];
    $customerNumber = trim((string)$customerNumber);
    if ($customerNumber !== '') {
        $parts[] = $customerNumber;
    }
    $name = implode(' - ', array_filter($parts, fn($v) => $v !== ''));
    if ($name === '') {
        $name = 'Recharge Sale';
    }
    return function_exists('mb_substr') ? mb_substr($name, 0, 250, 'UTF-8') : substr($name, 0, 250);
}

function sync_recharge_sale_record($pdo, $rechargeId) {
    ensure_sales_records_for_recharges($pdo);

    $stmt = $pdo->prepare("SELECT * FROM recharges WHERE id = ?");
    $stmt->execute([$rechargeId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    // Remove the old linked sale first to prevent duplicates in transactions.
    $delete = $pdo->prepare("DELETE FROM sales_records WHERE recharge_id = ?");
    $delete->execute([$rechargeId]);

    if (!$r || $r['status'] !== 'Completed') {
        return;
    }

    $provider = valid_provider($r['provider'] ?? 'Du / Etisalat');
    $barcode = 'RECHARGE-' . strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $provider));
    $itemName = recharge_sale_item_name($provider, $r['service_type'] ?? 'Recharge', $r['customer_number'] ?? '', $r['notes'] ?? '');
    $amountPaid = round((float)($r['amount_paid'] ?? 0), 2);
    $costAmount = round((float)($r['cost_amount'] ?? 0), 2);
    $createdAt = !empty($r['created_at']) ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : date('Y-m-d H:i:s');

    $insert = $pdo->prepare("INSERT INTO sales_records
        (recharge_id, product_id, barcode, item_name, qty_sold, sale_price, total_amount, imported_cost, payment_method, sold_at)
        VALUES (?, NULL, ?, ?, 1, ?, ?, ?, 'CASH', ?)");
    $insert->execute([$rechargeId, $barcode, $itemName, $amountPaid, $amountPaid, $costAmount, $createdAt]);
}

function delete_recharge_sale_record($pdo, $rechargeId) {
    if (db_column_exists_recharge($pdo, 'sales_records', 'recharge_id')) {
        $stmt = $pdo->prepare("DELETE FROM sales_records WHERE recharge_id = ?");
        $stmt->execute([$rechargeId]);
    }
}

function sync_missing_recharge_sales($pdo) {
    ensure_sales_records_for_recharges($pdo);
    $stmt = $pdo->query("SELECT r.id
                         FROM recharges r
                         LEFT JOIN sales_records sr ON sr.recharge_id = r.id
                         WHERE r.status = 'Completed'
                           AND (r.source_type IS NULL OR r.source_type <> 'daily_transaction')
                           AND sr.id IS NULL
                         ORDER BY r.id ASC
                         LIMIT 200");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        sync_recharge_sale_record($pdo, (int)$id);
    }
}

function detect_daily_recharge_provider($text) {
    $text = trim((string)$text);
    if ($text === '') return null;

    if (function_exists('mb_strpos')) {
        return mb_strpos($text, 'رصيد', 0, 'UTF-8') !== false ? 'Du / Etisalat' : null;
    }
    return strpos($text, 'رصيد') !== false ? 'Du / Etisalat' : null;
}


function recharge_text_contains_local($text, $needle) {
    $text = trim((string)$text);
    $needle = trim((string)$needle);
    if ($text === '' || $needle === '') return false;

    if (function_exists('mb_strpos')) {
        return mb_strpos($text, $needle, 0, 'UTF-8') !== false;
    }
    return strpos($text, $needle) !== false;
}

function sync_daily_sheet_device_credits($pdo, $startDate, $endDate) {
    ensure_recharges_tables($pdo);

    if (!recharge_db_table_exists($pdo, 'drawer_withdrawals')) {
        return ['matched'=>0, 'added'=>0, 'amount'=>0.0, 'already_synced'=>0];
    }

    $hasLegacyKey = db_column_exists_recharge($pdo, 'drawer_withdrawals', 'legacy_import_key');
    $hasImportSource = db_column_exists_recharge($pdo, 'drawer_withdrawals', 'import_source');

    $sql = "SELECT id, name, amount, withdrawn_at";
    $sql .= $hasLegacyKey ? ", legacy_import_key" : ", NULL AS legacy_import_key";
    $sql .= $hasImportSource ? ", import_source" : ", NULL AS import_source";
    $sql .= " FROM drawer_withdrawals
              WHERE DATE(withdrawn_at) BETWEEN ? AND ?
                AND amount > 50
              ORDER BY withdrawn_at ASC, id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$startDate, $endDate]);

    $matched = 0;
    $added = 0;
    $already = 0;
    $totalAdded = 0.0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $description = trim((string)($row['name'] ?? ''));
        // ONLY withdrawals containing شحن رصيد are treated as device-credit top-ups.
        if (!recharge_text_contains_local($description, 'شحن رصيد')) {
            continue;
        }

        $amount = round(abs((float)($row['amount'] ?? 0)), 2);
        if ($amount <= 50) continue;
        $matched++;

        $legacyKey = trim((string)($row['legacy_import_key'] ?? ''));
        $importKey = $legacyKey !== ''
            ? 'drawer:' . $legacyKey
            : 'drawer_id:' . (int)$row['id'];

        $check = $pdo->prepare("SELECT id FROM recharge_balance_imports WHERE import_key=? LIMIT 1");
        $check->execute([$importKey]);
        if ($check->fetchColumn()) {
            $already++;
            continue;
        }

        // No direct balance mutation is needed.
        // The balance is calculated live from all شحن رصيد withdrawals.
        $movementDate = !empty($row['withdrawn_at'])
            ? date('Y-m-d', strtotime($row['withdrawn_at']))
            : date('Y-m-d');

        $insert = $pdo->prepare("
            INSERT INTO recharge_balance_imports
                (import_key, movement_date, description, amount)
            VALUES (?, ?, ?, ?)
        ");
        $insert->execute([$importKey, $movementDate, $description, $amount]);

        $added++;
        $totalAdded += $amount;
    }

    return [
        'matched'=>$matched,
        'added'=>$added,
        'amount'=>$totalAdded,
        'already_synced'=>$already
    ];
}

function sync_daily_transactions_to_recharges($pdo, $startDate, $endDate) {
    // Schema preparation must happen outside a MySQL transaction because
    // CREATE/ALTER TABLE can implicitly commit the active transaction.
    if (!$pdo->inTransaction()) {
        ensure_recharges_tables($pdo);
        ensure_sales_records_for_recharges($pdo);
    }

    $startDate = date('Y-m-d', strtotime($startDate ?: date('Y-m-d')));
    $endDate = date('Y-m-d', strtotime($endDate ?: $startDate));

    // The Excel importer is the authoritative creator of daily_transaction
    // recharge rows. This manual sync is now a SAFE catch-up:
    // it only inserts missing source_sale_id rows and never removes/rebuilds
    // existing rows, so device balance cannot be restored/deducted repeatedly.
    $salesStmt = $pdo->prepare("
        SELECT id, barcode, item_name, qty_sold, sale_price, total_amount,
               imported_cost, payment_method, sold_at, import_source
        FROM sales_records
        WHERE DATE(sold_at) BETWEEN ? AND ?
          AND (recharge_id IS NULL OR recharge_id = 0)
        ORDER BY sold_at ASC, id ASC
    ");
    $salesStmt->execute([$startDate, $endDate]);

    $imported = 0;
    $skippedBalance = 0;
    $matched = 0;
    $alreadySynced = 0;

    foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $sale) {
        $lookupText = trim(($sale['item_name'] ?? '') . ' ' . ($sale['barcode'] ?? ''));
        $provider = detect_daily_recharge_provider($lookupText);
        if (!$provider) continue;
        $matched++;

        $saleId = (int)($sale['id'] ?? 0);
        if ($saleId <= 0) continue;

        // Never deduct twice for the same transaction.
        $exists = $pdo->prepare("
            SELECT id
            FROM recharges
            WHERE source_type = 'daily_transaction'
              AND source_sale_id = ?
            LIMIT 1
        ");
        $exists->execute([$saleId]);
        if ($exists->fetchColumn()) {
            $alreadySynced++;
            continue;
        }

        $amountPaid = round((float)($sale['total_amount'] ?? 0), 2);
        $importedCost = round((float)($sale['imported_cost'] ?? 0), 2);

        // For imported Excel rows, imported_cost is already the cost belonging
        // to this sale row. For non-imported rows, fall back to amount paid.
        $costAmount = $importedCost > 0 ? $importedCost : $amountPaid;
        if ($costAmount <= 0 && $amountPaid > 0) $costAmount = $amountPaid;

        if ($amountPaid < 0 || $costAmount < 0) {
            continue;
        }
$provider = 'Du / Etisalat';
        $serviceType = 'رصيد';
        $customerNumber = (string)($sale['barcode'] ?? '');
        $name = (string)($sale['item_name'] ?? '');
        $notes = 'Synced from transaction #' . $saleId;
        if ($name !== '') $notes .= ' - ' . $name;
        $createdAt = !empty($sale['sold_at'])
            ? date('Y-m-d H:i:s', strtotime($sale['sold_at']))
            : date('Y-m-d H:i:s');

        apply_recharge_balance_effect($pdo, $provider, $costAmount, 'Completed', -1);

        $insert = $pdo->prepare("
            INSERT INTO recharges
                (provider, service_type, customer_number, amount_paid, cost_amount,
                 payment_method, status, notes, created_at, source_type, source_sale_id)
            VALUES (?, ?, ?, ?, ?, 'CASH', 'Completed', ?, ?, 'daily_transaction', ?)
        ");
        $insert->execute([
            $provider, $serviceType, $customerNumber, $amountPaid, $costAmount,
            $notes, $createdAt, $saleId
        ]);
        $imported++;
    }

    return [
        'removed' => 0,
        'matched' => $matched,
        'imported' => $imported,
        'already_synced' => $alreadySynced,
        'skipped_balance' => $skippedBalance,
        'start' => $startDate,
        'end' => $endDate,
    ];
}

try {
    ensure_recharges_tables($pdo);
    ensure_sales_records_for_recharges($pdo);
    sync_missing_recharge_sales($pdo);
} catch (Exception $e) {
    $message = "<div class='alert alert-danger'>Database setup error: " . h($e->getMessage()) . "</div>";
}

$editRecharge = null;

// Set total device balance for all providers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_device_balance'])) {
    try {
        $amount = round(floatval($_POST['available_amount'] ?? 0), 2);
        if ($amount < 0) {
            throw new Exception('Device balance cannot be negative.');
        }
        set_device_balance($pdo, $amount);
        $message = "<div class='alert alert-success'>Total device balance for all providers set to <strong>" . money_smart($amount) . "</strong>.</div>";
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Balance update error: " . h($e->getMessage()) . "</div>";
    }
}

// Add device credit and record it as drawer withdrawal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_device_credit'])) {
    try {
        $amount = round(floatval($_POST['credit_amount'] ?? 0), 2);
        $note = trim($_POST['credit_note'] ?? '');

        if ($amount <= 0) {
            throw new Exception('Credit amount must be greater than 0.');
        }

        $withdrawName = "شحن رصيد";
        if ($note !== '') {
            $withdrawName .= " - " . $note;
        }

        $pdo->beginTransaction();
        add_device_balance($pdo, $amount);

        // In your drawer system: positive amount = withdrawal from drawer.
        $stmt = $pdo->prepare("INSERT INTO drawer_withdrawals (name, amount, withdrawn_at) VALUES (?, ?, NOW())");
        $stmt->execute([$withdrawName, $amount]);

        if ($pdo->inTransaction()) $pdo->commit();
        $message = "<div class='alert alert-success'>Added <strong>" . money_smart($amount) . "</strong> credit to total device balance and recorded it as a cash drawer withdrawal.</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger'>Add credit error: " . h($e->getMessage()) . "</div>";
    }
}

// Sync Recharge device credits + sales from all available Daily Cash Sheet dates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_daily_sheet_recharges'])) {
    try {
        ensure_recharges_tables($pdo);
        ensure_sales_records_for_recharges($pdo);

        $syncStart = null;
        $syncEnd = null;

        if (recharge_db_table_exists($pdo, 'daily_cash_sheets')) {
            $rangeStmt = $pdo->query("
                SELECT MIN(sheet_date) AS min_date, MAX(sheet_date) AS max_date
                FROM daily_cash_sheets
            ");
            $range = $rangeStmt ? $rangeStmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($range) {
                $syncStart = !empty($range['min_date']) ? (string)$range['min_date'] : null;
                $syncEnd = !empty($range['max_date']) ? (string)$range['max_date'] : null;
            }
        }

        // Also include any transaction/drawer dates outside daily_cash_sheets.
        $salesRangeStmt = $pdo->query("
            SELECT DATE(MIN(sold_at)) AS min_date, DATE(MAX(sold_at)) AS max_date
            FROM sales_records
        ");
        $salesRange = $salesRangeStmt ? $salesRangeStmt->fetch(PDO::FETCH_ASSOC) : null;

        $drawerRange = null;
        if (recharge_db_table_exists($pdo, 'drawer_withdrawals')) {
            $drawerRangeStmt = $pdo->query("
                SELECT DATE(MIN(withdrawn_at)) AS min_date, DATE(MAX(withdrawn_at)) AS max_date
                FROM drawer_withdrawals
            ");
            $drawerRange = $drawerRangeStmt ? $drawerRangeStmt->fetch(PDO::FETCH_ASSOC) : null;
        }

        $candidateStarts = array_filter([
            $syncStart,
            $salesRange['min_date'] ?? null,
            $drawerRange['min_date'] ?? null
        ]);
        $candidateEnds = array_filter([
            $syncEnd,
            $salesRange['max_date'] ?? null,
            $drawerRange['max_date'] ?? null
        ]);

        if ($candidateStarts) $syncStart = min($candidateStarts);
        if ($candidateEnds) $syncEnd = max($candidateEnds);

        if (!$syncStart || !$syncEnd) {
            throw new Exception('No Daily Sheet / transaction dates were found to sync.');
        }

        $pdo->beginTransaction();

        // IMPORTANT ORDER:
        // 1) Add شحن رصيد credits to the device balance.
        // 2) Then deduct missing رصيد sales.
        $creditResult = sync_daily_sheet_device_credits($pdo, $syncStart, $syncEnd);
        $saleResult = sync_daily_transactions_to_recharges($pdo, $syncStart, $syncEnd);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $newBalance = get_device_balance($pdo);

        $message = "<div class='alert alert-success'>" .
            "<strong>Daily Sheet Recharge sync complete.</strong> " .
            "شحن رصيد credits found: <strong>" . (int)$creditResult['matched'] . "</strong>, " .
            "new credits added: <strong>" . (int)$creditResult['added'] . "</strong> " .
            "(" . money_smart($creditResult['amount']) . " AED), " .
            "already credited: <strong>" . (int)$creditResult['already_synced'] . "</strong>. " .
            "رصيد sales found: <strong>" . (int)$saleResult['matched'] . "</strong>, " .
            "already synced: <strong>" . (int)($saleResult['already_synced'] ?? 0) . "</strong>, " .
            "new sales deducted: <strong>" . (int)$saleResult['imported'] . "</strong>. " .
            "<br><strong>Device Balance Left: " . money_smart($newBalance) . " AED</strong>";

        $message .= "</div>";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "<div class='alert alert-danger'>Daily Sheet Recharge sync error: " .
            h($e->getMessage()) . "</div>";
    }
}

// Delete selected
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $ids = $_POST['selected_ids'] ?? [];
    $ids = array_values(array_filter(array_map('intval', (array)$ids)));
    if (!empty($ids)) {
        try {
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, provider, cost_amount, status FROM recharges WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $old) {
                apply_recharge_balance_effect($pdo, $old['provider'], (float)$old['cost_amount'], $old['status'], +1);
                delete_recharge_sale_record($pdo, (int)$old['id']);
            }
            $stmt = $pdo->prepare("DELETE FROM recharges WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            if ($pdo->inTransaction()) $pdo->commit();
            $message = "<div class='alert alert-success'>Deleted <strong>" . count($ids) . "</strong> selected recharge records and restored device balance for completed records.</div>";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "<div class='alert alert-danger'>Delete error: " . h($e->getMessage()) . "</div>";
        }
    } else {
        $message = "<div class='alert alert-warning'>Please select at least one recharge record to delete.</div>";
    }
}

// Single delete
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
    try {
        $pdo->beginTransaction();
        $deleteId = intval($_GET['id']);
        $stmt = $pdo->prepare("SELECT provider, cost_amount, status FROM recharges WHERE id = ?");
        $stmt->execute([$deleteId]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($old) {
            apply_recharge_balance_effect($pdo, $old['provider'], (float)$old['cost_amount'], $old['status'], +1);
            delete_recharge_sale_record($pdo, $deleteId);
        }
        $stmt = $pdo->prepare("DELETE FROM recharges WHERE id = ?");
        $stmt->execute([$deleteId]);
        if ($pdo->inTransaction()) $pdo->commit();
        header('Location: recharges.php?deleted=1');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger'>Delete error: " . h($e->getMessage()) . "</div>";
    }
}

if (isset($_GET['deleted'])) {
    $message = "<div class='alert alert-success'>Recharge record deleted and device balance restored if needed.</div>";
}

// Load edit
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
    $stmt = $pdo->prepare("SELECT * FROM recharges WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editRecharge = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Add / update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_recharge']) || isset($_POST['update_recharge']))) {
    $provider = valid_provider($_POST['provider'] ?? 'Du / Etisalat');
    $serviceType = trim($_POST['service_type'] ?? 'رصيد');
    $customerNumber = trim($_POST['customer_number'] ?? '');
    $amountPaid = round(floatval($_POST['amount_paid'] ?? 0), 2);
    $costAmount = round(floatval($_POST['cost_amount'] ?? 0), 2);
    $paymentMethod = 'CASH'; // Recharges are cash only.
    $status = valid_status($_POST['status'] ?? 'Completed');
    $notes = trim($_POST['notes'] ?? '');
    $createdAtInput = trim($_POST['created_at'] ?? '');

    if ($serviceType === '') $serviceType = 'رصيد';
    if ($costAmount <= 0 && $amountPaid > 0) $costAmount = $amountPaid; // profit 0 by default when cost left blank

    if ($amountPaid < 0 || $costAmount < 0) {
        $message = "<div class='alert alert-danger'>Amount and cost cannot be negative.</div>";
    } else {
        try {
            $createdAt = date('Y-m-d H:i:s');
            if ($createdAtInput !== '') {
                $ts = strtotime($createdAtInput);
                if ($ts !== false) $createdAt = date('Y-m-d H:i:s', $ts);
            }

            $pdo->beginTransaction();

            if (isset($_POST['update_recharge'])) {
                $id = intval($_POST['recharge_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT provider, cost_amount, status FROM recharges WHERE id = ?");
                $stmt->execute([$id]);
                $old = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$old) {
                    throw new Exception('Recharge record not found.');
                }

                // Restore old completed recharge first, then deduct new completed recharge.
                apply_recharge_balance_effect($pdo, $old['provider'], (float)$old['cost_amount'], $old['status'], +1);
                apply_recharge_balance_effect($pdo, $provider, $costAmount, $status, -1);

                $stmt = $pdo->prepare("UPDATE recharges SET provider=?, service_type=?, customer_number=?, amount_paid=?, cost_amount=?, payment_method=?, status=?, notes=?, created_at=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$provider, $serviceType, $customerNumber, $amountPaid, $costAmount, $paymentMethod, $status, $notes, $createdAt, $id]);
                sync_recharge_sale_record($pdo, $id);
                if ($pdo->inTransaction()) $pdo->commit();
                header('Location: recharges.php?updated=1');
                exit;
            } else {
                apply_recharge_balance_effect($pdo, $provider, $costAmount, $status, -1);
                $stmt = $pdo->prepare("INSERT INTO recharges (provider, service_type, customer_number, amount_paid, cost_amount, payment_method, status, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$provider, $serviceType, $customerNumber, $amountPaid, $costAmount, $paymentMethod, $status, $notes, $createdAt]);
                $newRechargeId = (int)$pdo->lastInsertId();
                sync_recharge_sale_record($pdo, $newRechargeId);
                if ($pdo->inTransaction()) $pdo->commit();
                header('Location: recharges.php?saved=1');
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "<div class='alert alert-danger'>Save error: " . h($e->getMessage()) . "</div>";
        }
    }
}

if (isset($_GET['barcode_saved'])) {
    $message = "<div class='alert alert-success'>Recharge barcode saved.</div>";
}
if (isset($_GET['barcode_deleted'])) {
    $message = "<div class='alert alert-success'>Recharge barcode deleted.</div>";
}

if (isset($_GET['saved'])) {
    $message = "<div class='alert alert-success'>Recharge record saved, device balance deducted, and added to transactions as SALE.</div>";
}
if (isset($_GET['updated'])) {
    $message = "<div class='alert alert-success'>Recharge record updated, device balance adjusted, and transactions SALE synced.</div>";
}

$deviceBalanceSummary = refresh_device_balance_from_daily_source($pdo);
$deviceBalance = (float)$deviceBalanceSummary['balance'];
$deviceCreditTotal = (float)$deviceBalanceSummary['credit_total'];
$deviceSalesCostTotal = (float)$deviceBalanceSummary['sales_cost_total'];

// Filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$providerFilter = $_GET['provider'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$movementFilter = $_GET['movement_type'] ?? '';
$search = trim($_GET['search'] ?? '');
$showAll = isset($_GET['all']) && $_GET['all'] === '1';

$where = [];
$params = [];

if (!$showAll) {
    $where[] = "DATE(created_at) BETWEEN ? AND ?";
    $params[] = $startDate;
    $params[] = $endDate;
}
if ($providerFilter !== '') {
    $where[] = "provider = ?";
    $params[] = $providerFilter;
}
if ($statusFilter !== '') {
    $where[] = "status = ?";
    $params[] = $statusFilter;
}
if ($movementFilter === 'added') {
    // Added rows come from drawer_withdrawals, so no recharge sales are selected.
    $where[] = "1 = 0";
}
if ($search !== '') {
    $where[] = "(customer_number LIKE ? OR service_type LIKE ? OR notes LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM recharges $whereSql ORDER BY created_at DESC, id DESC");
$stmt->execute($params);
$recharges = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Build one list containing both Recharge SALES and device-balance ADDED credits.
$rechargeMovements = [];
foreach ($recharges as $r) {
    $r['_movement_type'] = 'sale';
    $rechargeMovements[] = $r;
}

if ($movementFilter !== 'sales' && recharge_db_table_exists($pdo, 'drawer_withdrawals')) {
    $creditWhere = [];
    $creditParams = [];
    if (!$showAll) {
        $creditWhere[] = "DATE(withdrawn_at) BETWEEN ? AND ?";
        $creditParams[] = $startDate;
        $creditParams[] = $endDate;
    }
    $creditWhere[] = "amount > 0";
    if ($search !== '') {
        $creditWhere[] = "name LIKE ?";
        $creditParams[] = "%$search%";
    }
    $creditSql = $creditWhere ? ('WHERE ' . implode(' AND ', $creditWhere)) : '';
    $creditStmt = $pdo->prepare("SELECT id, name, amount, withdrawn_at FROM drawer_withdrawals $creditSql ORDER BY withdrawn_at DESC, id DESC");
    $creditStmt->execute($creditParams);
    foreach ($creditStmt->fetchAll(PDO::FETCH_ASSOC) as $credit) {
        $description = trim((string)($credit['name'] ?? ''));
        if (!recharge_text_contains_local($description, 'شحن رصيد')) continue;
        $rechargeMovements[] = [
            'id' => 'credit_' . (int)$credit['id'],
            'provider' => 'Du / Etisalat',
            'service_type' => 'شحن رصيد',
            'customer_number' => '',
            'amount_paid' => (float)$credit['amount'],
            'cost_amount' => 0,
            'payment_method' => 'CASH',
            'status' => 'Added',
            'notes' => $description,
            'created_at' => $credit['withdrawn_at'],
            '_movement_type' => 'added',
        ];
    }
}

usort($rechargeMovements, function($a, $b) {
    $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
    return $tb <=> $ta;
});
$recharges = $rechargeMovements;

$movementTotals = [
    'added' => 0.0,
    'sales_paid' => 0.0,
    'sales_cost' => 0.0,
    'sales_profit' => 0.0,
];
foreach ($recharges as $movementRow) {
    $movementAmount = (float)($movementRow['amount_paid'] ?? 0);
    if (($movementRow['_movement_type'] ?? 'sale') === 'added') {
        $movementTotals['added'] += $movementAmount;
        continue;
    }
    $movementCost = (float)($movementRow['cost_amount'] ?? 0);
    $movementTotals['sales_paid'] += $movementAmount;
    $movementTotals['sales_cost'] += $movementCost;
    $movementTotals['sales_profit'] += ($movementAmount - $movementCost);
}

$totals = [
    'count' => 0,
    'amount' => 0,
    'cost' => 0,
    'profit' => 0,
    'cash' => 0,
    'Du / Etisalat' => 0,
    'completed' => 0,
    'pending' => 0,
    'failed' => 0,
    'refunded' => 0,
];

foreach ($recharges as $r) {
    $amount = (float)$r['amount_paid'];
    $cost = (float)$r['cost_amount'];
    $profit = $amount - $cost;
    if (($r['_movement_type'] ?? 'sale') === 'added') {
        continue;
    }
    $totals['count']++;
    $totals['amount'] += $amount;
    $totals['cost'] += $cost;
    $totals['profit'] += $profit;
    $totals['cash'] += $amount;

    if (isset($totals[$r['provider']])) $totals[$r['provider']] += $amount;

    $statusKey = strtolower($r['status']);
    if (isset($totals[$statusKey])) $totals[$statusKey]++;
}

function badge_class_payment($payment) {
    return 'success';
}

function badge_class_status($status) {
    if ($status === 'Completed') return 'success';
    if ($status === 'Pending') return 'warning';
    if ($status === 'Failed') return 'danger';
    if ($status === 'Refunded') return 'secondary';
    return 'dark';
}

$form = $editRecharge ?: [
    'id' => '',
    'provider' => 'Du / Etisalat',
    'service_type' => 'رصيد',
    'customer_number' => '',
    'amount_paid' => '',
    'cost_amount' => '',
    'payment_method' => 'CASH',
    'status' => 'Completed',
    'notes' => '',
    'created_at' => date('Y-m-d\TH:i')
];

$form['provider'] = valid_provider($form['provider'] ?? 'Du / Etisalat');
$createdValue = '';
if (!empty($form['created_at'])) {
    $createdValue = date('Y-m-d\TH:i', strtotime($form['created_at']));
}

// Dashboard shell identity. Keep Recharge functionality available even if this page is opened directly.
$role = (($_SESSION['role'] ?? 'admin') === 'viewer') ? 'viewer' : 'admin';
$displayName = trim((string)($_SESSION['display_name'] ?? ''));
if ($displayName === '') $displayName = (string)($_SESSION['username'] ?? ucfirst($role));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Recharge Management - Rukn Al Lotus</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{--blue:#3154e8;--blue2:#5978ff;--ink:#152033;--muted:#718096;--line:#e7ebf2;--bg:#f5f7fb;--sidebar:#111a2d;--sidebar2:#17233a}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}.app{min-height:100vh}.sidebar{position:fixed;inset:0 auto 0 0;width:270px;background:linear-gradient(180deg,var(--sidebar),#0d1526);color:#d6deeb;padding:20px 16px;overflow-y:auto;z-index:1040}.brand{display:flex;align-items:center;gap:12px;padding:2px 8px 22px}.brand-mark{width:42px;height:42px;border-radius:13px;background:linear-gradient(135deg,#6380ff,#3154e8);display:grid;place-items:center;color:white;font-weight:900;box-shadow:0 8px 22px rgba(49,84,232,.3)}.brand-title{font-weight:850;color:#fff;line-height:1.05}.brand-sub{font-size:.72rem;color:#8996aa;margin-top:4px}.nav-label{font-size:.66rem;text-transform:uppercase;letter-spacing:.12em;color:#64748b;font-weight:800;margin:18px 10px 7px}.side-link{display:flex;align-items:center;gap:11px;color:#aeb9c9;text-decoration:none;padding:10px 12px;border-radius:11px;font-size:.84rem;font-weight:650;margin:2px 0;transition:.15s}.side-link i{width:20px;text-align:center;font-size:1rem}.side-link:hover{background:#1d2a44;color:#fff}.side-link.active{background:linear-gradient(90deg,#3154e8,#4a6af1);color:#fff;box-shadow:0 8px 20px rgba(49,84,232,.22)}.main{margin-left:270px;width:calc(100% - 270px);padding:20px 24px 34px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:18px}.page-kicker{font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:#8290a6;font-weight:800}.page-title{font-size:1.3rem;font-weight:850;margin:2px 0 0}.mobile-toggle{display:none}.user-chip{display:flex;align-items:center;gap:9px;background:#fff;border:1px solid var(--line);padding:7px 9px;border-radius:14px;box-shadow:0 4px 16px rgba(18,32,51,.04)}.user-avatar{width:34px;height:34px;border-radius:10px;background:#edf2ff;color:var(--blue);display:grid;place-items:center;font-weight:900}.user-name{font-size:.8rem;font-weight:800;line-height:1.1}.role-badge{font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;color:#8490a3;font-weight:800;margin-top:3px}.recharge-hero{background:linear-gradient(135deg,#243dd0,#4469ee 58%,#6985ff);border-radius:20px;padding:22px 24px;color:#fff;box-shadow:0 14px 34px rgba(49,84,232,.20);margin-bottom:18px;position:relative;overflow:hidden}.recharge-hero:after{content:"";position:absolute;width:250px;height:250px;border-radius:50%;background:rgba(255,255,255,.08);right:-70px;top:-100px}.recharge-hero h2{font-size:1.45rem;font-weight:900;margin:0 0 6px}.recharge-hero p{margin:0;color:rgba(255,255,255,.82);font-size:.86rem;max-width:780px}.hero-actions{position:relative;z-index:1}.hero-actions .btn{border-radius:10px;font-weight:750}.card-box{border:1px solid var(--line)!important;border-radius:16px!important;box-shadow:0 6px 22px rgba(18,32,51,.055)!important;background:#fff}.summary-card{min-height:124px;position:relative;overflow:hidden}.summary-card:after{content:"";position:absolute;right:-25px;bottom:-35px;width:90px;height:90px;border-radius:50%;background:#f1f5ff}.summary-card>*,.summary-card .fs-4{position:relative;z-index:1}.summary-card .fs-4{font-size:1.55rem!important;margin-top:9px}.metric-icon{width:36px;height:36px;border-radius:11px;background:#edf2ff;color:var(--blue);display:grid;place-items:center;margin-bottom:8px}.form-control,.form-select{border-radius:10px;border-color:#dce2ec;min-height:42px}.form-control:focus,.form-select:focus{border-color:#a7b5ff;box-shadow:0 0 0 .2rem rgba(49,84,232,.10)}.btn{border-radius:10px}.amount-input{font-size:1.05rem;font-weight:800}.table-responsive{border-radius:12px}.table thead th{white-space:nowrap;font-size:.71rem;text-transform:uppercase;letter-spacing:.045em;color:#69758c;background:#f8faff;border-bottom-color:#e6ebf3}.table tbody td{font-size:.82rem;vertical-align:middle;border-bottom-color:#eff2f6}.table-hover tbody tr:hover{background:#f8faff}.alert{border-radius:12px}.border-start{border-left-width:1px!important}.panel-accent-success{border-top:4px solid #22a06b!important}.panel-accent-warning{border-top:4px solid #f4b740!important}.panel-accent-info{border-top:4px solid #42a5f5!important}.panel-accent-primary{border-top:4px solid #3154e8!important}.status-mini .card-box{transition:.15s}.status-mini .card-box:hover{transform:translateY(-2px);box-shadow:0 10px 25px rgba(18,32,51,.08)!important}
        @media(max-width:991px){.sidebar{transform:translateX(-100%);transition:.2s}.sidebar.open{transform:translateX(0)}.main{margin-left:0;width:100%;padding:14px}.mobile-toggle{display:inline-flex}.topbar{align-items:flex-start}.user-chip .user-text{display:none}.recharge-hero{padding:18px}.hero-actions{margin-top:14px}}
    
.device-balance-hero{
    background:linear-gradient(135deg,#0f766e,#14b8a6);
    color:#fff;
    border-radius:18px;
    padding:20px 22px;
    margin-bottom:18px;
    box-shadow:0 14px 34px rgba(20,184,166,.18);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
}
.device-balance-hero .label{
    font-size:.76rem;
    text-transform:uppercase;
    letter-spacing:.08em;
    font-weight:800;
    color:rgba(255,255,255,.8);
}
.device-balance-hero .amount{
    font-size:2rem;
    font-weight:900;
    line-height:1.1;
    margin-top:4px;
}
.device-balance-hero .icon{
    width:54px;
    height:54px;
    border-radius:16px;
    background:rgba(255,255,255,.14);
    display:grid;
    place-items:center;
    font-size:1.45rem;
}

</style>

<style>
/* ===== Recharge colorful dashboard cards ===== */
.recharge-top-card{
    position:relative;overflow:hidden;border:0!important;min-height:118px;color:#fff!important;
    box-shadow:0 11px 28px rgba(16,24,40,.12)!important;
    transition:transform .18s ease,box-shadow .18s ease;
}
.recharge-top-card:hover{transform:translateY(-3px);box-shadow:0 16px 34px rgba(16,24,40,.17)!important}
.recharge-top-card::after{
    content:"";position:absolute;width:100px;height:100px;border-radius:50%;
    right:-27px;bottom:-39px;background:rgba(255,255,255,.14);
}
.recharge-top-card::before{
    content:"";position:absolute;width:54px;height:54px;border-radius:50%;
    right:30px;top:-24px;background:rgba(255,255,255,.08);
}
.recharge-top-card > *{position:relative;z-index:1}
.recharge-top-card .text-muted,.recharge-top-card .small,.recharge-top-card small{color:rgba(255,255,255,.84)!important}
.recharge-top-card .fs-4,.recharge-top-card .fw-bold{color:#fff!important}
.recharge-top-card .metric-icon{background:rgba(255,255,255,.18)!important;color:#fff!important}
.recharge-top-card.blue{background:linear-gradient(135deg,#3154e8,#667eea)!important}
.recharge-top-card.green{background:linear-gradient(135deg,#10986a,#2bbf8a)!important}
.recharge-top-card.purple{background:linear-gradient(135deg,#7652d9,#9b72ef)!important}
.recharge-top-card.orange{background:linear-gradient(135deg,#d98b16,#f6b73c)!important}
.recharge-top-card.red{background:linear-gradient(135deg,#d94a62,#f06a7d)!important}
.recharge-top-card.cyan{background:linear-gradient(135deg,#0e8fa8,#29b8d2)!important}

.recharge-mini-card{
    position:relative;overflow:hidden;border:0!important;min-height:92px;color:#fff!important;
    box-shadow:0 8px 22px rgba(16,24,40,.10)!important;transition:.18s ease;
}
.recharge-mini-card:hover{transform:translateY(-2px)}
.recharge-mini-card .fw-bold,.recharge-mini-card > div{color:#fff!important;position:relative;z-index:1}
.recharge-mini-card::after{
    content:"";position:absolute;width:74px;height:74px;border-radius:50%;
    right:-20px;bottom:-28px;background:rgba(255,255,255,.12)
}
.recharge-mini-card.green{background:linear-gradient(135deg,#0f9d69,#26b77f)!important}
.recharge-mini-card.blue{background:linear-gradient(135deg,#3154e8,#5f78ee)!important}
.recharge-mini-card.orange{background:linear-gradient(135deg,#d98b16,#f1ad32)!important}
.recharge-mini-card.red{background:linear-gradient(135deg,#d94a62,#ef6578)!important}
.recharge-mini-card.purple{background:linear-gradient(135deg,#7652d9,#9470e8)!important}
.recharge-mini-card.cyan{background:linear-gradient(135deg,#0e8fa8,#26afc7)!important}
</style>

<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body>
<div class="app">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main">
    <div class="topbar">
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-light border mobile-toggle" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="bi bi-list fs-5"></i></button>
            <div><div class="page-kicker">Sales &amp; Cash</div><div class="page-title">Recharge Management</div></div>
        </div>
        <div class="user-chip"><div class="user-avatar"><?php echo h(strtoupper(substr($displayName,0,1))); ?></div><div class="user-text"><div class="user-name"><?php echo h($displayName); ?></div><div class="role-badge"><?php echo h($role); ?></div></div></div>
    </div>

    <section class="recharge-hero">
        <div class="row align-items-center g-3 position-relative" style="z-index:1">
            <div class="col-lg-8"><h2><i class="bi bi-phone me-2"></i>Recharge Control Center</h2><p>Device Balance = total شحن رصيد withdrawals minus the cost of all رصيد sales.</p></div>
            <div class="col-lg-4"><div class="hero-actions d-flex flex-wrap gap-2 justify-content-lg-end"><a href="view_transactions.php" class="btn btn-light btn-sm"><i class="bi bi-receipt me-1"></i> Transactions</a><a href="reports.php" class="btn btn-outline-light btn-sm"><i class="bi bi-bar-chart me-1"></i> Reports</a></div></div>
        </div>
    </section>

    <?php echo $message; ?>

    <div class="device-balance-hero">
        <div class="d-flex align-items-center gap-3">
            <div class="icon"><i class="bi bi-wallet2"></i></div>
            <div>
                <div class="label">Device Balance Left</div>
                <div class="amount"><?php echo money_smart($deviceBalance); ?> AED</div>
                <div class="small" style="color:rgba(255,255,255,.82)">
                    شحن رصيد Total: <?php echo money_smart($deviceCreditTotal); ?> AED
                    &nbsp;−&nbsp;
                    رصيد Sales Cost: <?php echo money_smart($deviceSalesCostTotal); ?> AED
                </div>
            </div>
        </div>
        <div class="text-end">
            <div class="small" style="color:rgba(255,255,255,.75)">Available Now</div>
            <div class="fw-bold"><i class="bi bi-check-circle me-1"></i>Ready to Use</div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6"><div class="card card-box summary-card recharge-top-card blue p-3"><div class="metric-icon"><i class="bi bi-wallet2"></i></div><div class="text-muted small fw-bold">Device Balance Left</div><div class="fs-4 fw-bold text-success"><?php echo money_smart($deviceBalance); ?></div><small>شحن رصيد minus رصيد sales</small></div></div>
        <div class="col-md-2 col-sm-4"><div class="card card-box summary-card recharge-top-card green p-3"><div class="metric-icon"><i class="bi bi-cash-coin"></i></div><div class="text-muted small fw-bold">Total Received</div><div class="fs-4 fw-bold text-success"><?php echo money_smart($totals['amount']); ?></div><small>Cash only</small></div></div>
        <div class="col-md-2 col-sm-4"><div class="card card-box summary-card recharge-top-card purple p-3"><div class="metric-icon"><i class="bi bi-graph-up-arrow"></i></div><div class="text-muted small fw-bold">Total Profit</div><div class="fs-4 fw-bold text-primary"><?php echo money_smart($totals['profit']); ?></div><small>Received - cost</small></div></div>
    </div>

    <div class="row g-3 mb-4 status-mini">
        <div class="col-md-2"><div class="card card-box recharge-mini-card green p-3 text-center"><div class="fw-bold text-success">CASH</div><div><?php echo money_smart($totals['cash']); ?></div></div></div>
        <?php foreach (recharge_providers() as $provider): ?>
            <div class="col-md-2"><div class="card card-box recharge-mini-card blue p-3 text-center"><div class="fw-bold"><?php echo h($provider); ?></div><div><?php echo money_smart($totals[$provider] ?? 0); ?></div></div></div>
        <?php endforeach; ?>
        <div class="col-md-2"><div class="card card-box recharge-mini-card green p-3 text-center"><div class="fw-bold text-success">Completed</div><div><?php echo number_format($totals['completed']); ?></div></div></div>
        <div class="col-md-2"><div class="card card-box recharge-mini-card orange p-3 text-center"><div class="fw-bold text-warning">Pending</div><div><?php echo number_format($totals['pending']); ?></div></div></div>
        <div class="col-md-2"><div class="card card-box recharge-mini-card red p-3 text-center"><div class="fw-bold text-danger">Failed</div><div><?php echo number_format($totals['failed']); ?></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card card-box p-4 mb-4 panel-accent-success">
                <h5 class="fw-bold mb-3"><i class="bi bi-calculator me-2"></i>Device Balance Calculation</h5>
                <div class="alert alert-light border mb-3">
                    <div class="d-flex justify-content-between"><span>شحن رصيد Total</span><strong class="text-success"><?php echo money_smart($deviceCreditTotal); ?> AED</strong></div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between"><span>رصيد Sales Cost</span><strong class="text-danger">- <?php echo money_smart($deviceSalesCostTotal); ?> AED</strong></div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between fs-5"><span>Balance Left</span><strong class="<?php echo $deviceBalance >= 0 ? 'text-primary' : 'text-danger'; ?>"><?php echo money_smart($deviceBalance); ?> AED</strong></div>
                </div>
                <div class="form-text">Balance is calculated automatically from Daily Sheet / Transactions. Manual balance override is disabled.</div>
            </div>
            <div class="card card-box p-4 mb-4 panel-accent-warning">
                <h5 class="fw-bold mb-3">➕ Add شحن رصيد Credit</h5>
                <form method="POST" action="recharges.php" onsubmit="return confirm('Add this credit to the device and record it as cash withdrawn from drawer?');">
                    <div class="alert alert-info small py-2">This creates a Withdrawal named شحن رصيد. That amount becomes part of the total device balance automatically.</div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-warning">Credit Amount (AED)</label>
                        <input type="number" step="0.01" min="0.01" name="credit_amount" class="form-control amount-input" placeholder="e.g. 300" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description / Note</label>
                        <input type="text" name="credit_note" class="form-control" placeholder="Optional, e.g. recharge device top-up">
                    </div>
                    <button type="submit" name="add_device_credit" class="btn btn-warning w-100 fw-bold">💰 Add شحن رصيد</button>
                    <div class="form-text mt-2">This increases the total device balance and adds a withdrawal in daily transactions.</div>
                </form>
            </div>

            <div class="card card-box p-4 mb-4 panel-accent-info">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div style="width:42px;height:42px;border-radius:12px;background:#e0f2fe;color:#0369a1;display:grid;place-items:center;font-size:1.15rem;flex:0 0 auto">
                        <i class="bi bi-arrow-repeat"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-1">Sync from Daily Sheet</h5>
                        <div class="text-muted small">No date selection required. It first adds missing <strong>شحن رصيد</strong> device credits, then deducts any missing sales whose name/barcode contains <strong>رصيد</strong>.</div>
                    </div>
                </div>

                <form method="POST" action="recharges.php" onsubmit="return confirm('Sync all available Daily Cash Sheet dates? Already-synced Recharge rows will be skipped.');">
                    <div class="alert alert-light border small py-2 mb-3">
                        <strong>Safe sync:</strong> each <strong>شحن رصيد</strong> credit is added once and each sale containing <strong>رصيد</strong> is deducted once. Existing synced records are skipped.
                    </div>
                    <button type="submit" name="sync_daily_sheet_recharges" class="btn btn-info w-100 fw-bold">
                        <i class="bi bi-arrow-repeat me-2"></i>Sync from Daily Sheet
                    </button>
                </form>
            </div>
            
            <div class="card card-box p-4 mb-4 border-start border-primary border-5">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">📲 Etisalat / du Recharge Barcodes</h5>
                        <div class="text-muted small">Permanent recharge barcode: <b>629100000101</b> for <b>Du / Etisalat Recharge</b>. Cost is 0 because the actual amount can be different each transaction.</div>
                    </div>
                </div>
                <form method="POST" action="recharges.php" class="row g-2 align-items-end mb-3">
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Provider</label>
                        <select name="rb_provider" class="form-select" required>
                            <option value="Du / Etisalat">Du / Etisalat</option>
                            <option value="Etisalat">Etisalat</option>
                            <option value="du">du</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Barcode</label>
                        <input type="text" name="rb_barcode" class="form-control" placeholder="Scan barcode" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Sale Amount</label>
                        <input type="number" step="0.01" min="0.01" name="rb_amount" class="form-control" placeholder="50" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Cost</label>
                        <input type="number" step="0.01" min="0" name="rb_cost" class="form-control" placeholder="50">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Label</label>
                        <input type="text" name="rb_label" class="form-control" placeholder="Etisalat 50">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" name="save_recharge_barcode" class="btn btn-primary w-100 fw-bold">Save</button>
                    </div>
                </form>

                <?php if (!empty($rechargeBarcodes)): ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Provider</th><th>Barcode</th><th>Label</th><th class="text-end">Amount</th><th class="text-end">Cost</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rechargeBarcodes as $rb): ?>
                            <tr>
                                <td><span class="badge <?php echo $rb['provider'] === 'du' ? 'bg-info text-dark' : 'bg-success'; ?>"><?php echo h($rb['provider']); ?></span></td>
                                <td class="fw-bold"><?php echo h($rb['barcode']); ?></td>
                                <td><?php echo h($rb['label'] ?: ($rb['provider'] . ' Recharge')); ?></td>
                                <td class="text-end fw-bold"><?php echo money_smart($rb['amount']); ?></td>
                                <td class="text-end"><?php echo money_smart($rb['cost_amount']); ?></td>
                                <td class="text-end">
                                    <?php if (in_array($rb['barcode'], ['629100000101'], true)): ?>
                                        <span class="badge bg-secondary">Permanent</span>
                                    <?php else: ?>
                                        <a href="recharges.php?delete_recharge_barcode=<?php echo (int)$rb['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this recharge barcode?');">Delete</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

<div class="card card-box p-4 mb-4">
                <h5 class="fw-bold mb-3"><?php echo $editRecharge ? '✏️ Edit Recharge #' . h($editRecharge['id']) : '➕ Add New Recharge'; ?></h5>
                <form method="POST" action="recharges.php">
                    <?php if ($editRecharge): ?>
                        <input type="hidden" name="recharge_id" value="<?php echo h($form['id']); ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Provider</label>
                            <input type="hidden" name="provider" value="Du / Etisalat">
                            <div class="form-control bg-light fw-bold">Du / Etisalat</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Service Type</label>
                            <select name="service_type" class="form-select">
                                <?php
                                $serviceOptions = ['رصيد'];
                                foreach ($serviceOptions as $opt):
                                ?>
                                    <option value="<?php echo h($opt); ?>" <?php echo ($form['service_type'] === $opt) ? 'selected' : ''; ?>><?php echo h($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Customer Mobile / Account Number</label>
                        <input type="text" name="customer_number" class="form-control" value="<?php echo h($form['customer_number']); ?>" placeholder="e.g. 0501234567">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-success">Customer Paid (AED)</label>
                            <input type="number" step="0.01" min="0" name="amount_paid" id="amount_paid" class="form-control amount-input" value="<?php echo h($form['amount_paid']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-danger">Cost to You (AED)</label>
                            <input type="number" step="0.01" min="0" name="cost_amount" id="cost_amount" class="form-control amount-input" value="<?php echo h($form['cost_amount']); ?>" placeholder="Leave blank = same as paid">
                        </div>
                    </div>

                    <div class="alert alert-warning py-2 small">
                        <div class="fw-bold mb-2">⚡ Quick recharge / game card amounts</div>

                        <div class="mb-2">
                            <span class="fw-bold d-inline-block me-2">📱 Du / Etisalat:</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1" onclick="setQuickRecharge('Du / Etisalat', 'Mobile Balance Recharge', 5)">5 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1" onclick="setQuickRecharge('Du / Etisalat', 'Mobile Balance Recharge', 10)">10 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1" onclick="setQuickRecharge('Du / Etisalat', 'Mobile Balance Recharge', 20)">20 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1" onclick="setQuickRecharge('Du / Etisalat', 'Mobile Balance Recharge', 50)">50 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1" onclick="setQuickRecharge('Du / Etisalat', 'Mobile Balance Recharge', 100)">100 AED</button>
                        </div>

                        <div class="mb-2">
                            <span class="fw-bold d-inline-block me-2">🚗 Salik:</span>
                            <button type="button" class="btn btn-sm btn-outline-dark me-1 mb-1" onclick="setQuickRecharge('Salik', 'Salik Recharge', 50)">50 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-dark me-1 mb-1" onclick="setQuickRecharge('Salik', 'Salik Recharge', 100)">100 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-dark me-1 mb-1" onclick="setQuickRecharge('Salik', 'Salik Recharge', 200)">200 AED</button>
                        </div>

                        <div class="mb-2">
                            <span class="fw-bold d-inline-block me-2">🎮 Roblox:</span>
                            <button type="button" class="btn btn-sm btn-outline-primary me-1 mb-1" onclick="setQuickRecharge('Roblox', 'Roblox Gift Card', 50)">50 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-primary me-1 mb-1" onclick="setQuickRecharge('Roblox', 'Roblox Gift Card', 100)">100 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-primary me-1 mb-1" onclick="setQuickRecharge('Roblox', 'Roblox Gift Card', 200)">200 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-primary me-1 mb-1" onclick="setQuickRecharge('Roblox', 'Roblox Gift Card', 500)">500 AED</button>
                        </div>

                        <div class="mb-2">
                            <span class="fw-bold d-inline-block me-2">🎮 Playstation:</span>
                            <button type="button" class="btn btn-sm btn-outline-info me-1 mb-1" onclick="setQuickRecharge('Playstation', 'Playstation Gift Card', 37)">37 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-info me-1 mb-1" onclick="setQuickRecharge('Playstation', 'Playstation Gift Card', 74)">74 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-info me-1 mb-1" onclick="setQuickRecharge('Playstation', 'Playstation Gift Card', 185)">185 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-info me-1 mb-1" onclick="setQuickRecharge('Playstation', 'Playstation Gift Card', 370)">370 AED</button>
                        </div>

                        <div>
                            <span class="fw-bold d-inline-block me-2">🔫 PUBG:</span>
                            <button type="button" class="btn btn-sm btn-outline-success me-1 mb-1" onclick="setQuickRecharge('Pubg', 'PUBG UC', 4)">4 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-success me-1 mb-1" onclick="setQuickRecharge('Pubg', 'PUBG UC', 19)">19 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-success me-1 mb-1" onclick="setQuickRecharge('Pubg', 'PUBG UC', 37)">37 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-success me-1 mb-1" onclick="setQuickRecharge('Pubg', 'PUBG UC', 92)">92 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-success me-1 mb-1" onclick="setQuickRecharge('Pubg', 'PUBG UC', 185)">185 AED</button>
                            <button type="button" class="btn btn-sm btn-outline-success me-1 mb-1" onclick="setQuickRecharge('Pubg', 'PUBG UC', 360)">360 AED</button>
                        </div>

                        <div class="form-text">Click one button to set provider, service type, paid and cost amounts.</div>
                    </div>

                    <div class="alert alert-info py-2 small">
                        <strong>Payment Method:</strong> Cash only. Card and bank transfer are disabled for recharges.<br>
                        <strong>Transactions:</strong> Completed recharges will also be added to <code>sales_records</code> and appear in daily transactions as a SALE.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select">
                            <option value="Completed" <?php echo ($form['status'] === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="Pending" <?php echo ($form['status'] === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="Failed" <?php echo ($form['status'] === 'Failed') ? 'selected' : ''; ?>>Failed</option>
                            <option value="Refunded" <?php echo ($form['status'] === 'Refunded') ? 'selected' : ''; ?>>Refunded</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Date / Time</label>
                        <input type="datetime-local" name="created_at" class="form-control" value="<?php echo h($createdValue); ?>">
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."><?php echo h($form['notes']); ?></textarea>
                    </div>

                    <div class="d-grid gap-2">
                        <?php if ($editRecharge): ?>
                            <button type="submit" name="update_recharge" class="btn btn-success fw-bold">💾 Update Recharge</button>
                            <a href="recharges.php" class="btn btn-outline-secondary">Cancel Edit</a>
                        <?php else: ?>
                            <button type="submit" name="save_recharge" class="btn btn-primary fw-bold">💾 Save Recharge</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card card-box p-4">
                <h5 class="fw-bold mb-3">💡 Recommended Recharge Reports</h5>
                <ul class="mb-0 small">
                    <li>Daily Du / Etisalat, Salik, and game card totals.</li>
                    <li>Device balance movement by provider.</li>
                    <li>Pending and failed recharge follow-up.</li>
                    <li>Profit by provider and service type.</li>
                    <li>Customer number search history.</li>
                </ul>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card card-box p-4 mb-4">
                <h5 class="fw-bold mb-3">🔎 Filter Recharges</h5>
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Start</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo h($startDate); ?>" <?php echo $showAll ? 'disabled' : ''; ?>>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">End</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo h($endDate); ?>" <?php echo $showAll ? 'disabled' : ''; ?>>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Provider</label>
                        <select name="provider" class="form-select">
                            <option value="">All</option>
                            <?php foreach (recharge_providers() as $provider): ?>
                                <option value="<?php echo h($provider); ?>" <?php echo ($providerFilter === $provider) ? 'selected' : ''; ?>><?php echo h($provider); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="Completed" <?php echo ($statusFilter === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="Pending" <?php echo ($statusFilter === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="Failed" <?php echo ($statusFilter === 'Failed') ? 'selected' : ''; ?>>Failed</option>
                            <option value="Refunded" <?php echo ($statusFilter === 'Refunded') ? 'selected' : ''; ?>>Refunded</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Type</label>
                        <select name="movement_type" class="form-select">
                            <option value="" <?php echo $movementFilter === '' ? 'selected' : ''; ?>>All</option>
                            <option value="added" <?php echo $movementFilter === 'added' ? 'selected' : ''; ?>>Added</option>
                            <option value="sales" <?php echo $movementFilter === 'sales' ? 'selected' : ''; ?>>Sales</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Search</label>
                        <input type="text" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="number / note">
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2 mt-3">
                        <button class="btn btn-primary fw-bold" type="submit">Apply Filter</button>
                        <a href="recharges.php?all=1" class="btn btn-outline-dark fw-bold">Show All</a>
                        <a href="recharges.php" class="btn btn-outline-secondary fw-bold">Reset</a>
                        <button type="button" onclick="window.print()" class="btn btn-outline-success fw-bold">🖨️ Print</button>
                    </div>
                </form>
            </div>

            <div class="card card-box p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <h4 class="fw-bold m-0">📋 Recharge List</h4>
                    <div class="text-muted small"><?php echo $showAll ? 'Showing all records' : 'Showing ' . h($startDate) . ' to ' . h($endDate); ?></div>
                </div>

                <form method="POST" onsubmit="return confirm('Delete selected recharge records? Device balance will be restored for completed records.');">
                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <button type="submit" name="delete_selected" class="btn btn-outline-danger btn-sm fw-bold">🗑️ Delete Selected</button>
                        <small class="text-muted">Select records using the checkboxes.</small>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><input type="checkbox" id="selectAll"></th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Provider</th>
                                    <th>Service</th>
                                    <th>Customer</th>
                                    <th class="text-end">Paid</th>
                                    <th class="text-end">Cost</th>
                                    <th class="text-end">Profit</th>
                                    <th class="text-center">Payment</th>
                                    <th class="text-center">Status</th>
                                    <th>Notes</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recharges)): ?>
                                    <tr><td colspan="13" class="text-center text-muted py-4">No recharge records found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recharges as $r): ?>
                                        <?php
                                            $profit = (float)$r['amount_paid'] - (float)$r['cost_amount'];
                                            $profitClass = $profit >= 0 ? 'text-primary' : 'text-danger';
                                            $isAdded = (($r['_movement_type'] ?? 'sale') === 'added');
                                        ?>
                                        <tr>
                                            <td><?php if (!$isAdded): ?><input type="checkbox" name="selected_ids[]" value="<?php echo h($r['id']); ?>" class="row-check"><?php endif; ?></td>
                                            <td class="small text-muted"><?php echo h(date('Y-m-d h:i A', strtotime($r['created_at']))); ?></td>
                                            <td><span class="badge <?php echo $isAdded ? 'bg-success' : 'bg-primary'; ?>"><?php echo $isAdded ? 'ADDED' : 'SALE'; ?></span></td>
                                            <td><span class="badge bg-dark"><?php echo h($r['provider']); ?></span></td>
                                            <td><?php echo h($r['service_type']); ?></td>
                                            <td class="fw-semibold"><?php echo h($r['customer_number'] ?: '—'); ?></td>
                                            <td class="text-end fw-bold <?php echo $isAdded ? 'text-success' : 'text-success'; ?>"><?php echo money_smart($r['amount_paid']); ?></td>
                                            <td class="text-end fw-bold text-danger"><?php echo $isAdded ? '—' : money_smart($r['cost_amount']); ?></td>
                                            <td class="text-end fw-bold <?php echo $profitClass; ?>"><?php echo $isAdded ? '—' : money_smart($profit); ?></td>
                                            <td class="text-center"><span class="badge bg-success">CASH</span></td>
                                            <td class="text-center"><span class="badge bg-<?php echo $isAdded ? 'success' : badge_class_status($r['status']); ?>"><?php echo h($r['status']); ?></span></td>
                                            <td class="small"><?php echo h($r['notes']); ?></td>
                                            <td class="text-center text-nowrap">
                                                <?php if (!$isAdded): ?>
                                                    <a href="recharges.php?action=edit&id=<?php echo h($r['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                    <a href="recharges.php?action=delete&id=<?php echo h($r['id']); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this recharge record?');">Delete</a>
                                                <?php else: ?>
                                                    <span class="text-muted small">From Withdrawal</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($recharges)): ?>
                                <tfoot class="table-light fw-bold">
                                    <?php if ($movementFilter !== 'sales'): ?>
                                    <tr>
                                        <td colspan="6" class="text-end"><span class="badge bg-success me-2">ADDED</span>شحن رصيد Total:</td>
                                        <td class="text-end text-success"><?php echo money_smart($movementTotals['added']); ?></td>
                                        <td class="text-end">—</td>
                                        <td class="text-end">—</td>
                                        <td colspan="4"></td>
                                    </tr>
                                    <?php endif; ?>

                                    <?php if ($movementFilter !== 'added'): ?>
                                    <tr>
                                        <td colspan="6" class="text-end"><span class="badge bg-primary me-2">SALES</span>رصيد Sales Total:</td>
                                        <td class="text-end text-success"><?php echo money_smart($movementTotals['sales_paid']); ?></td>
                                        <td class="text-end text-danger"><?php echo money_smart($movementTotals['sales_cost']); ?></td>
                                        <td class="text-end text-primary"><?php echo money_smart($movementTotals['sales_profit']); ?></td>
                                        <td colspan="4"></td>
                                    </tr>
                                    <?php endif; ?>

                                    <tr style="border-top:2px solid #d0d5dd">
                                        <td colspan="6" class="text-end">Device Balance Left:</td>
                                        <td class="text-end <?php echo $deviceBalance >= 0 ? 'text-primary' : 'text-danger'; ?>">
                                            <?php echo money_smart($deviceBalance); ?>
                                        </td>
                                        <td colspan="6" class="small text-muted fw-normal">
                                            Total شحن رصيد − total رصيد sales cost
                                        </td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>
</div>

<script>
function setQuickRecharge(providerName, serviceName, amount) {
    const provider = document.querySelector('select[name="provider"]');
    const service = document.querySelector('select[name="service_type"]');
    const paid = document.getElementById('amount_paid');
    const cost = document.getElementById('cost_amount');
    if (provider) provider.value = providerName;
    if (service) service.value = serviceName;
    if (paid) paid.value = amount;
    if (cost) cost.value = amount;
}

document.getElementById('selectAll')?.addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
});

document.querySelectorAll('.side-link').forEach(link => link.addEventListener('click', () => {
    if (window.innerWidth <= 991) document.getElementById('sidebar')?.classList.remove('open');
}));
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
