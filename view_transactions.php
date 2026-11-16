<?php
require_once __DIR__ . '/session_keepalive.php';
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$selected_date = trim((string)($_GET['date'] ?? ($_POST['date'] ?? '')));
$range_start_date = trim((string)($_GET['start_date'] ?? ($_POST['start_date'] ?? '')));
$range_end_date = trim((string)($_GET['end_date'] ?? ($_POST['end_date'] ?? '')));

// Validate date filters. A range takes priority over the old single-date filter.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) $selected_date = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_start_date)) $range_start_date = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_end_date)) $range_end_date = '';

if ($range_start_date !== '' && $range_end_date === '') $range_end_date = $range_start_date;
if ($range_end_date !== '' && $range_start_date === '') $range_start_date = $range_end_date;
if ($range_start_date !== '' && $range_end_date !== '' && $range_start_date > $range_end_date) {
    [$range_start_date, $range_end_date] = [$range_end_date, $range_start_date];
}
if ($range_start_date !== '' && $range_end_date !== '') $selected_date = '';

if (isset($_GET['all'])) {
    $selected_date = '';
    $range_start_date = '';
    $range_end_date = '';
}
$card_fee_rate =  0.025; // VISA / CARD fee = 2.5%
$import_message = "";
$page_message = "";

function format_money($amount) {
    return (fmod((float)$amount, 1) == 0)
        ? number_format((float)$amount, 0)
        : number_format((float)$amount, 2);
}

function format_money_2($amount) {
    return number_format((float)$amount, 2);
}

function clean_text($value) {
    $value = (string)($value ?? '');
    $value = str_replace(["\xC2\xA0", "\xE2\x80\x8E", "\xE2\x80\x8F"], ' ', $value);
    return trim($value);
}

function normalize_number($value) {
    if ($value === null || $value === '') return 0.0;
    if (is_numeric($value)) return (float)$value;

    $value = clean_text($value);
    $arabic_digits = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.','٬'=>''];
    $value = strtr($value, $arabic_digits);
    $value = str_replace(',', '', $value);
    $value = preg_replace('/[^0-9.\-]/u', '', $value);
    return ($value === '' || $value === '-' || $value === '.') ? 0.0 : (float)$value;
}

function decode_cost_price($raw_cost) {
    if ($raw_cost === null || $raw_cost === '') {
        return 0.0;
    }

    $raw_cost = clean_text((string)$raw_cost);

    // New cost code format: 962909700011 = 11 AED, 962909700211 = 211 AED
    if (preg_match('/^9629097\d+$/', $raw_cost)) {
        return (float)intval(substr($raw_cost, -3));
    }

    return normalize_number($raw_cost);
}

