<?php
require_once __DIR__ . '/session_keepalive.php';
// DEFINITIVE_ORIGINAL_QTY_EDIT_PATCH_V1_5 - Original Qty is editable in edit form and list.
// tools.php - Mobile Tools / Spare Parts Inventory Management
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$message = "";

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_clean($value) {
    $value = (float)$value;
    if (abs($value - round($value)) < 0.00001) {
        return number_format($value, 0);
    }
    return number_format($value, 2);
}

function parse_aed_amount($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $value = str_replace([',', 'AED', 'aed', 'درهم'], '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);
    if ($value === '' || $value === '-' || !is_numeric($value)) return null;
    return (float)$value;
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


function tool_barcode_exists(PDO $pdo, $barcode) {
    $barcode = trim((string)$barcode);
    if ($barcode === '') return false;

    try {
        $stmt = $pdo->prepare("SELECT 1 FROM tool_items WHERE TRIM(barcode) = ? LIMIT 1");
        $stmt->execute([$barcode]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function next_available_tool_barcode(PDO $pdo, $barcode) {
    $candidate = trim((string)$barcode);
    if ($candidate === '' || !preg_match('/^\d+$/', $candidate)) return '';

    // Keep increasing until the barcode is unused anywhere in tool_items.
    // Safety limit prevents an accidental endless loop if the database is unusual.
    for ($i = 0; $i < 100000; $i++) {
        if (!tool_barcode_exists($pdo, $candidate)) {
            return $candidate;
        }
        $candidate = increment_numeric_barcode($candidate);
        if ($candidate === '') return '';
    }

    return '';
}

function compare_numeric_barcode_strings($a, $b) {
    $a = ltrim((string)$a, '0');
    $b = ltrim((string)$b, '0');
    if ($a === '') $a = '0';
    if ($b === '') $b = '0';
    if (strlen($a) !== strlen($b)) return strlen($a) <=> strlen($b);
    return strcmp($a, $b);
}

function last_barcode_info_for_terms($pdo, $terms) {
    $terms = array_values(array_filter(array_map('trim', (array)$terms)));
    if (!$terms) return ['last' => '', 'next' => '', 'brand' => '', 'model' => ''];

    $parts = [];
    $params = [];
    foreach ($terms as $term) {
        $parts[] = "brand LIKE ?";
        $params[] = '%' . $term . '%';
        $parts[] = "model_name LIKE ?";
        $params[] = '%' . $term . '%';
    }

    try {
        $sql = "SELECT barcode, brand, model_name FROM tool_items WHERE barcode <> '' AND (" . implode(' OR ', $parts) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Exception $e) {
        return ['last' => '', 'next' => '', 'brand' => '', 'model' => ''];
    }

    $best = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $barcode = trim((string)($row['barcode'] ?? ''));
        if ($barcode === '' || !preg_match('/^\d+$/', $barcode)) continue;
        if ($best === null || compare_numeric_barcode_strings($barcode, $best['last']) > 0) {
            $best = [
                'last' => $barcode,
                'next' => next_available_tool_barcode($pdo, increment_numeric_barcode($barcode)),
                'brand' => (string)($row['brand'] ?? ''),
                'model' => (string)($row['model_name'] ?? '')
            ];
        }
    }

    return $best ?: ['last' => '', 'next' => '', 'brand' => '', 'model' => ''];
}


function last_barcode_info_for_terms_with_start($pdo, $terms, $startBarcode) {
    $startBarcode = trim((string)$startBarcode);
    $terms = array_values(array_filter(array_map('trim', (array)$terms)));

    if ($startBarcode === '' || !preg_match('/^\d+$/', $startBarcode) || !$terms) {
        return ['last' => '', 'next' => $startBarcode, 'brand' => '', 'model' => ''];
    }

    // This group uses our own internal numeric sequence.
    // Ignore normal EAN/manufacturer barcodes (usually longer numbers such as 69xxxxxxxxxxx).
    // Only consider barcodes with exactly the same length as the configured starting barcode.
    $parts = [];
    $params = [];

    foreach ($terms as $term) {
        $parts[] = "brand LIKE ?";
        $params[] = '%' . $term . '%';
        $parts[] = "model_name LIKE ?";
        $params[] = '%' . $term . '%';
    }

    try {
        $sql = "SELECT barcode, brand, model_name
                FROM tool_items
                WHERE barcode <> ''
                  AND CHAR_LENGTH(TRIM(barcode)) = ?
                  AND (" . implode(' OR ', $parts) . ")";
        array_unshift($params, strlen($startBarcode));

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Exception $e) {
        return ['last' => '', 'next' => $startBarcode, 'brand' => '', 'model' => ''];
    }

    $best = null;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $barcode = trim((string)($row['barcode'] ?? ''));

        if ($barcode === '' || !preg_match('/^\d+$/', $barcode)) continue;

        // Ignore older/different custom sequences below the configured start.
        if (compare_numeric_barcode_strings($barcode, $startBarcode) < 0) continue;

        if ($best === null || compare_numeric_barcode_strings($barcode, $best['last']) > 0) {
            $best = [
                'last' => $barcode,
                'next' => next_available_tool_barcode($pdo, increment_numeric_barcode($barcode)),
                'brand' => (string)($row['brand'] ?? ''),
                'model' => (string)($row['model_name'] ?? '')
            ];
        }
    }

    return $best ?: [
        'last' => '',
        'next' => next_available_tool_barcode($pdo, $startBarcode),
        'brand' => '',
        'model' => ''
    ];
}

function column_index_from_ref($cellRef) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef));
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function read_csv_rows($path) {
    $rows = [];
    if (($handle = fopen($path, 'r')) !== false) {
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $rows[] = $data;
        }
        fclose($handle);
    }
    return $rows;
}

function read_xlsx_rows($path) {
    $rows = [];

    if (!class_exists('ZipArchive')) {
        throw new Exception('ZipArchive is not enabled on this server. Please upload CSV or enable PHP Zip extension.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception('Unable to open Excel file.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = simplexml_load_string($sharedXml);
        if ($xml) {
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $run) {
                        $text .= (string)$run->t;
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }

    if ($sheetXml === false) {
        $zip->close();
        throw new Exception('No worksheet found in Excel file.');
    }

    $xml = simplexml_load_string($sheetXml);
    if (!$xml) {
        $zip->close();
        throw new Exception('Unable to read worksheet XML.');
    }

    foreach ($xml->sheetData->row as $row) {
        $rowNum = intval($row['r']);
        $values = [];
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r'];
            $colIndex = column_index_from_ref($ref);
            $type = (string)$cell['t'];
            $value = '';

            if ($type === 's') {
                $idx = intval($cell->v);
                $value = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = isset($cell->is->t) ? (string)$cell->is->t : '';
            } else {
                $value = isset($cell->v) ? (string)$cell->v : '';
            }
            $values[$colIndex] = $value;
        }
        if (!empty($values)) {
            ksort($values);
            $maxCol = max(array_keys($values));
            $rowArr = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $rowArr[] = $values[$i] ?? '';
            }
            $rows[$rowNum - 1] = $rowArr;
        }
    }

    $zip->close();
    ksort($rows);
    return array_values($rows);
}

function google_sheet_to_export_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';

    if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=xlsx';
    }

    if (preg_match('#key=([a-zA-Z0-9_-]+)#', $url, $m)) {
        return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=xlsx';
    }

    return $url;
}

function download_remote_file($url) {
    $exportUrl = google_sheet_to_export_url($url);
    if ($exportUrl === '') {
        throw new Exception('Google Sheets link is empty.');
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'tools_sheet_') . '.xlsx';
    $data = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($exportUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 ToolsInventoryImporter'
        ]);
        $data = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($data === false || $http >= 400) {
            throw new Exception('Could not download Google Sheet. Make sure it is shared as Anyone with the link. ' . $err);
        }
    } else {
        $data = @file_get_contents($exportUrl);
        if ($data === false) {
            throw new Exception('Could not download Google Sheet. Enable cURL or allow_url_fopen.');
        }
    }

    file_put_contents($tmpPath, $data);
    return $tmpPath;
}

