<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/migrations.php';

try {
    $pdo = db();
    $settings = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch() ?: [];
    if (migration_pending_ids($pdo, DB_NAME) !== []) {
        header('Location: install');
        exit;
    }
} catch (Throwable) {
    header('Location: install');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

$returnPage = basename((string) ($_GET['return'] ?? 'dashboard.php'));
$reserved = ['index.php', 'api.php', 'config.php', 'install.php'];
if (!preg_match('/^[a-z0-9-]+\.php$/', $returnPage) || in_array($returnPage, $reserved, true) || !is_file(__DIR__ . '/' . $returnPage)) {
    $returnPage = 'dashboard.php';
}

$loggedIn = false;
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare('SELECT auth_version FROM users WHERE id = ? AND status = 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $authVersion = $stmt->fetchColumn();
    $loggedIn = $authVersion !== false && isset($_SESSION['auth_version']) && (int) $_SESSION['auth_version'] === (int) $authVersion;
}

if ($loggedIn) {
    header('Location: ' . $returnPage);
    exit;
}

$businessName = $settings['business_name'] ?? SOFTWARE_NAME;
if ($businessName === 'Cloud Core POS') { $businessName = SOFTWARE_NAME; }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2563EB">
    <meta name="author" content="<?= htmlspecialchars(DEVELOPER_NAME, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= htmlspecialchars(DEVELOPER_LOGO_URL, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars(DEVELOPER_LOGO_URL, ENT_QUOTES, 'UTF-8') ?>">
    <title>Sign In | <?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="style.css?v=1.2.1">
    <link rel="stylesheet" href="ui-complete.css?v=1.0.2">
</head>
<body class="login-view">
<div class="login-stage">
    <div class="login-art" aria-hidden="true">
        <div class="orbit orbit-one"></div>
        <div class="orbit orbit-two"></div>
        <div class="login-preview">
            <div class="preview-head"><span></span><span></span><span></span></div>
            <div class="preview-grid"><i></i><i></i><i></i><i></i></div>
            <div class="preview-chart"><b></b><b></b><b></b><b></b><b></b><b></b></div>
        </div>
        <p>Inventory, sales and accounts.<br>One clear workspace.</p>
    </div>
    <section class="login-card">
        <div class="login-brand"><span class="brand-mark"><?php if (!empty($settings['logo_data'])): ?><img src="<?= htmlspecialchars($settings['logo_data'], ENT_QUOTES, 'UTF-8') ?>" alt="Business logo"><?php else: ?><img src="<?= htmlspecialchars(DEVELOPER_LOGO_URL, ENT_QUOTES, 'UTF-8') ?>" alt="Vib Tools icon"><?php endif; ?></span><span><?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?></span></div>
        <div class="login-copy"><span class="eyebrow">VIBRETAIL · VIB TOOLS</span><h1>Welcome back</h1><p>Sign in to manage your business.</p></div>
        <form id="login-form" class="stack-form">
            <label>Mobile number<input name="phone" type="tel" autocomplete="username" required></label>
            <label>Password<span class="password-wrap"><input name="password" type="password" autocomplete="current-password" required><button type="button" id="toggle-password" aria-label="Show password">Show</button></span></label>
            <button class="button button-primary button-block" type="submit">Sign in</button>
            <p id="login-message" class="form-message" role="alert"></p>
        </form>
        <div class="login-developer">
            <strong>VibRetail by <a href="<?= htmlspecialchars(DEVELOPER_COMPANY_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Vib Tools</a></strong>
            <nav><a href="<?= htmlspecialchars(DEVELOPER_COMPANY_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Website</a><a href="<?= htmlspecialchars(DEVELOPER_CONTACT_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Contact</a><a href="<?= htmlspecialchars(DEVELOPER_GITHUB_ORG_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">GitHub</a></nav>
        </div>
    </section>
</div>
<div id="toast-root" class="toast-root" aria-live="polite"></div>
<div id="modal-root"></div>
<script nonce="<?= htmlspecialchars(security_nonce(), ENT_QUOTES, 'UTF-8') ?>">
window.POS_CONFIG = <?= html_script_json_encode([
    'api' => 'api.php',
    'csrf' => $_SESSION['csrf'],
    'loggedIn' => false,
    'businessName' => $businessName,
    'currency' => $settings['currency'] ?? 'BDT',
    'loginRedirect' => $returnPage,
]) ?>;
</script>
<script src="app.js?v=1.5.1"></script>
</body>
</html>
