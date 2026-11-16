<?php
require_once __DIR__ . '/session_keepalive.php';
// categories.php
require_once 'db.php';
session_start();

$message = "";
$edit_cat = null;

// 1. Handle Add Category
if (isset($_POST['add_category'])) {
    $category_name = trim($_POST['category_name']);
    
    if (!empty($category_name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO categories (category_name) VALUES (?)");
            $stmt->execute([$category_name]);
            $message = "<div class='alert alert-success'>Category added successfully!</div>";
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Category already exists or database error occurred.</div>";
        }
    }
}

// 2. Handle Edit (Step 1: Load existing)
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$id]);
    $edit_cat = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 2. Handle Edit (Step 2: Update database)
if (isset($_POST['update_category'])) {
    $id = intval($_POST['category_id']);
    $category_name = trim($_POST['category_name']);
    
    if (!empty($category_name) && $id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE categories SET category_name = ? WHERE id = ?");
            $stmt->execute([$category_name, $id]);
            $message = "<div class='alert alert-success'>Category updated successfully!</div>";
            header("Location: categories.php");
            exit;
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger'>Failed to update category.</div>";
        }
    }
}

// 3. Handle Delete
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        $message = "<div class='alert alert-success'>Category deleted successfully!</div>";
        header("Location: categories.php");
        exit;
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>Cannot delete category while products are assigned to it.</div>";
    }
}