function table_has_column($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function create_tool_table_if_needed($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tool_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barcode VARCHAR(120) NOT NULL,
        cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        quantity INT NOT NULL DEFAULT 0,
        original_quantity INT NOT NULL DEFAULT 0,
        brand VARCHAR(120) NOT NULL DEFAULT '',
        model_name VARCHAR(160) NOT NULL DEFAULT '',
        item_type VARCHAR(100) NOT NULL DEFAULT '',
        quality VARCHAR(120) NOT NULL DEFAULT '',
        details TEXT NULL,
        tool_condition VARCHAR(80) NOT NULL DEFAULT '',
        status VARCHAR(80) NOT NULL DEFAULT 'Available',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tool_barcode (barcode),
        INDEX idx_tool_brand (brand),
        INDEX idx_tool_model (model_name),
        INDEX idx_tool_type (item_type),
        INDEX idx_tool_quality (quality),
        INDEX idx_tool_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'original_quantity' => "ALTER TABLE tool_items ADD COLUMN original_quantity INT NOT NULL DEFAULT 0 AFTER quantity",
        'brand' => "ALTER TABLE tool_items ADD COLUMN brand VARCHAR(120) NOT NULL DEFAULT '' AFTER original_quantity",
        'model_name' => "ALTER TABLE tool_items ADD COLUMN model_name VARCHAR(160) NOT NULL DEFAULT '' AFTER brand",
        'item_type' => "ALTER TABLE tool_items ADD COLUMN item_type VARCHAR(100) NOT NULL DEFAULT '' AFTER model_name",
        'quality' => "ALTER TABLE tool_items ADD COLUMN quality VARCHAR(120) NOT NULL DEFAULT '' AFTER item_type",
        'details' => "ALTER TABLE tool_items ADD COLUMN details TEXT NULL AFTER quality",
        'tool_condition' => "ALTER TABLE tool_items ADD COLUMN tool_condition VARCHAR(80) NOT NULL DEFAULT '' AFTER details",
        'status' => "ALTER TABLE tool_items ADD COLUMN status VARCHAR(80) NOT NULL DEFAULT 'Available' AFTER tool_condition"
    ];
    foreach ($columns as $col => $sql) {
        if (!table_has_column($pdo, 'tool_items', $col)) {
            $pdo->exec($sql);
        }
    }
    $pdo->exec("UPDATE tool_items SET original_quantity = quantity WHERE original_quantity = 0 AND quantity > 0");
}