function db_column_exists(PDO $pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function db_index_exists(PDO $pdo, $table, $index) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function db_table_exists(PDO $pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensure_legacy_import_columns(PDO $pdo) {
    if (!db_column_exists($pdo, 'sales_records', 'imported_cost')) {
        $pdo->exec("ALTER TABLE sales_records ADD COLUMN imported_cost DECIMAL(10,2) NULL AFTER total_amount");
    }
    if (!db_column_exists($pdo, 'sales_records', 'imported_profit')) {
        $pdo->exec("ALTER TABLE sales_records ADD COLUMN imported_profit DECIMAL(10,2) NULL AFTER imported_cost");
    }
    if (!db_column_exists($pdo, 'sales_records', 'import_source')) {
        $pdo->exec("ALTER TABLE sales_records ADD COLUMN import_source VARCHAR(50) NULL AFTER imported_profit");
    }
    if (!db_column_exists($pdo, 'sales_records', 'legacy_import_key')) {
        $pdo->exec("ALTER TABLE sales_records ADD COLUMN legacy_import_key VARCHAR(191) NULL AFTER import_source");
    }
    if (!db_index_exists($pdo, 'sales_records', 'uniq_sales_legacy_import_key')) {
        $pdo->exec("ALTER TABLE sales_records ADD UNIQUE KEY uniq_sales_legacy_import_key (legacy_import_key)");
    }
    if (!db_column_exists($pdo, 'sales_records', 'stock_deducted')) {
        $pdo->exec("ALTER TABLE sales_records ADD COLUMN stock_deducted TINYINT(1) NOT NULL DEFAULT 0 AFTER legacy_import_key");
    }
    if (!db_column_exists($pdo, 'sales_records', 'phone_id')) {
        $pdo->exec("ALTER TABLE sales_records ADD COLUMN phone_id INT NULL AFTER product_id");
    }
    if (!db_column_exists($pdo, 'sales_records', 'phone_stock_deducted')) {
        $pdo->exec("ALTER TABLE sales_records ADD COLUMN phone_stock_deducted TINYINT(1) NOT NULL DEFAULT 0 AFTER stock_deducted");
    }

    if (!db_column_exists($pdo, 'drawer_withdrawals', 'import_source')) {
        $pdo->exec("ALTER TABLE drawer_withdrawals ADD COLUMN import_source VARCHAR(50) NULL AFTER withdrawn_at");
    }
    if (!db_column_exists($pdo, 'drawer_withdrawals', 'legacy_import_key')) {
        $pdo->exec("ALTER TABLE drawer_withdrawals ADD COLUMN legacy_import_key VARCHAR(191) NULL AFTER import_source");
    }
    if (!db_index_exists($pdo, 'drawer_withdrawals', 'uniq_drawer_legacy_import_key')) {
        $pdo->exec("ALTER TABLE drawer_withdrawals ADD UNIQUE KEY uniq_drawer_legacy_import_key (legacy_import_key)");
    }
}

function xlsx_col_to_index($cell_ref) {
    preg_match('/^([A-Z]+)/i', $cell_ref, $matches);
    $letters = strtoupper($matches[1] ?? 'A');
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return $index; // 1-based
}

function xlsx_read_shared_strings(ZipArchive $zip) {
    $strings = [];
    $xml_string = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml_string === false) return $strings;

    $xml = simplexml_load_string($xml_string);
    if (!$xml) return $strings;

    $namespaces = $xml->getNamespaces(true);
    $main_ns = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    foreach ($xml->children($main_ns)->si as $si) {
        $text = '';
        $t_nodes = $si->xpath('.//*[local-name()="t"]');
        if ($t_nodes) {
            foreach ($t_nodes as $t) $text .= (string)$t;
        }
        $strings[] = $text;
    }
    return $strings;
}

function xlsx_get_cell_value($cell, $main_ns, $shared_strings) {
    $attrs = $cell->attributes();
    $type = (string)($attrs['t'] ?? '');
    $children = $cell->children($main_ns);

    if ($type === 's') {
        $idx = isset($children->v) ? (int)$children->v : -1;
        return $shared_strings[$idx] ?? '';
    }

    if ($type === 'inlineStr') {
        $text = '';
        $t_nodes = $cell->xpath('.//*[local-name()="t"]');
        if ($t_nodes) {
            foreach ($t_nodes as $t) $text .= (string)$t;
        }
        return $text;
    }

    if (isset($children->v)) {
        return (string)$children->v;
    }

    return '';
}

function xlsx_read_workbook($file_path) {
    if (!class_exists('ZipArchive')) {
        throw new Exception('PHP ZipArchive extension is required to read .xlsx files. Please enable php-zip on your server.');
    }

    $zip = new ZipArchive();
    if ($zip->open($file_path) !== true) {
        throw new Exception('Could not open Excel file. Please upload a valid .xlsx file.');
    }

    $shared_strings = xlsx_read_shared_strings($zip);

    $rels_xml_string = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $workbook_xml_string = $zip->getFromName('xl/workbook.xml');
    if ($rels_xml_string === false || $workbook_xml_string === false) {
        throw new Exception('Invalid Excel workbook structure.');
    }

    $rels_xml = simplexml_load_string($rels_xml_string);
    $workbook_xml = simplexml_load_string($workbook_xml_string);

    $rel_targets = [];
    foreach ($rels_xml->Relationship as $rel) {
        $attrs = $rel->attributes();
        $target = (string)$attrs['Target'];
        if (strpos($target, '/') === 0) {
            $target_path = ltrim($target, '/');
        } else {
            $target_path = 'xl/' . ltrim($target, '/');
        }
        $rel_targets[(string)$attrs['Id']] = $target_path;
    }

    $namespaces = $workbook_xml->getNamespaces(true);
    $main_ns = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $r_ns = $namespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $sheets = [];
    foreach ($workbook_xml->children($main_ns)->sheets->children($main_ns)->sheet as $sheet_node) {
        $attrs = $sheet_node->attributes();
        $r_attrs = $sheet_node->attributes($r_ns);
        $sheet_name = (string)$attrs['name'];
        $rid = (string)$r_attrs['id'];
        $path = $rel_targets[$rid] ?? null;
        if (!$path) continue;

        $sheet_xml_string = $zip->getFromName($path);
        if ($sheet_xml_string === false) continue;

        $sheet_xml = simplexml_load_string($sheet_xml_string);
        $sheet_ns = ($sheet_xml->getNamespaces(true)[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cells = [];

        $sheet_children = $sheet_xml->children($sheet_ns);
        if (isset($sheet_children->sheetData)) {
            foreach ($sheet_children->sheetData->children($sheet_ns)->row as $row) {
                foreach ($row->children($sheet_ns)->c as $cell) {
                    $cell_ref = (string)$cell->attributes()['r'];
                    if ($cell_ref === '') continue;
                    preg_match('/([A-Z]+)(\d+)/i', $cell_ref, $m);
                    if (!$m) continue;
                    $col = xlsx_col_to_index($cell_ref);
                    $row_num = (int)$m[2];
                    $cells[$row_num][$col] = xlsx_get_cell_value($cell, $sheet_ns, $shared_strings);
                }
            }
        }
        $sheets[] = ['name' => $sheet_name, 'cells' => $cells];
    }

    $zip->close();
    return $sheets;
}

function cell_value($cells, $row, $col) {
    return $cells[$row][$col] ?? '';
}

function first_non_empty_cell($cells, $row, array $cols) {
    foreach ($cols as $col) {
        $value = clean_text(cell_value($cells, $row, $col));
        if ($value !== '') return $value;
    }
    return '';
}

function sheet_name_to_date($sheet_name, DateTime $start_date) {
    $name = clean_text((string)$sheet_name);
    $arabic_digits = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
    $name = strtr($name, $arabic_digits);
    $name = preg_replace('/\s+/', ' ', $name);

    $base_year = (int)$start_date->format('Y');
    $base_month = (int)$start_date->format('m');

    // Full sheet date: 2026-05-01 / 2026.05.01 / 2026/05/01
    if (preg_match('/^((?:20)\d{2})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $name, $m)) {
        $year = (int)$m[1];
        $month = (int)$m[2];
        $day = (int)$m[3];
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    // Full sheet date: 01-05-2026 / 01.05.2026 / 01/05/2026
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.]((?:20)\d{2})$/', $name, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    // Day + month sheet date without year: 01-05 / 01.05 / 01/05. Year comes from the file month/year.
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})$/', $name, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = $base_year;
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    // Monthly workbooks: file name 05-2026.xlsx sets start_date to 2026-05-01.
    // Sheet names can be 1, 2, 03, 15, 31 and become that day inside the file month.
    if (preg_match('/^\d{1,2}$/', $name)) {
        $day = (int)$name;
        $month = $base_month;
        $year = $base_year;

        // Keep old rolling behavior only when the fallback start date is mid-month, e.g. 28/05 then sheets 1-16 are next month.
        $base_day = (int)$start_date->format('j');
        if ($base_day > 1 && $day < $base_day) {
            $next = clone $start_date;
            $next->modify('first day of next month');
            $month = (int)$next->format('m');
            $year = (int)$next->format('Y');
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    return null;
}

function product_sort_key_for_import($product) {
    $qty = isset($product['quantity']) ? (int)$product['quantity'] : 0;
    return ($qty > 0) ? 0 : 1;
}

function product_display_name_for_import(array $product, $fallback_name) {
    $parts = array_filter([$product['product_name'] ?? '', $product['model_name'] ?? ''], function($v) {
        return clean_text($v) !== '';
    });
    return !empty($parts) ? implode(' - ', $parts) : $fallback_name;
}

function phone_display_name_for_import(array $phone, $fallback_name) {
    $parts = [];
    if (clean_text($phone['brand'] ?? '') !== '') $parts[] = $phone['brand'];
    if (clean_text($phone['model_name'] ?? '') !== '') $parts[] = $phone['model_name'];
    if (clean_text($phone['details'] ?? '') !== '') $parts[] = $phone['details'];
    if (clean_text($phone['phone_condition'] ?? '') !== '') $parts[] = $phone['phone_condition'];
    $label = !empty($parts) ? implode(' - ', $parts) : $fallback_name;
    return 'PHONE: ' . $label;
}


function tool_display_name_for_transactions(array $tool, $fallback_name = '') {
    // Tools inventory is stored in tool_items.
    // Use the real tool/model name first, then append its saved Details when available.
    $model = clean_text($tool['model_name'] ?? '');
    $details = clean_text($tool['details'] ?? '');
    $brand = clean_text($tool['brand'] ?? '');
    $type = clean_text($tool['item_type'] ?? '');

    $name = $model;
    if ($name === '' && $type !== '') $name = $type;
    if ($name === '' && $brand !== '') $name = $brand;
    if ($name === '') $name = clean_text($fallback_name);

    if ($details !== '' && strcasecmp($details, $name) !== 0) {
        return trim($name . ' - ' . $details, ' -');
    }
    return $name;
}

function get_tool_by_barcode_for_transactions(PDO $pdo, $barcode, $expected_cost = 0) {
    if (!db_table_exists($pdo, 'tool_items')) return null;

    $barcode = preg_replace('/\s+/', '', clean_text($barcode));
    if ($barcode === '') return null;

    try {
        // One barcode can exist on more than one Tool item with different cost prices.
        // Pull every matching row, then use the cost from Excel to identify the correct item.
        $stmt = $pdo->prepare("
            SELECT *
            FROM tool_items
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(TRIM(barcode), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), '') = ?
            ORDER BY
                CASE WHEN quantity > 0 THEN 0 ELSE 1 END,
                id ASC
        ");
        $stmt->execute([$barcode]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$matches) return null;
        if (count($matches) === 1) return $matches[0];

        $expectedCost = decode_cost_price($expected_cost);

        if ($expectedCost > 0) {
            $sameCost = [];
            foreach ($matches as $tool) {
                $toolCost = decode_cost_price($tool['cost_price'] ?? 0);
                if (abs($toolCost - $expectedCost) < 0.01) {
                    $sameCost[] = $tool;
                }
            }

            if ($sameCost) {
                // Prefer a matching-price row that still has stock.
                usort($sameCost, function($a, $b) {
                    $qa = ((int)($a['quantity'] ?? 0) > 0) ? 0 : 1;
                    $qb = ((int)($b['quantity'] ?? 0) > 0) ? 0 : 1;
                    if ($qa !== $qb) return $qa <=> $qb;
                    return ((int)$a['id']) <=> ((int)$b['id']);
                });
                return $sameCost[0];
            }

            // Multiple items share this barcode, but none has the Excel cost.
            // Do not guess the wrong Tool description.
            return null;
        }

        // With no Excel cost and duplicate barcode, do not guess.
        return null;
    } catch (Throwable $e) {
        return null;
    }
}


function get_product_by_barcode_for_transactions(PDO $pdo, $barcode, $expected_cost = 0) {
    if (!db_table_exists($pdo, 'products')) return null;

    $barcode = preg_replace('/\s+/', '', clean_text($barcode));
    if ($barcode === '') return null;

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM products
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(TRIM(barcode), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), '') = ?
            ORDER BY
                CASE WHEN quantity > 0 THEN 0 ELSE 1 END,
                id ASC
        ");
        $stmt->execute([$barcode]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$matches) return null;
        if (count($matches) === 1) return $matches[0];

        $expectedCost = decode_cost_price($expected_cost);

        if ($expectedCost > 0) {
            $sameCost = [];
            foreach ($matches as $product) {
                $productCost = decode_cost_price($product['cost_price'] ?? 0);
                if (abs($productCost - $expectedCost) < 0.01) {
                    $sameCost[] = $product;
                }
            }

            if ($sameCost) {
                usort($sameCost, function($a, $b) {
                    $qa = ((int)($a['quantity'] ?? 0) > 0) ? 0 : 1;
                    $qb = ((int)($b['quantity'] ?? 0) > 0) ? 0 : 1;
                    if ($qa !== $qb) return $qa <=> $qb;
                    return ((int)$a['id']) <=> ((int)$b['id']);
                });
                return $sameCost[0];
            }

            // Duplicate barcode but no matching Excel cost: do not guess.
            return null;
        }

        // Duplicate barcode and no Excel cost: do not guess.
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

function product_display_name_for_transactions(array $product, $fallback_name = '') {
    $productName = clean_text($product['product_name'] ?? '');
    $modelName = clean_text($product['model_name'] ?? '');
    $details = clean_text($product['details'] ?? '');

    $parts = [];
    if ($productName !== '') $parts[] = $productName;
    if ($modelName !== '' && $modelName !== $productName) $parts[] = $modelName;
    if ($details !== '') $parts[] = $details;

    if ($parts) return implode(' - ', $parts);
    return clean_text($fallback_name);
}

function transaction_item_description(PDO $pdo, array $row) {
    $type = strtoupper(clean_text($row['record_type'] ?? ''));
    if ($type !== 'SALE') {
        return clean_text($row['item_name'] ?? '');
    }

    $barcode = clean_text($row['ref'] ?? ($row['barcode'] ?? ''));
    $excelCost = ($row['imported_cost'] ?? null);

    if ($barcode !== '' && $barcode !== 'LEGACY_SERVICE') {
        // 1) Products: barcode + Excel cost when duplicate barcodes exist.
        $product = get_product_by_barcode_for_transactions($pdo, $barcode, $excelCost);
        if ($product) {
            return product_display_name_for_transactions($product, $row['item_name'] ?? '');
        }

        // 2) Tools: barcode + Excel cost when duplicate barcodes exist.
        $tool = get_tool_by_barcode_for_transactions($pdo, $barcode, $excelCost);
        if ($tool) {
            return tool_display_name_for_transactions($tool, $row['item_name'] ?? '');
        }
    }

    // If neither Product nor Tool can be identified safely, preserve the stored transaction description.
    return clean_text($row['item_name'] ?? '');
}

function build_legacy_phone_item(PDO $pdo, $clean_ref, $fallback_name, $expected_cost) {
    if (!db_table_exists($pdo, 'phones')) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM phones WHERE barcode = ? ORDER BY id ASC");
    $stmt->execute([$clean_ref]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($matches)) {
        return null;
    }

    $expected_cost = decode_cost_price($expected_cost);
    $same_cost = [];
    if ($expected_cost > 0) {
        foreach ($matches as $phone) {
            $phone_cost = decode_cost_price($phone['cost_price'] ?? 0);
            if (abs($phone_cost - $expected_cost) < 0.01) {
                $same_cost[] = $phone;
            }
        }
    }

    $candidates = !empty($same_cost) ? $same_cost : $matches;

    // If more than one phone shares the barcode and Excel has a cost, but none match that cost,
    // do not deduct to avoid taking stock from the wrong phone.
    if ($expected_cost > 0 && empty($same_cost) && count($matches) > 1) {
        return [
            'product_id' => null,
            'phone_id' => null,
            'barcode' => $clean_ref,
            'item_name' => $fallback_name . ' [Phone barcode found but phone cost did not match: ' . format_money_2($expected_cost) . ' AED]',
            'stock_match' => false,
            'stock_source' => 'phone',
            'match_reason' => 'phone_cost_not_matched',
            'matched_cost' => null,
        ];
    }

    usort($candidates, function($a, $b) {
        $qa = ((int)($a['quantity'] ?? 0) > 0) ? 0 : 1;
        $qb = ((int)($b['quantity'] ?? 0) > 0) ? 0 : 1;
        if ($qa !== $qb) return $qa <=> $qb;
        return ((int)$a['id']) <=> ((int)$b['id']);
    });

    foreach ($candidates as $phone) {
        if ((int)($phone['quantity'] ?? 0) > 0) {
            return [
                'product_id' => null,
                'phone_id' => (int)$phone['id'],
                'barcode' => $phone['barcode'],
                'item_name' => phone_display_name_for_import($phone, $fallback_name),
                'stock_match' => true,
                'stock_source' => 'phone',
                'match_reason' => ($expected_cost > 0 && !empty($same_cost)) ? 'phone_barcode_cost_available' : 'phone_barcode_available',
                'matched_cost' => decode_cost_price($phone['cost_price'] ?? 0),
            ];
        }
    }

    return [
        'product_id' => null,
        'phone_id' => null,
        'barcode' => $clean_ref,
        'item_name' => $fallback_name . ' [Phone barcode found but phone stock is 0]',
        'stock_match' => false,
        'stock_source' => 'phone',
        'match_reason' => 'phone_stock_empty',
        'matched_cost' => $expected_cost,
    ];
}

function build_legacy_item(PDO $pdo, $name_desc, $expected_cost, array &$product_cache) {
    $name_desc = clean_text($name_desc);
    $clean_ref = preg_replace('/\s+/', '', $name_desc);
    $expected_cost = decode_cost_price($expected_cost);

    // Barcode-like value: try phones FIRST, then normal products.
    // This fixes phone sales imported/edited in Daily Sales that stayed Available because products were checked before phones.
    // A barcode can be all digits (e.g. 868544077880407) OR alphanumeric (e.g. H96DWHQ1GC).
    // We require at least one digit so plain text descriptions (e.g. "Repair", "Screen Guard")
    // are still treated as services rather than being mistaken for a barcode.
    if (preg_match('/^[A-Za-z0-9]{6,}$/', $clean_ref) && preg_match('/\d/', $clean_ref)) {
        $phone_item_first = build_legacy_phone_item($pdo, $clean_ref, $name_desc, $expected_cost);
        if ($phone_item_first !== null) {
            return $phone_item_first;
        }

        // Fetch fresh product quantities every row, because stock is deducted while importing.
        // If the same barcode + cost exists more than once, use an available row; when it reaches 0, use the next one.
        $stmt = $pdo->prepare("
            SELECT id, barcode, product_name, model_name, cost_price, quantity
            FROM products
            WHERE barcode = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$clean_ref]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($matches)) {
            $selected = null;
            $match_reason = '';

            if ($expected_cost > 0) {
                $same_cost = [];
                foreach ($matches as $p) {
                    $product_cost = decode_cost_price($p['cost_price'] ?? 0);
                    if (abs($product_cost - $expected_cost) < 0.01) {
                        $same_cost[] = $p;
                    }
                }

                if (!empty($same_cost)) {
                    usort($same_cost, function($a, $b) {
                        $qa = product_sort_key_for_import($a);
                        $qb = product_sort_key_for_import($b);
                        if ($qa !== $qb) return $qa <=> $qb;
                        return ((int)$a['id']) <=> ((int)$b['id']);
                    });

                    foreach ($same_cost as $candidate) {
                        if ((int)($candidate['quantity'] ?? 0) > 0) {
                            $selected = $candidate;
                            $match_reason = 'barcode_cost_available';
                            break;
                        }
                    }

                    if (!$selected) {
                        $phone_item = build_legacy_phone_item($pdo, $clean_ref, $name_desc, $expected_cost);
                        if ($phone_item !== null && ($phone_item['stock_match'] ?? false)) {
                            return $phone_item;
                        }
                        return [
                            'product_id' => null,
                            'phone_id' => null,
                            'barcode' => $clean_ref,
                            'item_name' => $name_desc . ' [Matched barcode/cost but stock is 0]',
                            'stock_match' => false,
                            'stock_source' => 'product',
                            'match_reason' => 'barcode_cost_stock_empty',
                            'matched_cost' => $expected_cost,
                        ];
                    }
                }
            }

            if (!$selected && count($matches) === 1) {
                if ((int)($matches[0]['quantity'] ?? 0) > 0) {
                    $selected = $matches[0];
                    $match_reason = ($expected_cost > 0) ? 'barcode_only_cost_not_matched_single' : 'barcode_only_single';
                } else {
                    $phone_item = build_legacy_phone_item($pdo, $clean_ref, $name_desc, $expected_cost);
                    if ($phone_item !== null && ($phone_item['stock_match'] ?? false)) {
                        return $phone_item;
                    }
                    return [
                        'product_id' => null,
                        'phone_id' => null,
                        'barcode' => $clean_ref,
                        'item_name' => $name_desc . ' [Barcode found but stock is 0]',
                        'stock_match' => false,
                        'stock_source' => 'product',
                        'match_reason' => 'barcode_stock_empty',
                        'matched_cost' => decode_cost_price($matches[0]['cost_price'] ?? 0),
                    ];
                }
            }

            if ($selected) {
                return [
                    'product_id' => (int)$selected['id'],
                    'phone_id' => null,
                    'barcode' => $selected['barcode'],
                    'item_name' => product_display_name_for_import($selected, $name_desc),
                    'stock_match' => true,
                    'stock_source' => 'product',
                    'match_reason' => $match_reason,
                    'matched_cost' => decode_cost_price($selected['cost_price'] ?? 0),
                ];
            }

            $phone_item = build_legacy_phone_item($pdo, $clean_ref, $name_desc, $expected_cost);
            if ($phone_item !== null && ($phone_item['stock_match'] ?? false)) {
                return $phone_item;
            }

            return [
                'product_id' => null,
                'phone_id' => null,
                'barcode' => $clean_ref,
                'item_name' => $name_desc . ($expected_cost > 0 ? ' [No stock cost match: ' . format_money_2($expected_cost) . ' AED]' : ' [No cost provided for duplicate barcode]'),
                'stock_match' => false,
                'stock_source' => 'product',
                'match_reason' => 'barcode_found_cost_not_matched',
                'matched_cost' => null,
            ];
        }

        $phone_item = build_legacy_phone_item($pdo, $clean_ref, $name_desc, $expected_cost);
        if ($phone_item !== null) {
            return $phone_item;
        }


        // No Product/Phone match: try Tools using barcode + Excel cost.
        $tool_match = get_tool_by_barcode_for_transactions($pdo, $clean_ref, $expected_cost);
        if ($tool_match) {
            return [
                'product_id' => null,
                'phone_id' => null,
                'barcode' => $clean_ref,
                'item_name' => tool_display_name_for_transactions($tool_match, $name_desc),
                'stock_match' => false,
                'stock_source' => 'tool',
                'match_reason' => 'tool_barcode_cost_description',
                'matched_cost' => decode_cost_price($tool_match['cost_price'] ?? $expected_cost),
            ];
        }

        return ['product_id' => null, 'phone_id' => null, 'barcode' => $clean_ref, 'item_name' => $name_desc, 'stock_match' => false, 'stock_source' => 'none', 'match_reason' => 'barcode_not_found', 'matched_cost' => null];
    }

    // Arabic/English text or internal code that is not barcode: import it as a service.
    return ['product_id' => null, 'phone_id' => null, 'barcode' => 'LEGACY_SERVICE', 'item_name' => $name_desc, 'stock_match' => false, 'stock_source' => 'service', 'match_reason' => 'service_text', 'matched_cost' => null];
}

function upsert_legacy_sale(PDO $pdo, $product_id, $barcode, $item_name, $amount, $payment_method, $sold_at, $imported_cost, $imported_profit, $legacy_key, $deduct_from_stock = true, $phone_id = null) {
    $new_product_id = !empty($product_id) ? (int)$product_id : null;
    $new_phone_id = !empty($phone_id) ? (int)$phone_id : null;
    $new_qty = 1;

    // Check whether this legacy row already exists so re-uploading the same Excel will not deduct stock twice.
    $old_stmt = $pdo->prepare("SELECT id, product_id, phone_id, qty_sold, import_source, stock_deducted, phone_stock_deducted FROM sales_records WHERE legacy_import_key = ? LIMIT 1");
    $old_stmt->execute([$legacy_key]);
    $old_sale = $old_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmt = $pdo->prepare("
        INSERT INTO sales_records
        (product_id, phone_id, barcode, item_name, qty_sold, sale_price, total_amount, payment_method, sold_at, imported_cost, imported_profit, import_source, legacy_import_key, stock_deducted, phone_stock_deducted)
        VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, 'legacy_excel', ?, 0, 0)
        ON DUPLICATE KEY UPDATE
            product_id = VALUES(product_id),
            phone_id = VALUES(phone_id),
            barcode = VALUES(barcode),
            item_name = VALUES(item_name),
            qty_sold = VALUES(qty_sold),
            sale_price = VALUES(sale_price),
            total_amount = VALUES(total_amount),
            payment_method = VALUES(payment_method),
            sold_at = VALUES(sold_at),
            imported_cost = VALUES(imported_cost),
            imported_profit = VALUES(imported_profit),
            import_source = VALUES(import_source)
    ");
    $stmt->execute([$new_product_id, $new_phone_id, $barcode, $item_name, $amount, $amount, $payment_method, $sold_at, $imported_cost, $imported_profit, $legacy_key]);

    $old_product_was_deducted = $old_sale && (int)($old_sale['stock_deducted'] ?? 0) === 1 && !empty($old_sale['product_id']);
    $old_phone_was_deducted = $old_sale && (int)($old_sale['phone_stock_deducted'] ?? 0) === 1 && !empty($old_sale['phone_id']);

    // If this existing imported row was already deducted before, restore the old stock first.
    // Then deduct again using the latest barcode/product/phone from the Excel row.
    if ($old_product_was_deducted) {
        $restore = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
        $restore->execute([(int)$old_sale['qty_sold'], (int)$old_sale['product_id']]);
    }

    if ($old_phone_was_deducted && db_table_exists($pdo, 'phones')) {
        $restorePhone = $pdo->prepare("UPDATE phones SET quantity = quantity + ? WHERE id = ?");
        $restorePhone->execute([(int)$old_sale['qty_sold'], (int)$old_sale['phone_id']]);
        $markAvailable = $pdo->prepare("UPDATE phones SET status = 'Available' WHERE id = ? AND quantity > 0 AND LOWER(status) = 'sold'");
        $markAvailable->execute([(int)$old_sale['phone_id']]);
    }

    $deducted_product_now = false;
    $deducted_phone_now = false;

    if ($deduct_from_stock && !empty($new_product_id)) {
        // Do not allow negative stock. build_legacy_item() already chooses an available matching row,
        // but this WHERE protects the database if stock changed before the update.
        $deduct = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
        $deduct->execute([$new_qty, $new_product_id, $new_qty]);
        $deducted_product_now = ($deduct->rowCount() > 0);
    } elseif ($deduct_from_stock && !empty($new_phone_id) && db_table_exists($pdo, 'phones')) {
        $deductPhone = $pdo->prepare("UPDATE phones SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
        $deductPhone->execute([$new_qty, $new_phone_id, $new_qty]);
        $deducted_phone_now = ($deductPhone->rowCount() > 0);
        if ($deducted_phone_now) {
            $markSold = $pdo->prepare("UPDATE phones SET status = 'Sold' WHERE id = ? AND quantity <= 0");
            $markSold->execute([$new_phone_id]);
        }
    }

    $flag_product = $deducted_product_now ? 1 : 0;
    $flag_phone = $deducted_phone_now ? 1 : 0;
    $flag_stmt = $pdo->prepare("UPDATE sales_records SET stock_deducted = ?, phone_stock_deducted = ? WHERE legacy_import_key = ?");
    $flag_stmt->execute([$flag_product, $flag_phone, $legacy_key]);

    if ($deducted_phone_now) return 'phone';
    if ($deducted_product_now) return 'product';
    return '';
}

function upsert_legacy_drawer(PDO $pdo, $name, $amount, $date_time, $legacy_key) {
    $stmt = $pdo->prepare("
        INSERT INTO drawer_withdrawals (name, amount, withdrawn_at, import_source, legacy_import_key)
        VALUES (?, ?, ?, 'legacy_excel', ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            amount = VALUES(amount),
            withdrawn_at = VALUES(withdrawn_at),
            import_source = VALUES(import_source)
    ");
    $stmt->execute([$name, $amount, $date_time, $legacy_key]);
}

function cleanup_blank_legacy_drawer_rows(PDO $pdo, $date) {
    // Some old Excel sheets repeat H/J amounts again as totals with no description.
    // This removes previously imported blank/default drawer rows for the day so re-importing fixes duplicates.
    $stmt = $pdo->prepare("
        DELETE FROM drawer_withdrawals
        WHERE DATE(withdrawn_at) = ?
          AND import_source = 'legacy_excel'
          AND (name = 'Legacy withdrawal' OR name = 'DEPOSIT: Legacy deposit')
    ");
    $stmt->execute([$date]);
}

function upsert_reconciliation(PDO $pdo, $date, $opening_balance, $cash_sales, $expected_amount, $actual_amount, $difference) {
    $check = $pdo->prepare("SELECT id FROM drawer_reconciliations WHERE DATE(reconciled_at) = ? ORDER BY reconciled_at DESC LIMIT 1");
    $check->execute([$date]);
    $id = $check->fetchColumn();
    $reconciled_at = $date . ' 23:59:00';

    if ($id) {
        $stmt = $pdo->prepare("
            UPDATE drawer_reconciliations
            SET opening_balance = ?, cash_sales = ?, expected_amount = ?, actual_amount = ?, difference = ?, reconciled_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$opening_balance, $cash_sales, $expected_amount, $actual_amount, $difference, $reconciled_at, $id]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO drawer_reconciliations
            (opening_balance, cash_sales, expected_amount, actual_amount, difference, reconciled_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$opening_balance, $cash_sales, $expected_amount, $actual_amount, $difference, $reconciled_at]);
    }
}


function normalize_activity_type($type) {
    $type = strtoupper(clean_text($type));
    return in_array($type, ['SALE', 'WITHDRAWAL', 'DEPOSIT', 'DRAWER'], true) ? $type : '';
}

function drawer_is_deposit($name, $amount) {
    $name = clean_text($name);
    return ((float)$amount < 0 || stripos($name, 'DEPOSIT:') === 0 || stripos($name, '[DEPOSIT]') === 0);
}

function strip_deposit_prefix($name) {
    $name = clean_text($name);
    $name = preg_replace('/^\s*(DEPOSIT:|\[DEPOSIT\])\s*/iu', '', $name);
    return clean_text($name);
}

function restore_stock_for_deleted_sale(PDO $pdo, $sale_id) {
    $stmt = $pdo->prepare("SELECT product_id, phone_id, qty_sold, import_source, stock_deducted, phone_stock_deducted FROM sales_records WHERE id = ? LIMIT 1");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        return;
    }

    // Normal POS product sales were already deducted by sales.php.
    // Legacy Excel product rows are restored only when this importer marked stock_deducted = 1.
    $product_was_deducted = !empty($sale['product_id']) && (((int)($sale['stock_deducted'] ?? 0) === 1) || (($sale['import_source'] ?? '') !== 'legacy_excel'));
    if ($product_was_deducted) {
        $restore = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
        $restore->execute([(int)$sale['qty_sold'], (int)$sale['product_id']]);
    }

    // Legacy Excel phone rows are restored when phone_stock_deducted = 1.
    $phone_was_deducted = !empty($sale['phone_id']) && ((int)($sale['phone_stock_deducted'] ?? 0) === 1);
    if ($phone_was_deducted && db_table_exists($pdo, 'phones')) {
        $restorePhone = $pdo->prepare("UPDATE phones SET quantity = quantity + ? WHERE id = ?");
        $restorePhone->execute([(int)$sale['qty_sold'], (int)$sale['phone_id']]);
        $markAvailable = $pdo->prepare("UPDATE phones SET status = 'Available' WHERE id = ? AND quantity > 0 AND LOWER(status) = 'sold'");
        $markAvailable->execute([(int)$sale['phone_id']]);
    }
}

function ensure_recharge_import_support(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS recharge_device_balances (
        provider VARCHAR(30) PRIMARY KEY,
        available_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        source_type VARCHAR(50) DEFAULT NULL,
        source_sale_id INT DEFAULT NULL,
        INDEX idx_recharges_date (created_at),
        INDEX idx_recharges_source_sale_id (source_sale_id),
        INDEX idx_recharges_source_type (source_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!db_column_exists($pdo, 'recharges', 'source_type')) {
        $pdo->exec("ALTER TABLE recharges ADD COLUMN source_type VARCHAR(50) DEFAULT NULL AFTER updated_at");
    }
    if (!db_column_exists($pdo, 'recharges', 'source_sale_id')) {
        $pdo->exec("ALTER TABLE recharges ADD COLUMN source_sale_id INT DEFAULT NULL AFTER source_type");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS recharge_balance_imports (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        import_key VARCHAR(191) NOT NULL,
        movement_date DATE NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_recharge_balance_import_key (import_key),
        INDEX idx_recharge_balance_import_date (movement_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $pdo->prepare("INSERT INTO recharge_device_balances (provider, available_amount, updated_at)
                           VALUES ('__TOTAL_DEVICE_BALANCE__', 0, NOW())
                           ON DUPLICATE KEY UPDATE provider = VALUES(provider)");
    $stmt->execute();
}

function recharge_text_contains(PDO $pdo, $text, $needle) {
    $text = trim((string)$text);
    if ($text === '') return false;
    if (function_exists('mb_stripos')) return mb_stripos($text, $needle, 0, 'UTF-8') !== false;
    return strpos($text, $needle) !== false;
}

function add_recharge_device_balance_import(PDO $pdo, $amount) {
    $stmt = $pdo->prepare("INSERT INTO recharge_device_balances (provider, available_amount, updated_at)
                           VALUES ('__TOTAL_DEVICE_BALANCE__', ?, NOW())
                           ON DUPLICATE KEY UPDATE available_amount = available_amount + VALUES(available_amount), updated_at = NOW()");
    $stmt->execute([(float)$amount]);
}

function get_recharge_device_balance_import(PDO $pdo) {
    $stmt = $pdo->prepare("SELECT available_amount FROM recharge_device_balances WHERE provider='__TOTAL_DEVICE_BALANCE__'");
    $stmt->execute();
    $v = $stmt->fetchColumn();
    return $v === false ? 0.0 : (float)$v;
}

function restore_recharge_import_credits_for_deleted_date(PDO $pdo, $date) {
    $stmt = $pdo->prepare("SELECT id, amount FROM recharge_balance_imports WHERE movement_date = ?");
    $stmt->execute([$date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($rows as $row) {
        $amount = (float)($row['amount'] ?? 0);
        if ($amount != 0) add_recharge_device_balance_import($pdo, -$amount);
        $del = $pdo->prepare("DELETE FROM recharge_balance_imports WHERE id = ?");
        $del->execute([(int)$row['id']]);
        $count += $del->rowCount();
    }
    return $count;
}

function apply_imported_recharge_credits_for_date(PDO $pdo, $date) {
    if (!db_table_exists($pdo, 'drawer_withdrawals')) return ['count'=>0,'amount'=>0.0];

    $sql = "SELECT id, name, amount, withdrawn_at";
    $sql .= db_column_exists($pdo, 'drawer_withdrawals', 'legacy_import_key') ? ", legacy_import_key" : ", NULL AS legacy_import_key";
    $sql .= db_column_exists($pdo, 'drawer_withdrawals', 'import_source') ? ", import_source" : ", NULL AS import_source";
    $sql .= " FROM drawer_withdrawals WHERE DATE(withdrawn_at)=? AND amount > 50 ORDER BY id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date]);

    $count = 0;
    $total = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!recharge_text_contains($pdo, $row['name'] ?? '', 'رصيد للمحل')) continue;
        if (($row['import_source'] ?? '') !== '' && ($row['import_source'] ?? '') !== 'legacy_excel') continue;
        $amount = abs((float)($row['amount'] ?? 0));
        if ($amount <= 50) continue;
        $key = trim((string)($row['legacy_import_key'] ?? ''));
        if ($key === '') $key = 'drawer:' . (int)$row['id'];

        $check = $pdo->prepare("SELECT id FROM recharge_balance_imports WHERE import_key=? LIMIT 1");
        $check->execute([$key]);
        if ($check->fetchColumn()) continue;

        add_recharge_device_balance_import($pdo, $amount);
        $ins = $pdo->prepare("INSERT INTO recharge_balance_imports (import_key, movement_date, description, amount) VALUES (?, ?, ?, ?)");
        $ins->execute([$key, $date, (string)($row['name'] ?? ''), $amount]);
        $count++;
        $total += $amount;
    }
    return ['count'=>$count,'amount'=>$total];
}

function auto_sync_imported_recharge_sales_for_date(PDO $pdo, $date) {
    $hasImportedCost = db_column_exists($pdo, 'sales_records', 'imported_cost');
    $sql = "SELECT id, barcode, item_name, qty_sold, total_amount, payment_method, sold_at";
    $sql .= $hasImportedCost ? ", imported_cost" : ", NULL AS imported_cost";
    $sql .= " FROM sales_records WHERE DATE(sold_at)=? ORDER BY sold_at ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date]);

    $matched = 0;
    $imported = 0;
    $deducted = 0.0;
    $skippedBalance = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sale) {
        $lookup = trim((string)($sale['item_name'] ?? '') . ' ' . (string)($sale['barcode'] ?? ''));
        if (!recharge_text_contains($pdo, $lookup, 'رصيد')) continue;
        $matched++;

        $saleId = (int)$sale['id'];
        $exists = $pdo->prepare("SELECT id FROM recharges WHERE source_type='daily_transaction' AND source_sale_id=? LIMIT 1");
        $exists->execute([$saleId]);
        if ($exists->fetchColumn()) continue;

        $qty = max(1, (int)($sale['qty_sold'] ?? 1));
        $amountPaid = round((float)($sale['total_amount'] ?? 0), 2);
        $importedCost = (float)($sale['imported_cost'] ?? 0);
        $costAmount = $importedCost > 0 ? round($importedCost * $qty, 2) : $amountPaid;
        if ($costAmount <= 0 && $amountPaid > 0) $costAmount = $amountPaid;
        if ($costAmount < 0 || $amountPaid < 0) continue;

        $balance = get_recharge_device_balance_import($pdo);
        if ($costAmount > 0 && $balance + 0.0001 < $costAmount) {
            $skippedBalance++;
            continue;
        }

        if ($costAmount > 0) add_recharge_device_balance_import($pdo, -$costAmount);
        $notes = 'Auto imported from Excel transaction #' . $saleId;
        if (trim((string)($sale['item_name'] ?? '')) !== '') $notes .= ' - ' . trim((string)$sale['item_name']);
        $createdAt = !empty($sale['sold_at']) ? date('Y-m-d H:i:s', strtotime($sale['sold_at'])) : $date . ' 12:00:00';
        $customer = trim((string)($sale['barcode'] ?? ''));
        $ins = $pdo->prepare("INSERT INTO recharges
            (provider, service_type, customer_number, amount_paid, cost_amount, payment_method, status, notes, created_at, source_type, source_sale_id)
            VALUES ('Du / Etisalat', 'Daily رصيد Sale', ?, ?, ?, 'CASH', 'Completed', ?, ?, 'daily_transaction', ?)");
        $ins->execute([$customer, $amountPaid, $costAmount, $notes, $createdAt, $saleId]);
        $imported++;
        $deducted += $costAmount;
    }
    return ['matched'=>$matched,'imported'=>$imported,'deducted'=>$deducted,'skipped_balance'=>$skippedBalance];
}

function restore_recharge_device_balance_for_deleted_date(PDO $pdo, $date) {
    // If recharge records exist for this imported day, restore their device-balance effect before deleting them.
    // This supports the recharges.php total device balance table without requiring this page to include recharges.php.
    if (!db_table_exists($pdo, 'recharges')) {
        return 0;
    }

    $stmt = $pdo->prepare("SELECT id, cost_amount, status FROM recharges WHERE DATE(created_at) = ?");
    $stmt->execute([$date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $deleted = 0;

    $hasBalanceTable = db_table_exists($pdo, 'recharge_device_balances');
    $restoreBalanceStmt = null;
    if ($hasBalanceTable) {
        $restoreBalanceStmt = $pdo->prepare("INSERT INTO recharge_device_balances (provider, available_amount, updated_at)
            VALUES ('__TOTAL_DEVICE_BALANCE__', ?, NOW())
            ON DUPLICATE KEY UPDATE available_amount = available_amount + VALUES(available_amount), updated_at = NOW()");
    }

    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? '');
        $cost = (float)($row['cost_amount'] ?? 0);
        if ($restoreBalanceStmt && $status === 'Completed' && $cost > 0) {
            $restoreBalanceStmt->execute([$cost]);
        }

        // Delete any transaction row that was created by a recharge record.
        if (db_column_exists($pdo, 'sales_records', 'recharge_id')) {
            $deleteLinkedSale = $pdo->prepare("DELETE FROM sales_records WHERE recharge_id = ?");
            $deleteLinkedSale->execute([(int)$row['id']]);
        }

        $deleteRecharge = $pdo->prepare("DELETE FROM recharges WHERE id = ?");
        $deleteRecharge->execute([(int)$row['id']]);
        $deleted += $deleteRecharge->rowCount();
    }

    return $deleted;
}

function replace_import_day_records(PDO $pdo, $date) {
    // When daily transactions are imported, the Excel/Google Sheet is the source of truth for that date.
    // So old sales, drawer movements, recharges, and reconciliation for that date are deleted first,
    // while product/phone stock and recharge device balance are restored before rebuilding fresh from the file.
    $summary = [
        'sales_deleted' => 0,
        'drawer_deleted' => 0,
        'recharges_deleted' => 0,
        'recharge_credits_reversed' => 0,
        'reconciliations_deleted' => 0,
    ];

    $summary['recharges_deleted'] = restore_recharge_device_balance_for_deleted_date($pdo, $date);
    $summary['recharge_credits_reversed'] = restore_recharge_import_credits_for_deleted_date($pdo, $date);

    $saleIds = $pdo->prepare("SELECT id FROM sales_records WHERE DATE(sold_at) = ?");
    $saleIds->execute([$date]);
    $ids = $saleIds->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $saleId) {
        restore_stock_for_deleted_sale($pdo, (int)$saleId);
    }
    if (!empty($ids)) {
        $deleteSales = $pdo->prepare("DELETE FROM sales_records WHERE DATE(sold_at) = ?");
        $deleteSales->execute([$date]);
        $summary['sales_deleted'] = $deleteSales->rowCount();
    }

    if (db_table_exists($pdo, 'drawer_withdrawals')) {
        $deleteDrawer = $pdo->prepare("DELETE FROM drawer_withdrawals WHERE DATE(withdrawn_at) = ?");
        $deleteDrawer->execute([$date]);
        $summary['drawer_deleted'] = $deleteDrawer->rowCount();
    }

    if (db_table_exists($pdo, 'drawer_reconciliations')) {
        if (db_column_exists($pdo, 'drawer_reconciliations', 'reconciled_at')) {
            $deleteRecon = $pdo->prepare("DELETE FROM drawer_reconciliations WHERE DATE(reconciled_at) = ?");
            $deleteRecon->execute([$date]);
            $summary['reconciliations_deleted'] = $deleteRecon->rowCount();
        } elseif (db_column_exists($pdo, 'drawer_reconciliations', 'recon_date')) {
            $deleteRecon = $pdo->prepare("DELETE FROM drawer_reconciliations WHERE recon_date = ?");
            $deleteRecon->execute([$date]);
            $summary['reconciliations_deleted'] = $deleteRecon->rowCount();
        }
    }

    return $summary;
}


function delete_activity_item(PDO $pdo, $type, $id) {
    $type = normalize_activity_type($type);
    $id = (int)$id;
    if ($id <= 0 || $type === '') return false;

    if ($type === 'SALE') {
        restore_stock_for_deleted_sale($pdo, $id);
        $stmt = $pdo->prepare("DELETE FROM sales_records WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    // WITHDRAWAL / DEPOSIT are both stored in drawer_withdrawals.
    $stmt = $pdo->prepare("DELETE FROM drawer_withdrawals WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function fetch_edit_activity(PDO $pdo, $type, $id) {
    $type = normalize_activity_type($type);
    $id = (int)$id;
    if ($id <= 0 || $type === '') return null;

    if ($type === 'SALE') {
        $stmt = $pdo->prepare("\n            SELECT sr.*, MIN(p.cost_price) AS join_cost_price\n            FROM sales_records sr\n            LEFT JOIN products p ON sr.barcode = p.barcode\n            WHERE sr.id = ?\n            GROUP BY sr.id\n            LIMIT 1\n        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['edit_type'] = 'SALE';
            $row['cost_per_item'] = ($row['imported_cost'] !== null && $row['imported_cost'] !== '')
                ? (float)$row['imported_cost']
                : decode_cost_price($row['join_cost_price'] ?? 0);
        }
        return $row ?: null;
    }

    $stmt = $pdo->prepare("SELECT * FROM drawer_withdrawals WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['edit_type'] = drawer_is_deposit($row['name'], $row['amount']) ? 'DEPOSIT' : 'WITHDRAWAL';
        $row['clean_name'] = strip_deposit_prefix($row['name']);
        $row['clean_amount'] = abs((float)$row['amount']);
    }
    return $row ?: null;
}


function ensure_daily_cash_sheet_tables_from_transactions(PDO $pdo) {
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
        INDEX idx_daily_cash_sheet_rows (sheet_id, row_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!db_column_exists($pdo, 'daily_cash_sheet_rows', 'item_name')) {
        $pdo->exec("ALTER TABLE daily_cash_sheet_rows ADD COLUMN item_name VARCHAR(255) NULL AFTER code");
    }
    if (!db_column_exists($pdo, 'daily_cash_sheet_rows', 'tamara_amount')) {
        $pdo->exec("ALTER TABLE daily_cash_sheet_rows ADD COLUMN tamara_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER visa_amount");
    }
}

function sync_imported_date_to_daily_cash_sheet(PDO $pdo, $date) {
    ensure_daily_cash_sheet_tables_from_transactions($pdo);

    $recon = null;
    if (db_table_exists($pdo, 'drawer_reconciliations')) {
        if (db_column_exists($pdo, 'drawer_reconciliations', 'reconciled_at')) {
            $stmt = $pdo->prepare("SELECT * FROM drawer_reconciliations WHERE DATE(reconciled_at) = ? ORDER BY reconciled_at DESC LIMIT 1");
            $stmt->execute([$date]);
            $recon = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    $opening = (float)($recon['opening_balance'] ?? 0);
    $physical = (float)($recon['actual_amount'] ?? 0);

    $upsert = $pdo->prepare("\n        INSERT INTO daily_cash_sheets\n            (sheet_date, previous_day_balance, physical_drawer_amount, notes, updated_at)\n        VALUES (?, ?, ?, '', NOW())\n        ON DUPLICATE KEY UPDATE\n            previous_day_balance = VALUES(previous_day_balance),\n            physical_drawer_amount = VALUES(physical_drawer_amount),\n            updated_at = NOW()\n    ");
    $upsert->execute([$date, $opening, $physical]);

    $idStmt = $pdo->prepare("SELECT id FROM daily_cash_sheets WHERE sheet_date = ?");
    $idStmt->execute([$date]);
    $sheetId = (int)$idStmt->fetchColumn();

    $pdo->prepare("DELETE FROM daily_cash_sheet_rows WHERE sheet_id = ?")->execute([$sheetId]);

    $hasImportedCost = db_column_exists($pdo, 'sales_records', 'imported_cost');
    $hasImportedProfit = db_column_exists($pdo, 'sales_records', 'imported_profit');

    $salesSql = "SELECT id, barcode, item_name, qty_sold, sale_price, total_amount, payment_method, sold_at";
    $salesSql .= $hasImportedCost ? ", imported_cost" : ", NULL AS imported_cost";
    $salesSql .= $hasImportedProfit ? ", imported_profit" : ", NULL AS imported_profit";
    $salesSql .= " FROM sales_records WHERE DATE(sold_at) = ? ORDER BY sold_at ASC, id ASC";
    $salesStmt = $pdo->prepare($salesSql);
    $salesStmt->execute([$date]);
    $sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

    $movements = [];
    if (db_table_exists($pdo, 'drawer_withdrawals')) {
        $movementStmt = $pdo->prepare("SELECT id, name, amount, withdrawn_at FROM drawer_withdrawals WHERE DATE(withdrawn_at) = ? ORDER BY withdrawn_at ASC, id ASC");
        $movementStmt->execute([$date]);
        $movements = $movementStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $insert = $pdo->prepare("\n        INSERT INTO daily_cash_sheet_rows\n            (sheet_id, row_order, code, item_name, cash_amount, visa_amount, tamara_amount,\n             cost_amount, profit_amount, withdrawal_name, withdrawal_amount,\n             addition_name, addition_amount, row_notes)\n        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n    ");

    $rowOrder = 1;
    foreach ($sales as $sale) {
        $qty = max(1, (int)($sale['qty_sold'] ?? 1));
        $amount = (float)($sale['total_amount'] ?? 0);
        if ($amount == 0 && isset($sale['sale_price'])) {
            $amount = (float)$sale['sale_price'] * $qty;
        }

        $method = strtoupper(trim((string)($sale['payment_method'] ?? 'CASH')));
        $cash = 0.0;
        $visa = 0.0;
        $tamara = 0.0;
        $note = '';

        if ($method === '' || $method === 'CASH') {
            $cash = $amount;
        } elseif (in_array($method, ['VISA', 'CARD', 'CREDIT', 'DEBIT', 'VISA / CARD'], true)) {
            $visa = $amount;
        } elseif (in_array($method, ['TAMARA', 'TABBY', 'TAMARA / TABBY', 'TAMARA/TABBY'], true)) {
            $tamara = $amount;
        } else {
            $note = 'Payment: ' . $method;
        }

        $unitCost = ($sale['imported_cost'] !== null && $sale['imported_cost'] !== '')
            ? (float)$sale['imported_cost']
            : 0.0;
        $costTotal = $unitCost * $qty;

        if ($sale['imported_profit'] !== null && $sale['imported_profit'] !== '') {
            $profit = (float)$sale['imported_profit'];
        } else {
            $profit = $cash + ($visa * 0.975) + $tamara - $costTotal;
        }

        $name = trim((string)($sale['item_name'] ?? ''));
        if ($qty > 1) $name .= ($name !== '' ? ' ' : '') . '×' . $qty;

        $insert->execute([
            $sheetId, $rowOrder++, trim((string)($sale['barcode'] ?? '')), $name,
            $cash, $visa, $tamara, $costTotal, $profit,
            '', 0, '', 0, $note
        ]);
    }

    foreach ($movements as $movement) {
        $amount = (float)($movement['amount'] ?? 0);
        $description = trim((string)($movement['name'] ?? ''));

        if ($amount < 0 || stripos($description, 'DEPOSIT:') === 0 || stripos($description, '[DEPOSIT]') === 0) {
            $description = strip_deposit_prefix($description);
            $insert->execute([$sheetId, $rowOrder++, '', '', 0, 0, 0, 0, 0, '', 0, $description, abs($amount), '']);
        } elseif ($amount > 0) {
            $insert->execute([$sheetId, $rowOrder++, '', '', 0, 0, 0, 0, 0, $description, $amount, '', 0, '']);
        }
    }

    return $rowOrder - 1;
}

function import_legacy_excel(PDO $pdo, $file_path, $start_date_string) {
    ensure_legacy_import_columns($pdo);
    // IMPORTANT: MySQL DDL (CREATE/ALTER TABLE) implicitly commits active transactions.
    // Prepare Recharge support before beginTransaction() so the Excel import remains atomic.
    ensure_recharge_import_support($pdo);

    $start_date = new DateTime($start_date_string ?: '2026-05-28');
    $sheets = xlsx_read_workbook($file_path);
    $product_cache = [];

    $imported_sheets = 0;
    $imported_sales = 0;
    $stock_deducted_sales = 0;
    $phone_deducted_sales = 0;
    $cost_unmatched_sales = 0;
    $stock_empty_sales = 0;
    $phone_stock_empty_sales = 0;
    $imported_withdrawals = 0;
    $imported_deposits = 0;
    $imported_reconciliations = 0;
    $deleted_old_sales = 0;
    $deleted_old_drawer = 0;
    $deleted_old_recharges = 0;
    $recharge_credits_added = 0;
    $recharge_credit_amount = 0.0;
    $recharge_sales_imported = 0;
    $recharge_sales_deducted = 0.0;
    $recharge_sales_skipped_balance = 0;
    $tamara_imported_amount = 0.0;
    $tamara_sheet_total = 0.0;
    $tamara_total_mismatch_sheets = 0;
    $tamara_total_difference = 0.0;
    $deleted_old_recharge_credits = 0;
    $deleted_old_reconciliations = 0;
    $replaced_dates = [];
    $skipped_sheets = [];

    // Sort by real date so the imported history is clean.
    usort($sheets, function($a, $b) use ($start_date) {
        $da = sheet_name_to_date($a['name'], $start_date) ?? '9999-12-31';
        $db = sheet_name_to_date($b['name'], $start_date) ?? '9999-12-31';
        return strcmp($da, $db);
    });

    $pdo->beginTransaction();
    try {
        foreach ($sheets as $sheet) {
            $sheet_name = $sheet['name'];
            $date = sheet_name_to_date($sheet_name, $start_date);
            if (!$date) {
                $skipped_sheets[] = $sheet_name;
                continue;
            }

            // Excel layout changed starting 15 August 2026:
            // 2026-08-15 and newer: C = تابي/تمارا, so the old C onward columns moved one place right.
            // Older dates keep the original Excel mapping.
            $usesTamaraLayout = ($date >= '2026-08-15');

            $cells = $sheet['cells'];
            $imported_sheets++;

            // Replace all old records for this imported date before rebuilding from the file.
            // This deletes old sales, withdrawals/deposits, recharge rows, and reconciliation for the date.
            if (!isset($replaced_dates[$date])) {
                $replaceSummary = replace_import_day_records($pdo, $date);
                $deleted_old_sales += (int)$replaceSummary['sales_deleted'];
                $deleted_old_drawer += (int)$replaceSummary['drawer_deleted'];
                $deleted_old_recharges += (int)$replaceSummary['recharges_deleted'];
                $deleted_old_recharge_credits += (int)($replaceSummary['recharge_credits_reversed'] ?? 0);
                $deleted_old_reconciliations += (int)$replaceSummary['reconciliations_deleted'];
                $replaced_dates[$date] = true;
            }

            $sheet_cash_total = normalize_number(cell_value($cells, 121, 2)); // 121:B
            if ($usesTamaraLayout) {
                $sheet_tamara_total = normalize_number(cell_value($cells, 121, 3)); // 121:C - تابي/تمارا
                $sheet_visa_total = normalize_number(cell_value($cells, 121, 4)); // 121:D
                $sheet_cost_total = normalize_number(cell_value($cells, 121, 5)); // 121:E
                $sheet_profit_total = normalize_number(cell_value($cells, 121, 6)); // 121:F
            } else {
                $sheet_tamara_total = 0.0;
                $sheet_visa_total = normalize_number(cell_value($cells, 121, 3)); // old 121:C
                $sheet_cost_total = normalize_number(cell_value($cells, 121, 4)); // old 121:D
                $sheet_profit_total = normalize_number(cell_value($cells, 121, 5)); // old 121:E
            }

            $opening_balance = normalize_number(first_non_empty_cell($cells, 1, [3,4,5])); // 1:CDE
            $expected_drawer_from_sheet = normalize_number(first_non_empty_cell($cells, 122, [4,5,6])); // 122:DEF
            $drawer_amount = normalize_number(first_non_empty_cell($cells, 123, [5,6])); // 123:EF
            $sheet_difference = normalize_number(cell_value($cells, 123, 2)); // 123:B

            $withdrawals_total = 0.0;
            $deposits_total = 0.0;

            for ($row = 3; $row <= 120; $row++) {
                $name_desc = clean_text(cell_value($cells, $row, 1)); // A
                $cash_received = normalize_number(cell_value($cells, $row, 2)); // B

                if ($usesTamaraLayout) {
                    $tamara_received = normalize_number(cell_value($cells, $row, 3)); // C - تابي/تمارا
                    $visa_received = normalize_number(cell_value($cells, $row, 4)); // D
                    $cost_product = normalize_number(cell_value($cells, $row, 5)); // E
                    $profit_value = normalize_number(cell_value($cells, $row, 6)); // F

                    $withdraw_amount = normalize_number(cell_value($cells, $row, 9)); // I
                    $withdraw_desc = clean_text(cell_value($cells, $row, 10)); // J
                    $deposit_amount = normalize_number(cell_value($cells, $row, 11)); // K
                    $deposit_desc = clean_text(cell_value($cells, $row, 12)); // L
                    $profit_cell_raw = clean_text(cell_value($cells, $row, 6)); // F
                } else {
                    $tamara_received = 0.0;
                    $visa_received = normalize_number(cell_value($cells, $row, 3)); // old C
                    $cost_product = normalize_number(cell_value($cells, $row, 4)); // old D
                    $profit_value = normalize_number(cell_value($cells, $row, 5)); // old E

                    $withdraw_amount = normalize_number(cell_value($cells, $row, 8)); // old H
                    $withdraw_desc = clean_text(cell_value($cells, $row, 9)); // old I
                    $deposit_amount = normalize_number(cell_value($cells, $row, 10)); // old J
                    $deposit_desc = clean_text(cell_value($cells, $row, 11)); // old K
                    $profit_cell_raw = clean_text(cell_value($cells, $row, 5)); // old E
                }

                $row_total_received = $cash_received + $tamara_received + $visa_received;
                $has_received_amount = ($row_total_received > 0);

                // Import normal sale rows, and also import cost-only or amount-less rows.
                // Any row with an item/code in column A is treated as sold, even if column B
                // (Cash) is empty or 0 and no cost/profit was filled in — it's recorded as a
                // AED 0 sale so stock still gets deducted and the item is marked Sold.
                if ($name_desc !== '') {
                    $item = build_legacy_item($pdo, $name_desc, $cost_product, $product_cache);
                    if (($item['match_reason'] ?? '') === 'barcode_found_cost_not_matched') {
                        $cost_unmatched_sales++;
                    }
                    if (in_array(($item['match_reason'] ?? ''), ['barcode_cost_stock_empty', 'barcode_stock_empty'], true)) {
                        $stock_empty_sales++;
                    }
                    if (in_array(($item['match_reason'] ?? ''), ['phone_stock_empty'], true)) {
                        $phone_stock_empty_sales++;
                    }
                    $sale_time = sprintf('%s %02d:%02d:00', $date, min(22, 9 + intdiv($row, 30)), $row % 60);
                    $row_profit_value = ($profit_cell_raw !== '') ? $profit_value : ($row_total_received - $cost_product);

                    // Deduct stock only once per Excel row/product. If CASH, TAMARA/TABBY and/or VISA are filled in the same row,
                    // they are imported as separate payment records, but inventory is reduced one time only.
                    $stock_deduction_done_for_row = false;

                    if ($cash_received > 0) {
                        $ratio = ($row_total_received > 0) ? ($cash_received / $row_total_received) : 1;
                        $deducted = upsert_legacy_sale(
                            $pdo,
                            $item['product_id'],
                            $item['barcode'],
                            $item['item_name'],
                            $cash_received,
                            'CASH',
                            $sale_time,
                            $cost_product * $ratio,
                            $row_profit_value * $ratio,
                            'legacy_excel:' . $date . ':' . $sheet_name . ':r' . $row . ':cash',
                            !$stock_deduction_done_for_row,
                            $item['phone_id'] ?? null
                        );
                        if ($deducted) {
                            if ($deducted === 'phone') $phone_deducted_sales++; else $stock_deducted_sales++;
                            $stock_deduction_done_for_row = true;
                        }
                        $imported_sales++;
                    }

                    if ($tamara_received > 0) {
                        $ratio = ($row_total_received > 0) ? ($tamara_received / $row_total_received) : 1;
                        $deducted = upsert_legacy_sale(
                            $pdo,
                            $item['product_id'],
                            $item['barcode'],
                            $item['item_name'],
                            $tamara_received,
                            'TAMARA / TABBY',
                            $sale_time,
                            $cost_product * $ratio,
                            $row_profit_value * $ratio,
                            'legacy_excel:' . $date . ':' . $sheet_name . ':r' . $row . ':tamara_tabby',
                            !$stock_deduction_done_for_row,
                            $item['phone_id'] ?? null
                        );
                        if ($deducted) {
                            if ($deducted === 'phone') $phone_deducted_sales++; else $stock_deducted_sales++;
                            $stock_deduction_done_for_row = true;
                        }
                        $imported_sales++;
                        $tamara_imported_amount += $tamara_received;
                    }

                    if ($visa_received > 0) {
                        $ratio = ($row_total_received > 0) ? ($visa_received / $row_total_received) : 1;
                        $deducted = upsert_legacy_sale(
                            $pdo,
                            $item['product_id'],
                            $item['barcode'],
                            $item['item_name'],
                            $visa_received,
                            'VISA',
                            $sale_time,
                            $cost_product * $ratio,
                            $row_profit_value * $ratio,
                            'legacy_excel:' . $date . ':' . $sheet_name . ':r' . $row . ':visa',
                            !$stock_deduction_done_for_row,
                            $item['phone_id'] ?? null
                        );
                        if ($deducted) {
                            if ($deducted === 'phone') $phone_deducted_sales++; else $stock_deducted_sales++;
                            $stock_deduction_done_for_row = true;
                        }
                        $imported_sales++;
                    }

                    if (!$has_received_amount) {
                        $deducted = upsert_legacy_sale(
                            $pdo,
                            $item['product_id'],
                            $item['barcode'],
                            $item['item_name'],
                            0,
                            'CASH',
                            $sale_time,
                            $cost_product,
                            $row_profit_value,
                            'legacy_excel:' . $date . ':' . $sheet_name . ':r' . $row . ':cash',
                            true,
                            $item['phone_id'] ?? null
                        );
                        if ($deducted) { if ($deducted === 'phone') $phone_deducted_sales++; else $stock_deducted_sales++; }
                        $imported_sales++;
                    }
                }

                // Import drawer movement only when the description cell exists.
                // Some sheets repeat H/J amounts later as totals with blank description; skip those rows to avoid duplicates.
                if ($withdraw_amount > 0 && $withdraw_desc !== '') {
                    $withdrawals_total += $withdraw_amount;
                    $name = $withdraw_desc;
                    $time = sprintf('%s 20:%02d:00', $date, $row % 60);
                    upsert_legacy_drawer($pdo, $name, abs($withdraw_amount), $time, 'legacy_excel:' . $date . ':' . $sheet_name . ':r' . $row . ':withdraw');
                    $imported_withdrawals++;
                }

                if ($deposit_amount > 0 && $deposit_desc !== '') {
                    $deposits_total += $deposit_amount;
                    $name = 'DEPOSIT: ' . $deposit_desc;
                    $time = sprintf('%s 20:%02d:30', $date, $row % 60);
                    // Deposit is stored as negative amount in drawer_withdrawals so it can be separated from withdrawals.
                    upsert_legacy_drawer($pdo, $name, -abs($deposit_amount), $time, 'legacy_excel:' . $date . ':' . $sheet_name . ':r' . $row . ':deposit');
                    $imported_deposits++;
                }
            }

            // Verify تابي/تمارا only for sheets using the new layout (15-Aug-2026 onward).
            if ($usesTamaraLayout) {
                $tamara_sheet_total += $sheet_tamara_total;
                $sheet_tamara_imported = 0.0;
                for ($tamaraRow = 3; $tamaraRow <= 120; $tamaraRow++) {
                    $sheet_tamara_imported += normalize_number(cell_value($cells, $tamaraRow, 3));
                }
                $tamaraDiff = $sheet_tamara_imported - $sheet_tamara_total;
                if (abs($tamaraDiff) > 0.01) {
                    $tamara_total_mismatch_sheets++;
                    $tamara_total_difference += $tamaraDiff;
                }
            }

            // Recharge automation from imported Excel data:
            // 1) Withdrawal description containing "رصيد للمحل" and amount > 50 AED adds to shared Recharge Balance.
            // 2) Every imported sale containing "رصيد" is added to Recharge list and deducts its imported cost (or sale amount when cost is blank).
            $creditResult = apply_imported_recharge_credits_for_date($pdo, $date);
            $recharge_credits_added += (int)($creditResult['count'] ?? 0);
            $recharge_credit_amount += (float)($creditResult['amount'] ?? 0);

            $rechargeResult = auto_sync_imported_recharge_sales_for_date($pdo, $date);
            $recharge_sales_imported += (int)($rechargeResult['imported'] ?? 0);
            $recharge_sales_deducted += (float)($rechargeResult['deducted'] ?? 0);
            $recharge_sales_skipped_balance += (int)($rechargeResult['skipped_balance'] ?? 0);

            // Drawer reconciliation:
            // From 15-Aug-2026 onward, column C is Tamara/Tabby (non-cash).
            // Never trust an Excel expected-drawer formula for these new-layout sheets,
            // because that formula may still include column C. Calculate cash drawer here
            // using ONLY opening balance + column B cash + deposits - withdrawals.
            $calculated_expected_drawer = $opening_balance + $sheet_cash_total + $deposits_total - $withdrawals_total;

            if ($usesTamaraLayout) {
                $expected_amount = $calculated_expected_drawer;
                $actual_amount = ($drawer_amount > 0) ? $drawer_amount : $expected_amount;
                $difference = $actual_amount - $expected_amount;
            } else {
                // Keep the old behavior for sheets before 15-Aug-2026.
                $expected_amount = ($expected_drawer_from_sheet > 0) ? $expected_drawer_from_sheet : $calculated_expected_drawer;
                $actual_amount = ($drawer_amount > 0) ? $drawer_amount : $expected_amount;
                $difference = ($drawer_amount > 0) ? ($actual_amount - $expected_amount) : $sheet_difference;
            }

            upsert_reconciliation($pdo, $date, $opening_balance, $sheet_cash_total, $expected_amount, $actual_amount, $difference);
            $imported_reconciliations++;
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'sheets' => $imported_sheets,
        'sales' => $imported_sales,
        'stock_deducted' => $stock_deducted_sales,
        'phone_deducted' => $phone_deducted_sales,
        'cost_unmatched' => $cost_unmatched_sales,
        'stock_empty' => $stock_empty_sales,
        'phone_stock_empty' => $phone_stock_empty_sales,
        'withdrawals' => $imported_withdrawals,
        'deposits' => $imported_deposits,
        'reconciliations' => $imported_reconciliations,
        'deleted_sales' => $deleted_old_sales,
        'deleted_drawer' => $deleted_old_drawer,
        'deleted_recharges' => $deleted_old_recharges,
        'recharge_credits' => $recharge_credits_added,
        'recharge_credit_amount' => $recharge_credit_amount,
        'recharge_sales' => $recharge_sales_imported,
        'recharge_deducted' => $recharge_sales_deducted,
        'recharge_skipped_balance' => $recharge_sales_skipped_balance,
        'tamara_imported_amount' => $tamara_imported_amount,
        'tamara_sheet_total' => $tamara_sheet_total,
        'tamara_total_mismatch_sheets' => $tamara_total_mismatch_sheets,
        'tamara_total_difference' => $tamara_total_difference,
        'deleted_recharge_credits' => $deleted_old_recharge_credits,
        'deleted_reconciliations' => $deleted_old_reconciliations,
        'skipped' => $skipped_sheets,
        'dates' => array_keys($replaced_dates),
    ];
}


function google_sheet_export_xlsx_url($url) {
    $url = clean_text($url);
    if ($url === '') return '';

    if (preg_match('~docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)~', $url, $m)) {
        $sheet_id = $m[1];
        return 'https://docs.google.com/spreadsheets/d/' . $sheet_id . '/export?format=xlsx';
    }

    // Allow a direct .xlsx URL too.
    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }

    return '';
}

function download_xlsx_from_url($url) {
    $download_url = google_sheet_export_xlsx_url($url);
    if ($download_url === '') {
        throw new Exception('Please paste a valid Google Sheets link or direct .xlsx link.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'daily_sales_google_');
    if ($tmp === false) {
        throw new Exception('Could not create a temporary file for the Google Sheet download.');
    }
    $tmp_xlsx = $tmp . '.xlsx';
    @rename($tmp, $tmp_xlsx);

    $ok = false;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($download_url);
        $fp = fopen($tmp_xlsx, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 DailySalesImporter',
        ]);
        $ok = curl_exec($ch);
        if (!$ok) $error = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($http >= 400 || $http === 0) {
            $ok = false;
            if ($error === '') $error = 'HTTP error ' . $http;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 120,
                'header' => "User-Agent: Mozilla/5.0 DailySalesImporter\r\n",
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $data = @file_get_contents($download_url, false, $context);
        if ($data !== false) {
            file_put_contents($tmp_xlsx, $data);
            $ok = true;
        } else {
            $error = 'Server could not download the Google Sheet. Enable cURL or allow_url_fopen.';
        }
    }

    if (!$ok || !file_exists($tmp_xlsx) || filesize($tmp_xlsx) < 1000) {
        @unlink($tmp_xlsx);
        throw new Exception('Could not download the Google Sheet as Excel. Make sure sharing is set to "Anyone with the link". ' . $error);
    }

    // Basic XLSX check: .xlsx files are ZIP files starting with PK.
    $fh = fopen($tmp_xlsx, 'rb');
    $signature = $fh ? fread($fh, 2) : '';
    if ($fh) fclose($fh);
    if ($signature !== 'PK') {
        @unlink($tmp_xlsx);
        throw new Exception('Downloaded file is not a valid .xlsx file. Please check the Google Sheet sharing/export link.');
    }

    return $tmp_xlsx;
}


function infer_legacy_start_date_from_upload_name($file_name, $fallback_date) {
    $base = pathinfo((string)$file_name, PATHINFO_FILENAME);
    $base = str_replace(['_', '.', ' '], '-', $base);

    // Monthly file names: 05-2026, 5-2026, 2026-05, 2026_06, etc.
    if (preg_match('/(?:^|[^0-9])(0?[1-9]|1[0-2])-((?:20)\d{2})(?:[^0-9]|$)/', $base, $m)) {
        return sprintf('%04d-%02d-01', (int)$m[2], (int)$m[1]);
    }
    if (preg_match('/(?:^|[^0-9])((?:20)\d{2})-(0?[1-9]|1[0-2])(?:[^0-9]|$)/', $base, $m)) {
        return sprintf('%04d-%02d-01', (int)$m[1], (int)$m[2]);
    }

    // Full date file names: 28-05-2026 or 2026-05-28.
    if (preg_match('/(?:^|[^0-9])(\d{1,2})-(\d{1,2})-((?:20)\d{2})(?:[^0-9]|$)/', $base, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    if (preg_match('/(?:^|[^0-9])((?:20)\d{2})-(\d{1,2})-(\d{1,2})(?:[^0-9]|$)/', $base, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }

    return $fallback_date;
}

function uploaded_excel_files_list($field_name) {
    if (!isset($_FILES[$field_name])) return [];
    $files = $_FILES[$field_name];
    $result = [];

    if (is_array($files['name'] ?? null)) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $result[] = [
                'name' => $files['name'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }
    } else {
        $result[] = [
            'name' => $files['name'] ?? '',
            'tmp_name' => $files['tmp_name'] ?? '',
            'error' => $files['error'] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'] ?? 0,
        ];
    }

    return array_values(array_filter($result, function($f) {
        return (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE || clean_text($f['name'] ?? '') !== '';
    }));
}

function empty_import_totals() {
    return [
        'sheets' => 0,
        'sales' => 0,
        'stock_deducted' => 0,
        'phone_deducted' => 0,
        'cost_unmatched' => 0,
        'stock_empty' => 0,
        'phone_stock_empty' => 0,
        'withdrawals' => 0,
        'deposits' => 0,
        'reconciliations' => 0,
        'deleted_sales' => 0,
        'deleted_drawer' => 0,
        'deleted_recharges' => 0,
        'recharge_credits' => 0,
        'recharge_credit_amount' => 0.0,
        'recharge_sales' => 0,
        'recharge_deducted' => 0.0,
        'recharge_skipped_balance' => 0,
        'tamara_imported_amount' => 0.0,
        'tamara_sheet_total' => 0.0,
        'tamara_total_mismatch_sheets' => 0,
        'tamara_total_difference' => 0.0,
        'deleted_recharge_credits' => 0,
        'deleted_reconciliations' => 0,
    ];
}

function add_import_totals(array &$totals, array $result) {
    foreach ($totals as $key => $value) {
        $floatKeys = ['recharge_credit_amount','recharge_deducted','tamara_imported_amount','tamara_sheet_total','tamara_total_difference'];
        $totals[$key] += in_array($key, $floatKeys, true) ? (float)($result[$key] ?? 0) : (int)($result[$key] ?? 0);
    }
}

try {
    ensure_legacy_import_columns($pdo);
} catch (Exception $e) {
    // Do not stop page view if ALTER permissions are missing; import will show the exact error.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_legacy_excel'])) {
    $downloaded_temp_file = null;
    try {
        $google_url = clean_text($_POST['legacy_google_sheet_url'] ?? '');
        $fallback_start_date = $_POST['legacy_start_date'] ?? '2026-05-28';
        $imported_start_dates = [];
        $file_summaries = [];
        $totals = empty_import_totals();
        $all_skipped = [];
        $daily_sheet_synced_dates = [];
        $daily_sheet_synced_rows = 0;

        if ($google_url !== '') {
            $downloaded_temp_file = download_xlsx_from_url($google_url);
            $result = import_legacy_excel($pdo, $downloaded_temp_file, $fallback_start_date);
            foreach (($result['dates'] ?? []) as $syncDate) {
                $daily_sheet_synced_rows += sync_imported_date_to_daily_cash_sheet($pdo, $syncDate);
                $daily_sheet_synced_dates[$syncDate] = true;
            }
            add_import_totals($totals, $result);
            $imported_start_dates[] = $fallback_start_date;
            $file_summaries[] = [
                'name' => 'Google Sheets link',
                'start_date' => $fallback_start_date,
                'result' => $result,
            ];
            if (!empty($result['skipped'])) {
                foreach ($result['skipped'] as $skip) $all_skipped[] = 'Google Sheets link: ' . $skip;
            }
        } else {
            $upload_files = uploaded_excel_files_list('legacy_excel');
            if (empty($upload_files)) {
                throw new Exception('Please choose one or more .xlsx files, or paste a Google Sheets link to import.');
            }

            foreach ($upload_files as $upload_file) {
                if ((int)$upload_file['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Upload failed for ' . ($upload_file['name'] ?: 'one file') . ' (error code ' . (int)$upload_file['error'] . ').');
                }

                $file_name = $upload_file['name'] ?? '';
                $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                if ($ext !== 'xlsx') {
                    throw new Exception('Only .xlsx Excel files are supported: ' . htmlspecialchars($file_name));
                }

                // If file name is 05-2026.xlsx / 06-2026.xlsx, use 2026-05-01 / 2026-06-01 automatically.
                // If the name does not contain a month, use the fallback date field.
                $file_start_date = infer_legacy_start_date_from_upload_name($file_name, $fallback_start_date);
                $result = import_legacy_excel($pdo, $upload_file['tmp_name'], $file_start_date);
                foreach (($result['dates'] ?? []) as $syncDate) {
                    $daily_sheet_synced_rows += sync_imported_date_to_daily_cash_sheet($pdo, $syncDate);
                    $daily_sheet_synced_dates[$syncDate] = true;
                }
                add_import_totals($totals, $result);
                $imported_start_dates[] = $file_start_date;
                $file_summaries[] = [
                    'name' => $file_name,
                    'start_date' => $file_start_date,
                    'result' => $result,
                ];
                if (!empty($result['skipped'])) {
                    foreach ($result['skipped'] as $skip) $all_skipped[] = $file_name . ': ' . $skip;
                }
            }
        }

        $import_message = "<div class='alert alert-success'><strong>Daily transactions imported successfully.</strong><br>" .
            "Files: <strong>" . count($file_summaries) . "</strong> | Sheets: <strong>" . (int)$totals['sheets'] . "</strong> | Sales: <strong>" . (int)$totals['sales'] . "</strong> | Product stock deducted: <strong>" . (int)$totals['stock_deducted'] . "</strong> | Phone stock sold: <strong>" . (int)$totals['phone_deducted'] . "</strong> | Cost unmatched: <strong>" . (int)$totals['cost_unmatched'] . "</strong> | Stock empty: <strong>" . (int)$totals['stock_empty'] . "</strong> | Phone stock empty: <strong>" . (int)$totals['phone_stock_empty'] . "</strong> | Withdrawals: <strong>" . (int)$totals['withdrawals'] . "</strong> | Deposits: <strong>" . (int)$totals['deposits'] . "</strong> | Tamara/Tabby: <strong>" . format_money_2($totals['tamara_imported_amount']) . " AED</strong> | Recharge credits: <strong>" . (int)$totals['recharge_credits'] . "</strong> (<strong>" . format_money_2($totals['recharge_credit_amount']) . " AED</strong>) | رصيد sales synced: <strong>" . (int)$totals['recharge_sales'] . "</strong> (deducted <strong>" . format_money_2($totals['recharge_deducted']) . " AED</strong>) | Reconciliations: <strong>" . (int)$totals['reconciliations'] . "</strong><br>" .
            "Old records deleted first — Sales: <strong>" . (int)$totals['deleted_sales'] . "</strong> | Withdraw/Deposit: <strong>" . (int)$totals['deleted_drawer'] . "</strong> | Recharges: <strong>" . (int)$totals['deleted_recharges'] . "</strong> | Recharge credits reversed: <strong>" . (int)$totals['deleted_recharge_credits'] . "</strong> | Reconciliations: <strong>" . (int)$totals['deleted_reconciliations'] . "</strong><br>" .
            "Daily Cash Sheet auto-updated — Dates: <strong>" . count($daily_sheet_synced_dates) . "</strong> | Rows: <strong>" . (int)$daily_sheet_synced_rows . "</strong>";

        if (count($file_summaries) > 1) {
            $import_message .= "<hr class='my-2'><div class='small'><strong>Imported files:</strong><ul class='mb-0'>";
            foreach ($file_summaries as $summary) {
                $r = $summary['result'];
                $import_message .= "<li>" . htmlspecialchars($summary['name']) . " → start date <strong>" . htmlspecialchars($summary['start_date']) . "</strong> | Sheets: " . (int)($r['sheets'] ?? 0) . " | Sales: " . (int)($r['sales'] ?? 0) . " | Withdrawals: " . (int)($r['withdrawals'] ?? 0) . " | Deposits: " . (int)($r['deposits'] ?? 0) . "</li>";
            }
            $import_message .= "</ul></div>";
        }

        if ((float)$totals['tamara_imported_amount'] > 0) {
            $import_message .= "<br><span class='text-info'>Drawer cash uses Column B Cash only. Tamara/Tabby is excluded from drawer reconciliation.</span>";
        }

        if ((int)$totals['tamara_total_mismatch_sheets'] > 0) {
            $import_message .= "<br><span class='text-danger fw-bold'>Tamara/Tabby total warning:</span> " .
                "<span class='text-danger'>" . (int)$totals['tamara_total_mismatch_sheets'] . " sheet(s) did not match C121. " .
                "Imported rows total: " . format_money_2($totals['tamara_imported_amount']) . " AED | " .
                "Excel C121 total: " . format_money_2($totals['tamara_sheet_total']) . " AED | " .
                "Difference: " . format_money_2($totals['tamara_total_difference']) . " AED.</span>";
        } elseif ((float)$totals['tamara_sheet_total'] > 0 || (float)$totals['tamara_imported_amount'] > 0) {
            $import_message .= "<br><span class='text-success'>Tamara/Tabby C121 verification matched.</span>";
        }

        if (!empty($all_skipped)) {
            $import_message .= "<br><span class='text-muted'>Skipped sheets without date name: " . htmlspecialchars(implode(', ', $all_skipped)) . "</span>";
        }
        $import_message .= "</div>";

        // After import, show the first imported start date by default.
        $selected_date = $imported_start_dates[0] ?? $fallback_start_date;
    } catch (Exception $e) {
        $import_message = "<div class='alert alert-danger'><strong>Import failed:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    } finally {
        if ($downloaded_temp_file && file_exists($downloaded_temp_file)) {
            @unlink($downloaded_temp_file);
        }
    }
}


// Delete one item from the daily activity log.
if (isset($_GET['action']) && $_GET['action'] === 'delete_item' && isset($_GET['type'], $_GET['id'])) {
    try {
        $deleted = delete_activity_item($pdo, $_GET['type'], $_GET['id']);
        $page_message = $deleted
            ? "<div class='alert alert-success'>Item deleted successfully.</div>"
            : "<div class='alert alert-warning'>Item was not found or was already deleted.</div>";
    } catch (Exception $e) {
        $page_message = "<div class='alert alert-danger'>Delete failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Delete selected items from the daily activity log.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_activity'])) {
    $selected_items = $_POST['selected_activity'] ?? [];
    $deleted_count = 0;

    if (!is_array($selected_items) || empty($selected_items)) {
        $page_message = "<div class='alert alert-warning'>Please select at least one item to delete.</div>";
    } else {
        try {
            $pdo->beginTransaction();
            foreach ($selected_items as $key) {
                $parts = explode(':', (string)$key, 2);
                if (count($parts) !== 2) continue;
                if (delete_activity_item($pdo, $parts[0], (int)$parts[1])) {
                    $deleted_count++;
                }
            }
            $pdo->commit();
            $page_message = "<div class='alert alert-success'>Deleted <strong>" . (int)$deleted_count . "</strong> selected item(s).</div>";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $page_message = "<div class='alert alert-danger'>Bulk delete failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Update a sale, withdrawal, or deposit row.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_activity_item'])) {
    $activity_type = normalize_activity_type($_POST['activity_type'] ?? '');
    $activity_id = (int)($_POST['activity_id'] ?? 0);
    $edit_date = $_POST['date'] ?? $selected_date;
    $edit_time = clean_text($_POST['created_time'] ?? '12:00');
    if (!preg_match('/^\d{2}:\d{2}$/', $edit_time)) $edit_time = '12:00';
    $created_at = $edit_date . ' ' . $edit_time . ':00';

    try {
        if ($activity_id <= 0 || $activity_type === '') {
            throw new Exception('Invalid item selected for update.');
        }

        if ($activity_type === 'SALE') {
            $ref = clean_text($_POST['ref'] ?? '');
            $item_name = clean_text($_POST['item_name'] ?? '');
            $qty = max(1, (int)normalize_number($_POST['qty_sold'] ?? 1));
            $unit_price = normalize_number($_POST['sale_price'] ?? 0);
            $cost_per_item = normalize_number($_POST['cost_per_item'] ?? 0);
            $payment_method = strtoupper(clean_text($_POST['payment_method'] ?? 'CASH'));
            if (!in_array($payment_method, ['CASH', 'VISA', 'TAMARA / TABBY', 'BANK_TRANSFER'], true)) $payment_method = 'CASH';
            if ($ref === '') $ref = 'MANUAL_EDIT';
            if ($item_name === '') $item_name = 'Edited Sale';
            if ($unit_price < 0 || $cost_per_item < 0) throw new Exception('Amount and cost cannot be negative.');

            $total_amount = $unit_price * $qty;

            $old_stmt = $pdo->prepare("SELECT product_id, phone_id, qty_sold, import_source, stock_deducted, phone_stock_deducted FROM sales_records WHERE id = ? LIMIT 1");
            $old_stmt->execute([$activity_id]);
            $old_sale = $old_stmt->fetch(PDO::FETCH_ASSOC);

            $clean_ref_for_stock = preg_replace('/\s+/', '', $ref);
            $new_product_id = null;
            $new_phone_id = null;

            // Daily Sales edit must check PHONES first. Before this fix, editing a sold phone barcode only checked products,
            // so the phone stayed Available even though the sale row was updated.
            if (preg_match('/^\d{6,}$/', $clean_ref_for_stock)) {
                if (db_table_exists($pdo, 'phones')) {
                    $phone_stmt = $pdo->prepare("
                        SELECT *
                        FROM phones
                        WHERE barcode = ?
                           OR REPLACE(REPLACE(REPLACE(REPLACE(TRIM(barcode), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), '') = ?
                        ORDER BY
                            CASE WHEN quantity >= ? THEN 0 ELSE 1 END,
                            id ASC
                        LIMIT 1
                    ");
                    $phone_stmt->execute([$clean_ref_for_stock, $clean_ref_for_stock, $qty]);
                    $found_phone = $phone_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($found_phone) {
                        $new_phone_id = (int)$found_phone['id'];
                        $ref = $found_phone['barcode'];
                    }
                }

                // Only use products if no phone with this barcode exists.
                if (empty($new_phone_id)) {
                    $prod_stmt = $pdo->prepare("SELECT id FROM products WHERE barcode = ? LIMIT 1");
                    $prod_stmt->execute([$clean_ref_for_stock]);
                    $found_product_id = $prod_stmt->fetchColumn();
                    if ($found_product_id) {
                        $new_product_id = (int)$found_product_id;
                        $ref = $clean_ref_for_stock;
                    }
                }
            }

            // Restore old deducted stock first if this sale previously deducted something else.
            if ($old_sale && !empty($old_sale['product_id'])) {
                $old_was_deducted = ((int)($old_sale['stock_deducted'] ?? 0) === 1) || (($old_sale['import_source'] ?? '') !== 'legacy_excel');
                if ($old_was_deducted) {
                    $restore = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                    $restore->execute([(int)$old_sale['qty_sold'], (int)$old_sale['product_id']]);
                }
            }

            if ($old_sale && !empty($old_sale['phone_id']) && (int)($old_sale['phone_stock_deducted'] ?? 0) === 1 && db_table_exists($pdo, 'phones')) {
                $restore_phone = $pdo->prepare("UPDATE phones SET quantity = quantity + ? WHERE id = ?");
                $restore_phone->execute([(int)$old_sale['qty_sold'], (int)$old_sale['phone_id']]);
                $mark_available = $pdo->prepare("UPDATE phones SET status = 'Available' WHERE id = ? AND quantity > 0 AND LOWER(status) = 'sold'");
                $mark_available->execute([(int)$old_sale['phone_id']]);
            }

            $new_stock_deducted = 0;
            $new_phone_stock_deducted = 0;

            if (!empty($new_phone_id) && db_table_exists($pdo, 'phones')) {
                $deduct_phone = $pdo->prepare("UPDATE phones SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
                $deduct_phone->execute([$qty, $new_phone_id, $qty]);
                $new_phone_stock_deducted = ($deduct_phone->rowCount() > 0) ? 1 : 0;
                if ($new_phone_stock_deducted) {
                    $mark_sold = $pdo->prepare("UPDATE phones SET status = 'Sold' WHERE id = ? AND quantity <= 0");
                    $mark_sold->execute([$new_phone_id]);
                }
                // A row cannot be a product sale and a phone sale at the same time.
                $new_product_id = null;
            } elseif (!empty($new_product_id)) {
                $deduct = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
                $deduct->execute([$qty, $new_product_id]);
                $new_stock_deducted = 1;
            }

            $stmt = $pdo->prepare("
                UPDATE sales_records
                SET product_id = ?, phone_id = ?, barcode = ?, item_name = ?, qty_sold = ?, sale_price = ?, total_amount = ?, payment_method = ?, sold_at = ?, imported_cost = ?, imported_profit = NULL, stock_deducted = ?, phone_stock_deducted = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_product_id, $new_phone_id, $ref, $item_name, $qty, $unit_price, $total_amount, $payment_method, $created_at, $cost_per_item, $new_stock_deducted, $new_phone_stock_deducted, $activity_id]);
            $page_message = "<div class='alert alert-success'>Sale item updated successfully. Phone/product stock was updated based on barcode.</div>";
        } else {
            $movement_kind = strtoupper(clean_text($_POST['movement_kind'] ?? $activity_type));
            if (!in_array($movement_kind, ['WITHDRAWAL', 'DEPOSIT'], true)) $movement_kind = 'WITHDRAWAL';
            $description = clean_text($_POST['drawer_description'] ?? '');
            $amount = abs(normalize_number($_POST['drawer_amount'] ?? 0));
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
            if ($description === '') $description = ($movement_kind === 'DEPOSIT') ? 'Deposit' : 'Withdrawal';

            $stored_amount = ($movement_kind === 'DEPOSIT') ? -$amount : $amount;
            $stored_name = ($movement_kind === 'DEPOSIT') ? 'DEPOSIT: ' . strip_deposit_prefix($description) : $description;
            $stmt = $pdo->prepare("UPDATE drawer_withdrawals SET name = ?, amount = ?, withdrawn_at = ? WHERE id = ?");
            $stmt->execute([$stored_name, $stored_amount, $created_at, $activity_id]);
            $page_message = "<div class='alert alert-success'>Drawer item updated successfully.</div>";
        }
    } catch (Exception $e) {
        $page_message = "<div class='alert alert-danger'>Update failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

$edit_activity = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit_item' && isset($_GET['type'], $_GET['id'])) {
    try {
        $edit_activity = fetch_edit_activity($pdo, $_GET['type'], $_GET['id']);
        if (!$edit_activity) {
            $page_message = "<div class='alert alert-warning'>Item not found for editing.</div>";
        }
    } catch (Exception $e) {
        $page_message = "<div class='alert alert-danger'>Could not load item for edit: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Pull sales + withdrawals + deposits using all dates, one date, or an inclusive date range.
$view_range_dates = ($range_start_date !== '' && $range_end_date !== '');
$view_all_dates = (!$view_range_dates && $selected_date === '');
$is_single_date_view = (!$view_all_dates && !$view_range_dates);

if ($view_range_dates) {
    $current_view_label = $range_start_date . ' to ' . $range_end_date;
    $date_filter_sales = 'WHERE sr.sold_at >= ? AND sr.sold_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $date_filter_drawer = 'WHERE dw.withdrawn_at >= ? AND dw.withdrawn_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $query_params = [$range_start_date, $range_end_date, $range_start_date, $range_end_date];
} elseif ($is_single_date_view) {
    $current_view_label = $selected_date;
    $date_filter_sales = 'WHERE DATE(sr.sold_at) = ?';
    $date_filter_drawer = 'WHERE DATE(dw.withdrawn_at) = ?';
    $query_params = [$selected_date, $selected_date];
} else {
    $current_view_label = 'All Dates';
    $date_filter_sales = '';
    $date_filter_drawer = '';
    $query_params = [];
}

$view_query_string = $view_range_dates
    ? 'start_date=' . urlencode($range_start_date) . '&end_date=' . urlencode($range_end_date)
    : ($is_single_date_view ? 'date=' . urlencode($selected_date) : 'all=1');

$stmt = $pdo->prepare("
    SELECT * FROM (
        SELECT
            'SALE' AS record_type,
            sr.id,
            sr.barcode AS ref,
            sr.item_name,
            sr.qty_sold,
            sr.sale_price,
            sr.total_amount,
            sr.payment_method,
            sr.sold_at AS created_at,
            COALESCE(
                (
                    SELECT p2.cost_price
                    FROM products p2
                    WHERE p2.barcode = sr.barcode
                      AND (
                        sr.imported_cost IS NULL
                        OR sr.imported_cost = ''
                        OR ABS(CAST(p2.cost_price AS DECIMAL(12,2)) - CAST(sr.imported_cost AS DECIMAL(12,2))) < 0.01
                      )
                    ORDER BY
                        CASE WHEN p2.quantity > 0 THEN 0 ELSE 1 END,
                        p2.id ASC
                    LIMIT 1
                ),
                MIN(p.cost_price)
            ) AS join_cost_price,
            sr.imported_cost,
            sr.imported_profit,
            sr.import_source
        FROM sales_records sr
        LEFT JOIN products p ON sr.barcode = p.barcode
        $date_filter_sales
        GROUP BY sr.id

        UNION ALL

        SELECT
            CASE WHEN dw.amount < 0 OR dw.name LIKE 'DEPOSIT:%' OR dw.name LIKE '[DEPOSIT]%' THEN 'DEPOSIT' ELSE 'WITHDRAWAL' END AS record_type,
            dw.id,
            CASE WHEN dw.amount < 0 OR dw.name LIKE 'DEPOSIT:%' OR dw.name LIKE '[DEPOSIT]%' THEN 'DEPOSIT' ELSE 'WITHDRAWAL' END AS ref,
            dw.name AS item_name,
            0 AS qty_sold,
            0 AS sale_price,
            CASE WHEN dw.amount < 0 OR dw.name LIKE 'DEPOSIT:%' OR dw.name LIKE '[DEPOSIT]%' THEN ABS(dw.amount) ELSE -ABS(dw.amount) END AS total_amount,
            'CASH' AS payment_method,
            dw.withdrawn_at AS created_at,
            0 AS join_cost_price,
            NULL AS imported_cost,
            NULL AS imported_profit,
            dw.import_source
        FROM drawer_withdrawals dw
        $date_filter_drawer
    ) AS daily_activity
    ORDER BY created_at DESC, id DESC
");
$stmt->execute($query_params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Opening balance and reconciliation info. Reconciliation is meaningful only for a single selected day.
$opening_balance = 0.0;
$actual_counted = null;
$reconciliation_difference = null;
$reconciliation_saved_expected = null;
$opening_source = $is_single_date_view ? 'No saved opening balance found' : 'Date range/all dates view: drawer opening is not applied';
if ($is_single_date_view) {
    try {
        $reconStmt = $pdo->prepare("SELECT * FROM drawer_reconciliations WHERE DATE(reconciled_at) = ? ORDER BY reconciled_at DESC LIMIT 1");
        $reconStmt->execute([$selected_date]);
        $recon = $reconStmt->fetch(PDO::FETCH_ASSOC);

        if ($recon) {
            $opening_balance = (float)$recon['opening_balance'];
            $actual_counted = ($recon['actual_amount'] !== null) ? (float)$recon['actual_amount'] : null;
            $reconciliation_difference = ($recon['difference'] !== null) ? (float)$recon['difference'] : null;
            $reconciliation_saved_expected = ($recon['expected_amount'] !== null) ? (float)$recon['expected_amount'] : null;
            $opening_source = 'From drawer reconciliation';
        } elseif ($selected_date === date('Y-m-d') && isset($_SESSION['cash_drawer_start'])) {
            $opening_balance = (float)$_SESSION['cash_drawer_start'];
            $opening_source = 'From today\'s open session';
        }
    } catch (Exception $e) {
        if ($selected_date === date('Y-m-d') && isset($_SESSION['cash_drawer_start'])) {
            $opening_balance = (float)$_SESSION['cash_drawer_start'];
            $opening_source = 'From today\'s open session';
        }
    }
}

// Totals
$grand_sales_total = 0.0;
$grand_profit_total = 0.0;
$grand_cost_total = 0.0;

$cash_count = 0;
$cash_total = 0.0;

$card_count = 0;
$card_gross_total = 0.0;
$card_fee_total = 0.0;
$card_net_total = 0.0;

$bank_count = 0;
$bank_total = 0.0;
$tamara_total = 0.0;
$tamara_count = 0;

$withdrawal_count = 0;
$withdrawal_total = 0.0;
$deposit_count = 0;
$deposit_total = 0.0;

foreach ($transactions as $t) {
    $record_type = $t['record_type'] ?? 'SALE';

    if ($record_type === 'WITHDRAWAL') {
        $withdrawal_count++;
        $withdrawal_total += abs((float)$t['total_amount']);
        continue;
    }

    if ($record_type === 'DEPOSIT') {
        $deposit_count++;
        $deposit_total += abs((float)$t['total_amount']);
        continue;
    }

    $qty = max(1, (int)$t['qty_sold']);
    $total_amount = (float)$t['total_amount'];
    $payment_method = strtoupper(trim((string)$t['payment_method']));
    if (in_array($payment_method, ['TAMARA','TABBY','TAMARA/TABBY'], true)) $payment_method = 'TAMARA / TABBY';

    $grand_sales_total += $total_amount;

    if ($payment_method === 'CASH') {
        if ($total_amount > 0) $cash_count++;
        $cash_total += $total_amount;
    } elseif ($payment_method === 'VISA') {
        if ($total_amount > 0) $card_count++;
        $card_gross_total += $total_amount;
        $card_fee_total += ($total_amount * $card_fee_rate);
        $card_net_total += ($total_amount * (1 - $card_fee_rate));
    } elseif ($payment_method === 'TAMARA / TABBY') {
        if ($total_amount > 0) {
            $tamara_count++;
            $card_count++;
        }
        $tamara_total += $total_amount;

        // Tamara/Tabby is a non-cash/card-style payment.
        // Add it to Card / Tamara-Tabby Gross/Net totals, but do NOT apply the Visa card fee.
        $card_gross_total += $total_amount;
        $card_net_total += $total_amount;
    } elseif ($payment_method === 'BANK_TRANSFER') {
        if ($total_amount > 0) $bank_count++;
        $bank_total += $total_amount;
    }

    $item_cost = ($t['imported_cost'] !== null && $t['imported_cost'] !== '')
        ? (float)$t['imported_cost']
        : decode_cost_price($t['join_cost_price'] ?? 0);

    $grand_cost_total += ($item_cost * $qty);

    if ($t['imported_profit'] !== null && $t['imported_profit'] !== '') {
        $grand_profit_total += (float)$t['imported_profit'];
    } else {
        $net_revenue_for_profit = ($payment_method === 'VISA') ? ($total_amount * (1 - $card_fee_rate)) : $total_amount;
        $grand_profit_total += ($net_revenue_for_profit - ($item_cost * $qty));
    }
}

$expected_drawer_left = $opening_balance + $cash_total + $deposit_total - $withdrawal_total;
$drawer_status = 'Not reconciled';
$drawer_status_class = 'secondary';
if ($actual_counted !== null) {
    $live_difference = $actual_counted - $expected_drawer_left;
    if (abs($live_difference) < 0.01) {
        $drawer_status = 'Balanced';
        $drawer_status_class = 'success';
    } elseif ($live_difference > 0) {
        $drawer_status = 'Overage';
        $drawer_status_class = 'info';
    } else {
        $drawer_status = 'Shortage';
        $drawer_status_class = 'danger';
    }
} else {
    $live_difference = null;
}

// Dashboard shell identity. Keep this page compatible with existing installations even before login is enforced globally.
$role = (($_SESSION['role'] ?? 'admin') === 'viewer') ? 'viewer' : 'admin';
$displayName = trim((string)($_SESSION['display_name'] ?? ''));
if ($displayName === '') $displayName = (string)($_SESSION['username'] ?? ucfirst($role));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transactions - POS Rukn Al Lotus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root{--sidebar:#0b1220;--sidebar2:#111a2c;--bg:#f3f6fb;--card:#fff;--text:#172033;--muted:#778199;--border:#e6ebf2;--blue:#3759e8;--green:#16a36a;--red:#dc3545;--orange:#f59e0b}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}.app{min-height:100vh}.sidebar{position:fixed;inset:0 auto 0 0;width:270px;background:linear-gradient(180deg,var(--sidebar),var(--sidebar2));color:#dce4f4;padding:22px 16px;overflow-y:auto;z-index:1040}.brand{display:flex;align-items:center;gap:12px;padding:0 8px 22px}.brand-mark{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;background:linear-gradient(135deg,#4f6df5,#7048e8);font-weight:900;color:#fff;box-shadow:0 8px 22px #3154e844}.brand-title{font-weight:850;color:#fff;font-size:.98rem}.brand-sub{font-size:.7rem;color:#8895ad}.nav-label{padding:17px 10px 7px;font-size:.65rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#66738b}.side-link{display:flex;align-items:center;gap:11px;color:#aeb9cc;text-decoration:none;padding:10px 11px;border-radius:10px;margin:2px 0;font-size:.82rem;font-weight:650;transition:.15s}.side-link i{font-size:1rem;width:19px}.side-link:hover{background:#ffffff0d;color:#fff}.side-link.active{background:linear-gradient(90deg,#3759e8,#546fee);color:#fff;box-shadow:0 8px 20px #2741b333}.role-badge{margin-left:auto;background:#ffffff18;color:#cbd5e1;border-radius:999px;padding:3px 7px;font-size:.58rem;font-weight:800}.main{margin-left:270px;width:calc(100% - 270px);padding:22px 26px 38px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px}.welcome h1{margin:0;font-size:1.25rem;font-weight:850}.welcome p{margin:4px 0 0;color:var(--muted);font-size:.8rem}.user-chip{display:flex;align-items:center;gap:9px;background:#fff;border:1px solid var(--border);padding:7px 10px;border-radius:12px;box-shadow:0 3px 12px #1720330a}.avatar{width:34px;height:34px;border-radius:10px;background:#edf1ff;color:var(--blue);display:grid;place-items:center;font-weight:900}.mobile-toggle{display:none}.transaction-hero{background:linear-gradient(135deg,#2947d6,#5574f5);border-radius:20px;padding:22px 24px;color:#fff;margin-bottom:18px;box-shadow:0 16px 35px #2947d62d;position:relative;overflow:hidden}.transaction-hero:after{content:"";position:absolute;width:220px;height:220px;border-radius:50%;background:#ffffff10;right:-55px;top:-85px}.hero-kicker{font-size:.68rem;font-weight:850;letter-spacing:.11em;text-transform:uppercase;opacity:.75}.transaction-hero h2{font-size:1.55rem;font-weight:900;margin:6px 0}.transaction-hero p{font-size:.84rem;opacity:.82;margin:0}.hero-pills{display:flex;flex-wrap:wrap;gap:9px;margin-top:16px}.hero-pill{background:#ffffff18;border:1px solid #ffffff25;border-radius:11px;padding:8px 11px;font-size:.72rem}.hero-pill strong{display:block;font-size:.92rem;color:#fff}.content-shell{max-width:none}.summary-card,.table-card{border:1px solid var(--border)!important;border-radius:16px!important;box-shadow:0 5px 18px rgba(23,32,51,.05)!important;background:#fff}.table-card{overflow:hidden}.form-control,.form-select{border-radius:10px;border-color:#dce2ec}.form-control:focus,.form-select:focus{border-color:#9caefc;box-shadow:0 0 0 .2rem rgba(55,89,232,.1)}.btn{border-radius:10px}.table-responsive{border-radius:12px}.table thead th{font-size:.68rem;text-transform:uppercase;letter-spacing:.045em;color:#69758c;white-space:nowrap}.table tbody td{font-size:.82rem;vertical-align:middle}.table-hover tbody tr:hover{background:#f8faff}.card h5{color:#1d2940}.alert{border-radius:12px}.filter-icon{width:36px;height:36px;border-radius:10px;background:#edf2ff;color:var(--blue);display:grid;place-items:center}.section-title-row{display:flex;align-items:center;gap:10px;margin-bottom:15px}.section-title-row h5{margin:0}.transaction-table-card{padding:18px!important}.transaction-table-card .table-responsive{max-height:70vh}.small-muted{font-size:.75rem;color:var(--muted)}
        @media(max-width:991px){.sidebar{transform:translateX(-100%);transition:.2s}.sidebar.open{transform:translateX(0)}.main{margin-left:0;width:100%;padding:14px}.mobile-toggle{display:inline-flex}.topbar{align-items:flex-start}.user-chip .user-text{display:none}.transaction-hero{padding:18px}}
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
            <div class="welcome"><h1>Transactions</h1><p><?php echo htmlspecialchars(date('l, d F Y')); ?> · Sales and cash activity</p></div>
        </div>
        <div class="user-chip"><div class="avatar"><?php echo htmlspecialchars(strtoupper(substr($displayName,0,1))); ?></div><div class="user-text"><div class="fw-bold small"><?php echo htmlspecialchars($displayName); ?></div><div class="text-muted" style="font-size:.7rem"><?php echo htmlspecialchars(ucfirst($role)); ?></div></div></div>
    </div>
    <section class="transaction-hero">
        <div class="hero-kicker">Rukn Al Lotus · Transaction Center</div>
        <h2><?php echo htmlspecialchars($current_view_label); ?></h2>
        <p>Review sales, payment methods, profit, withdrawals, deposits and drawer activity from one place.</p>
        <div class="hero-pills">
            <div class="hero-pill">Sales<strong><?php echo format_money_2($grand_sales_total); ?> AED</strong></div>
            <div class="hero-pill">Profit<strong><?php echo format_money_2($grand_profit_total); ?> AED</strong></div>
            <div class="hero-pill">Withdrawals<strong><?php echo format_money_2($withdrawal_total); ?> AED</strong></div>
            <div class="hero-pill">Deposits<strong><?php echo format_money_2($deposit_total); ?> AED</strong></div>
        </div>
    </section>
    <div class="content-shell">

    <?php echo $import_message; ?>
    <?php echo $page_message; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card table-card p-4 h-100">
                <h5 class="fw-bold mb-3">View Transactions</h5>
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-muted small">From Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($range_start_date); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-muted small">To Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($range_end_date); ?>" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100 fw-bold">Search Range</button>
                    </div>
                    <div class="col-md-2">
                        <a href="view_transactions.php?all=1" class="btn btn-dark w-100 fw-bold">All Sales</a>
                    </div>
                </form>
                <hr>
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label fw-bold text-muted small">Or View One Date</label>
                        <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-outline-primary w-100 fw-bold">View Date</button>
                    </div>
                </form>
                <div class="form-text mt-2">The date range includes both the From Date and To Date. Use <strong>All Sales</strong> to remove all date limits.</div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card table-card p-4 h-100 border-start border-primary border-4">
                <h5 class="fw-bold mb-2">Upload Daily Transactions Excel / Google Sheet <span class="badge bg-primary">v2.9 multi-file + sheet-date names</span></h5>
                <p class="text-muted small mb-3">
                    You can upload <strong>more than one Excel file</strong>. Name monthly files like <strong>05-2026.xlsx</strong> and <strong>06-2026.xlsx</strong>. Inside each Excel file, every sheet can be the date of that month: <strong>1, 2, 03, 15</strong> or full names like <strong>01-05-2026</strong>.
                </p>
                <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label fw-bold text-muted small">Excel files (.xlsx) - multiple allowed</label>
                        <input type="file" name="legacy_excel[]" class="form-control" accept=".xlsx" multiple>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label fw-bold text-muted small">Or Google Sheets link</label>
                        <input type="url" name="legacy_google_sheet_url" class="form-control" placeholder="Paste shared Google Sheet link here">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-muted small">Fallback start date</label>
                        <input type="date" name="legacy_start_date" class="form-control" value="2026-05-28">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" name="import_legacy_excel" class="btn btn-success w-100 fw-bold" onclick="return confirm('Import daily transactions from all selected files? File names like 05-2026 and 06-2026 will set the month, and each sheet name will be used as the day/date. This will DELETE old records for the imported dates first, restore stock/device balance, then rebuild from the files. Continue?');">Import</button>
                    </div>
                </form>
                <div class="small text-muted mt-3">
                    Upload one or more <strong>.xlsx</strong> files, or paste one Google Sheets link shared as <strong>Anyone with the link</strong>.<br>For monthly files, use file names like <strong>05-2026.xlsx</strong>, <strong>06-2026.xlsx</strong>. Inside the file, sheet names like <strong>1</strong>, <strong>02</strong>, <strong>15</strong> become days inside that month. Sheet names like <strong>01-05-2026</strong>, <strong>2026-05-01</strong>, or <strong>01-05</strong> are also supported. If a file name has no month, the fallback date is used.<br><strong>Replace mode:</strong> before each imported date is rebuilt, old normal sales, withdrawals/deposits, recharge rows, and drawer reconciliation for that date are deleted first.<br>
                    Layout: before 15-Aug-2026, A=Name, B=Cash, C=Visa, D=Cost, E=Profit, H/I=Withdraw, J/K=Deposit. From 15-Aug-2026 onward, A=Name, B=Cash, C=Tamara/Tabby, D=Visa, E=Cost, F=Profit, I/J=Withdraw, K/L=Deposit. Tamara/Tabby is kept separate and is not counted as Cash.
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="summary-card p-3 bg-success bg-opacity-10 border border-success h-100">
                <div class="text-success fw-bold">Grand Sales Total</div>
                <div class="fs-3 fw-bold text-dark"><?php echo format_money_2($grand_sales_total); ?> AED</div>
                <small class="text-muted">Cash + Card + Bank Transfer sales</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card p-3 bg-primary bg-opacity-10 border border-primary h-100">
                <div class="text-primary fw-bold">Total Profit</div>
                <div class="fs-3 fw-bold text-dark"><?php echo format_money_2($grand_profit_total); ?> AED</div>
                <small class="text-muted">Legacy import uses Excel profit; new sales calculate after 1.75% card fee</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card p-3 bg-info bg-opacity-10 border border-info h-100">
                <div class="text-info fw-bold">Total Cards</div>
                <div class="fs-3 fw-bold text-dark"><?php echo format_money($card_gross_total); ?> AED</div>
                <small class="text-muted">
                    <?php echo $card_count; ?> transactions<br>
                    Fee: <?php echo format_money($card_fee_total); ?> AED | Net: <?php echo format_money($card_net_total); ?> AED
                </small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card p-3 bg-dark bg-opacity-10 border border-dark h-100">
                <div class="text-dark fw-bold">Bank Transfer</div>
                <div class="fs-3 fw-bold text-dark"><?php echo format_money_2($bank_total); ?> AED</div>
                <small class="text-muted"><?php echo $bank_count; ?> transfer transactions<br>Not included in cash drawer</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card p-3 bg-warning bg-opacity-10 border border-warning h-100">
                <div class="text-warning fw-bold">Cash Left in Drawer</div>
                <div class="fs-3 fw-bold text-dark"><?php echo format_money($expected_drawer_left); ?> AED</div>
                <small class="text-muted">
                    Opening: <?php echo format_money($opening_balance); ?> + Cash: <?php echo format_money($cash_total); ?> + Deposit: <?php echo format_money($deposit_total); ?> - Withdraw: <?php echo format_money($withdrawal_total); ?><br>
                    <?php echo htmlspecialchars($opening_source); ?>
                </small>
            </div>
        </div>
    </div>

    <div class="card table-card p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="fw-bold mb-0">💰 Drawer Reconciliation</h5>
            <span class="badge bg-<?php echo $drawer_status_class; ?> px-3 py-2"><?php echo $drawer_status; ?></span>
        </div>
        <div class="row g-3">
            <div class="col-md-2"><div class="small text-muted">Opening</div><div class="fw-bold"><?php echo format_money($opening_balance); ?> AED</div></div>
            <div class="col-md-2"><div class="small text-muted">Cash Sales</div><div class="fw-bold text-success"><?php echo format_money($cash_total); ?> AED</div></div>
            <div class="col-md-2"><div class="small text-muted">Tamara / Tabby</div><div class="fw-bold" style="color:#f1506f"><?php echo format_money($tamara_total); ?> AED</div><div class="small text-muted">Counts as non-cash/card payment</div></div>
            <div class="col-md-2"><div class="small text-muted">Deposits</div><div class="fw-bold text-success"><?php echo format_money($deposit_total); ?> AED</div></div>
            <div class="col-md-2"><div class="small text-muted">Withdrawals</div><div class="fw-bold text-danger"><?php echo format_money($withdrawal_total); ?> AED</div></div>
            <div class="col-md-2"><div class="small text-muted">Expected</div><div class="fw-bold"><?php echo format_money($expected_drawer_left); ?> AED</div></div>
            <div class="col-md-2"><div class="small text-muted">Actual Drawer</div><div class="fw-bold"><?php echo ($actual_counted !== null) ? format_money($actual_counted) . ' AED' : '—'; ?></div></div>
            <div class="col-md-12">
                <div class="small text-muted">
                    Difference: <strong class="<?php echo ($live_difference !== null && $live_difference < 0) ? 'text-danger' : 'text-success'; ?>">
                        <?php echo ($live_difference !== null) ? format_money($live_difference) . ' AED' : '—'; ?>
                    </strong>
                    <?php if ($reconciliation_saved_expected !== null): ?>
                        | Saved expected from reconciliation: <?php echo format_money($reconciliation_saved_expected); ?> AED
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="summary-card p-3 bg-white h-100">
                <div class="text-success fw-bold">💵 Cash Sales</div>
                <div class="fs-5 fw-bold"><?php echo format_money($cash_total); ?> AED</div>
                <small class="text-muted"><?php echo $cash_count; ?> transactions</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card p-3 bg-white h-100">
                <div class="text-info fw-bold">💳 Card Net After Fee</div>
                <div class="fs-5 fw-bold"><?php echo format_money($card_net_total); ?> AED</div>
                <small class="text-muted">1.75% fee removed</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card p-3 bg-white h-100">
                <div class="text-danger fw-bold">💸 Withdrawals</div>
                <div class="fs-5 fw-bold">-<?php echo format_money($withdrawal_total); ?> AED</div>
                <small class="text-muted"><?php echo $withdrawal_count; ?> entries</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="summary-card p-3 bg-white h-100">
                <div class="text-success fw-bold">➕ Deposits</div>
                <div class="fs-5 fw-bold">+<?php echo format_money($deposit_total); ?> AED</div>
                <small class="text-muted"><?php echo $deposit_count; ?> entries</small>
            </div>
        </div>
    </div>

    <?php if ($edit_activity): ?>
        <?php
            $edit_item_date = $selected_date;
            if (($edit_activity['edit_type'] ?? '') === 'SALE' && !empty($edit_activity['sold_at'])) {
                $edit_item_date = date('Y-m-d', strtotime($edit_activity['sold_at']));
            } elseif (!empty($edit_activity['withdrawn_at'])) {
                $edit_item_date = date('Y-m-d', strtotime($edit_activity['withdrawn_at']));
            }
            if ($edit_item_date === '') $edit_item_date = date('Y-m-d');
            $return_query = $view_query_string;
            $return_url = 'view_transactions.php?' . $return_query;
        ?>
        <div class="card table-card p-4 mb-4 border-start border-warning border-4" id="editItemCard">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">✏️ Edit Activity Item</h5>
                <a href="<?php echo htmlspecialchars($return_url); ?>" class="btn btn-outline-secondary btn-sm fw-bold">Cancel Edit</a>
            </div>

            <?php if (($edit_activity['edit_type'] ?? '') === 'SALE'): ?>
                <form method="POST" class="row g-3 align-items-end">
                    <input type="hidden" name="update_activity_item" value="1">
                    <input type="hidden" name="activity_type" value="SALE">
                    <input type="hidden" name="activity_id" value="<?php echo (int)$edit_activity['id']; ?>">
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($edit_item_date ?? $selected_date); ?>">

                    <div class="col-md-2">
                        <label class="form-label fw-bold text-muted small">Ref / Barcode</label>
                        <input type="text" name="ref" class="form-control" value="<?php echo htmlspecialchars($edit_activity['barcode'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-muted small">Item / Description</label>
                        <input type="text" name="item_name" class="form-control" value="<?php echo htmlspecialchars(transaction_item_description($pdo, array_merge($edit_activity, ['record_type' => 'SALE', 'ref' => $edit_activity['barcode'] ?? '']))); ?>" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-bold text-muted small">Qty</label>
                        <input type="number" name="qty_sold" class="form-control" min="1" step="1" value="<?php echo (int)($edit_activity['qty_sold'] ?? 1); ?>" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-bold text-muted small">Cost</label>
                        <input type="number" name="cost_per_item" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars(format_money_2($edit_activity['cost_per_item'] ?? 0)); ?>" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-bold text-muted small">Unit</label>
                        <input type="number" name="sale_price" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars(format_money_2($edit_activity['sale_price'] ?? 0)); ?>" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-bold text-muted small">Payment</label>
                        <select name="payment_method" class="form-select">
                            <?php $edit_payment = strtoupper($edit_activity['payment_method'] ?? 'CASH'); ?>
                            <option value="CASH" <?php echo ($edit_payment === 'CASH') ? 'selected' : ''; ?>>CASH</option>
                            <option value="VISA" <?php echo ($edit_payment === 'VISA') ? 'selected' : ''; ?>>VISA</option>
                            <option value="TAMARA / TABBY" <?php echo (in_array($edit_payment, ['TAMARA / TABBY','TAMARA/TABBY','TAMARA','TABBY'], true)) ? 'selected' : ''; ?>>TAMARA / TABBY</option>
                            <option value="BANK_TRANSFER" <?php echo ($edit_payment === 'BANK_TRANSFER') ? 'selected' : ''; ?>>BANK TRANSFER</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-bold text-muted small">Time</label>
                        <input type="time" name="created_time" class="form-control" value="<?php echo htmlspecialchars(date('H:i', strtotime($edit_activity['sold_at'] ?? ($edit_item_date . ' 12:00:00')))); ?>" required>
                    </div>
                    <div class="col-md-1 d-grid">
                        <button type="submit" class="btn btn-warning fw-bold">Save</button>
                    </div>
                </form>
            <?php else: ?>
                <form method="POST" class="row g-3 align-items-end">
                    <input type="hidden" name="update_activity_item" value="1">
                    <input type="hidden" name="activity_type" value="<?php echo htmlspecialchars($edit_activity['edit_type'] ?? 'WITHDRAWAL'); ?>">
                    <input type="hidden" name="activity_id" value="<?php echo (int)$edit_activity['id']; ?>">
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($edit_item_date ?? $selected_date); ?>">

                    <div class="col-md-3">
                        <label class="form-label fw-bold text-muted small">Type</label>
                        <?php $drawer_edit_type = $edit_activity['edit_type'] ?? 'WITHDRAWAL'; ?>
                        <select name="movement_kind" class="form-select">
                            <option value="WITHDRAWAL" <?php echo ($drawer_edit_type === 'WITHDRAWAL') ? 'selected' : ''; ?>>Withdraw</option>
                            <option value="DEPOSIT" <?php echo ($drawer_edit_type === 'DEPOSIT') ? 'selected' : ''; ?>>Deposit</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold text-muted small">Amount</label>
                        <input type="number" name="drawer_amount" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars(format_money_2($edit_activity['clean_amount'] ?? 0)); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-muted small">Description</label>
                        <input type="text" name="drawer_description" class="form-control" value="<?php echo htmlspecialchars($edit_activity['clean_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-bold text-muted small">Time</label>
                        <input type="time" name="created_time" class="form-control" value="<?php echo htmlspecialchars(date('H:i', strtotime($edit_activity['withdrawn_at'] ?? ($edit_item_date . ' 12:00:00')))); ?>" required>
                    </div>
                    <div class="col-md-1 d-grid">
                        <button type="submit" class="btn btn-warning fw-bold">Save</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card table-card p-4 transaction-table-card">
        <form method="POST" id="activityBulkForm">
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
            <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($range_start_date); ?>">
            <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($range_end_date); ?>">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="fw-bold mb-0">Activity Log (<?php echo htmlspecialchars($current_view_label); ?>)</h5>
                <button type="submit" name="bulk_delete_activity" class="btn btn-outline-danger btn-sm fw-bold" onclick="return confirm('Delete all selected activity items?');">🗑️ Delete Selected</button>
            </div>
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted">
                    <tr>
                        <th class="text-center"><input type="checkbox" onclick="toggleActivitySelection(this)" title="Select all"></th>
                        <th>Type</th>
                        <th>Ref</th>
                        <th>Item/Description</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Cost / Item</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Profit</th>
                        <th class="text-center">Payment</th>
                        <th class="text-center">Date</th>
                        <th class="text-center">Time</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="13" class="text-center text-muted py-4">No activity found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                            <?php
                                $record_type = $t['record_type'] ?? 'SALE';
                                $is_withdrawal = ($record_type === 'WITHDRAWAL');
                                $is_deposit = ($record_type === 'DEPOSIT');

                                if ($is_withdrawal || $is_deposit) {
                                    $qty_display = '—';
                                    $cost_display = '—';
                                    $unit_price_display = '—';
                                    $amount_display = ($is_deposit ? '+' : '-') . format_money(abs((float)$t['total_amount'])) . ' AED';
                                    $amount_class = $is_deposit ? 'text-success' : 'text-danger';
                                    $profit_display = '—';
                                    $payment_display = $is_deposit ? 'DEPOSIT' : 'WITHDRAW';
                                    $badge_class = $is_deposit ? 'success' : 'danger';
                                    $type_badge = $is_deposit ? 'bg-success' : 'bg-danger';
                                    $type_label = $is_deposit ? 'DEPOSIT' : 'WITHDRAWAL';
                                    $row_class = $is_deposit ? 'table-success' : 'table-danger';
                                } else {
                                    $qty = max(1, (int)$t['qty_sold']);
                                    $total_amount = (float)$t['total_amount'];
                                    $payment_method = strtoupper(trim((string)$t['payment_method']));
                                    if (in_array($payment_method, ['TAMARA','TABBY','TAMARA/TABBY'], true)) $payment_method = 'TAMARA / TABBY';
                                    $item_cost = ($t['imported_cost'] !== null && $t['imported_cost'] !== '')
                                        ? (float)$t['imported_cost']
                                        : decode_cost_price($t['join_cost_price'] ?? 0);

                                    if ($t['imported_profit'] !== null && $t['imported_profit'] !== '') {
                                        $profit = (float)$t['imported_profit'];
                                    } else {
                                        $net_revenue = ($payment_method === 'VISA') ? ($total_amount * (1 - $card_fee_rate)) : $total_amount;
                                        $profit = $net_revenue - ($item_cost * $qty);
                                    }

                                    $qty_display = $t['qty_sold'];
                                    $cost_display = format_money($item_cost) . ' AED';
                                    $unit_price_display = format_money((float)$t['sale_price']) . ' AED';
                                    $amount_display = format_money($total_amount) . ' AED';
                                    $amount_class = 'text-success';
                                    $profit_display = format_money($profit) . ' AED';
                                    $payment_display = in_array($payment_method, ['CASH', 'VISA', 'TAMARA / TABBY', 'BANK_TRANSFER'], true) ? $payment_method : 'OTHER';
                                    $badge_class = ($payment_display === 'CASH') ? 'success' : (($payment_display === 'VISA') ? 'info' : (($payment_display === 'TAMARA / TABBY') ? 'danger' : (($payment_display === 'BANK_TRANSFER') ? 'dark' : 'secondary')));
                                    $type_badge = 'bg-success';
                                    $type_label = ($t['import_source'] === 'legacy_excel') ? 'LEGACY SALE' : 'SALE';
                                    $row_class = '';
                                }

                                $activity_type_for_action = $is_withdrawal ? 'WITHDRAWAL' : ($is_deposit ? 'DEPOSIT' : 'SALE');
                                $activity_key = $activity_type_for_action . ':' . (int)$t['id'];
                                $return_query = $view_query_string;
                                $edit_url = 'view_transactions.php?' . $return_query . '&action=edit_item&type=' . urlencode($activity_type_for_action) . '&id=' . (int)$t['id'] . '#editItemCard';
                                $delete_url = 'view_transactions.php?' . $return_query . '&action=delete_item&type=' . urlencode($activity_type_for_action) . '&id=' . (int)$t['id'];
                            ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td class="text-center"><input type="checkbox" class="activity-checkbox" name="selected_activity[]" value="<?php echo htmlspecialchars($activity_key); ?>"></td>
                                <td><span class="badge <?php echo $type_badge; ?>"><?php echo $type_label; ?></span></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars($t['ref']); ?></td>
                                <td><?php echo htmlspecialchars(transaction_item_description($pdo, $t)); ?></td>
                                <td class="text-center"><?php echo $qty_display; ?></td>
                                <td class="text-end"><?php echo $cost_display; ?></td>
                                <td class="text-end"><?php echo $unit_price_display; ?></td>
                                <td class="text-end fw-bold <?php echo $amount_class; ?>"><?php echo $amount_display; ?></td>
                                <td class="text-end fw-bold text-primary"><?php echo $profit_display; ?></td>
                                <td class="text-center"><span class="badge bg-<?php echo $badge_class; ?>"><?php echo ($payment_display === 'BANK_TRANSFER') ? 'BANK TRANSFER' : $payment_display; ?></span></td>
                                <td class="text-center text-muted small"><?php echo date('Y-m-d', strtotime($t['created_at'])); ?></td>
                                <td class="text-center text-muted small"><?php echo date('h:i A', strtotime($t['created_at'])); ?></td>
                                <td class="text-center">
                                    <a href="<?php echo htmlspecialchars($edit_url); ?>" class="btn btn-sm btn-outline-primary me-1">Edit</a>
                                    <a href="<?php echo htmlspecialchars($delete_url); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this item?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($transactions)): ?>
                    <tfoot class="table-light border-top border-2 border-dark">
                        <tr>
                            <td colspan="5" class="text-end fw-bold">Grand Totals:</td>
                            <td class="text-end fw-bold text-danger"><?php echo format_money($grand_cost_total); ?> AED</td>
                            <td></td>
                            <td class="text-end fw-bold text-success"><?php echo format_money_2($grand_sales_total); ?> AED</td>
                            <td class="text-end fw-bold text-primary"><?php echo format_money_2($grand_profit_total); ?> AED</td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
            </div>
        </form>
    </div>
    </div>
</main>
</div>
<script>
const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
if (sidebarToggle) sidebarToggle.addEventListener('click', function(){ sidebar.classList.toggle('open'); });
document.addEventListener('click', function(e){
    if (window.innerWidth <= 991 && sidebar && sidebar.classList.contains('open') && !sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) sidebar.classList.remove('open');
});
function toggleActivitySelection(source) {
    document.querySelectorAll('.activity-checkbox').forEach(function(box) {
        box.checked = source.checked;
    });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>