// Fetch all categories for listing
$categories = [];
try {
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY category_name ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Handle error silently
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Categories - Rukn Al Lotus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ===== Rukn Al Lotus dashboard shell ===== */
:root{--rl-bg:#f3f6fb;--rl-card:#fff;--rl-text:#172033;--rl-muted:#778199;--rl-border:#e6ebf2;--rl-blue:#3759e8;--rl-shadow:0 8px 28px rgba(34,51,84,.07)}
*{box-sizing:border-box}body{margin:0!important;background:var(--rl-bg)!important;font-family:Inter,system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif!important;color:var(--rl-text)}
.rl-app{display:flex;min-height:100vh}.rl-sidebar{width:270px;background:linear-gradient(180deg,#0b1220 0%,#101a2e 100%);color:#fff;position:fixed;inset:0 auto 0 0;overflow-y:auto;padding:20px 14px;z-index:1040;box-shadow:8px 0 26px rgba(13,24,45,.12)}
.rl-brand{display:flex;gap:12px;align-items:center;padding:4px 8px 20px;border-bottom:1px solid rgba(255,255,255,.07);margin-bottom:10px}.rl-brand-mark{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#eef2ff,#fff);color:#263fbb;display:grid;place-items:center;font-weight:900;box-shadow:0 6px 20px #0002}.rl-brand-title{font-weight:850;line-height:1.05}.rl-brand-sub{color:#8390a8;font-size:.76rem;margin-top:5px}.rl-nav-label{color:#64748b;font-size:.67rem;font-weight:850;text-transform:uppercase;letter-spacing:.1em;padding:17px 12px 7px}.rl-side-link{display:flex;align-items:center;gap:11px;color:#b9c3d5;text-decoration:none;padding:10px 12px;border-radius:11px;margin:3px 0;font-size:.88rem;font-weight:650;transition:.15s}.rl-side-link:hover{background:#ffffff0d;color:#fff;transform:translateX(2px)}.rl-side-link.active{background:linear-gradient(135deg,#3154e8,#536df0);color:#fff;box-shadow:0 8px 20px rgba(49,84,232,.24)}.rl-side-link i{font-size:1.02rem;width:20px;text-align:center}.rl-role{font-size:.62rem;margin-left:auto;color:#b7c4ff;background:#ffffff0c;padding:3px 6px;border-radius:7px}
.rl-main{margin-left:270px;width:calc(100% - 270px);padding:22px 26px 34px}.rl-topbar{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:18px}.rl-welcome h1{font-size:1.55rem;font-weight:850;margin:0;letter-spacing:-.03em}.rl-welcome p{margin:4px 0 0;color:var(--rl-muted);font-size:.86rem}.rl-user-chip{background:#fff;border:1px solid var(--rl-border);box-shadow:var(--rl-shadow);border-radius:14px;padding:8px 11px;display:flex;align-items:center;gap:10px}.rl-avatar{width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,#e0e7ff,#eef2ff);color:#3446b4;display:grid;place-items:center;font-weight:850}.rl-mobile-toggle{display:none}.rl-page-content{min-width:0}
.rl-page-content>.container,.rl-page-content>.container-fluid{max-width:none!important;width:100%!important;padding-left:0!important;padding-right:0!important;margin-top:0!important}.rl-page-content .form-container{max-width:none!important;margin:0!important;border-radius:18px!important;border:1px solid var(--rl-border);box-shadow:var(--rl-shadow)!important}.rl-page-content .page-card,.rl-page-content .card{border-radius:18px!important;border-color:var(--rl-border)!important;box-shadow:var(--rl-shadow)}.rl-page-content .table-responsive{border-radius:14px}.rl-page-content .table thead th{font-size:.72rem;text-transform:uppercase;letter-spacing:.035em;color:#6f7a90;white-space:nowrap}.rl-page-content .table tbody td{vertical-align:middle}.rl-page-content .btn{border-radius:10px}.rl-page-content .form-control,.rl-page-content .form-select{border-radius:10px;border-color:#dce3ed}.rl-page-content .form-control:focus,.rl-page-content .form-select:focus{border-color:#8ea2ff;box-shadow:0 0 0 .2rem rgba(55,89,232,.12)}
@media(max-width:991px){.rl-sidebar{transform:translateX(-100%);transition:.2s}.rl-sidebar.open{transform:translateX(0)}.rl-main{margin-left:0;width:100%;padding:14px}.rl-mobile-toggle{display:inline-flex}.rl-topbar{align-items:flex-start}.rl-user-chip .rl-user-text{display:none}}
</style>
<link rel="stylesheet" href="sidebar_shared.css">
</head>
<body class="bg-light py-5">
<div class="rl-app"><?php include __DIR__ . '/sidebar.php'; ?><main class="rl-main"><div class="rl-topbar"><div class="d-flex gap-2 align-items-start"><button class="btn btn-light border rl-mobile-toggle" id="rlSidebarToggle" type="button"><i class="bi bi-list"></i></button><div class="rl-welcome"><h1>Product Categories</h1><p>Manage inventory categories used across the POS · <?=htmlspecialchars(date('l, d F Y'), ENT_QUOTES, 'UTF-8')?></p></div></div><div class="rl-user-chip"><div class="rl-avatar"><?=htmlspecialchars(strtoupper(substr((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'A'),0,1)), ENT_QUOTES, 'UTF-8')?></div><div class="rl-user-text"><div class="fw-bold small"><?=htmlspecialchars((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Admin'), ENT_QUOTES, 'UTF-8')?></div><div class="text-muted" style="font-size:.7rem"><?=htmlspecialchars(ucfirst((string)($_SESSION['role'] ?? 'admin')), ENT_QUOTES, 'UTF-8')?></div></div></div></div><div class="rl-page-content">

<div class="container" style="max-width: 800px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-primary m-0">Product Categories</h2>
            <p class="text-muted m-0">Add, edit, or delete inventory categories</p>
        </div>
        <a href="add_product.php" class="btn btn-outline-secondary">Back to Add Product</a>
    </div>

    <?php echo $message; ?>

    <div class="card shadow-sm border-0 mb-4 p-4">
        <h5 class="fw-bold mb-3">
            <?php echo $edit_cat ? "✏️ Edit Category (#" . $edit_cat['id'] . ")" : "➕ Add New Category"; ?>
        </h5>
        <form method="POST" action="categories.php">
            <?php if ($edit_cat): ?>
                <input type="hidden" name="category_id" value="<?php echo $edit_cat['id']; ?>">
            <?php endif; ?>
            
            <div class="input-group">
                <input type="text" class="form-control" name="category_name" 
                       value="<?php echo $edit_cat ? htmlspecialchars($edit_cat['category_name']) : ''; ?>" 
                       placeholder="Category Name (e.g., Mobile Accessories)" required>
                
                <?php if ($edit_cat): ?>
                    <button type="submit" name="update_category" class="btn btn-success fw-bold">Update</button>
                    <a href="categories.php" class="btn btn-outline-secondary">Cancel</a>
                <?php else: ?>
                    <button type="submit" name="add_category" class="btn btn-primary fw-bold">Save Category</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card shadow-sm border-0 p-4">
        <h5 class="fw-bold mb-3">Existing Categories</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 10%;">ID</th>
                        <th>Category Name</th>
                        <th class="text-end" style="width: 25%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted py-3">No categories found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><?php echo $cat['id']; ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                <td class="text-end">
                                    <a href="categories.php?action=edit&id=<?php echo $cat['id']; ?>" class="btn btn-sm btn-outline-primary me-1">Edit</a>
                                    <a href="categories.php?action=delete&id=<?php echo $cat['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this category?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</div></main></div>
<script>
(function(){
 const b=document.getElementById('rlSidebarToggle'), s=document.getElementById('rlSidebar');
 if(b&&s){b.addEventListener('click',()=>s.classList.toggle('open'));document.addEventListener('click',e=>{if(window.innerWidth<=991&&s.classList.contains('open')&&!s.contains(e.target)&&!b.contains(e.target))s.classList.remove('open')})}
})();
</script>
</body>
</html>