<?php
// mobile_models.php - Brand / Model Database Manager
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$message = '';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
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


try {
    ensure_mobile_models_tables($pdo);
} catch (Exception $e) {
    $message = "<div class='alert alert-danger'>Database setup error: " . h($e->getMessage()) . "</div>";
}

function active_badge($active) {
    return ((int)$active === 1) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_model'])) {
    $id = intval($_POST['model_id'] ?? 0);
    $brandName = trim($_POST['brand_name'] ?? '');
    $modelName = trim($_POST['model_name'] ?? '');
    $series = trim($_POST['series'] ?? '');
    $deviceType = trim($_POST['device_type'] ?? 'Phone');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($brandName === '' || $modelName === '') {
        $message = "<div class='alert alert-danger'>Brand and Model Name are required.</div>";
    } else {
        try {
            $brandId = mobile_brand_id($pdo, $brandName);
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE mobile_models SET brand_id=?, model_name=?, series=?, device_type=?, is_active=? WHERE id=?");
                $stmt->execute([$brandId, $modelName, $series, $deviceType ?: 'Phone', $isActive, $id]);
                header('Location: mobile_models.php?updated=1');
                exit;
            } else {
                $stmt = $pdo->prepare("INSERT INTO mobile_models (brand_id, model_name, series, device_type, is_active)
                                       VALUES (?, ?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE series=VALUES(series), device_type=VALUES(device_type), is_active=VALUES(is_active)");
                $stmt->execute([$brandId, $modelName, $series, $deviceType ?: 'Phone', $isActive]);
                header('Location: mobile_models.php?added=1');
                exit;
            }
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Save failed: " . h($e->getMessage()) . "</div>";
        }
    }
}

if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
    try {
        $stmt = $pdo->prepare("DELETE FROM mobile_models WHERE id=?");
        $stmt->execute([intval($_GET['id'])]);
        header('Location: mobile_models.php?deleted=1');
        exit;
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Delete failed: " . h($e->getMessage()) . "</div>";
    }
}

if (isset($_GET['added'])) $message = "<div class='alert alert-success'>Model added successfully.</div>";
if (isset($_GET['updated'])) $message = "<div class='alert alert-success'>Model updated successfully.</div>";
if (isset($_GET['deleted'])) $message = "<div class='alert alert-success'>Model deleted successfully.</div>";

$editModel = null;
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
    $stmt = $pdo->prepare("SELECT m.*, b.brand_name FROM mobile_models m INNER JOIN mobile_brands b ON b.id=m.brand_id WHERE m.id=?");
    $stmt->execute([intval($_GET['id'])]);
    $editModel = $stmt->fetch(PDO::FETCH_ASSOC);
}

$search = trim($_GET['search'] ?? '');
$brandFilter = trim($_GET['brand'] ?? '');
$typeFilter = trim($_GET['device_type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(b.brand_name LIKE ? OR m.model_name LIKE ? OR m.series LIKE ? OR m.device_type LIKE ?)";
    for ($i = 0; $i < 4; $i++) $params[] = "%$search%";
}
if ($brandFilter !== '') { $where[] = "b.brand_name = ?"; $params[] = $brandFilter; }
if ($typeFilter !== '') { $where[] = "m.device_type = ?"; $params[] = $typeFilter; }
if ($statusFilter === 'active') $where[] = "m.is_active = 1";
if ($statusFilter === 'hidden') $where[] = "m.is_active = 0";
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT m.*, b.brand_name
                       FROM mobile_models m
                       INNER JOIN mobile_brands b ON b.id=m.brand_id
                       $whereSql
                       ORDER BY b.brand_name ASC, m.series ASC, m.model_name ASC");
$stmt->execute($params);
$models = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeModelCount = 0;
$hiddenModelCount = 0;
try {
    $activeModelCount = (int)$pdo->query("SELECT COUNT(*) FROM mobile_models WHERE is_active = 1")->fetchColumn();
    $hiddenModelCount = (int)$pdo->query("SELECT COUNT(*) FROM mobile_models WHERE is_active = 0")->fetchColumn();
} catch (Throwable $e) {
    $activeModelCount = 0;
    $hiddenModelCount = 0;
}


$brands = get_mobile_model_brands($pdo);
$deviceTypes = $pdo->query("SELECT DISTINCT device_type FROM mobile_models WHERE device_type <> '' ORDER BY device_type ASC")->fetchAll(PDO::FETCH_COLUMN);
$totalModels = (int)$pdo->query("SELECT COUNT(*) FROM mobile_models")->fetchColumn();
$totalBrands = (int)$pdo->query("SELECT COUNT(*) FROM mobile_brands")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mobile Models Database - Rukn Al Lotus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; font-family: system-ui, sans-serif; }
        .card-box { border:0; border-radius:14px; box-shadow:0 4px 14px rgba(0,0,0,.06); }
        .table td,.table th { vertical-align:middle; }
    </style>

