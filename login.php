<?php
// login.php - POS Rukn Al Lotus Admin / Viewer Login
require_once 'db.php';
session_start();

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ensure_pos_users_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        display_name VARCHAR(120) NOT NULL DEFAULT '',
        role ENUM('admin','viewer') NOT NULL DEFAULT 'viewer',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX idx_pos_users_role (role),
        INDEX idx_pos_users_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function seed_default_pos_users(PDO $pdo): void {
    $defaults = [
        [
            'username' => 'admin',
            'password' => 'Admin@123',
            'display_name' => 'Administrator',
            'role' => 'admin'
        ],
        [
            'username' => 'viewer',
            'password' => 'Viewer@123',
            'display_name' => 'Viewer',
            'role' => 'viewer'
        ]
    ];

    $check = $pdo->prepare("SELECT id FROM pos_users WHERE username = ? LIMIT 1");
    $insert = $pdo->prepare("INSERT INTO pos_users (username, password_hash, display_name, role, is_active) VALUES (?, ?, ?, ?, 1)");

    foreach ($defaults as $user) {
        $check->execute([$user['username']]);
        if (!$check->fetchColumn()) {
            $insert->execute([
                $user['username'],
                password_hash($user['password'], PASSWORD_DEFAULT),
                $user['display_name'],
                $user['role']
            ]);
        }
    }
}

function role_home(string $role): string {
    return $role === 'admin' ? 'sales.php' : 'daily_cash_sheet_view.php';
}

$error = '';
$setup_error = '';
$username = '';

try {
    ensure_pos_users_table($pdo);
    seed_default_pos_users($pdo);
} catch (Throwable $e) {
    $setup_error = 'Login database setup failed: ' . $e->getMessage();
}

