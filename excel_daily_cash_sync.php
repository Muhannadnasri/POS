<?php
// excel_daily_cash_sync.php
// Receives one full Excel daily sheet as JSON and replaces that date in Daily Cash.
// Upload beside db.php.

require_once __DIR__ . '/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

const EXCEL_SYNC_TOKEN = 'f70921c74a22c9e0c076f50972a16fb0';

function respond_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_text($v): string {
    return trim((string)($v ?? ''));
}

function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function decode_cost($value): float {
    if ($value === null || $value === '') return 0.0;
    $text = trim((string)$value);

    $clean = str_ireplace(['AED','DHS','DH','درهم','د.إ'], '', $text);
    $clean = str_replace([',',' '], '', $clean);
    if (is_numeric($clean)) return round((float)$clean, 2);

    $digits = preg_replace('/\D+/', '', $text);
    if ($digits !== '' && strpos($digits, '9629097') === 0) {
        if (strlen($digits) >= 11) return (float)((int)substr($digits, -4));
        return (float)((int)substr($digits, -3));
    }
    return 0.0;
}

function valid_date(string $value): bool {
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

function ensure_daily_cash_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_cash_sheets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sheet_date DATE NOT NULL UNIQUE,
        previous_day_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        physical_drawer_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_cash_sheet_rows (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sheet_id INT NOT NULL,
        row_order INT NOT NULL DEFAULT 0,
        code VARCHAR(191) NULL,
        item_name VARCHAR(255) NULL,
        cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        visa_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        tamara_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        cost_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        profit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        withdrawal_name VARCHAR(255) NULL,
        withdrawal_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        addition_name VARCHAR(255) NULL,
        addition_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        row_notes TEXT NULL,
        INDEX idx_daily_sheet_rows (sheet_id, row_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!column_exists($pdo, 'daily_cash_sheet_rows', 'tamara_amount')) {
        $pdo->exec("
            ALTER TABLE daily_cash_sheet_rows
            ADD COLUMN tamara_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER visa_amount
        ");
    }
}

function recharge_by_barcode(PDO $pdo, string $barcode): ?array {
    if (!table_exists($pdo, 'recharge_barcodes')) return null;
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM recharge_barcodes
            WHERE barcode = ? AND active = 1
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute([$barcode]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function lookup_product(PDO $pdo, string $barcode, float $excelCost): ?array {
    if (!table_exists($pdo, 'products')) return null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE barcode = ? ORDER BY quantity DESC, id ASC");
        $stmt->execute([$barcode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;

        // If barcode is duplicated, use the Excel cost to identify the correct item.
        if (count($rows) > 1 && $excelCost > 0) {
            foreach ($rows as $r) {
                if (abs(decode_cost($r['cost_price'] ?? 0) - $excelCost) < 0.01) {
                    return $r;
                }
            }
        }
        return $rows[0];
    } catch (Throwable $e) {
        return null;
    }
}

function lookup_tool(PDO $pdo, string $barcode, float $excelCost): ?array {
    $table = table_exists($pdo, 'tool_items') ? 'tool_items' : (table_exists($pdo, 'tools') ? 'tools' : '');
    if ($table === '' || !column_exists($pdo, $table, 'barcode')) return null;

    try {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE barcode = ? ORDER BY quantity DESC, id ASC");
        $stmt->execute([$barcode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;

        if (count($rows) > 1 && $excelCost > 0) {
            foreach ($rows as $r) {
                if (abs(decode_cost($r['cost_price'] ?? 0) - $excelCost) < 0.01) {
                    return $r;
                }
            }
        }
        return $rows[0];
    } catch (Throwable $e) {
        return null;
    }
}

function lookup_simple(PDO $pdo, string $table, string $barcode): ?array {
    if (!table_exists($pdo, $table) || !column_exists($pdo, $table, 'barcode')) return null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE barcode = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$barcode]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function resolve_item_name(PDO $pdo, string $barcode, string $excelName, float $excelCost): string {
    $barcode = clean_text($barcode);
    $excelName = clean_text($excelName);

    if ($barcode === '') return $excelName;

    // Recharge takes priority.
    $rch = recharge_by_barcode($pdo, $barcode);
    if ($rch) {
        $label = clean_text($rch['label'] ?? '');
        if ($label !== '') return $label;
        $provider = clean_text($rch['provider'] ?? 'Du / Etisalat');
        return ($provider !== '' ? $provider : 'Du / Etisalat') . ' Recharge';
    }

    $p = lookup_product($pdo, $barcode, $excelCost);
    if ($p) {
        $name = trim(clean_text($p['product_name'] ?? '') . ' ' . clean_text($p['model_name'] ?? ''));
        if ($name !== '') return $name;
    }

    $phone = lookup_simple($pdo, 'phones', $barcode);
    if ($phone) {
        $name = trim(clean_text($phone['brand'] ?? '') . ' ' . clean_text($phone['model_name'] ?? '') . ' ' . clean_text($phone['details'] ?? ''));
        if ($name !== '') return $name;
    }

    $laptop = lookup_simple($pdo, 'laptops', $barcode);
    if ($laptop) {
        $name = trim(clean_text($laptop['brand'] ?? '') . ' ' . clean_text($laptop['model_name'] ?? '') . ' ' . clean_text($laptop['details'] ?? ''));
        if ($name !== '') return $name;
    }

    $tool = lookup_tool($pdo, $barcode, $excelCost);
    if ($tool) {
        $name = trim(
            clean_text($tool['brand'] ?? '') . ' ' .
            clean_text($tool['model_name'] ?? '') . ' ' .
            clean_text($tool['item_type'] ?? ($tool['type'] ?? '')) . ' ' .
            clean_text($tool['details'] ?? '')
        );
        if ($name !== '') return $name;
    }

    return $excelName;
}

// ---- Request ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['ok'=>false,'error'=>'POST required'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    respond_json(['ok'=>false,'error'=>'Invalid JSON'], 400);
}

$token = (string)($data['token'] ?? '');
if ($token === '' || !hash_equals(EXCEL_SYNC_TOKEN, $token)) {
    respond_json(['ok'=>false,'error'=>'UNAUTHORIZED'], 401);
}

$date = clean_text($data['date'] ?? '');
if (!valid_date($date)) {
    respond_json(['ok'=>false,'error'=>'Invalid date'], 400);
}

$rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
$previous = (float)($data['previous_day_balance'] ?? 0);
$physical = (float)($data['physical_drawer_amount'] ?? 0);
$sheetNotes = clean_text($data['notes'] ?? '');

ensure_daily_cash_tables($pdo);

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        INSERT INTO daily_cash_sheets
            (sheet_date, previous_day_balance, physical_drawer_amount, notes, updated_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            previous_day_balance=VALUES(previous_day_balance),
            physical_drawer_amount=VALUES(physical_drawer_amount),
            notes=VALUES(notes),
            updated_at=NOW()
    ");
    $stmt->execute([$date, $previous, $physical, $sheetNotes]);

    $stmt = $pdo->prepare("SELECT id FROM daily_cash_sheets WHERE sheet_date = ?");
    $stmt->execute([$date]);
    $sheetId = (int)$stmt->fetchColumn();

    $pdo->prepare("DELETE FROM daily_cash_sheet_rows WHERE sheet_id = ?")->execute([$sheetId]);

    $insert = $pdo->prepare("
        INSERT INTO daily_cash_sheet_rows
        (sheet_id,row_order,code,item_name,cash_amount,visa_amount,tamara_amount,
         cost_amount,profit_amount,withdrawal_name,withdrawal_amount,
         addition_name,addition_amount,row_notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $saved = 0;
    foreach ($rows as $index => $row) {
        if (!is_array($row)) continue;

        $code = clean_text($row['code'] ?? '');
        $excelName = clean_text($row['item_name'] ?? '');
        $cash = (float)($row['cash_amount'] ?? 0);
        $visa = (float)($row['visa_amount'] ?? 0);
        $tamara = (float)($row['tamara_amount'] ?? 0);
        $cost = (float)($row['cost_amount'] ?? 0);
        $profit = (float)($row['profit_amount'] ?? 0);
        $withdrawalName = clean_text($row['withdrawal_name'] ?? '');
        $withdrawalAmount = (float)($row['withdrawal_amount'] ?? 0);
        $additionName = clean_text($row['addition_name'] ?? '');
        $additionAmount = (float)($row['addition_amount'] ?? 0);
        $notes = clean_text($row['row_notes'] ?? '');

        $empty = $code === '' && $excelName === '' &&
                 abs($cash) < 0.000001 && abs($visa) < 0.000001 &&
                 abs($tamara) < 0.000001 && abs($cost) < 0.000001 &&
                 abs($profit) < 0.000001 &&
                 $withdrawalName === '' && abs($withdrawalAmount) < 0.000001 &&
                 $additionName === '' && abs($additionAmount) < 0.000001 &&
                 $notes === '';
        if ($empty) continue;

        $name = resolve_item_name($pdo, $code, $excelName, $cost);

        $insert->execute([
            $sheetId,
            (int)($row['row_order'] ?? ($index + 1)),
            $code,
            $name,
            $cash,
            $visa,
            $tamara,
            $cost,
            $profit,
            $withdrawalName,
            $withdrawalAmount,
            $additionName,
            $additionAmount,
            $notes
        ]);
        $saved++;
    }

    $pdo->commit();
    respond_json([
        'ok'=>true,
        'date'=>$date,
        'rows_saved'=>$saved,
        'updated_at'=>date('Y-m-d H:i:s')
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond_json(['ok'=>false,'error'=>$e->getMessage()], 500);
}
