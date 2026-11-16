<?php
require_once 'db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// Show the POS sidebar only for a logged-in Admin.
// Public/view-only visitors keep the full-width layout.
$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$showAdminSidebar = ($currentRole === 'admin');


$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function valid_date_view($value) {
    $date = DateTime::createFromFormat('Y-m-d', (string)$value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : date('Y-m-d');
}

function fetch_live_sheet(PDO $pdo, string $date): array {
    $stmt = $pdo->prepare("SELECT * FROM daily_cash_sheets WHERE sheet_date = ?");
    $stmt->execute([$date]);
    $sheet = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sheet) {
        return [
            'found' => false,
            'sheet_date' => $date,
            'previous_day_balance' => 0,
            'physical_drawer_amount' => 0,
            'notes' => '',
            'updated_at' => null,
            'rows' => []
        ];
    }

    $rowStmt = $pdo->prepare("
        SELECT *
        FROM daily_cash_sheet_rows
        WHERE sheet_id = ?
        ORDER BY row_order ASC, id ASC
    ");
    $rowStmt->execute([(int)$sheet['id']]);

    $sheet['rows'] = $rowStmt->fetchAll(PDO::FETCH_ASSOC);
    $sheet['found'] = true;
    return $sheet;
}

$date = valid_date_view($_GET['date'] ?? date('Y-m-d'));

if (isset($_GET['ajax']) && $_GET['ajax'] === 'sheet') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        echo json_encode(fetch_live_sheet($pdo, $date), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'found' => false,
            'error' => $e->getMessage(),
            'rows' => []
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Daily Cash Sheet Live View</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>

:root{--sidebar:#0b1220;--bg:#f3f6fb;--card:#fff;--text:#172033;--muted:#778199;--border:#e6ebf2;--blue:#3759e8;--green:#16a36a;--orange:#f59e0b;--red:#e5484d;--tamara:#f1506f;--shadow:0 8px 28px rgba(34,51,84,.07)}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Inter,system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif;color:var(--text)}
.app{display:flex;min-height:100vh}.sidebar{width:270px;background:linear-gradient(180deg,#0b1220 0%,#101a2e 100%);color:#fff;position:fixed;inset:0 auto 0 0;overflow-y:auto;padding:20px 14px;z-index:1040;box-shadow:8px 0 26px rgba(13,24,45,.12)}
.brand{display:flex;gap:12px;align-items:center;padding:4px 8px 20px;border-bottom:1px solid rgba(255,255,255,.07);margin-bottom:10px}.brand-mark{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#eef2ff,#fff);color:#263fbb;display:grid;place-items:center;font-weight:900;box-shadow:0 6px 20px #0002}.brand-title{font-weight:850;line-height:1.05}.brand-sub{color:#8390a8;font-size:.76rem;margin-top:5px}.nav-label{color:#64748b;font-size:.67rem;font-weight:850;text-transform:uppercase;letter-spacing:.1em;padding:17px 12px 7px}.side-link{display:flex;align-items:center;gap:11px;color:#b9c3d5;text-decoration:none;padding:10px 12px;border-radius:11px;margin:3px 0;font-size:.88rem;font-weight:650;transition:.15s}.side-link:hover{background:#ffffff0d;color:#fff;transform:translateX(2px)}.side-link.active{background:linear-gradient(135deg,#3154e8,#536df0);color:#fff;box-shadow:0 8px 20px rgba(49,84,232,.24)}.side-link i{font-size:1.02rem;width:20px;text-align:center}.role-badge{font-size:.62rem;margin-left:auto;color:#b7c4ff;background:#ffffff0c;padding:3px 6px;border-radius:7px}
.main{margin-left:270px;width:calc(100% - 270px);padding:22px 20px 34px;min-width:0}.topbar{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:16px}.welcome h1{font-size:1.55rem;font-weight:850;margin:0;letter-spacing:-.03em}.welcome p{margin:4px 0 0;color:var(--muted);font-size:.86rem}.user-chip{background:#fff;border:1px solid var(--border);box-shadow:var(--shadow);border-radius:14px;padding:8px 11px;display:flex;align-items:center;gap:10px}.avatar{width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,#e0e7ff,#eef2ff);color:#3446b4;display:grid;place-items:center;font-weight:850}.mobile-toggle{display:none}.sidebar-hide-toggle{display:inline-flex}.app.sidebar-hidden .sidebar{transform:translateX(-100%)}.app.sidebar-hidden .main{margin-left:0;width:100%}html[dir="rtl"] .app.sidebar-hidden .sidebar{transform:translateX(100%)}html[dir="rtl"] .app.sidebar-hidden .main{margin-right:0;width:100%}
.cash-hero{background:linear-gradient(120deg,#263aa8 0%,#3b5ae7 55%,#7384ff 100%);border-radius:20px;padding:18px 20px;color:#fff;box-shadow:0 14px 30px rgba(45,72,191,.18);position:relative;overflow:hidden;margin-bottom:16px}.cash-hero:after{content:"";position:absolute;width:220px;height:220px;border-radius:50%;background:#ffffff10;right:-70px;top:-100px}.cash-hero>*{position:relative;z-index:1}.cash-hero .kicker{font-size:.7rem;text-transform:uppercase;letter-spacing:.09em;font-weight:800;color:#dbe3ff}.cash-hero h2{font-size:1.35rem;font-weight:850;margin:4px 0}.cash-hero p{font-size:.82rem;color:#dce4ff;margin:0}.hero-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:flex-end}.hero-actions .btn{border-radius:10px;font-weight:700;font-size:.82rem}
.control-card,.sheet-card{background:#fff;border:1px solid var(--border);box-shadow:var(--shadow);border-radius:18px}.control-card{padding:14px 16px;margin-bottom:14px}.sheet-card{padding:12px;overflow:hidden}.scroll{overflow:auto;border-radius:12px}.sheet{min-width:1670px;width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;background:#fff}.sheet th,.sheet td{border-right:1px solid #d9dee8;border-bottom:1px solid #d9dee8;height:38px;padding:0;text-align:center}.sheet th:first-child,.sheet td:first-child{border-left:1px solid #d9dee8}.sheet thead tr:first-child th{border-top:1px solid #d9dee8}.sheet input,.sheet textarea{width:100%;height:100%;border:0;background:transparent;text-align:center;padding:6px;outline:none}.sheet input:focus{box-shadow:inset 0 0 0 2px rgba(55,89,232,.35);background:#fff}.code{background:#eef1f5}.cash,.cost,.profit,.amt{background:#fff3c8}.visa{background:#dff3d0}.tamara{background:#f1506f;color:#fff}.tamara input{color:#fff}.divider{background:#e5484d}.section{background:#f3e2a8;font-weight:800}.desc{background:#edf5fc}.notes{background:#e5f1db}.notesHead{background:#f9dfcf}.top{background:#eef2ff;font-weight:850;color:#263fbb}.summary{background:#dff3d0;font-size:1.15rem;font-weight:850}.drawer{background:#f9dfcf;font-weight:850}.diff{background:#ffd7bd;font-weight:850}.neg{color:#b00020}.save-indicator{min-width:150px;text-align:center;padding:8px 10px;border-radius:9px}.lang-switch{display:inline-flex;border:1px solid #d9dfeb;border-radius:10px;overflow:hidden;background:#fff}.lang-switch button{border:0;background:#fff;padding:7px 12px;font-weight:750;font-size:.8rem}.lang-switch button.active{background:#3154e8;color:#fff}.form-control,.form-select{border-radius:10px;border-color:#dce2ec}.form-control:focus{border-color:#9caefc;box-shadow:0 0 0 .2rem rgba(55,89,232,.10)}
html[dir="rtl"] body{direction:rtl;text-align:right;font-family:Tahoma,Arial,sans-serif}html[dir="rtl"] .sidebar{inset:0 0 0 auto}html[dir="rtl"] .main{margin-left:0;margin-right:270px}html[dir="rtl"] .role-badge{margin-left:0;margin-right:auto}
@media(max-width:991px){.sidebar{transform:translateX(-100%);transition:.2s}.sidebar.open{transform:translateX(0)}.main{margin-left:0;width:100%;padding:14px}.mobile-toggle{display:inline-flex}.topbar{align-items:flex-start}.user-chip .user-text{display:none}.hero-actions{justify-content:flex-start;margin-top:14px}html[dir="rtl"] .sidebar{transform:translateX(100%)}html[dir="rtl"] .sidebar.open{transform:translateX(0)}html[dir="rtl"] .main{margin-right:0}}
@media print{.no-print,.sidebar,.topbar,.cash-hero,.control-card{display:none!important}.main{margin:0!important;width:100%!important;padding:0!important}.sheet-card{border:0;box-shadow:none;padding:0}.scroll{overflow:visible}.sheet{min-width:0;width:100%}}

.cost-picker-backdrop{position:fixed;inset:0;background:rgba(11,18,32,.52);backdrop-filter:blur(3px);z-index:3000;display:none;align-items:center;justify-content:center;padding:18px}
.cost-picker-backdrop.show{display:flex}
.cost-picker{width:min(720px,96vw);max-height:82vh;overflow:hidden;background:#fff;border:1px solid #e6ebf2;border-radius:20px;box-shadow:0 28px 80px rgba(11,18,32,.28)}
.cost-picker-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:20px 22px 16px;border-bottom:1px solid #eef1f5}
.cost-picker-title{font-size:1.08rem;font-weight:850;margin:0;color:#172033}
.cost-picker-sub{font-size:.78rem;color:#778199;margin-top:4px}
.cost-picker-close{border:0;background:#f2f4f8;width:36px;height:36px;border-radius:10px;color:#667085}
.cost-picker-list{padding:14px;overflow:auto;max-height:58vh}
.cost-option{width:100%;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:center;border:1px solid #e5eaf1;background:#fff;border-radius:15px;padding:15px 16px;margin-bottom:10px;text-align:left;transition:.15s}
.cost-option:hover,.cost-option:focus{border-color:#8da2ff;box-shadow:0 8px 24px rgba(55,89,232,.10)}
.cost-option-name{font-weight:800;color:#172033}
.cost-option-meta{display:flex;flex-wrap:wrap;gap:7px;margin-top:7px}
.cost-chip{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:750;padding:4px 8px;border-radius:999px;background:#f2f5fa;color:#5f6c80}
.cost-chip.stock{background:#ecfdf3;color:#087443}.cost-chip.out{background:#fff1f2;color:#b42318}
.cost-option-price{text-align:right;white-space:nowrap}.cost-option-price strong{display:block;font-size:1.25rem;color:#1d4ed8}.cost-option-price span{font-size:.72rem;color:#8a94a6}
@media(max-width:600px){.cost-option{grid-template-columns:1fr}.cost-option-price{text-align:left}}


.code-cell-wrap{display:block}
.code-cell-wrap .code-input{width:100%;min-width:0}



/* ===== Live View additions on top of Admin Daily Cash UI ===== */
.live-dot{
    display:inline-block;width:9px;height:9px;border-radius:50%;
    background:#16a36a;margin-inline-end:6px;animation:pulse 1.5s infinite
}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
.live-status{
    display:inline-flex;align-items:center;padding:7px 11px;border-radius:10px;
    background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.20);
    color:#fff;font-size:.78rem;font-weight:750
}
.live-status .live-dot{background:#8cffb5}

.dcv-kpis{margin-bottom:14px}
.dcv-kpi{
    position:relative;overflow:hidden;border:0;border-radius:16px;min-height:108px;
    padding:15px 16px;color:#fff;box-shadow:0 10px 26px rgba(16,24,40,.12);
    transition:transform .18s ease,box-shadow .18s ease
}
.dcv-kpi:hover{transform:translateY(-3px);box-shadow:0 15px 32px rgba(16,24,40,.17)}
.dcv-kpi:after{content:"";position:absolute;width:90px;height:90px;border-radius:50%;right:-24px;bottom:-35px;background:rgba(255,255,255,.14)}
.dcv-kpi>*{position:relative;z-index:1}
.dcv-kpi-label{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:rgba(255,255,255,.84)}
.dcv-kpi-value{font-size:1.35rem;font-weight:900;line-height:1.1;margin-top:7px}
.dcv-green{background:linear-gradient(135deg,#10986a,#2bbf8a)}
.dcv-blue{background:linear-gradient(135deg,#3154e8,#667eea)}
.dcv-pink{background:linear-gradient(135deg,#f1506f,#df3d60)}
.dcv-red{background:linear-gradient(135deg,#d94a62,#f06a7d)}
.dcv-orange{background:linear-gradient(135deg,#d98b16,#f6b73c)}
.dcv-purple{background:linear-gradient(135deg,#7652d9,#9b72ef)}
.dcv-cyan{background:linear-gradient(135deg,#0e8fa8,#29b8d2)}

.readonly-sheet .sheet td{padding:6px}
.readonly-sheet .sheet tbody td{font-size:.84rem}
.readonly-sheet .sheet-card{padding:12px}
.readonly-sheet #generalNotes{
    min-height:70px;border:1px solid #dce2ec;border-radius:10px;background:#f8fafc;padding:10px
}
@media print{.dcv-kpis{display:none!important}}

</style>

<style>
/* Daily Cash View: full-width admin-style layout without sidebar */
.no-sidebar-app{display:block!important;min-height:100vh}
.no-sidebar-app .main{
    margin-left:0!important;
    margin-right:0!important;
    width:100%!important;
    max-width:none!important;
    padding:22px 20px 34px!important;
}
html[dir="rtl"] .no-sidebar-app .main{
    margin-left:0!important;
    margin-right:0!important;
}
@media(max-width:991px){
    .no-sidebar-app .main{padding:14px!important}
}
</style>


<style>
/* Full browser-screen Daily Cash View */
html,body{
    width:100%!important;
    max-width:none!important;
    min-height:100%!important;
    margin:0!important;
    padding:0!important;
}
body{overflow-x:hidden!important}
.no-sidebar-app{
    width:100vw!important;
    max-width:none!important;
    min-height:100vh!important;
    margin:0!important;
    padding:0!important;
}
.no-sidebar-app .main{
    width:100%!important;
    max-width:none!important;
    min-height:100vh!important;
    margin:0!important;
    padding:12px!important;
}
.no-sidebar-app .topbar,
.no-sidebar-app .cash-hero,
.no-sidebar-app .control-card,
.no-sidebar-app .sheet-card,
.no-sidebar-app .dcv-kpis{
    width:100%!important;
    max-width:none!important;
}
.no-sidebar-app .sheet-card{margin-left:0!important;margin-right:0!important}
.no-sidebar-app .scroll{
    width:100%!important;
    max-width:none!important;
    overflow-x:auto!important;
}
.no-sidebar-app .sheet{
    width:100%!important;
    max-width:none!important;
}
@media(min-width:1400px){
    .no-sidebar-app .main{padding:14px 16px!important}
}
@media(max-width:767px){
    .no-sidebar-app .main{padding:7px!important}
}
</style>


<style>
/* ===== Layout correction: use the whole browser screen cleanly ===== */
html,body{
    width:100%!important;
    max-width:none!important;
    margin:0!important;
    padding:0!important;
}
body{background:#f3f6fb!important;overflow-x:hidden!important}
.no-sidebar-app{
    width:100%!important;
    max-width:none!important;
    margin:0!important;
}
.no-sidebar-app .main{
    width:100%!important;
    max-width:none!important;
    margin:0!important;
    padding:14px 18px 24px!important;
}

/* Remove the old centered/narrow desktop constraint */
.no-sidebar-app .topbar,
.no-sidebar-app .cash-hero,
.no-sidebar-app .control-card,
.no-sidebar-app .dcv-kpis,
.no-sidebar-app .sheet-card{
    width:100%!important;
    max-width:none!important;
    margin-left:0!important;
    margin-right:0!important;
}

/* Compact header */
.no-sidebar-app .topbar{
    min-height:auto!important;
    padding:4px 2px 12px!important;
}
.no-sidebar-app .welcome h1{font-size:1.45rem!important;margin-bottom:2px!important}
.no-sidebar-app .welcome p{margin-bottom:0!important}

/* Hero should not take too much vertical room */
.no-sidebar-app .cash-hero{
    padding:16px 20px!important;
    margin-bottom:12px!important;
    border-radius:16px!important;
}
.no-sidebar-app .cash-hero h2{font-size:1.35rem!important;margin-bottom:3px!important}
.no-sidebar-app .cash-hero p{margin-bottom:0!important}

/* Date controls use the screen better */
.no-sidebar-app .control-card{
    padding:13px 16px!important;
    margin-bottom:12px!important;
    border-radius:15px!important;
}

/* KPI cards: one clean row on large desktop */
.no-sidebar-app .dcv-kpis{
    display:grid!important;
    grid-template-columns:repeat(7,minmax(0,1fr))!important;
    gap:10px!important;
    margin-bottom:12px!important;
}
.no-sidebar-app .dcv-kpis > [class*="col-"]{
    width:auto!important;
    max-width:none!important;
    padding:0!important;
}
.no-sidebar-app .dcv-kpi{
    min-height:94px!important;
    height:100%!important;
    padding:13px 14px!important;
    border-radius:14px!important;
}
.no-sidebar-app .dcv-kpi-value{
    font-size:1.22rem!important;
    white-space:nowrap!important;
}

/* Table should fill the page, without the huge unused margins */
.no-sidebar-app .sheet-card{
    padding:10px!important;
    border-radius:15px!important;
}
.no-sidebar-app .scroll{
    width:100%!important;
    max-width:none!important;
    overflow-x:auto!important;
    border-radius:10px!important;
}
.no-sidebar-app table.sheet{
    width:100%!important;
    min-width:1380px!important;
    table-layout:fixed!important;
    margin:0!important;
}
.no-sidebar-app .sheet th,
.no-sidebar-app .sheet td{
    padding:6px 7px!important;
    vertical-align:middle!important;
}
.no-sidebar-app .sheet tbody td{
    font-size:.80rem!important;
}

/* Give Code/Name enough room; keep money columns compact */
.no-sidebar-app .sheet th:nth-child(1),
.no-sidebar-app .sheet td:nth-child(1){width:125px!important}
.no-sidebar-app .sheet th:nth-child(2),
.no-sidebar-app .sheet td:nth-child(2){width:210px!important}
.no-sidebar-app .sheet th:nth-child(3),
.no-sidebar-app .sheet td:nth-child(3),
.no-sidebar-app .sheet th:nth-child(4),
.no-sidebar-app .sheet td:nth-child(4),
.no-sidebar-app .sheet th:nth-child(5),
.no-sidebar-app .sheet td:nth-child(5),
.no-sidebar-app .sheet th:nth-child(6),
.no-sidebar-app .sheet td:nth-child(6),
.no-sidebar-app .sheet th:nth-child(7),
.no-sidebar-app .sheet td:nth-child(7){width:105px!important}

/* Responsive */
@media(max-width:1450px){
    .no-sidebar-app .dcv-kpis{grid-template-columns:repeat(4,minmax(0,1fr))!important}
}
@media(max-width:900px){
    .no-sidebar-app .main{padding:10px!important}
    .no-sidebar-app .dcv-kpis{grid-template-columns:repeat(2,minmax(0,1fr))!important}
}
@media(max-width:520px){
    .no-sidebar-app .dcv-kpis{grid-template-columns:1fr!important}
    .no-sidebar-app .cash-hero{padding:14px!important}
}
</style>


<style>
/* Final full-width override: remove remaining left/right desktop gutter */
html, body{
    width:100vw!important;
    max-width:100vw!important;
    margin:0!important;
    padding:0!important;
}
.no-sidebar-app,
.no-sidebar-app .main{
    width:100vw!important;
    max-width:100vw!important;
    margin:0!important;
}
.no-sidebar-app .main{
    padding:8px 6px 18px!important;
}
.no-sidebar-app .topbar,
.no-sidebar-app .cash-hero,
.no-sidebar-app .control-card,
.no-sidebar-app .dcv-kpis,
.no-sidebar-app .sheet-card{
    width:100%!important;
    max-width:100%!important;
    margin-left:0!important;
    margin-right:0!important;
}
.no-sidebar-app .sheet-card{
    padding:6px!important;
}
.no-sidebar-app .scroll{
    width:100%!important;
    max-width:100%!important;
}
.no-sidebar-app table.sheet{
    width:100%!important;
    min-width:100%!important;
}
@media(min-width:1200px){
    .no-sidebar-app .main{padding-left:6px!important;padding-right:6px!important}
}
</style>


<style>
/* ===== HARD RESET: remove the old 270px sidebar reservation ===== */
body,
body .app,
body .no-sidebar-app,
body main.main,
body .main{
    margin-left:0!important;
    margin-right:0!important;
    left:0!important;
    right:auto!important;
    width:100%!important;
    max-width:none!important;
}

/* Admin CSS defines .main { margin-left:270px; width:calc(...)}.
   This override removes it completely for the read-only view. */
body .app.no-sidebar-app{
    display:block!important;
    width:100%!important;
    min-width:100%!important;
    padding:0!important;
}
body .app.no-sidebar-app > main.main{
    margin:0!important;
    width:100%!important;
    min-width:100%!important;
    max-width:none!important;
    padding:10px 12px 20px!important;
    transform:none!important;
}

/* Prevent any inherited/shared sidebar stylesheet from shifting content */
body.has-shared-sidebar > .wrap,
body.has-shared-sidebar > .container-fluid,
body.has-shared-sidebar .main{
    margin-left:0!important;
    margin-right:0!important;
    width:100%!important;
    max-width:none!important;
}

/* Make every major section span the available width */
body .main > .topbar,
body .main > .cash-hero,
body .main > .control-card,
body .main > .dcv-kpis,
body .main > .sheet-card{
    width:100%!important;
    max-width:none!important;
    margin-left:0!important;
    margin-right:0!important;
}

/* The table should consume the full viewport, with only tiny page padding */
body .sheet-card,
body .scroll,
body table.sheet{
    width:100%!important;
    max-width:none!important;
}

@media(min-width:992px){
    body .app.no-sidebar-app > main.main{
        padding-left:12px!important;
        padding-right:12px!important;
    }
}
</style>


<style>
.dcv-logout-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    min-height:42px;
    padding:9px 14px;
    margin-left:9px;
    border-radius:12px;
    background:linear-gradient(135deg,#e5484d,#f05b61);
    color:#fff!important;
    text-decoration:none!important;
    font-size:.82rem;
    font-weight:800;
    box-shadow:0 7px 18px rgba(229,72,77,.20);
    transition:transform .15s ease,box-shadow .15s ease;
}
.dcv-logout-btn:hover{
    color:#fff!important;
    transform:translateY(-1px);
    box-shadow:0 10px 22px rgba(229,72,77,.28);
}
.dcv-logout-btn i{font-size:1rem}
html[dir="rtl"] .dcv-logout-btn{margin-left:0;margin-right:9px}
@media(max-width:600px){
    .dcv-logout-btn span{display:none}
    .dcv-logout-btn{width:42px;padding:8px}
}
</style>


<style>
.dcv-logout-under{
    display:inline-flex;
    align-items:center;
    gap:4px;
    margin-top:4px;
    padding:3px 8px;
    border-radius:7px;
    background:#fee2e2;
    color:#dc3545!important;
    text-decoration:none!important;
    font-size:.65rem;
    line-height:1.2;
    font-weight:800;
    white-space:nowrap;
}
.dcv-logout-under:hover{
    background:#dc3545;
    color:#fff!important;
}
.dcv-logout-under i{font-size:.72rem}
.user-chip{align-items:flex-start!important}
</style>


<link rel="stylesheet" href="sidebar_shared.css">

<style>
/* Daily Cash View:
   Admin = shared sidebar visible
   Public/View = full-width without sidebar */
body .app{
    width:100%!important;
    min-height:100vh!important;
}

/* Admin layout */
body .app.with-admin-sidebar{
    display:flex!important;
}
body .app.with-admin-sidebar > main.main{
    margin-left:270px!important;
    margin-right:0!important;
    width:calc(100% - 270px)!important;
    max-width:none!important;
    min-height:100vh!important;
    padding:10px 12px 20px!important;
}
html[dir="rtl"] body .app.with-admin-sidebar > main.main{
    margin-left:0!important;
    margin-right:270px!important;
    width:calc(100% - 270px)!important;
}

/* Public / Viewer layout */
body .app.without-admin-sidebar{
    display:block!important;
}
body .app.without-admin-sidebar > main.main{
    margin-left:0!important;
    margin-right:0!important;
    width:100%!important;
    max-width:none!important;
    min-height:100vh!important;
    padding:10px 12px 20px!important;
}

/* Major sections fill the available area */
body .main > .topbar,
body .main > .cash-hero,
body .main > .control-card,
body .main > .dcv-kpis,
body .main > .sheet-card{
    width:100%!important;
    max-width:none!important;
    margin-left:0!important;
    margin-right:0!important;
}
body .sheet-card,
body .scroll,
body table.sheet{
    width:100%!important;
    max-width:none!important;
}
body .scroll{overflow-x:auto!important}

@media(max-width:991px){
    body .app.with-admin-sidebar > main.main,
    html[dir="rtl"] body .app.with-admin-sidebar > main.main,
    body .app.without-admin-sidebar > main.main{
        margin-left:0!important;
        margin-right:0!important;
        width:100%!important;
        padding:10px!important;
    }
}
</style>

</head>
<body class="readonly-sheet">
<div class="app <?= $showAdminSidebar ? 'with-admin-sidebar' : 'without-admin-sidebar' ?>">
<?php if ($showAdminSidebar): ?>
<?php $role = 'admin'; include __DIR__ . '/sidebar.php'; ?>
<?php endif; ?>
<main class="main">
    <div class="topbar no-print">
        <div class="d-flex gap-2 align-items-start">
            <div class="welcome">
                <h1 data-i18n="title">Daily Cash Sheet — Live View</h1>
                <p><span class="live-dot"></span><span data-i18n="subtitle">Updates automatically every 2 seconds</span> · <?=h(date('l, d F Y', strtotime($date)))?></p>
            </div>
        </div>
        <div class="user-chip">
            <div class="avatar"><?= $showAdminSidebar ? 'A' : 'V' ?></div>
            <div class="user-text">
                <?php if ($showAdminSidebar): ?>
                    <div class="fw-bold small"><?=h((string)($_SESSION['username'] ?? 'Admin'))?></div>
                    <div class="text-muted" style="font-size:.7rem">Admin</div>
                <?php else: ?>
                    <div class="fw-bold small">Public View</div>
                    <div class="text-muted" style="font-size:.7rem">Viewer</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <section class="cash-hero no-print">
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <div class="kicker">Rukn Al Lotus · POS</div>
                <h2 data-i18n="cash_control">Daily Cash Control</h2>
                <p data-i18n="cash_control_sub">Read-only live view of sales payments, costs and drawer movements.</p>
            </div>
            <div class="col-lg-5">
                <div class="hero-actions">
                    <div class="lang-switch">
                        <button type="button" id="langEnglish" onclick="setLanguage('en')">English</button>
                        <button type="button" id="langArabic" onclick="setLanguage('ar')">العربية</button>
                    </div>
                    <div class="live-status"><span class="live-dot"></span><span id="heroLiveStatus">Live</span></div>
                </div>
            </div>
        </div>
    </section>

    <div class="control-card no-print">
        <form method="get" class="row g-2 align-items-end mb-0">
            <div class="col-md-4 col-lg-3">
                <label class="form-label fw-bold mb-1" data-i18n="date">Date</label>
                <input type="date" name="date" value="<?=h($date)?>" class="form-control">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" data-i18n="load">Load</button>
            </div>
            <div class="col-md-5 ms-md-auto d-flex align-items-end">
                <div id="lastUpdate" class="alert alert-light border py-2 mb-0 w-100" data-i18n="waiting">Waiting for data…</div>
            </div>
        </form>
    </div>

    <div class="row g-3 dcv-kpis no-print">
        <div class="col-xl col-md-4 col-6"><div class="dcv-kpi dcv-green"><div class="dcv-kpi-label" data-i18n="cash">Cash</div><div class="dcv-kpi-value"><span id="cardCashTotal">0</span> AED</div></div></div>
        <div class="col-xl col-md-4 col-6"><div class="dcv-kpi dcv-blue"><div class="dcv-kpi-label" data-i18n="visa">Visa</div><div class="dcv-kpi-value"><span id="cardVisaTotal">0</span> AED</div></div></div>
        <div class="col-xl col-md-4 col-6"><div class="dcv-kpi dcv-pink"><div class="dcv-kpi-label" data-i18n="tamara">Tamara / Tabby</div><div class="dcv-kpi-value"><span id="cardTamaraTotal">0</span> AED</div></div></div>
        <div class="col-xl col-md-4 col-6"><div class="dcv-kpi dcv-red"><div class="dcv-kpi-label" data-i18n="cost">Cost</div><div class="dcv-kpi-value"><span id="cardCostTotal">0</span> AED</div></div></div>
        <div class="col-xl col-md-4 col-6"><div class="dcv-kpi dcv-orange"><div class="dcv-kpi-label" data-i18n="profit">Profit</div><div class="dcv-kpi-value"><span id="cardProfitTotal">0</span> AED</div></div></div>
        <div class="col-xl col-md-4 col-6"><div class="dcv-kpi dcv-purple"><div class="dcv-kpi-label" data-i18n="remaining">Remaining in Drawer</div><div class="dcv-kpi-value"><span id="cardRemaining">0</span> AED</div></div></div>
        <div class="col-xl col-md-4 col-6"><div class="dcv-kpi dcv-cyan"><div class="dcv-kpi-label" data-i18n="difference">Drawer Difference</div><div class="dcv-kpi-value"><span id="cardDifference">0</span> AED</div></div></div>
    </div>

    <div class="sheet-card">

    <div class="scroll">
        <table class="sheet">
            <thead>
                <tr>
                    <th colspan="7" class="top">
                        <span data-i18n="previous_balance">Previous Day Balance</span>:
                        <span id="previousBalance">0</span>
                    </th>
                    <th class="divider"></th>
                    <th colspan="2" class="section" data-i18n="withdrawal">Withdrawal</th>
                    <th colspan="2" class="section" data-i18n="addition">Addition</th>
                    <th class="notesHead" data-i18n="notes">Notes</th>
                </tr>
                <tr>
                    <th class="code" data-i18n="code">Code</th>
                    <th class="code" data-i18n="name">Name</th>
                    <th class="cash" data-i18n="cash">Cash</th>
                    <th class="visa" data-i18n="visa">Visa (2.5% Fee)</th>
                    <th class="tamara" data-i18n="tamara">Tamara / Tabby</th>
                    <th class="cost" data-i18n="cost">Cost</th>
                    <th class="profit" data-i18n="profit">Profit</th>
                    <th class="divider"></th>
                    <th class="section" data-i18n="amount">Amount</th>
                    <th class="section" data-i18n="description">Description</th>
                    <th class="section" data-i18n="amount">Amount</th>
                    <th class="section" data-i18n="description">Description</th>
                    <th class="notesHead" data-i18n="notes">Notes</th>
                </tr>
            </thead>

            <tbody id="liveRows"></tbody>

            <tfoot>
                <tr>
                    <th class="code" data-i18n="totals">Totals</th>
                    <th class="code"></th>
                    <th class="cash" id="cashTotal">0</th>
                    <th class="visa" id="visaTotal">0</th>
                    <th class="tamara" id="tamaraTotal">0</th>
                    <th class="cost" id="costTotal">0</th>
                    <th class="profit" id="profitTotal">0</th>
                    <th class="divider"></th>
                    <th class="amt" id="withdrawalTotal">0</th>
                    <th class="section" data-i18n="withdrawal_total">Withdrawal Total</th>
                    <th class="amt" id="additionTotal">0</th>
                    <th class="section" data-i18n="addition_total">Addition Total</th>
                    <th class="notes"></th>
                </tr>

                <tr>
                    <th colspan="9" class="summary" data-i18n="remaining">Remaining in Drawer</th>
                    <th colspan="4" class="summary" id="remaining">0</th>
                </tr>

                <tr>
                    <th colspan="9" class="drawer">
                        <span data-i18n="physical">Physical Drawer Amount</span>:
                        <span id="physicalDrawer">0</span>
                    </th>
                    <th colspan="2" class="diff" data-i18n="difference">Drawer Difference</th>
                    <th colspan="2" class="diff" id="difference">0</th>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="mt-3">
        <label class="form-label fw-bold" data-i18n="general_notes">General Notes</label>
        <div id="generalNotes" class="form-control bg-light" style="min-height:70px"></div>
    </div>

    </div>
</main>
</div>

<script>
const dateValue = <?=json_encode($date)?>;

const translations = {
    en: {
        title:'Daily Cash Sheet — Live View',
        subtitle:'Updates automatically every 2 seconds',
        cash_control:'Daily Cash Control',
        cash_control_sub:'Read-only live view of sales payments, costs and drawer movements.',
        edit:'Edit Sheet',
        back:'Back',
        print:'Print',
        date:'Date',
        load:'Load',
        waiting:'Waiting for data…',
        previous_balance:'Previous Day Balance',
        withdrawal:'Withdrawal',
        addition:'Addition',
        notes:'Notes',
        code:'Code',
        name:'Name',
        cash:'Cash',
        visa:'Visa (2.5% Fee)',
        tamara:'Tamara / Tabby',
        cost:'Cost',
        profit:'Profit',
        amount:'Amount',
        description:'Description',
        totals:'Totals',
        withdrawal_total:'Withdrawal Total',
        addition_total:'Addition Total',
        remaining:'Remaining in Drawer',
        physical:'Physical Drawer Amount',
        difference:'Drawer Difference',
        general_notes:'General Notes',
        no_rows:'No saved rows for this date.',
        database_updated:'Database updated',
        no_sheet:'No saved sheet for this date yet.',
        load_error:'Unable to load live data.'
    },
    ar: {
        title:'كشف الصندوق اليومي — العرض المباشر',
        subtitle:'يتم التحديث تلقائياً كل ثانيتين',
        cash_control:'إدارة الصندوق اليومية',
        cash_control_sub:'عرض مباشر للقراءة فقط للمبيعات والتكاليف وحركة الصندوق.',
        edit:'تعديل الكشف',
        back:'رجوع',
        print:'طباعة',
        date:'التاريخ',
        load:'تحميل',
        waiting:'بانتظار البيانات…',
        previous_balance:'رصيد اليوم السابق',
        withdrawal:'السحب',
        addition:'الإضافة',
        notes:'ملاحظات',
        code:'الكود',
        name:'الاسم',
        cash:'نقدي',
        visa:'فيزا (رسوم 2.5%)',
        tamara:'تمارا / تابي',
        cost:'التكلفة',
        profit:'الربح',
        amount:'المبلغ',
        description:'الوصف',
        totals:'الإجمالي',
        withdrawal_total:'إجمالي السحب',
        addition_total:'إجمالي الإضافة',
        remaining:'المتبقي في الصندوق',
        physical:'المبلغ الفعلي في الصندوق',
        difference:'فرق الصندوق',
        general_notes:'ملاحظات عامة',
        no_rows:'لا توجد صفوف محفوظة لهذا التاريخ.',
        database_updated:'آخر تحديث لقاعدة البيانات',
        no_sheet:'لا يوجد كشف محفوظ لهذا التاريخ.',
        load_error:'تعذر تحميل البيانات المباشرة.'
    }
};

let currentLanguage = localStorage.getItem('dailyCashLanguage') || 'ar';

function tr(key) {
    return translations[currentLanguage][key] || key;
}

function setLanguage(language) {
    currentLanguage = language === 'ar' ? 'ar' : 'en';
    localStorage.setItem('dailyCashLanguage', currentLanguage);

    document.documentElement.lang = currentLanguage;
    document.documentElement.dir = currentLanguage === 'ar' ? 'rtl' : 'ltr';

    document.querySelectorAll('[data-i18n]').forEach(element => {
        const key = element.dataset.i18n;
        if (translations[currentLanguage][key]) {
            element.textContent = translations[currentLanguage][key];
        }
    });

    document.getElementById('langEnglish').classList.toggle('active', currentLanguage === 'en');
    document.getElementById('langArabic').classList.toggle('active', currentLanguage === 'ar');

    document.title = tr('title');
    refreshSheet();
}

const n = value => {
    const parsed = parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const f = value => {
    const rounded = Math.round(value * 100) / 100;
    return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2);
};

const esc = value => String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');

const display = value => n(value) === 0 ? '' : f(n(value));

async function refreshSheet() {
    try {
        const response = await fetch(
            'daily_cash_sheet_view.php?ajax=sheet&date=' + encodeURIComponent(dateValue),
            { cache:'no-store' }
        );

        const data = await response.json();

        if (!response.ok || data.error) {
            throw new Error(data.error || tr('load_error'));
        }

        document.getElementById('previousBalance').textContent = f(n(data.previous_day_balance));
        document.getElementById('physicalDrawer').textContent = f(n(data.physical_drawer_amount));
        document.getElementById('generalNotes').textContent = data.notes || '';

        const rows = Array.isArray(data.rows) ? data.rows : [];
        let html = '';

        let cash = 0;
        let visa = 0;
        let tamara = 0;
        let cost = 0;
        let profit = 0;
        let withdrawals = 0;
        let additions = 0;

        rows.forEach(row => {
            cash += n(row.cash_amount);
            visa += n(row.visa_amount);
            tamara += n(row.tamara_amount);
            cost += n(row.cost_amount);
            profit += n(row.profit_amount);
            withdrawals += n(row.withdrawal_amount);
            additions += n(row.addition_amount);

            html += `<tr>
                <td class="code">${esc(row.code)}</td>
                <td class="code">${esc(row.item_name)}</td>
                <td class="cash">${display(row.cash_amount)}</td>
                <td class="visa">${display(row.visa_amount)}</td>
                <td class="tamara">${display(row.tamara_amount)}</td>
                <td class="cost">${display(row.cost_amount)}</td>
                <td class="profit">${display(row.profit_amount)}</td>
                <td class="divider"></td>
                <td class="amt">${display(row.withdrawal_amount)}</td>
                <td class="desc">${esc(row.withdrawal_name)}</td>
                <td class="amt">${display(row.addition_amount)}</td>
                <td class="desc">${esc(row.addition_name)}</td>
                <td class="notes">${esc(row.row_notes)}</td>
            </tr>`;
        });

        if (!html) {
            html = `<tr>
                <td colspan="13" class="py-4 text-muted">${esc(tr('no_rows'))}</td>
            </tr>`;
        }

        document.getElementById('liveRows').innerHTML = html;
        document.getElementById('cashTotal').textContent = f(cash);
        document.getElementById('visaTotal').textContent = f(visa);
        document.getElementById('tamaraTotal').textContent = f(tamara);
        document.getElementById('costTotal').textContent = f(cost);
        document.getElementById('profitTotal').textContent = f(profit);
        document.getElementById('withdrawalTotal').textContent = f(withdrawals);
        document.getElementById('additionTotal').textContent = f(additions);

        // Update dashboard cards.
        document.getElementById('cardCashTotal').textContent = f(cash);
        document.getElementById('cardVisaTotal').textContent = f(visa);
        document.getElementById('cardTamaraTotal').textContent = f(tamara);
        document.getElementById('cardCostTotal').textContent = f(cost);
        document.getElementById('cardProfitTotal').textContent = f(profit);

        // Tamara/Tabby is non-cash and does not belong in the physical drawer.
        const remainingValue =
            n(data.previous_day_balance) +
            cash +
            additions -
            withdrawals;

        const differenceValue =
            n(data.physical_drawer_amount) -
            remainingValue;

        document.getElementById('remaining').textContent = f(remainingValue);
        document.getElementById('difference').textContent = f(differenceValue);
        document.getElementById('difference').classList.toggle('neg', differenceValue < 0);
        document.getElementById('cardRemaining').textContent = f(remainingValue);
        document.getElementById('cardDifference').textContent = f(differenceValue);

        document.getElementById('lastUpdate').textContent = data.updated_at
            ? tr('database_updated') + ': ' + data.updated_at
            : tr('no_sheet');

    } catch (error) {
        document.getElementById('lastUpdate').textContent = tr('load_error');
        console.error(error);
    }
}

setLanguage(currentLanguage);
setInterval(refreshSheet, 2000);
</script>

</body>
</html>
