<?php
require_once __DIR__ . '/session_keepalive.php';
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

function import_export_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function import_export_html_cell($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function import_export_sheet(string $title, array $rows): void {
    echo '<h2 style="font-family:Arial">' . import_export_html_cell($title) . '</h2>';
    if (!$rows) {
        echo '<p>No records found.</p>';
        return;
    }

    $headers = array_keys($rows[0]);
    echo '<table border="1" cellspacing="0" cellpadding="5">';
    echo '<thead><tr>';
    foreach ($headers as $header) {
        echo '<th style="background:#eaf0ff;font-weight:bold">' . import_export_html_cell($header) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($headers as $header) {
            $value = $row[$header] ?? '';
            // mso-number-format Text prevents Excel from changing long barcodes.
            echo '<td style="mso-number-format:\'\\@\';">' . import_export_html_cell($value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table><br><br>';
}

function import_export_download_headers(string $filename): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"></head><body>';
}

if (isset($_GET['export']) && isset($pdo)) {
    $export = strtolower(trim((string)$_GET['export']));
    $filename = 'rukn_al_lotus_' . preg_replace('/[^a-z0-9_]+/i', '_', $export) . '_' . date('Y-m-d_H-i') . '.xls';

    import_export_download_headers($filename);

    try {
        if ($export === 'products') {
            $rows = import_export_table_exists($pdo, 'products')
                ? $pdo->query("SELECT * FROM products ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC)
                : [];
            import_export_sheet('Products', $rows);

        } elseif ($export === 'phones') {
            $rows = import_export_table_exists($pdo, 'phones')
                ? $pdo->query("SELECT * FROM phones ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC)
                : [];
            import_export_sheet('Phones', $rows);

        } elseif ($export === 'laptops') {
            $rows = import_export_table_exists($pdo, 'laptops')
                ? $pdo->query("SELECT * FROM laptops ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC)
                : [];
            import_export_sheet('Laptops', $rows);

        } elseif ($export === 'tools') {
            $toolTable = import_export_table_exists($pdo, 'tool_items')
                ? 'tool_items'
                : (import_export_table_exists($pdo, 'tools') ? 'tools' : '');
            $rows = $toolTable !== ''
                ? $pdo->query("SELECT * FROM `$toolTable` ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC)
                : [];
            import_export_sheet('Tools / Spare Parts', $rows);

        } elseif ($export === 'daily_sheet') {
            $rows = [];
            if (import_export_table_exists($pdo, 'daily_cash_sheets') &&
                import_export_table_exists($pdo, 'daily_cash_sheet_rows')) {
                $stmt = $pdo->query("
                    SELECT
                        s.sheet_date,
                        s.previous_day_balance,
                        s.physical_drawer_amount,
                        s.notes AS general_notes,
                        r.row_order,
                        r.code,
                        r.item_name,
                        r.cash_amount,
                        r.visa_amount,
                        r.tamara_amount,
                        r.cost_amount,
                        r.profit_amount,
                        r.withdraw_amount,
                        r.withdraw_description,
                        r.deposit_amount,
                        r.deposit_description,
                        r.row_notes
                    FROM daily_cash_sheets s
                    LEFT JOIN daily_cash_sheet_rows r ON r.sheet_id = s.id
                    ORDER BY s.sheet_date ASC, r.row_order ASC, r.id ASC
                ");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            import_export_sheet('Daily Cash Sheet', $rows);

        } elseif ($export === 'transactions') {
            $sales = import_export_table_exists($pdo, 'sales_records')
                ? $pdo->query("SELECT * FROM sales_records ORDER BY sold_at ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC)
                : [];
            import_export_sheet('Sales Transactions', $sales);

            $drawer = import_export_table_exists($pdo, 'drawer_withdrawals')
                ? $pdo->query("SELECT * FROM drawer_withdrawals ORDER BY withdrawn_at ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC)
                : [];
            import_export_sheet('Withdrawals / Deposits', $drawer);

        } else {
            import_export_sheet('Export', [['Error' => 'Unknown export type']]);
        }
    } catch (Throwable $e) {
        import_export_sheet('Export Error', [['Error' => $e->getMessage()]]);
    }

    echo '</body></html>';
    exit;
}

$role = (($_SESSION['role'] ?? 'admin') === 'viewer') ? 'viewer' : 'admin';
$displayName = trim((string)($_SESSION['display_name'] ?? ''));
if ($displayName === '') {
    $displayName = (string)($_SESSION['username'] ?? ucfirst($role));
}
function h_import($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Import Center - Rukn Al Lotus</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{
    --bg:#f4f7fb;--text:#172033;--muted:#778199;--line:#e5eaf1;
    --sidebar:#101828;--primary:#3154e8;--shadow:0 8px 28px rgba(34,51,84,.07);
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,"Segoe UI",Arial,sans-serif}
.app{display:flex;min-height:100vh}
.sidebar{
    width:270px;position:fixed;inset:0 auto 0 0;overflow-y:auto;
    background:linear-gradient(180deg,#0b1220,#101a2e);padding:20px 14px;
    color:#fff;z-index:1040;box-shadow:8px 0 26px rgba(13,24,45,.12)
}
.brand{display:flex;gap:12px;align-items:center;padding:4px 8px 20px;border-bottom:1px solid rgba(255,255,255,.07);margin-bottom:10px}
.brand-mark{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#eef2ff,#fff);color:#263fbb;display:grid;place-items:center;font-weight:900}
.brand-title{font-weight:850;line-height:1.05}.brand-sub{color:#8390a8;font-size:.76rem;margin-top:5px}
.nav-label{color:#64748b;font-size:.67rem;font-weight:850;text-transform:uppercase;letter-spacing:.1em;padding:17px 12px 7px}
.side-link{display:flex;align-items:center;gap:11px;color:#b9c3d5;text-decoration:none;padding:10px 12px;border-radius:11px;margin:3px 0;font-size:.88rem;font-weight:650}
.side-link:hover{background:#ffffff0d;color:#fff}.side-link.active{background:linear-gradient(135deg,#3154e8,#536df0);color:#fff;box-shadow:0 8px 20px rgba(49,84,232,.24)}
.side-link i{width:20px;text-align:center}
.main{margin-left:270px;width:calc(100% - 270px);padding:22px 26px 40px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:18px}
.welcome h1{font-size:1.55rem;font-weight:850;margin:0}.welcome p{margin:4px 0 0;color:var(--muted);font-size:.86rem}
.user-chip{background:#fff;border:1px solid var(--line);box-shadow:var(--shadow);border-radius:14px;padding:8px 11px;display:flex;align-items:center;gap:10px}
.avatar{width:36px;height:36px;border-radius:11px;background:#eef2ff;color:#3446b4;display:grid;place-items:center;font-weight:850}
.mobile-toggle{display:none}
.hero{background:linear-gradient(135deg,#223ed0,#486bf0 62%,#6f86ff);border-radius:22px;padding:25px;color:#fff;margin-bottom:20px;box-shadow:0 14px 34px rgba(49,84,232,.2);position:relative;overflow:hidden}
.hero:after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;background:#ffffff12;right:-80px;top:-100px}
.hero h2{font-size:1.45rem;font-weight:900;margin:0 0 7px}.hero p{margin:0;color:#ffffffd0;font-size:.88rem;max-width:850px}
.import-card{
    border:1px solid var(--line);border-radius:19px;background:#fff;box-shadow:var(--shadow);
    overflow:hidden;height:100%;transition:.18s ease;position:relative
}
.import-card:hover{transform:translateY(-3px);box-shadow:0 14px 34px rgba(34,51,84,.12)}
.card-top{height:6px;background:var(--accent,#3154e8)}
.card-body-import{padding:20px}
.icon-box{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font-size:1.2rem;background:var(--soft,#eef2ff);color:var(--accent,#3154e8);margin-bottom:13px}
.import-title{font-size:1.05rem;font-weight:850;margin-bottom:4px}.import-sub{color:var(--muted);font-size:.78rem;min-height:39px}
.product-card{--accent:#3154e8;--soft:#eef2ff}.phone-card{--accent:#0e9f6e;--soft:#ecfdf5}
.laptop-card{--accent:#7c5ce7;--soft:#f3efff}.tool-card{--accent:#e69a1f;--soft:#fff7e6}
.daily-card{--accent:#0d9eb7;--soft:#eaf9fd}.transaction-card{--accent:#e05268;--soft:#fff1f2}
.form-label{font-size:.76rem;font-weight:800;color:#475467;margin-bottom:6px}
.form-control,.form-select{border-radius:10px;border-color:#d7dee8;min-height:43px}
.btn{border-radius:10px;font-weight:750}
.file-zone{border:1px dashed #bbc6d5;border-radius:12px;padding:13px;background:#fafcff}
.form-check-label{font-size:.76rem}
.note{font-size:.71rem;color:#69758a;background:#f8fafc;border:1px solid #eef1f5;border-radius:9px;padding:9px 10px}
.route{display:flex;align-items:center;gap:7px;color:#8a94a6;font-size:.69rem;margin-top:11px}
.export-btn{border:1px solid var(--accent,#3154e8)!important;color:var(--accent,#3154e8)!important;background:#fff!important}
.export-btn:hover{background:var(--soft,#eef2ff)!important}
.import-actions{display:grid;grid-template-columns:1fr 1fr;gap:9px}
@media(max-width:520px){.import-actions{grid-template-columns:1fr}}

.warning-note{border:1px solid #f4d38b;background:#fffaeb;color:#8b5b00;border-radius:12px;padding:12px 14px;font-size:.78rem}
@media(max-width:991px){
    .sidebar{transform:translateX(-100%);transition:.2s}.sidebar.open{transform:translateX(0)}
    .main{margin-left:0;width:100%;padding:14px}.mobile-toggle{display:inline-flex}.user-text{display:none}
}
</style>
<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body>
<div class="app">
<?php include __DIR__ . '/sidebar.php'; ?>

<main class="main">
    <div class="topbar">
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-light border mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="bi bi-list fs-5"></i></button>
            <div class="welcome"><h1>Import Center</h1><p>Upload inventory and daily transaction files from one place.</p></div>
        </div>
        <div class="user-chip">
            <div class="avatar"><?=h_import(strtoupper(substr($displayName,0,1)))?></div>
            <div class="user-text"><div class="fw-bold small"><?=h_import($displayName)?></div><div class="text-muted" style="font-size:.68rem"><?=h_import(strtoupper($role))?></div></div>
        </div>
    </div>

    <section class="hero">
        <div class="position-relative" style="z-index:1">
            <h2><i class="bi bi-cloud-arrow-up me-2"></i>File Import Center</h2>
            <p>Select the type of data you want to upload. Every section now uses file upload only and sends the file to the existing POS importer for that module.</p>
        </div>
    </section>

    <?php if ($role !== 'admin'): ?>
        <div class="alert alert-warning">Import Center is available to administrators only.</div>
    <?php else: ?>

    <div class="row g-4">

        <!-- PRODUCTS -->
        <div class="col-xl-4 col-md-6">
            <div class="import-card product-card">
                <div class="card-top"></div>
                <div class="card-body-import">
                    <div class="icon-box"><i class="bi bi-box-seam"></i></div>
                    <div class="import-title">Products</div>
                    <div class="import-sub">Import normal product/accessory inventory.</div>
                    <form action="add_product.php" method="POST" enctype="multipart/form-data" class="mt-3">
                        <div class="file-zone mb-3">
                            <label class="form-label">CSV / Excel file</label>
                            <input type="file" name="product_file" class="form-control" accept=".xlsx,.xls,.csv" required>
                        </div>
                        <div class="import-actions">
                            <button type="submit" name="upload_products" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload Products</button>
                            <a href="import_center.php?export=products" class="btn export-btn"><i class="bi bi-file-earmark-excel me-1"></i>Export Existing</a>
                        </div>
                    </form>
                    <div class="route"><i class="bi bi-arrow-right"></i>Uses existing <strong>add_product.php</strong> importer</div>
                </div>
            </div>
        </div>

        <!-- PHONES -->
        <div class="col-xl-4 col-md-6">
            <div class="import-card phone-card">
                <div class="card-top"></div>
                <div class="card-body-import">
                    <div class="icon-box"><i class="bi bi-phone-fill"></i></div>
                    <div class="import-title">Phones</div>
                    <div class="import-sub">Import phone inventory with barcode, cost, quantity, brand and condition.</div>
                    <form action="phones.php" method="POST" enctype="multipart/form-data" class="mt-3">
                        <input type="hidden" name="upload_phones" value="1">
                        <div class="file-zone mb-3">
                            <label class="form-label">CSV / Excel file</label>
                            <input type="file" name="phone_file" class="form-control" accept=".xlsx,.csv" required>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="replace_phones" id="ic_replace_phones">
                            <label class="form-check-label" for="ic_replace_phones">Replace current phone inventory before import</label>
                        </div>
                        <div class="import-actions">
                            <button class="btn btn-success"><i class="bi bi-upload me-1"></i>Upload Phones</button>
                            <a href="import_center.php?export=phones" class="btn export-btn"><i class="bi bi-file-earmark-excel me-1"></i>Export Existing</a>
                        </div>
                    </form>
                    <div class="route"><i class="bi bi-arrow-right"></i>Uses existing <strong>phones.php</strong> importer</div>
                </div>
            </div>
        </div>

        <!-- LAPTOPS -->
        <div class="col-xl-4 col-md-6">
            <div class="import-card laptop-card">
                <div class="card-top"></div>
                <div class="card-body-import">
                    <div class="icon-box"><i class="bi bi-laptop"></i></div>
                    <div class="import-title">Laptops</div>
                    <div class="import-sub">Upload laptop inventory directly from a CSV or Excel file.</div>
                    <form action="laptops.php" method="POST" enctype="multipart/form-data" class="mt-3">
                        <input type="hidden" name="upload_laptops" value="1">
                        <div class="file-zone mb-2">
                            <label class="form-label">CSV / Excel file</label>
                            <input type="file" name="laptop_file" class="form-control" accept=".xlsx,.csv" required>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="replace_laptops" id="ic_replace_laptops">
                            <label class="form-check-label" for="ic_replace_laptops">Replace laptop inventory</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="sync_after_upload" id="ic_laptop_sync" checked>
                            <label class="form-check-label" for="ic_laptop_sync">Sync sold/available after import</label>
                        </div>
                        <div class="import-actions">
                            <button class="btn text-white" style="background:#7c5ce7"><i class="bi bi-upload me-1"></i>Upload Laptops</button>
                            <a href="import_center.php?export=laptops" class="btn export-btn"><i class="bi bi-file-earmark-excel me-1"></i>Export Existing</a>
                        </div>
                    </form>
                    <div class="route"><i class="bi bi-arrow-right"></i>Uses existing <strong>laptops.php</strong> importer</div>
                </div>
            </div>
        </div>

        <!-- TOOLS -->
        <div class="col-xl-4 col-md-6">
            <div class="import-card tool-card">
                <div class="card-top"></div>
                <div class="card-body-import">
                    <div class="icon-box"><i class="bi bi-tools"></i></div>
                    <div class="import-title">Tools / Spare Parts</div>
                    <div class="import-sub">Import tools and spare parts inventory directly from CSV or Excel.</div>
                    <form action="tools.php" method="POST" enctype="multipart/form-data" class="mt-3">
                        <input type="hidden" name="upload_tools" value="1">
                        <div class="file-zone mb-2">
                            <label class="form-label">CSV / Excel file</label>
                            <input type="file" name="tool_file" class="form-control" accept=".xlsx,.csv" required>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="replace_tools" id="ic_replace_tools">
                            <label class="form-check-label" for="ic_replace_tools">Replace tools inventory</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="sync_after_upload" id="ic_tool_sync" checked>
                            <label class="form-check-label" for="ic_tool_sync">Sync sold/available after import</label>
                        </div>
                        <div class="import-actions">
                            <button class="btn text-white" style="background:#e69a1f"><i class="bi bi-upload me-1"></i>Upload Tools</button>
                            <a href="import_center.php?export=tools" class="btn export-btn"><i class="bi bi-file-earmark-excel me-1"></i>Export Existing</a>
                        </div>
                    </form>
                    <div class="route"><i class="bi bi-arrow-right"></i>Uses existing <strong>tools.php</strong> importer</div>
                </div>
            </div>
        </div>

        <!-- DAILY CASH SHEET -->
        <div class="col-xl-4 col-md-6">
            <div class="import-card daily-card">
                <div class="card-top"></div>
                <div class="card-body-import">
                    <div class="icon-box"><i class="bi bi-cash-stack"></i></div>
                    <div class="import-title">Daily Cash Sheet</div>
                    <div class="import-sub">Import the daily transaction workbook; imported dates automatically feed the Daily Cash Sheet.</div>
                    <form action="view_transactions.php" method="POST" enctype="multipart/form-data" class="mt-3" onsubmit="return confirm('Import this workbook and rebuild the corresponding daily dates?');">
                        <div class="file-zone mb-3">
                            <label class="form-label">Daily workbook (.xlsx)</label>
                            <input type="file" name="legacy_excel[]" class="form-control" accept=".xlsx" multiple required>
                        </div>
                        <input type="hidden" name="legacy_start_date" value="<?=h_import(date('Y-m-d'))?>">
                        <div class="import-actions">
                            <button type="submit" name="import_legacy_excel" class="btn text-white" style="background:#0d9eb7"><i class="bi bi-cloud-arrow-up me-1"></i>Import & Sync</button>
                            <a href="import_center.php?export=daily_sheet" class="btn export-btn"><i class="bi bi-file-earmark-excel me-1"></i>Export Existing</a>
                        </div>
                    </form>
                    <div class="note mt-3"><strong>Why Excel only?</strong> Your Daily Sheet transaction format uses workbook sheet names as dates, so CSV cannot preserve that multi-sheet structure.</div>
                    <div class="route"><i class="bi bi-arrow-right"></i>Uses <strong>view_transactions.php</strong> → Daily Sheet sync</div>
                </div>
            </div>
        </div>

        <!-- TRANSACTIONS -->
        <div class="col-xl-4 col-md-6">
            <div class="import-card transaction-card">
                <div class="card-top"></div>
                <div class="card-body-import">
                    <div class="icon-box"><i class="bi bi-receipt"></i></div>
                    <div class="import-title">Transactions</div>
                    <div class="import-sub">Import one or more monthly transaction Excel workbooks.</div>
                    <form action="view_transactions.php" method="POST" enctype="multipart/form-data" class="mt-3" onsubmit="return confirm('Import selected transaction files? Existing imported dates may be rebuilt from the workbook.');">
                        <div class="file-zone mb-2">
                            <label class="form-label">Excel workbook(s)</label>
                            <input type="file" name="legacy_excel[]" class="form-control" accept=".xlsx" multiple required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Fallback start date</label>
                            <input type="date" name="legacy_start_date" class="form-control" value="<?=h_import(date('Y-m-d'))?>">
                        </div>
                        <div class="import-actions">
                            <button type="submit" name="import_legacy_excel" class="btn text-white" style="background:#e05268"><i class="bi bi-upload me-1"></i>Import Transactions</button>
                            <a href="import_center.php?export=transactions" class="btn export-btn"><i class="bi bi-file-earmark-excel me-1"></i>Export Existing</a>
                        </div>
                    </form>
                    <div class="note mt-3">Monthly files should keep your existing naming/date structure, for example <strong>08-2026.xlsx</strong> with sheets named by day/date.</div>
                    <div class="route"><i class="bi bi-arrow-right"></i>Uses existing <strong>view_transactions.php</strong> importer</div>
                </div>
            </div>
        </div>

    </div>

    <div class="warning-note mt-4">
        <i class="bi bi-shield-check me-1"></i>
        This page does not replace your current import code. It is a single UI that forwards files to the importers already used by Products, Phones, Laptops, Tools and Transactions.
    </div>

    <?php endif; ?>
</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