// Already logged in: allow a normal visit to the login page to continue to the correct area.
if ($setup_error === '' && !empty($_SESSION['logged_in']) && !empty($_SESSION['role'])) {
    $role = ($_SESSION['role'] === 'admin') ? 'admin' : 'viewer';
    header('Location: ' . role_home($role));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setup_error === '') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, username, password_hash, display_name, role, is_active FROM pos_users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !(int)$user['is_active'] || !password_verify($password, (string)$user['password_hash'])) {
                $error = 'Invalid username or password.';
            } else {
                session_regenerate_id(true);

                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = (string)$user['username'];
                $_SESSION['display_name'] = (string)$user['display_name'];
                $_SESSION['role'] = ((string)$user['role'] === 'admin') ? 'admin' : 'viewer';
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();

                $update = $pdo->prepare("UPDATE pos_users SET last_login_at = NOW() WHERE id = ?");
                $update->execute([(int)$user['id']]);

                $redirect = role_home($_SESSION['role']);
                header('Location: ' . $redirect);
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Unable to sign in right now. Please check the database connection.';
        }
    }
}
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - POS Rukn Al Lotus</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root {
    --primary: #111827;
    --accent: #0d6efd;
    --surface: #ffffff;
    --muted: #6b7280;
    --border: #e5e7eb;
}
* { box-sizing: border-box; }
body {
    min-height: 100vh;
    margin: 0;
    background:
        radial-gradient(circle at 12% 10%, rgba(13,110,253,.13), transparent 28%),
        radial-gradient(circle at 88% 88%, rgba(241,80,111,.10), transparent 28%),
        #f5f7fb;
    font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Tahoma, Arial, sans-serif;
    color: var(--primary);
}
.login-shell {
    min-height: 100vh;
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(420px, .95fr);
}
.brand-panel {
    position: relative;
    padding: 56px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    overflow: hidden;
    background: linear-gradient(145deg, #111827 0%, #1f2937 55%, #172554 100%);
    color: #fff;
}
.brand-panel::after {
    content: "";
    position: absolute;
    width: 420px;
    height: 420px;
    border-radius: 50%;
    border: 80px solid rgba(255,255,255,.035);
    right: -150px;
    bottom: -160px;
}
.logo-box {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    font-size: 25px;
    font-weight: 900;
    background: #fff;
    color: #111827;
    box-shadow: 0 12px 40px rgba(0,0,0,.18);
}
.brand-title {
    max-width: 620px;
    font-size: clamp(2.3rem, 5vw, 4.5rem);
    line-height: .98;
    letter-spacing: -.055em;
    font-weight: 850;
    margin: 24px 0 20px;
}
.brand-copy {
    max-width: 570px;
    color: rgba(255,255,255,.70);
    font-size: 1.05rem;
    line-height: 1.7;
}
.role-preview {
    position: relative;
    z-index: 1;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}
.role-chip {
    padding: 10px 14px;
    border: 1px solid rgba(255,255,255,.14);
    border-radius: 999px;
    background: rgba(255,255,255,.07);
    backdrop-filter: blur(10px);
    color: rgba(255,255,255,.86);
    font-size: .88rem;
    font-weight: 700;
}
.login-panel {
    padding: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.login-card {
    width: 100%;
    max-width: 475px;
    background: rgba(255,255,255,.94);
    border: 1px solid rgba(229,231,235,.85);
    border-radius: 24px;
    padding: 34px;
    box-shadow: 0 24px 70px rgba(17,24,39,.10);
}
.lang-switch {
    display: inline-flex;
    background: #f3f4f6;
    border-radius: 10px;
    padding: 3px;
}
.lang-switch button {
    border: 0;
    background: transparent;
    color: #6b7280;
    font-size: .78rem;
    font-weight: 800;
    padding: 7px 11px;
    border-radius: 8px;
}
.lang-switch button.active {
    color: #111827;
    background: #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
}
.eyebrow {
    text-transform: uppercase;
    letter-spacing: .12em;
    color: var(--accent);
    font-size: .75rem;
    font-weight: 850;
}
.login-title {
    font-size: 2rem;
    line-height: 1.1;
    letter-spacing: -.035em;
    font-weight: 850;
    margin: 8px 0 8px;
}
.login-subtitle {
    color: var(--muted);
    margin-bottom: 28px;
}
.form-label {
    color: #374151;
    font-size: .88rem;
    font-weight: 750;
}
.input-wrap { position: relative; }
.form-control {
    min-height: 52px;
    border-radius: 12px;
    border-color: var(--border);
    padding-left: 46px;
    box-shadow: none !important;
}
html[dir="rtl"] .form-control {
    padding-left: 12px;
    padding-right: 46px;
}
.form-control:focus { border-color: #93b8ff; }
.field-icon {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    pointer-events: none;
}
html[dir="rtl"] .field-icon {
    left: auto;
    right: 15px;
}
.password-toggle {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    border: 0;
    background: transparent;
    color: #6b7280;
    font-size: .8rem;
    font-weight: 700;
    padding: 8px;
}
html[dir="rtl"] .password-toggle {
    right: auto;
    left: 10px;
}
.password-input { padding-right: 72px; }
html[dir="rtl"] .password-input { padding-right: 46px; padding-left: 72px; }
.login-btn {
    min-height: 52px;
    border-radius: 12px;
    font-weight: 800;
    box-shadow: 0 10px 24px rgba(13,110,253,.18);
}
.role-help {
    margin-top: 24px;
    padding-top: 22px;
    border-top: 1px solid var(--border);
}
.role-line {
    display: flex;
    gap: 11px;
    align-items: flex-start;
    color: #6b7280;
    font-size: .84rem;
    margin-top: 10px;
}
.role-dot {
    flex: 0 0 auto;
    width: 9px;
    height: 9px;
    margin-top: 5px;
    border-radius: 50%;
    background: #0d6efd;
}
.role-line.viewer .role-dot { background: #f1506f; }
.alert { border-radius: 12px; font-size: .9rem; }
.security-note {
    margin-top: 18px;
    color: #9ca3af;
    text-align: center;
    font-size: .76rem;
}
@media (max-width: 900px) {
    .login-shell { grid-template-columns: 1fr; }
    .brand-panel { min-height: 290px; padding: 34px 28px; }
    .brand-title { font-size: 2.7rem; margin-top: 18px; }
    .brand-copy { font-size: .95rem; }
    .login-panel { padding: 24px 16px 36px; }
}
@media (max-width: 520px) {
    .brand-panel { min-height: 235px; padding: 26px 20px; }
    .brand-title { font-size: 2.2rem; }
    .brand-copy { display: none; }
    .login-card { padding: 24px 20px; border-radius: 20px; }
}
html[dir="rtl"] body { direction: rtl; text-align: right; }
</style>
</head>
<body>
<div class="login-shell">
    <section class="brand-panel">
        <div style="position:relative;z-index:1">
            <div class="logo-box">RL</div>
            <h1 class="brand-title" data-i18n="brand_title">Rukn Al Lotus<br>Point of Sale</h1>
            <p class="brand-copy" data-i18n="brand_copy">One secure entry point for your store operations, inventory, transactions, daily cash sheet, and reporting.</p>
        </div>
        <div class="role-preview">
            <span class="role-chip" data-i18n="admin_chip">Admin · Full access</span>
            <span class="role-chip" data-i18n="viewer_chip">Viewer · Read only</span>
        </div>
    </section>

    <main class="login-panel">
        <div class="login-card">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                <div>
                    <div class="eyebrow" data-i18n="secure_access">Secure access</div>
                    <h2 class="login-title" data-i18n="welcome">Welcome back</h2>
                    <div class="login-subtitle mb-0" data-i18n="subtitle">Sign in to continue to POS Rukn Al Lotus.</div>
                </div>
                <div class="lang-switch" aria-label="Language">
                    <button type="button" id="langEnglish" onclick="setLanguage('en')">EN</button>
                    <button type="button" id="langArabic" onclick="setLanguage('ar')">AR</button>
                </div>
            </div>

            <?php if ($setup_error !== ''): ?>
                <div class="alert alert-danger"><?= h($setup_error) ?></div>
            <?php elseif ($error !== ''): ?>
                <div class="alert alert-danger" data-login-error="1"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <div class="mb-3">
                    <label for="username" class="form-label" data-i18n="username">Username</label>
                    <div class="input-wrap">
                        <span class="field-icon">●</span>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            value="<?= h($username) ?>"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label" data-i18n="password">Password</label>
                    <div class="input-wrap">
                        <span class="field-icon">◆</span>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control password-input"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="password-toggle" id="passwordToggle" onclick="togglePassword()" data-i18n="show">Show</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary login-btn w-100" data-i18n="sign_in">Sign In</button>
            </form>

            <div class="role-help">
                <div class="fw-bold small" data-i18n="access_levels">Access levels</div>
                <div class="role-line">
                    <span class="role-dot"></span>
                    <span><strong data-i18n="admin">Admin</strong> — <span data-i18n="admin_desc">opens the main POS / stock dashboard.</span></span>
                </div>
                <div class="role-line viewer">
                    <span class="role-dot"></span>
                    <span><strong data-i18n="viewer">Viewer</strong> — <span data-i18n="viewer_desc">opens the read-only Daily Cash Sheet view.</span></span>
                </div>
            </div>

            <div class="security-note" data-i18n="security_note">Passwords are stored as secure hashes in the database.</div>
        </div>
    </main>
</div>

<script>
const translations = {
    en: {
        brand_title: 'Rukn Al Lotus\nPoint of Sale',
        brand_copy: 'One secure entry point for your store operations, inventory, transactions, daily cash sheet, and reporting.',
        admin_chip: 'Admin · Full access',
        viewer_chip: 'Viewer · Read only',
        secure_access: 'Secure access',
        welcome: 'Welcome back',
        subtitle: 'Sign in to continue to POS Rukn Al Lotus.',
        username: 'Username',
        password: 'Password',
        show: 'Show',
        hide: 'Hide',
        sign_in: 'Sign In',
        access_levels: 'Access levels',
        admin: 'Admin',
        admin_desc: 'opens the main POS / stock dashboard.',
        viewer: 'Viewer',
        viewer_desc: 'opens the read-only Daily Cash Sheet view.',
        security_note: 'Passwords are stored as secure hashes in the database.'
    },
    ar: {
        brand_title: 'ركن اللوتس\nنظام نقاط البيع',
        brand_copy: 'بوابة دخول آمنة لإدارة عمليات المتجر والمخزون والحركات وكشف الصندوق اليومي والتقارير.',
        admin_chip: 'المدير · صلاحية كاملة',
        viewer_chip: 'المشاهد · عرض فقط',
        secure_access: 'دخول آمن',
        welcome: 'مرحباً بعودتك',
        subtitle: 'سجل الدخول للمتابعة إلى نظام ركن اللوتس.',
        username: 'اسم المستخدم',
        password: 'كلمة المرور',
        show: 'إظهار',
        hide: 'إخفاء',
        sign_in: 'تسجيل الدخول',
        access_levels: 'مستويات الصلاحية',
        admin: 'المدير',
        admin_desc: 'يفتح لوحة نظام نقاط البيع والمخزون الرئيسية.',
        viewer: 'المشاهد',
        viewer_desc: 'يفتح العرض فقط لكشف الصندوق اليومي.',
        security_note: 'يتم حفظ كلمات المرور في قاعدة البيانات بصورة مشفرة وآمنة.'
    }
};

let currentLanguage = localStorage.getItem('posLoginLanguage') || 'en';

function tr(key) {
    return (translations[currentLanguage] && translations[currentLanguage][key]) || key;
}

function setLanguage(lang) {
    currentLanguage = lang === 'ar' ? 'ar' : 'en';
    localStorage.setItem('posLoginLanguage', currentLanguage);

    document.documentElement.lang = currentLanguage;
    document.documentElement.dir = currentLanguage === 'ar' ? 'rtl' : 'ltr';

    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.dataset.i18n;
        if (!translations[currentLanguage][key]) return;
        const value = translations[currentLanguage][key];
        if (key === 'brand_title') {
            el.innerHTML = value.replace('\n', '<br>');
        } else {
            el.textContent = value;
        }
    });

    document.getElementById('langEnglish').classList.toggle('active', currentLanguage === 'en');
    document.getElementById('langArabic').classList.toggle('active', currentLanguage === 'ar');

    const password = document.getElementById('password');
    document.getElementById('passwordToggle').textContent = password.type === 'password' ? tr('show') : tr('hide');
}

function togglePassword() {
    const password = document.getElementById('password');
    password.type = password.type === 'password' ? 'text' : 'password';
    document.getElementById('passwordToggle').textContent = password.type === 'password' ? tr('show') : tr('hide');
    password.focus();
}

setLanguage(currentLanguage);
</script>
</body>
</html>