function mobile_model_seed_data() {
    return [
        'Apple' => [
            ['model_name' => 'iPhone 17 Pro Max', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 17 Pro', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 17', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 17e', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone Air', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 16 Pro Max', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 16 Pro', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 16 Plus', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 16', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 16e', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 15 Pro Max', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 15 Pro', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 15 Plus', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 15', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 14 Pro Max', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 14 Pro', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 14 Plus', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 14', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 13 Pro Max', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 13 Pro', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 13', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 13 mini', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 12 Pro Max', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 12 Pro', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 12', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 12 mini', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 11 Pro Max', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 11 Pro', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 11', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone XS Max', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone XS', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone XR', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone X', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 8 Plus', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 8', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 7 Plus', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 7', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 6s Plus', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 6s', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 6 Plus', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 6', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone SE 3rd Gen', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone SE 2nd Gen', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone SE 1st Gen', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 5s', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 5c', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 5', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 4s', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 4', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 3GS', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 3G', 'series' => 'iPhone', 'device_type' => 'Phone'],
            ['model_name' => 'iPhone 1st Gen', 'series' => 'iPhone', 'device_type' => 'Phone']
        ],
        'Samsung' => [
            ['model_name' => 'Galaxy S26 Ultra', 'series' => 'S26', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S26+', 'series' => 'S26+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S26', 'series' => 'S26', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S25 Ultra', 'series' => 'S25', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S25+', 'series' => 'S25+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S25 Edge', 'series' => 'S25', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S25', 'series' => 'S25', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S25 FE', 'series' => 'S25', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S24 Ultra', 'series' => 'S24', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S24+', 'series' => 'S24+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S24', 'series' => 'S24', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S24 FE', 'series' => 'S24', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S23 Ultra', 'series' => 'S23', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S23+', 'series' => 'S23+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S23', 'series' => 'S23', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S23 FE', 'series' => 'S23', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S22 Ultra', 'series' => 'S22', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S22+', 'series' => 'S22+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S22', 'series' => 'S22', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S21 Ultra', 'series' => 'S21', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S21+', 'series' => 'S21+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S21', 'series' => 'S21', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S21 FE', 'series' => 'S21', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S20 Ultra', 'series' => 'S20', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S20+', 'series' => 'S20+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S20', 'series' => 'S20', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S20 FE', 'series' => 'S20', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S10 5G', 'series' => 'S10', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S10+', 'series' => 'S10+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S10', 'series' => 'S10', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S10e', 'series' => 'S10e', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S9+', 'series' => 'S9+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S9', 'series' => 'S9', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S8+', 'series' => 'S8+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S8', 'series' => 'S8', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S7 Edge', 'series' => 'S7', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S7', 'series' => 'S7', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S6 Edge+', 'series' => 'S6', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S6 Edge', 'series' => 'S6', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S6', 'series' => 'S6', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S5', 'series' => 'S5', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S4', 'series' => 'S4', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S3', 'series' => 'S3', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S II', 'series' => 'S', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy S', 'series' => 'S', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note20 Ultra', 'series' => 'Note20', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note20', 'series' => 'Note20', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note10+', 'series' => 'Note10+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note10', 'series' => 'Note10', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note10 Lite', 'series' => 'Note10', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note9', 'series' => 'Note9', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note8', 'series' => 'Note8', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note FE', 'series' => 'Note', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note7', 'series' => 'Note7', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note5', 'series' => 'Note5', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note4', 'series' => 'Note4', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note3', 'series' => 'Note3', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note II', 'series' => 'Note', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Note', 'series' => 'Note', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Fold7', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Flip7', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Fold6', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Flip6', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Fold5', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Flip5', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Fold4', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Flip4', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Fold3', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Flip3', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Fold2', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Flip 5G', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Z Flip', 'series' => 'Z', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Fold', 'series' => 'Fold', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A57 5G', 'series' => 'A57', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A56 5G', 'series' => 'A56', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A55 5G', 'series' => 'A55', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A54 5G', 'series' => 'A54', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A53 5G', 'series' => 'A53', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A52s 5G', 'series' => 'A52s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A52 5G', 'series' => 'A52', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A52', 'series' => 'A52', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A51', 'series' => 'A51', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A50s', 'series' => 'A50s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A50', 'series' => 'A50', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A37 5G', 'series' => 'A37', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A36 5G', 'series' => 'A36', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A35 5G', 'series' => 'A35', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A34 5G', 'series' => 'A34', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A33 5G', 'series' => 'A33', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A32 5G', 'series' => 'A32', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A32', 'series' => 'A32', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A31', 'series' => 'A31', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A30s', 'series' => 'A30s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A30', 'series' => 'A30', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A26 5G', 'series' => 'A26', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A25 5G', 'series' => 'A25', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A24', 'series' => 'A24', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A23 5G', 'series' => 'A23', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A23', 'series' => 'A23', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A22 5G', 'series' => 'A22', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A22', 'series' => 'A22', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A21s', 'series' => 'A21s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A20s', 'series' => 'A20s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A20', 'series' => 'A20', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A17 5G', 'series' => 'A17', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A16 5G', 'series' => 'A16', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A16', 'series' => 'A16', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A15 5G', 'series' => 'A15', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A15', 'series' => 'A15', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A14 5G', 'series' => 'A14', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A14', 'series' => 'A14', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A13 5G', 'series' => 'A13', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A13', 'series' => 'A13', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A12', 'series' => 'A12', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A11', 'series' => 'A11', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A10s', 'series' => 'A10s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A10', 'series' => 'A10', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A07', 'series' => 'A07', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A06 5G', 'series' => 'A06', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A06', 'series' => 'A06', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A05s', 'series' => 'A05s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A05', 'series' => 'A05', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A04s', 'series' => 'A04s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A04e', 'series' => 'A04e', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A04', 'series' => 'A04', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A03s', 'series' => 'A03s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A03', 'series' => 'A03', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A02s', 'series' => 'A02s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A02', 'series' => 'A02', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A01', 'series' => 'A01', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A90 5G', 'series' => 'A90', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A80', 'series' => 'A80', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A73 5G', 'series' => 'A73', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A72', 'series' => 'A72', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A71 5G', 'series' => 'A71', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A71', 'series' => 'A71', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A70', 'series' => 'A70', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A42 5G', 'series' => 'A42', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A41', 'series' => 'A41', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A40', 'series' => 'A40', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A9', 'series' => 'A9', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A8', 'series' => 'A8', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A7', 'series' => 'A7', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A6+', 'series' => 'A6+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A6', 'series' => 'A6', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A5', 'series' => 'A5', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy A3', 'series' => 'A3', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M56 5G', 'series' => 'M56', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M55 5G', 'series' => 'M55', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M54 5G', 'series' => 'M54', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M53 5G', 'series' => 'M53', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M52 5G', 'series' => 'M52', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M51', 'series' => 'M51', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M40', 'series' => 'M40', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M35 5G', 'series' => 'M35', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M34 5G', 'series' => 'M34', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M33 5G', 'series' => 'M33', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M32', 'series' => 'M32', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M31s', 'series' => 'M31s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M31', 'series' => 'M31', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M30s', 'series' => 'M30s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M30', 'series' => 'M30', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M23 5G', 'series' => 'M23', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M22', 'series' => 'M22', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M21', 'series' => 'M21', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M20', 'series' => 'M20', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M15 5G', 'series' => 'M15', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M14 5G', 'series' => 'M14', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M13', 'series' => 'M13', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M12', 'series' => 'M12', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M11', 'series' => 'M11', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M10s', 'series' => 'M10s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M10', 'series' => 'M10', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M02s', 'series' => 'M02s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M02', 'series' => 'M02', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy M01', 'series' => 'M01', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F55 5G', 'series' => 'F55', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F54 5G', 'series' => 'F54', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F52 5G', 'series' => 'F52', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F42 5G', 'series' => 'F42', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F41', 'series' => 'F41', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F34 5G', 'series' => 'F34', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F23 5G', 'series' => 'F23', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F22', 'series' => 'F22', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F15 5G', 'series' => 'F15', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F14 5G', 'series' => 'F14', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F13', 'series' => 'F13', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F12', 'series' => 'F12', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy F02s', 'series' => 'F02s', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J8', 'series' => 'J8', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J7 Pro', 'series' => 'J7', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J7 Prime', 'series' => 'J7', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J7', 'series' => 'J7', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J6+', 'series' => 'J6+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J6', 'series' => 'J6', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J5 Pro', 'series' => 'J5', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J5 Prime', 'series' => 'J5', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J5', 'series' => 'J5', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J4+', 'series' => 'J4+', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J4', 'series' => 'J4', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J3', 'series' => 'J3', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J2 Prime', 'series' => 'J2', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J2', 'series' => 'J2', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy J1', 'series' => 'J1', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Grand Prime', 'series' => 'Grand', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Grand 2', 'series' => 'Grand', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Core Prime', 'series' => 'Core', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Core 2', 'series' => 'Core', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Mega', 'series' => 'Mega', 'device_type' => 'Phone'],
            ['model_name' => 'Galaxy Alpha', 'series' => 'Alpha', 'device_type' => 'Phone']
        ]
    ];
}

function ensure_mobile_models_tables(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_brands (
        id INT AUTO_INCREMENT PRIMARY KEY,
        brand_name VARCHAR(120) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_mobile_brand_name (brand_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_models (
        id INT AUTO_INCREMENT PRIMARY KEY,
        brand_id INT NOT NULL,
        model_name VARCHAR(180) NOT NULL,
        series VARCHAR(120) NOT NULL DEFAULT '',
        device_type VARCHAR(60) NOT NULL DEFAULT 'Phone',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_mobile_brand_model (brand_id, model_name),
        INDEX idx_mobile_models_brand (brand_id),
        INDEX idx_mobile_models_name (model_name),
        CONSTRAINT fk_mobile_models_brand FOREIGN KEY (brand_id) REFERENCES mobile_brands(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    seed_mobile_models($pdo);
}

function mobile_brand_id(PDO $pdo, $brandName) {
    $brandName = trim((string)$brandName);
    if ($brandName === '') return 0;
    $stmt = $pdo->prepare("INSERT INTO mobile_brands (brand_name, is_active) VALUES (?, 1)
                           ON DUPLICATE KEY UPDATE brand_name = VALUES(brand_name), is_active = 1");
    $stmt->execute([$brandName]);
    $stmt = $pdo->prepare("SELECT id FROM mobile_brands WHERE brand_name = ? LIMIT 1");
    $stmt->execute([$brandName]);
    return (int)$stmt->fetchColumn();
}

function add_mobile_model_if_missing(PDO $pdo, $brandName, $modelName, $series = '', $deviceType = 'Phone') {
    $brandName = trim((string)$brandName);
    $modelName = trim((string)$modelName);
    if ($brandName === '' || $modelName === '') return;
    $brandId = mobile_brand_id($pdo, $brandName);
    if ($brandId <= 0) return;
    $stmt = $pdo->prepare("INSERT INTO mobile_models (brand_id, model_name, series, device_type, is_active)
                           VALUES (?, ?, ?, ?, 1)
                           ON DUPLICATE KEY UPDATE model_name = VALUES(model_name), series = IF(series = '', VALUES(series), series), device_type = IF(device_type = '', VALUES(device_type), device_type), is_active = 1");
    $stmt->execute([$brandId, $modelName, trim((string)$series), trim((string)$deviceType) ?: 'Phone']);
}

function seed_mobile_models(PDO $pdo) {
    foreach (mobile_model_seed_data() as $brandName => $models) {
        foreach ($models as $m) {
            add_mobile_model_if_missing($pdo, $brandName, $m['model_name'], $m['series'] ?? '', $m['device_type'] ?? 'Phone');
        }
    }
}

function get_mobile_model_brands(PDO $pdo) {
    return $pdo->query("SELECT brand_name FROM mobile_brands WHERE is_active = 1 ORDER BY brand_name ASC")->fetchAll(PDO::FETCH_COLUMN);
}

function get_mobile_model_rows(PDO $pdo) {
    return $pdo->query("SELECT m.id, b.brand_name, m.model_name, m.series, m.device_type
                        FROM mobile_models m
                        INNER JOIN mobile_brands b ON b.id = m.brand_id
                        WHERE m.is_active = 1 AND b.is_active = 1
                        ORDER BY b.brand_name ASC, m.series ASC, m.model_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function sync_tools_from_sales($pdo) {
    $items = $pdo->query("SELECT * FROM tool_items ORDER BY barcode ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) return ['matched' => 0, 'sold_units' => 0];

    $barcodes = array_values(array_unique(array_filter(array_map(function($x) { return $x['barcode']; }, $items))));
    if (!$barcodes) return ['matched' => 0, 'sold_units' => 0];

    $placeholders = implode(',', array_fill(0, count($barcodes), '?'));
    $stmt = $pdo->prepare("SELECT barcode, COALESCE(SUM(qty_sold),0) AS sold_qty FROM sales_records WHERE barcode IN ($placeholders) GROUP BY barcode");
    $stmt->execute($barcodes);
    $soldByBarcode = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $soldByBarcode[$row['barcode']] = (int)$row['sold_qty'];
    }

    $byBarcode = [];
    foreach ($items as $item) {
        $byBarcode[$item['barcode']][] = $item;
    }

    $matchedRows = 0;
    $soldUnits = 0;
    $pdo->beginTransaction();
    try {
        foreach ($byBarcode as $barcode => $group) {
            $remainingSold = $soldByBarcode[$barcode] ?? 0;
            foreach ($group as $item) {
                $original = (int)($item['original_quantity'] ?: $item['quantity']);
                $deduct = min($original, max(0, $remainingSold));
                $newQty = max(0, $original - $deduct);
                $remainingSold -= $deduct;
                $soldUnits += $deduct;
                $newStatus = $item['status'];
                if ($deduct > 0) {
                    $matchedRows++;
                    $newStatus = ($newQty <= 0) ? 'Sold' : 'Available';
                } elseif ($newQty > 0 && strtolower((string)$newStatus) === 'sold') {
                    $newStatus = 'Available';
                }
                $upd = $pdo->prepare("UPDATE tool_items SET original_quantity=?, quantity=?, status=? WHERE id=?");
                $upd->execute([$original, $newQty, $newStatus, (int)$item['id']]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['matched' => $matchedRows, 'sold_units' => $soldUnits];
}

try {
    create_tool_table_if_needed($pdo);
    ensure_mobile_models_tables($pdo);
} catch (Exception $e) {
    $message = "<div class='alert alert-danger'>Database setup error: " . h($e->getMessage()) . "</div>";
}

// Bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['selected_ids'] ?? []))));
    if (!$ids) {
        $message = "<div class='alert alert-warning'>No items selected.</div>";
    } else {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM tool_items WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $message = "<div class='alert alert-success'>Deleted <strong>" . count($ids) . "</strong> selected item(s).</div>";
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Bulk delete failed: " . h($e->getMessage()) . "</div>";
        }
    }
}

// Delete single
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
    try {
        $stmt = $pdo->prepare("DELETE FROM tool_items WHERE id = ?");
        $stmt->execute([intval($_GET['id'])]);
        header("Location: tools.php?deleted=1");
        exit;
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Delete failed: " . h($e->getMessage()) . "</div>";
    }
}
if (isset($_GET['deleted'])) $message = "<div class='alert alert-success'>Tool/spare part deleted.</div>";

// Sync button
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_tool_sales'])) {
    try {
        $res = sync_tools_from_sales($pdo);
        $message = "<div class='alert alert-success'>Tool sold status synced from daily sales. Matched rows: <strong>{$res['matched']}</strong>, sold units applied: <strong>{$res['sold_units']}</strong>.</div>";
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Sync failed: " . h($e->getMessage()) . "</div>";
    }
}


// Quick update Original Qty from list row
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_update_original_qty'])) {
    $id = intval($_POST['quick_update_original_qty']);
    $quickValues = $_POST['quick_original_qty'] ?? [];
    $newOriginal = isset($quickValues[$id]) ? intval($quickValues[$id]) : -1;

    try {
        $stmt = $pdo->prepare("SELECT quantity FROM tool_items WHERE id = ?");
        $stmt->execute([$id]);
        $currentQty = $stmt->fetchColumn();

        if ($currentQty === false) {
            throw new Exception('Tool item not found.');
        }

        $currentQty = intval($currentQty);
        if ($newOriginal < $currentQty) {
            $newOriginal = $currentQty;
        }

        $stmt = $pdo->prepare("UPDATE tool_items SET original_quantity = ? WHERE id = ?");
        $stmt->execute([$newOriginal, $id]);
        header("Location: tools.php?original_qty_updated=1");
        exit;
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Original Qty update failed: " . h($e->getMessage()) . "</div>";
    }
}
if (isset($_GET['original_qty_updated'])) $message = "<div class='alert alert-success'>Original Qty updated successfully.</div>";

// Load edit item
$editItem = null;
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
    $stmt = $pdo->prepare("SELECT * FROM tool_items WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editItem = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Manual add/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tool'])) {
    $id = intval($_POST['tool_id'] ?? 0);
    $barcode = trim($_POST['barcode'] ?? '');
    $costAmount = parse_aed_amount($_POST['cost_price'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);
    $originalQuantity = intval($_POST['original_quantity'] ?? $quantity);
    if ($originalQuantity < $quantity) $originalQuantity = $quantity;
    $brand = trim($_POST['brand'] ?? '');
    $modelName = trim($_POST['model_name'] ?? '');
    $itemType = trim($_POST['item_type'] ?? '');
    $quality = trim($_POST['quality'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $condition = trim($_POST['tool_condition'] ?? '');
    $status = trim($_POST['status'] ?? 'Available');

    if ($barcode === '' || $costAmount === null || $quantity < 0 || $brand === '' || $itemType === '') {
        $message = "<div class='alert alert-danger'>Please fill Barcode, Cost Price, Quantity, Brand, and Type correctly.</div>";
    } else {
        try {
            // Keep the model database updated when you type a new brand/model manually.
            if ($brand !== '' && $modelName !== '') {
                add_mobile_model_if_missing($pdo, $brand, $modelName, '', 'Phone');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE tool_items SET barcode=?, cost_price=?, quantity=?, original_quantity=?, brand=?, model_name=?, item_type=?, quality=?, details=?, tool_condition=?, status=? WHERE id=?");
                $stmt->execute([$barcode, $costAmount, $quantity, $originalQuantity, $brand, $modelName, $itemType, $quality, $details, $condition, $status, $id]);
                header("Location: tools.php?updated=1");
                exit;
            } else {
                $stmt = $pdo->prepare("INSERT INTO tool_items (barcode, cost_price, quantity, original_quantity, brand, model_name, item_type, quality, details, tool_condition, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$barcode, $costAmount, $quantity, $quantity, $brand, $modelName, $itemType, $quality, $details, $condition, $status]);
                header("Location: tools.php?added=1");
                exit;
            }
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Save failed: " . h($e->getMessage()) . "</div>";
        }
    }
}
if (isset($_GET['added'])) $message = "<div class='alert alert-success'>Tool/spare part added successfully.</div>";
if (isset($_GET['updated'])) $message = "<div class='alert alert-success'>Tool/spare part updated successfully.</div>";

// Upload Excel/CSV/Google Sheet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_tools'])) {
    $tmp = null;
    $name = '';
    $fromGoogle = false;
    try {
        $googleLink = trim($_POST['google_sheet_link'] ?? '');
        if ($googleLink !== '') {
            $tmp = download_remote_file($googleLink);
            $name = 'google_sheet.xlsx';
            $fromGoogle = true;
        } elseif (isset($_FILES['tool_file']) && $_FILES['tool_file']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['tool_file']['tmp_name'];
            $name = $_FILES['tool_file']['name'];
        } else {
            throw new Exception('Please choose a valid Excel/CSV file or paste a Google Sheets link.');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $replace = isset($_POST['replace_tools']);
        $syncAfter = isset($_POST['sync_after_upload']);
        $rows = ($ext === 'csv') ? read_csv_rows($tmp) : read_xlsx_rows($tmp);
        if (count($rows) < 2) {
            throw new Exception('The file does not contain rows.');
        }

        $inserted = 0; $updated = 0; $skipped = 0; $errors = 0;
        $pdo->beginTransaction();
        if ($replace) {
            $pdo->exec("DELETE FROM tool_items");
        }

        // Layout: ID | Barcode | Cost Price (AED) | Quantity | Brand | Model Name | Type | Quality | Details | Condition | Status
        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $excelId = trim((string)($row[0] ?? ''));
            $barcode = trim((string)($row[1] ?? ''));
            $costAmount = parse_aed_amount($row[2] ?? '');
            $quantity = intval($row[3] ?? 0);
            $brand = trim((string)($row[4] ?? ''));
            $modelName = trim((string)($row[5] ?? ''));
            $itemType = trim((string)($row[6] ?? ''));
            $quality = trim((string)($row[7] ?? ''));
            $details = trim((string)($row[8] ?? ''));
            $condition = trim((string)($row[9] ?? ''));
            $status = trim((string)($row[10] ?? '')) ?: 'Available';

            if ($barcode === '' && $brand === '' && $modelName === '' && $itemType === '' && $details === '') {
                continue;
            }
            if ($barcode === '' || $costAmount === null || $quantity < 0 || $brand === '' || $itemType === '') {
                $skipped++;
                continue;
            }

            try {
                if ($brand !== '' && $modelName !== '') {
                    add_mobile_model_if_missing($pdo, $brand, $modelName, '', 'Phone');
                }

                if ($excelId !== '' && ctype_digit($excelId)) {
                    $check = $pdo->prepare("SELECT id FROM tool_items WHERE id = ?");
                    $check->execute([intval($excelId)]);
                    if ($check->fetchColumn()) {
                        $stmt = $pdo->prepare("UPDATE tool_items SET barcode=?, cost_price=?, quantity=?, original_quantity=?, brand=?, model_name=?, item_type=?, quality=?, details=?, tool_condition=?, status=? WHERE id=?");
                        $stmt->execute([$barcode, $costAmount, $quantity, $quantity, $brand, $modelName, $itemType, $quality, $details, $condition, $status, intval($excelId)]);
                        $updated++;
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO tool_items (id, barcode, cost_price, quantity, original_quantity, brand, model_name, item_type, quality, details, tool_condition, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([intval($excelId), $barcode, $costAmount, $quantity, $quantity, $brand, $modelName, $itemType, $quality, $details, $condition, $status]);
                        $inserted++;
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO tool_items (barcode, cost_price, quantity, original_quantity, brand, model_name, item_type, quality, details, tool_condition, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$barcode, $costAmount, $quantity, $quantity, $brand, $modelName, $itemType, $quality, $details, $condition, $status]);
                    $inserted++;
                }
            } catch (Exception $e) {
                $errors++;
            }
        }
        $pdo->commit();

        $syncMsg = '';
        if ($syncAfter) {
            $res = sync_tools_from_sales($pdo);
            $syncMsg = " Synced sold units: <strong>{$res['sold_units']}</strong>.";
        }
        $message = "<div class='alert alert-success'>Tools upload complete. Inserted: <strong>{$inserted}</strong>, Updated: <strong>{$updated}</strong>, Skipped: <strong>{$skipped}</strong>, Errors: <strong>{$errors}</strong>.{$syncMsg}</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='alert alert-danger'>Upload failed: " . h($e->getMessage()) . "</div>";
    } finally {
        if ($fromGoogle && $tmp && file_exists($tmp)) @unlink($tmp);
    }
}

// Filters
$search = trim($_GET['search'] ?? '');
$brandFilter = trim($_GET['brand'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$qualityFilter = trim($_GET['quality'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$conditionFilter = trim($_GET['condition'] ?? '');
$duplicatesOnly = isset($_GET['duplicates']) && $_GET['duplicates'] === '1';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(barcode LIKE ? OR brand LIKE ? OR model_name LIKE ? OR item_type LIKE ? OR quality LIKE ? OR details LIKE ? OR tool_condition LIKE ? OR status LIKE ?)";
    for ($i = 0; $i < 8; $i++) $params[] = "%$search%";
}
if ($brandFilter !== '') { $where[] = "brand = ?"; $params[] = $brandFilter; }
if ($typeFilter !== '') { $where[] = "item_type = ?"; $params[] = $typeFilter; }
if ($qualityFilter !== '') { $where[] = "quality = ?"; $params[] = $qualityFilter; }
if ($statusFilter !== '') { $where[] = "status = ?"; $params[] = $statusFilter; }
if ($conditionFilter !== '') { $where[] = "tool_condition = ?"; $params[] = $conditionFilter; }
if ($duplicatesOnly) {
    $where[] = "barcode <> '' AND barcode IN (SELECT barcode FROM tool_items WHERE barcode <> '' GROUP BY barcode HAVING COUNT(*) > 1)";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM tool_items $whereSql ORDER BY id DESC");
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$brands = $pdo->query("SELECT DISTINCT brand FROM tool_items WHERE brand <> '' ORDER BY brand ASC")->fetchAll(PDO::FETCH_COLUMN);
$types = $pdo->query("SELECT DISTINCT item_type FROM tool_items WHERE item_type <> '' ORDER BY item_type ASC")->fetchAll(PDO::FETCH_COLUMN);
$qualities = $pdo->query("SELECT DISTINCT quality FROM tool_items WHERE quality <> '' ORDER BY quality ASC")->fetchAll(PDO::FETCH_COLUMN);
$conditions = $pdo->query("SELECT DISTINCT tool_condition FROM tool_items WHERE tool_condition <> '' ORDER BY tool_condition ASC")->fetchAll(PDO::FETCH_COLUMN);

$modelBrands = get_mobile_model_brands($pdo);
$mobileModelRows = get_mobile_model_rows($pdo);
$brands = array_values(array_unique(array_filter(array_merge($modelBrands, $brands))));
sort($brands, SORT_NATURAL | SORT_FLAG_CASE);
$mobileModelsForJs = array_map(function($row) {
    return [
        'brand_name' => (string)$row['brand_name'],
        'model_name' => (string)$row['model_name'],
        'series' => (string)($row['series'] ?? ''),
        'device_type' => (string)($row['device_type'] ?? '')
    ];
}, $mobileModelRows);

$stats = ['rows'=>0,'units'=>0,'original_units'=>0,'available'=>0,'sold'=>0,'cost_value'=>0];
$allStats = $pdo->query("SELECT * FROM tool_items")->fetchAll(PDO::FETCH_ASSOC);
foreach ($allStats as $it) {
    $stats['rows']++;
    $q = (int)$it['quantity'];
    $orig = (int)($it['original_quantity'] ?: $q);
    $sold = max(0, $orig - $q);
    $cost = (float)$it['cost_price'];
    $stats['units'] += $q;
    $stats['original_units'] += $orig;
    $stats['sold'] += $sold;
    if (strtolower($it['status']) === 'available') $stats['available'] += $q;
    $stats['cost_value'] += $q * $cost;
}

$lastAppleBarcode = last_barcode_info_for_terms($pdo, ['Apple', 'iPhone', 'iPad']);
$lastSamsungBarcode = last_barcode_info_for_terms($pdo, ['Samsung', 'Galaxy']);
$lastUniversalBarcode = last_barcode_info_for_terms_with_start(
    $pdo,
    ['Universal', 'Oppo', 'OPPO', 'Realme', 'realme', 'Redmi', 'Xiaomi'],
    '000000001502'
);

function sold_summary_for_barcodes($pdo, $barcodes) {
    $barcodes = array_values(array_unique(array_filter($barcodes)));
    if (!$barcodes) return [];
    $placeholders = implode(',', array_fill(0, count($barcodes), '?'));
    $stmt = $pdo->prepare("SELECT barcode, SUM(qty_sold) AS sold_qty, SUM(total_amount) AS total_sold, MAX(sold_at) AS last_sold_at FROM sales_records WHERE barcode IN ($placeholders) GROUP BY barcode");
    $stmt->execute($barcodes);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $qty = max(1, (int)$row['sold_qty']);
        $out[$row['barcode']] = [
            'sold_qty' => (int)$row['sold_qty'],
            'total_sold' => (float)$row['total_sold'],
            'avg_price' => (float)$row['total_sold'] / $qty,
            'last_sold_at' => $row['last_sold_at']
        ];
    }
    return $out;
}
$soldSummary = sold_summary_for_barcodes($pdo, array_map(function($x){ return $x['barcode']; }, $items));

// Export current filtered list
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="tools_inventory_' . date('Y-m-d_H-i') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "<html><head><meta charset='UTF-8'></head><body>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Barcode</th><th>Brand</th><th>Model Name</th><th>Type</th><th>Quality</th><th>Cost Price (AED)</th><th>Original Qty</th><th>Qty Available</th><th>Sold Units</th><th>Condition</th><th>Status</th><th>Sold Price</th><th>Last Sold</th><th>Details</th></tr>";
    foreach ($items as $it) {
        $orig = (int)($it['original_quantity'] ?: $it['quantity']);
        $soldUnits = max(0, $orig - (int)$it['quantity']);
        $sum = $soldSummary[$it['barcode']] ?? null;
        echo "<tr>";
        echo "<td>" . h($it['id']) . "</td>";
        echo "<td style='mso-number-format:\"\\@\";'>" . h($it['barcode']) . "</td>";
        echo "<td>" . h($it['brand']) . "</td>";
        echo "<td>" . h($it['model_name']) . "</td>";
        echo "<td>" . h($it['item_type']) . "</td>";
        echo "<td>" . h($it['quality']) . "</td>";
        echo "<td>" . h(money_clean($it['cost_price'])) . "</td>";
        echo "<td>" . h($orig) . "</td>";
        echo "<td>" . h($it['quantity']) . "</td>";
        echo "<td>" . h($soldUnits) . "</td>";
        echo "<td>" . h($it['tool_condition']) . "</td>";
        echo "<td>" . h($it['status']) . "</td>";
        echo "<td>" . h($sum ? money_clean($sum['avg_price']) : '') . "</td>";
        echo "<td>" . h($sum['last_sold_at'] ?? '') . "</td>";
        echo "<td>" . h($it['details']) . "</td>";
        echo "</tr>";
    }
    echo "</table></body></html>";
    exit;
}

$defaultTypes = ['Screen','Touch','Battery','Back Glass','Charging Port','Camera','Speaker','Earpiece','Microphone','Flex Cable','Housing','Board','Accessory','Other'];
$defaultQualities = ['LZY','DD','GX','OLED ( ORG )','SVC-SEVT','Original','Service Pack','OEM','OLED','Incell','AAA','High Copy','Copy','Refurbished','Used','Other'];
$statusList = ['Available','Sold','Reserved','Repair','Returned','Defective'];
$conditionList = ['New','Used','Refurbished','Damaged','For Parts'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tools / Spare Parts Inventory v1.5 ORIGINAL QTY EDIT - Rukn Al Lotus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
:root{--rl-sidebar:#101828;--rl-sidebar-soft:#1d2939;--rl-primary:#2563eb;--rl-bg:#f5f7fb;--rl-card:#fff;--rl-text:#101828;--rl-muted:#667085;--rl-border:#e4e7ec}
*{box-sizing:border-box}
body{margin:0;background:var(--rl-bg);color:var(--rl-text);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.rl-app{min-height:100vh;display:flex}
.rl-sidebar{width:258px;min-width:258px;background:var(--rl-sidebar);color:#fff;position:fixed;inset:0 auto 0 0;overflow-y:auto;z-index:1040;padding:20px 14px}
.rl-brand{display:flex;align-items:center;gap:12px;padding:4px 8px 20px;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:16px}
.rl-brand-mark{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#3b82f6,#7c3aed);display:grid;place-items:center;font-weight:800}
.rl-brand-title{font-weight:800;font-size:1rem}.rl-brand-sub{font-size:.72rem;color:#98a2b3}
.rl-nav-label{padding:13px 10px 6px;color:#667085;font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;font-weight:800}
.rl-side-link{display:flex;align-items:center;gap:11px;color:#d0d5dd;text-decoration:none;padding:10px 11px;border-radius:10px;margin:2px 0;font-size:.88rem;font-weight:600}
.rl-side-link:hover{background:var(--rl-sidebar-soft);color:#fff}.rl-side-link.active{background:#2563eb;color:#fff;box-shadow:0 8px 20px rgba(37,99,235,.22)}
.rl-side-link i{font-size:1rem;width:20px;text-align:center}.rl-role{margin-left:auto;font-size:.58rem;background:rgba(255,255,255,.12);padding:3px 6px;border-radius:6px}
.rl-main{margin-left:258px;width:calc(100% - 258px);min-height:100vh}
.rl-topbar{min-height:78px;background:#fff;border-bottom:1px solid var(--rl-border);padding:15px 28px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1030}
.rl-welcome h1{font-size:1.12rem;font-weight:800;margin:0}.rl-welcome p{margin:3px 0 0;color:var(--rl-muted);font-size:.78rem}
.rl-user-chip{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid var(--rl-border);border-radius:12px;padding:7px 10px}
.rl-avatar{width:34px;height:34px;border-radius:9px;background:#e8efff;color:#2457c5;display:grid;place-items:center;font-weight:800}
.rl-page-content{padding:0}.rl-mobile-toggle{display:none}.rl-overlay{display:none}
.card-box{border:1px solid var(--rl-border)!important;border-radius:16px!important;box-shadow:0 8px 24px rgba(16,24,40,.05)!important;background:#fff}
.small-label{font-size:.72rem;color:#667085;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.form-control,.form-select{border-color:#d0d5dd;border-radius:10px;min-height:42px}.form-control:focus,.form-select:focus{border-color:#84adff;box-shadow:0 0 0 .2rem rgba(37,99,235,.12)}
.btn{border-radius:9px}.table{margin-bottom:0}.table td,.table th{vertical-align:middle}.table thead th{font-size:.75rem;text-transform:uppercase;letter-spacing:.03em;color:#667085;background:#f9fafb;border-bottom-color:#eaecf0}.table tbody td{border-color:#f0f1f3}.table-hover tbody tr:hover{background:#f8fafc}
@media(max-width:991.98px){.rl-sidebar{transform:translateX(-100%);transition:.2s ease}.rl-sidebar.show{transform:translateX(0)}.rl-main{margin-left:0;width:100%}.rl-mobile-toggle{display:inline-flex}.rl-overlay{position:fixed;inset:0;background:rgba(16,24,40,.45);z-index:1035}.rl-overlay.show{display:block}.rl-topbar{padding:12px 16px}.rl-user-text{display:none}.container-fluid{padding-left:14px!important;padding-right:14px!important}}
</style>

<style>
.tool-top-card{position:relative;overflow:hidden;border:0!important;min-height:112px;color:#fff!important;box-shadow:0 10px 26px rgba(16,24,40,.12)!important;transition:.18s ease}
.tool-top-card:hover{transform:translateY(-3px);box-shadow:0 15px 32px rgba(16,24,40,.17)!important}
.tool-top-card:after{content:"";position:absolute;width:95px;height:95px;border-radius:50%;right:-25px;bottom:-38px;background:rgba(255,255,255,.14)}
.tool-top-card>*{position:relative;z-index:1}
.tool-top-card,.tool-top-card h3,.tool-top-card .fw-bold{color:#fff!important}
.tool-top-card .small-label,.tool-top-card small,.tool-top-card .small,.tool-top-card .text-muted{color:rgba(255,255,255,.84)!important}
.tool-card-blue{background:linear-gradient(135deg,#3154e8,#667eea)!important}
.tool-card-cyan{background:linear-gradient(135deg,#0e8fa8,#29b8d2)!important}
.tool-card-red{background:linear-gradient(135deg,#d94a62,#f06a7d)!important}
.tool-card-green{background:linear-gradient(135deg,#10986a,#2bbf8a)!important}
.tool-card-orange{background:linear-gradient(135deg,#d98b16,#f6b73c)!important}
.tool-card-purple{background:linear-gradient(135deg,#7652d9,#9b72ef)!important}
</style>

<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body>
<div class="rl-overlay" id="rlOverlay"></div>
<div class="rl-app">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="rl-main">
<div class="rl-topbar">
<div class="d-flex gap-2 align-items-start"><button class="btn btn-light border rl-mobile-toggle" id="rlSidebarToggle" type="button"><i class="bi bi-list"></i></button><div class="rl-welcome"><h1>Tools & Spare Parts</h1><p>Manage spare parts inventory, quality and stock · <?=h(date('l, d F Y'))?></p></div></div>
<div class="rl-user-chip"><div class="rl-avatar"><?=h(strtoupper(substr((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'A'),0,1)))?></div><div class="rl-user-text"><div class="fw-bold small"><?=h((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Admin'))?></div><div class="text-muted" style="font-size:.7rem"><?=h(ucfirst((string)($_SESSION['role'] ?? 'admin')))?></div></div></div>
</div>
<div class="rl-page-content">
<div class="container-fluid py-4 px-4">
    <?php echo $message; ?>

    <?php if ($editItem): ?>
        <div class="alert alert-info border-0 shadow-sm" id="edit-original-qty-notice">
            <strong>Editing tool ID #<?php echo h($editItem['id']); ?></strong> — Original Qty is editable in the form below AND directly in the list row.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-2"><div class="card card-box p-3 tool-top-card tool-card-blue"><div class="small-label">Rows</div><h3 class="fw-bold mb-0"><?php echo number_format($stats['rows']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box p-3 tool-top-card tool-card-cyan"><div class="small-label">Units in Stock</div><h3 class="fw-bold text-primary mb-0"><?php echo number_format($stats['units']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box p-3 tool-top-card tool-card-red"><div class="small-label">Inventory Cost</div><h3 class="fw-bold text-danger mb-0"><?php echo money_clean($stats['cost_value']); ?></h3><small>AED</small></div></div>
        <div class="col-md-2"><div class="card card-box p-3 tool-top-card tool-card-green"><div class="small-label">Available Units</div><h3 class="fw-bold text-success mb-0"><?php echo number_format($stats['available']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box p-3 tool-top-card tool-card-orange"><div class="small-label">Sold Units</div><h3 class="fw-bold text-secondary mb-0"><?php echo number_format($stats['sold']); ?></h3></div></div>
        <div class="col-md-2"><div class="card card-box p-3 tool-top-card tool-card-purple"><div class="small-label">Original Units</div><h3 class="fw-bold text-dark mb-0"><?php echo number_format($stats['original_units']); ?></h3></div></div>
    </div>

    <div class="row g-4 mb-4">
        

        <div class="col-12">
            <div class="card card-box p-4 h-100">
                <h5 class="fw-bold mb-3"><?php echo $editItem ? '✏️ Edit Tool / Spare Part' : '➕ Add Tool / Spare Part Manually'; ?></h5>
                <?php if ($editItem): ?>
                    <div class="alert alert-warning border border-3 border-warning fw-bold">
                        ✅ ORIGINAL QTY EDIT ACTIVE — change <span class="text-danger">Original Qty</span> below, then press Update Item.
                    </div>
                <?php endif; ?>
                <form method="POST" id="tool-edit-form">
                    <input type="hidden" name="save_tool" value="1">
                    <input type="hidden" name="tool_id" value="<?php echo h($editItem['id'] ?? ''); ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Barcode *</label>
                            <input type="text" name="barcode" class="form-control" value="<?php echo h($editItem['barcode'] ?? ''); ?>" required>
                            <div class="mt-2 p-2 rounded border bg-light small">
                                <div class="fw-bold text-muted mb-1">Last barcode in database</div>
                                <div class="mb-1">
                                    🍎 Apple / iPhone / iPad:<br>
                                    <strong><?php echo $lastAppleBarcode['last'] !== '' ? h($lastAppleBarcode['last']) : 'No barcode yet'; ?></strong>
                                    <?php if ($lastAppleBarcode['next'] !== ''): ?>
                                        <span class="text-muted">→ Next:</span> <strong class="text-success"><?php echo h($lastAppleBarcode['next']); ?></strong>
                                        <button type="button" class="btn btn-sm btn-outline-success py-0 ms-1" data-fill-barcode="<?php echo h($lastAppleBarcode['next']); ?>">Use</button>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-1">
                                    📱 Samsung:<br>
                                    <strong><?php echo $lastSamsungBarcode['last'] !== '' ? h($lastSamsungBarcode['last']) : 'No barcode yet'; ?></strong>
                                    <?php if ($lastSamsungBarcode['next'] !== ''): ?>
                                        <span class="text-muted">→ Next:</span> <strong class="text-success"><?php echo h($lastSamsungBarcode['next']); ?></strong>
                                        <button type="button" class="btn btn-sm btn-outline-success py-0 ms-1" data-fill-barcode="<?php echo h($lastSamsungBarcode['next']); ?>">Use</button>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    🌐 Universal / Oppo / Realme / Redmi:<br>
                                    <strong><?php echo $lastUniversalBarcode['last'] !== '' ? h($lastUniversalBarcode['last']) : 'Start from 000000001502'; ?></strong>
                                    <?php if ($lastUniversalBarcode['next'] !== ''): ?>
                                        <span class="text-muted">→ Next:</span> <strong class="text-success"><?php echo h($lastUniversalBarcode['next']); ?></strong>
                                        <button type="button" class="btn btn-sm btn-outline-success py-0 ms-1" data-fill-barcode="<?php echo h($lastUniversalBarcode['next']); ?>">Use</button>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted mt-1">Increase the next number to avoid duplicate barcode.</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Cost Price (AED) *</label>
                            <input type="number" step="1" min="0" name="cost_price" class="form-control" value="<?php echo h(isset($editItem['cost_price']) ? money_clean($editItem['cost_price']) : ''); ?>" placeholder="25" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Quantity Available *</label>
                            <input type="number" name="quantity" class="form-control" min="0" value="<?php echo h($editItem['quantity'] ?? '1'); ?>" required>
                            <div class="form-text">Current quantity left in shop.</div>
                        </div>
                        <div class="col-md-4" id="originalQtyEditBox">
                            <label class="form-label fw-bold text-danger">Original Qty / Total Received *</label>
                            <input type="number" name="original_quantity" class="form-control form-control-lg border border-3 border-danger fw-bold" min="0" value="<?php echo h($editItem ? ($editItem['original_quantity'] ?: $editItem['quantity']) : '1'); ?>" required>
                            <div class="form-text fw-bold text-danger">EDITABLE FIELD — this saves to tool_items.original_quantity.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Brand *</label>
                            <input type="text" name="brand" id="brandInput" list="brandOptions" class="form-control" value="<?php echo h($editItem['brand'] ?? ''); ?>" placeholder="Apple / Samsung / Xiaomi" autocomplete="off" required>
                            <datalist id="brandOptions">
                                <?php foreach ($modelBrands as $b): ?><option value="<?php echo h($b); ?>"></option><?php endforeach; ?>
                            </datalist>
                            <div class="form-text">Type a new brand or choose one from database. <a href="mobile_models.php" target="_blank">Manage models</a></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Model Name</label>
                            <input type="text" name="model_name" id="modelInput" list="modelOptions" class="form-control" value="<?php echo h($editItem['model_name'] ?? ''); ?>" placeholder="iPhone 13 Pro Max / Galaxy A52" autocomplete="off">
                            <datalist id="modelOptions"></datalist>
                            <div class="form-text">Models are filtered by the selected brand.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Type *</label>
                            <select name="item_type" class="form-select" required>
                                <option value="">Select type</option>
                                <?php foreach ($defaultTypes as $t): ?><option value="<?php echo h($t); ?>" <?php echo (($editItem['item_type'] ?? '') === $t) ? 'selected' : ''; ?>><?php echo h($t); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Quality</label>
                            <select name="quality" class="form-select">
                                <option value="">Select quality</option>
                                <?php foreach ($defaultQualities as $q): ?><option value="<?php echo h($q); ?>" <?php echo (($editItem['quality'] ?? '') === $q) ? 'selected' : ''; ?>><?php echo h($q); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Condition</label>
                            <select name="tool_condition" class="form-select">
                                <option value="">Select condition</option>
                                <?php foreach ($conditionList as $c): ?><option value="<?php echo h($c); ?>" <?php echo (($editItem['tool_condition'] ?? '') === $c) ? 'selected' : ''; ?>><?php echo h($c); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach ($statusList as $s): ?><option value="<?php echo h($s); ?>" <?php echo (($editItem['status'] ?? 'Available') === $s) ? 'selected' : ''; ?>><?php echo h($s); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Details</label>
                            <textarea name="details" class="form-control" rows="2" placeholder="Example: black screen with frame, battery 100%, warranty, supplier note..."><?php echo h($editItem['details'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-success fw-bold"><?php echo $editItem ? 'Update Item' : 'Save Item'; ?></button>
                            <?php if ($editItem): ?><a href="tools.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card card-box p-4 mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold">Search</label>
                <input type="text" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="Barcode, brand, model, type, quality...">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Brand</label>
                <select name="brand" class="form-select"><option value="">All Brands</option><?php foreach ($brands as $b): ?><option value="<?php echo h($b); ?>" <?php echo ($brandFilter === $b) ? 'selected' : ''; ?>><?php echo h($b); ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Type</label>
                <select name="type" class="form-select"><option value="">All Types</option><?php foreach (array_unique(array_merge($defaultTypes, $types)) as $t): ?><option value="<?php echo h($t); ?>" <?php echo ($typeFilter === $t) ? 'selected' : ''; ?>><?php echo h($t); ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Quality</label>
                <select name="quality" class="form-select"><option value="">All Qualities</option><?php foreach (array_unique(array_merge($defaultQualities, $qualities)) as $q): ?><option value="<?php echo h($q); ?>" <?php echo ($qualityFilter === $q) ? 'selected' : ''; ?>><?php echo h($q); ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-1">
                <label class="form-label fw-bold">Status</label>
                <select name="status" class="form-select"><option value="">All</option><?php foreach ($statusList as $s): ?><option value="<?php echo h($s); ?>" <?php echo ($statusFilter === $s) ? 'selected' : ''; ?>><?php echo h($s); ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-dark fw-bold">Filter</button>
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap mt-3">
                <a href="tools.php" class="btn btn-sm btn-outline-secondary">All</a>
                <a href="tools.php?status=Available" class="btn btn-sm btn-outline-success">Available</a>
                <a href="tools.php?status=Sold" class="btn btn-sm btn-outline-dark">Sold</a>
                <a href="tools.php?type=Screen" class="btn btn-sm btn-outline-primary">Screens</a>
                <a href="tools.php?type=Battery" class="btn btn-sm btn-outline-warning">Batteries</a>
                <a href="tools.php?<?php echo http_build_query(array_merge($_GET, ['duplicates' => 1])); ?>" class="btn btn-sm btn-outline-danger<?php echo $duplicatesOnly ? ' active' : ''; ?> fw-bold">🔁 Same Barcode</a>
                <?php if ($duplicatesOnly): ?>
                    <a href="tools.php?<?php echo http_build_query(array_diff_key($_GET, ['duplicates' => ''])); ?>" class="btn btn-sm btn-outline-secondary">✖ Clear Same Barcode</a>
                <?php endif; ?>
                <a href="tools.php?export=excel&<?php echo http_build_query($_GET); ?>" class="btn btn-sm btn-success fw-bold">⬇️ Download Excel</a>
            </div>
        </form>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card card-box p-4 h-100">
                <h5 class="fw-bold mb-3 text-success">✅ Available Tools Preview</h5>
                <div class="table-responsive"><table class="table table-sm table-hover align-middle">
                    <thead class="table-light"><tr><th>Barcode</th><th>Item</th><th>Type</th><th>Quality</th><th>Qty</th><th>Cost</th></tr></thead><tbody>
                    <?php $count=0; foreach ($items as $it): if (strtolower($it['status']) !== 'available' || (int)$it['quantity'] <= 0) continue; $count++; if ($count > 8) break; ?>
                    <tr><td class="fw-semibold"><?php echo h($it['barcode']); ?></td><td><?php echo h($it['brand'] . ' ' . $it['model_name']); ?></td><td><?php echo h($it['item_type']); ?></td><td><?php echo h($it['quality']); ?></td><td><?php echo h($it['quantity']); ?></td><td><?php echo money_clean($it['cost_price']); ?> AED</td></tr>
                    <?php endforeach; if ($count === 0): ?><tr><td colspan="6" class="text-muted text-center">No available tools in current filter.</td></tr><?php endif; ?>
                    </tbody></table></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card card-box p-4 h-100">
                <h5 class="fw-bold mb-3 text-secondary">🛒 Sold Tools Preview</h5>
                <div class="table-responsive"><table class="table table-sm table-hover align-middle">
                    <thead class="table-light"><tr><th>Barcode</th><th>Item</th><th>Type</th><th>Quality</th><th>Sold Price</th><th>Last Sold</th></tr></thead><tbody>
                    <?php $count=0; foreach ($items as $it): $orig=(int)($it['original_quantity'] ?: $it['quantity']); $soldUnits=max(0,$orig-(int)$it['quantity']); if (strtolower($it['status']) !== 'sold' && $soldUnits <= 0) continue; $count++; if ($count > 8) break; $sum=$soldSummary[$it['barcode']] ?? null; ?>
                    <tr><td class="fw-semibold"><?php echo h($it['barcode']); ?></td><td><?php echo h($it['brand'] . ' ' . $it['model_name']); ?></td><td><?php echo h($it['item_type']); ?></td><td><?php echo h($it['quality']); ?></td><td><?php echo $sum ? money_clean($sum['avg_price']) . ' AED' : '—'; ?></td><td><?php echo h($sum['last_sold_at'] ?? '—'); ?></td></tr>
                    <?php endforeach; if ($count === 0): ?><tr><td colspan="6" class="text-muted text-center">No sold tools in current filter.</td></tr><?php endif; ?>
                    </tbody></table></div>
            </div>
        </div>
    </div>

    <form method="POST" onsubmit="return confirm('Delete selected tools/spare parts?');">
        <div class="card card-box p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Tools / Spare Parts List</h5>
                <button type="submit" name="delete_selected" class="btn btn-outline-danger btn-sm fw-bold">🗑️ Delete Selected</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th><input type="checkbox" id="selectAll"></th>
                            <th>ID</th><th>Barcode</th><th>Brand</th><th>Model</th><th>Type</th><th>Quality</th><th>Cost</th><th>Original Qty</th><th>Qty Available</th><th>Sold Info</th><th>Sold Price</th><th>Condition</th><th>Status</th><th>Details</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$items): ?>
                        <tr><td colspan="16" class="text-center text-muted py-4">No tools/spare parts found.</td></tr>
                    <?php else: foreach ($items as $it): ?>
                        <?php
                            $orig = (int)($it['original_quantity'] ?: $it['quantity']);
                            $soldUnits = max(0, $orig - (int)$it['quantity']);
                            $sum = $soldSummary[$it['barcode']] ?? null;
                            $status = $it['status'] ?: 'Available';
                            $badge = 'secondary';
                            if (strtolower($status) === 'available') $badge = 'success';
                            if (strtolower($status) === 'sold') $badge = 'dark';
                            if (strtolower($status) === 'reserved') $badge = 'warning';
                        ?>
                        <tr>
                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo h($it['id']); ?>" class="row-check"></td>
                            <td><?php echo h($it['id']); ?></td>
                            <td class="fw-semibold"><?php echo h($it['barcode']); ?></td>
                            <td><?php echo h($it['brand']); ?></td>
                            <td><?php echo h($it['model_name']); ?></td>
                            <td><span class="badge bg-primary"><?php echo h($it['item_type']); ?></span></td>
                            <td><?php echo h($it['quality']); ?></td>
                            <td class="fw-bold text-danger"><?php echo money_clean($it['cost_price']); ?> AED</td>
                            <td style="min-width:150px;">
                                <div class="input-group input-group-sm">
                                    <input type="number" min="0" class="form-control" name="quick_original_qty[<?php echo h($it['id']); ?>]" value="<?php echo h($orig); ?>" title="Edit Original Qty">
                                    <button type="submit" name="quick_update_original_qty" value="<?php echo h($it['id']); ?>" class="btn btn-warning fw-bold" title="Save Original Qty">Save</button>
                                </div>
                                <small class="text-primary fw-bold">Editable Original Qty</small>
                            </td>
                            <td class="fw-bold <?php echo ((int)$it['quantity'] <= 0) ? 'text-danger' : 'text-success'; ?>"><?php echo h($it['quantity']); ?></td>
                            <td>
                                <?php if ($soldUnits > 0 || strtolower($status) === 'sold'): ?>
                                    <span class="fw-bold"><?php echo h(max($soldUnits, (int)($sum['sold_qty'] ?? 0))); ?> sold</span><br>
                                    <small class="text-muted">Last: <?php echo h($sum['last_sold_at'] ?? 'Unknown'); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">Not sold yet</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $sum ? '<span class="fw-bold text-success">' . h(money_clean($sum['avg_price'])) . ' AED</span>' : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo h($it['tool_condition']); ?></td>
                            <td><span class="badge bg-<?php echo $badge; ?>"><?php echo h($status); ?></span></td>
                            <td style="max-width:260px; white-space:normal;"><?php echo h($it['details']); ?></td>
                            <td class="text-end">
                                <a href="tools.php?action=edit&id=<?php echo h($it['id']); ?>#tool-edit-form" class="btn btn-sm btn-outline-primary">Edit</a>
                                <a href="tools.php?action=delete&id=<?php echo h($it['id']); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this item?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
</div>
</div>
</main>
</div>
<script>
document.querySelectorAll('[data-fill-barcode]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const input = document.querySelector('input[name="barcode"]');
        if (input && this.dataset.fillBarcode) {
            input.value = this.dataset.fillBarcode;
            input.focus();
        }
    });
});

const mobileModels = <?php echo json_encode($mobileModelsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const brandInput = document.getElementById('brandInput');
const modelOptions = document.getElementById('modelOptions');

function refreshModelOptions() {
    if (!modelOptions) return;
    const selectedBrand = (brandInput?.value || '').trim().toLowerCase();
    modelOptions.innerHTML = '';

    mobileModels
        .filter(row => !selectedBrand || String(row.brand_name).toLowerCase() === selectedBrand)
        .forEach(row => {
            const opt = document.createElement('option');
            opt.value = row.model_name;
            opt.label = row.brand_name + (row.series ? ' - ' + row.series : '');
            modelOptions.appendChild(opt);
        });
}

brandInput?.addEventListener('input', refreshModelOptions);
refreshModelOptions();

document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
});
</script>

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