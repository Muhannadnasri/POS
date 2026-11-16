<?php
// sidebar.php — shared Dashboard sidebar for all POS pages.
$sidebarRole = (($_SESSION['role'] ?? $_SESSION['pos_role'] ?? ($role ?? 'admin')) === 'viewer') ? 'viewer' : 'admin';
$sidebarCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
if (!function_exists('pos_sidebar_active')) {
    function pos_sidebar_active($page, $currentPage) {
        return $page === $currentPage ? ' active' : '';
    }
}
?>
<div id="sidebar" class="shared-sidebar-host">
<aside class="sidebar rl-sidebar no-print shared-sidebar" id="rlSidebar">
    <div class="brand rl-brand shared-brand">
        <img src="rukn_al_lotus_logo.png" class="sidebar-shop-logo" alt="Rukn Al Lotus Mobile Shop">
    </div>

    <div class="nav-label rl-nav-label">Overview</div>
    <a class="side-link rl-side-link<?=pos_sidebar_active('dashboard.php',$sidebarCurrentPage)?>" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('daily_cash_sheet_view.php',$sidebarCurrentPage)?>" href="daily_cash_sheet_view.php"><i class="bi bi-eye"></i><span>Daily Cash View</span></a>

    <?php if ($sidebarRole === 'admin'): ?>
    <div class="nav-label rl-nav-label">Sales & Cash</div>
    <a class="side-link rl-side-link<?=pos_sidebar_active('view_transactions.php',$sidebarCurrentPage)?>" href="view_transactions.php"><i class="bi bi-receipt"></i><span>Transactions</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('recharges.php',$sidebarCurrentPage)?>" href="recharges.php"><i class="bi bi-phone"></i><span>Recharges</span></a>

    <div class="nav-label rl-nav-label">Inventory</div>
    <a class="side-link rl-side-link<?=pos_sidebar_active('sales.php',$sidebarCurrentPage)?>" href="sales.php"><i class="bi bi-upc-scan"></i><span>Stock Check</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('add_product.php',$sidebarCurrentPage)?>" href="add_product.php"><i class="bi bi-plus-square"></i><span>Add Product</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('view_products.php',$sidebarCurrentPage)?>" href="view_products.php"><i class="bi bi-box-seam"></i><span>Products</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('phones.php',$sidebarCurrentPage)?>" href="phones.php"><i class="bi bi-phone-fill"></i><span>Phones</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('laptops.php',$sidebarCurrentPage)?>" href="laptops.php"><i class="bi bi-laptop"></i><span>Laptops</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('tools.php',$sidebarCurrentPage)?>" href="tools.php"><i class="bi bi-tools"></i><span>Tools</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('categories.php',$sidebarCurrentPage)?>" href="categories.php"><i class="bi bi-tags"></i><span>Categories</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('mobile_models.php',$sidebarCurrentPage)?>" href="mobile_models.php"><i class="bi bi-database"></i><span>Mobile Models</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('import_center.php',$sidebarCurrentPage)?>" href="import_center.php"><i class="bi bi-file-earmark-arrow-up"></i><span>Import / Export Center</span></a>

    <div class="nav-label rl-nav-label">Reports</div>
    <a class="side-link rl-side-link<?=pos_sidebar_active('reports.php',$sidebarCurrentPage)?>" href="reports.php"><i class="bi bi-bar-chart"></i><span>Reports</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('monthly_report.php',$sidebarCurrentPage)?>" href="monthly_report.php"><i class="bi bi-calendar3"></i><span>Monthly Report</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('supplier_report.php',$sidebarCurrentPage)?>" href="supplier_report.php"><i class="bi bi-truck"></i><span>Supplier Report</span></a>
    <a class="side-link rl-side-link<?=pos_sidebar_active('inventory_history.php',$sidebarCurrentPage)?>" href="inventory_history.php"><i class="bi bi-clock-history"></i><span>Inventory History</span></a>
    <?php endif; ?>

    <div class="nav-label rl-nav-label">Account</div>
    <a class="side-link rl-side-link" href="dashboard.php?logout=1"><i class="bi bi-box-arrow-left"></i><span>Logout</span><span class="role-badge rl-role"><?=htmlspecialchars(strtoupper($sidebarRole),ENT_QUOTES,'UTF-8')?></span></a>
</aside>
</div>