<style>
/* ===== Mobile Models colored top cards ===== */
.mobile-model-top-card{
    position:relative;
    overflow:hidden;
    border:0!important;
    min-height:112px;
    color:#fff!important;
    box-shadow:0 10px 26px rgba(16,24,40,.12)!important;
    transition:transform .18s ease,box-shadow .18s ease;
}
.mobile-model-top-card:hover{
    transform:translateY(-3px);
    box-shadow:0 15px 32px rgba(16,24,40,.17)!important;
}
.mobile-model-top-card::after{
    content:"";
    position:absolute;
    width:95px;
    height:95px;
    border-radius:50%;
    right:-25px;
    bottom:-38px;
    background:rgba(255,255,255,.14);
}
.mobile-model-top-card::before{
    content:"";
    position:absolute;
    width:50px;
    height:50px;
    border-radius:50%;
    right:28px;
    top:-22px;
    background:rgba(255,255,255,.08);
}
.mobile-model-top-card>*{position:relative;z-index:1}
.mobile-model-top-card,
.mobile-model-top-card h1,.mobile-model-top-card h2,.mobile-model-top-card h3,
.mobile-model-top-card h4,.mobile-model-top-card h5,.mobile-model-top-card h6,
.mobile-model-top-card .fw-bold,.mobile-model-top-card .text-dark,
.mobile-model-top-card .text-primary,.mobile-model-top-card .text-success,
.mobile-model-top-card .text-danger,.mobile-model-top-card .text-warning,
.mobile-model-top-card .text-info{color:#fff!important}
.mobile-model-top-card .small-label,
.mobile-model-top-card .small,
.mobile-model-top-card small,
.mobile-model-top-card .text-muted{color:rgba(255,255,255,.84)!important}

.mm-blue{background:linear-gradient(135deg,#3154e8,#667eea)!important}
.mm-cyan{background:linear-gradient(135deg,#0e8fa8,#29b8d2)!important}
.mm-green{background:linear-gradient(135deg,#10986a,#2bbf8a)!important}
.mm-orange{background:linear-gradient(135deg,#d98b16,#f6b73c)!important}
.mm-purple{background:linear-gradient(135deg,#7652d9,#9b72ef)!important}
.mm-red{background:linear-gradient(135deg,#d94a62,#f06a7d)!important}
</style>

<link rel="stylesheet" href="sidebar_shared.css">

<style>
/* Mobile Models shared sidebar layout */
body{margin:0!important}
.mobile-models-sidebar-page{
    margin-left:270px;
    width:calc(100% - 270px);
    min-height:100vh;
}
@media(max-width:991px){
    .mobile-models-sidebar-page{
        margin-left:0;
        width:100%;
    }
}
html[dir="rtl"] .mobile-models-sidebar-page{
    margin-left:0;
    margin-right:270px;
}
@media(max-width:991px){
    html[dir="rtl"] .mobile-models-sidebar-page{
        margin-right:0;
    }
}
</style>

</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="mobile-models-sidebar-page">


<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-0">📱 Mobile Brand / Model Database</h2>
            <p class="text-muted mb-0">Add, search, edit, and delete phone models used by Tools / Spare Parts.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="tools.php" class="btn btn-outline-primary fw-bold">Back to Tools</a>
            <a href="sales.php" class="btn btn-outline-secondary fw-bold">Back</a>
        </div>
    </div>

    <?php echo $message; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card card-box p-3 mobile-model-top-card mm-blue"><div class="text-muted fw-bold small">Brands</div><h3 class="fw-bold mb-0"><?php echo number_format($totalBrands); ?></h3></div></div>
        <div class="col-md-3"><div class="card card-box p-3 mobile-model-top-card mm-cyan"><div class="text-muted fw-bold small">Models</div><h3 class="fw-bold mb-0"><?php echo number_format($totalModels); ?></h3></div></div>
        <div class="col-md-6"><div class="card card-box p-3 mobile-model-top-card mm-green"><div class="fw-bold">Active Models</div><div class="text-muted small">This page automatically creates the tables and inserts Apple iPhone + Samsung starter lists without deleting your edits.</div></div></div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card card-box p-4 h-100">
                <h5 class="fw-bold mb-3"><?php echo $editModel ? '✏️ Edit Model' : '➕ Add Brand + Model'; ?></h5>
                <form method="POST">
                    <input type="hidden" name="save_model" value="1">
                    <input type="hidden" name="model_id" value="<?php echo h($editModel['id'] ?? ''); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Brand *</label>
                        <input type="text" name="brand_name" list="brandOptions" class="form-control" value="<?php echo h($editModel['brand_name'] ?? ''); ?>" placeholder="Apple / Samsung / Xiaomi" required>
                        <datalist id="brandOptions">
                            <?php foreach ($brands as $b): ?><option value="<?php echo h($b); ?>"></option><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Model Name *</label>
                        <input type="text" name="model_name" class="form-control" value="<?php echo h($editModel['model_name'] ?? ''); ?>" placeholder="iPhone 15 Pro Max / Galaxy A55" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Series</label>
                            <input type="text" name="series" class="form-control" value="<?php echo h($editModel['series'] ?? ''); ?>" placeholder="iPhone / Galaxy A">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Device Type</label>
                            <input type="text" name="device_type" list="deviceTypeOptions" class="form-control" value="<?php echo h($editModel['device_type'] ?? 'Phone'); ?>" placeholder="Phone">
                            <datalist id="deviceTypeOptions">
                                <?php foreach (array_unique(array_merge(['Phone','Tablet'], $deviceTypes)) as $dt): ?><option value="<?php echo h($dt); ?>"></option><?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?php echo ((int)($editModel['is_active'] ?? 1) === 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="is_active">Active / show in dropdowns</label>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-success fw-bold"><?php echo $editModel ? 'Update Model' : 'Save Model'; ?></button>
                        <?php if ($editModel): ?><a href="mobile_models.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card card-box p-4 h-100">
                <h5 class="fw-bold mb-3">🔎 Search Models</h5>
                <form method="GET" class="row g-2 align-items-end mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Search</label>
                        <input type="text" name="search" class="form-control" value="<?php echo h($search); ?>" placeholder="brand, model, series...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Brand</label>
                        <select name="brand" class="form-select">
                            <option value="">All Brands</option>
                            <?php foreach ($brands as $b): ?><option value="<?php echo h($b); ?>" <?php echo $brandFilter === $b ? 'selected' : ''; ?>><?php echo h($b); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Type</label>
                        <select name="device_type" class="form-select">
                            <option value="">All</option>
                            <?php foreach ($deviceTypes as $dt): ?><option value="<?php echo h($dt); ?>" <?php echo $typeFilter === $dt ? 'selected' : ''; ?>><?php echo h($dt); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="hidden" <?php echo $statusFilter === 'hidden' ? 'selected' : ''; ?>>Hidden</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-grid"><button class="btn btn-dark fw-bold">Go</button></div>
                    <div class="col-12"><a href="mobile_models.php" class="btn btn-sm btn-outline-secondary">Reset</a></div>
                </form>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light"><tr><th>ID</th><th>Brand</th><th>Model</th><th>Series</th><th>Type</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                        <?php if (!$models): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No models found.</td></tr>
                        <?php else: foreach ($models as $m): ?>
                            <tr>
                                <td><?php echo h($m['id']); ?></td>
                                <td class="fw-bold"><?php echo h($m['brand_name']); ?></td>
                                <td><?php echo h($m['model_name']); ?></td>
                                <td><?php echo h($m['series']); ?></td>
                                <td><?php echo h($m['device_type']); ?></td>
                                <td><?php echo active_badge($m['is_active']); ?></td>
                                <td class="text-end">
                                    <a href="mobile_models.php?action=edit&id=<?php echo h($m['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <a href="mobile_models.php?action=delete&id=<?php echo h($m['id']); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this model?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

</div>
</body>
</html>
