<?php
declare(strict_types=1);

require __DIR__ . '/app-context.php';

/** @var array<int, array<string, mixed>> $navigation */
$navigation = require __DIR__ . '/app-navigation.php';
$shellShowDeveloperCredit = $shellShowDeveloperCredit ?? true;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#00b86b">
    <meta name="author" content="<?= htmlspecialchars(DEVELOPER_NAME, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="style.css?v=1.2.1">
    <link rel="stylesheet" href="ui-shell.css?v=1.0.0">
    <link rel="stylesheet" href="ui-components.css?v=1.0.0">
</head>
<body class="app-view">
<div id="app" class="app-shell">
    <aside id="sidebar" class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-mark"><?php if (!empty($settings['logo_data'])): ?><img src="<?= htmlspecialchars($settings['logo_data'], ENT_QUOTES, 'UTF-8') ?>" alt="Business logo"><?php else: ?>C<?php endif; ?></div>
            <div><strong id="sidebar-business"><?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars(SOFTWARE_NAME, ENT_QUOTES, 'UTF-8') ?></small></div>
            <button id="sidebar-close" class="icon-button sidebar-close" aria-label="Close menu">&times;</button>
        </div>
        <nav class="sidebar-nav" aria-label="Main navigation">
            <?php foreach ($navigation as $nav): ?>
                <?php if ($nav['type'] === 'link'): ?>
                    <a class="nav-link <?= $pageKey === $nav['key'] ? 'active' : '' ?>" href="<?= htmlspecialchars($nav['key'], ENT_QUOTES, 'UTF-8') ?>.php" data-page="<?= htmlspecialchars($nav['key'], ENT_QUOTES, 'UTF-8') ?>"><span class="nav-icon"><?= htmlspecialchars($nav['icon'], ENT_QUOTES, 'UTF-8') ?></span><span><?= htmlspecialchars($nav['label'], ENT_QUOTES, 'UTF-8') ?></span></a>
                <?php else: ?>
                    <?php $groupKeys = array_column($nav['items'], 0); $isOpen = in_array($pageKey, $groupKeys, true); ?>
                    <div class="nav-group <?= $isOpen ? 'open' : '' ?>">
                        <button class="nav-parent"><span class="nav-icon"><?= htmlspecialchars($nav['icon'], ENT_QUOTES, 'UTF-8') ?></span><span><?= htmlspecialchars($nav['label'], ENT_QUOTES, 'UTF-8') ?></span><?php if (!empty($nav['new'])): ?><em>NEW</em><?php endif; ?><b>+</b></button>
                        <div class="nav-children <?= !empty($nav['report']) ? 'report-links' : '' ?>">
                            <?php foreach ($nav['items'] as $item): ?>
                                <a class="<?= $pageKey === $item[0] ? 'active' : '' ?>" href="<?= htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') ?>.php" data-page="<?= htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($item[2])): ?> <em>NEW</em><?php endif; ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot developer-credit">
            <span class="database-state"><i class="status-dot"></i> Database connected</span>
            <?php if ($shellShowDeveloperCredit): ?>
                <span>Developed by <a href="<?= htmlspecialchars(DEVELOPER_FACEBOOK_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars(DEVELOPER_NAME, ENT_QUOTES, 'UTF-8') ?></a></span>
                <span><a href="<?= htmlspecialchars(DEVELOPER_COMPANY_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars(DEVELOPER_COMPANY, ENT_QUOTES, 'UTF-8') ?></a> · <a href="<?= htmlspecialchars(DEVELOPER_GITHUB_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">GitHub</a> · <a href="license.php">License</a></span>
            <?php endif; ?>
        </div>
    </aside>
    <div id="sidebar-scrim" class="sidebar-scrim"></div>
    <section class="workspace">
        <header class="topbar">
            <div class="topbar-left">
                <button id="menu-toggle" class="icon-button menu-toggle" aria-label="Open menu">&#9776;</button>
                <button class="system-pill"><span class="screen-icon"></span> System</button>
                <select class="language-select" aria-label="Language"><option>English</option><option>বাংলা</option></select>
                <span class="support-text">Support: <?= htmlspecialchars($settings['phone'] ?: '01715048306', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="topbar-actions">
                <a class="outline-pill" href="sale-new.php" data-page="sale-new">Sale</a>
                <div class="topbar-menu-anchor">
                    <button id="quick-add" class="outline-pill">+ Add New</button>
                    <div id="quick-menu" class="quick-menu"><a href="sale-new.php" data-page="sale-new">New Sale</a><a href="purchase-new.php" data-page="purchase-new">New Purchase</a><a href="product-new.php" data-page="product-new">New Product</a><a href="customer.php" data-page="customer">New Customer</a><a href="payment-center.php" data-page="payment-center">Payment</a><a href="buy-sms.php" data-page="buy-sms">Buy SMS</a></div>
                </div>
                <div class="topbar-menu-anchor topbar-profile-anchor">
                    <button id="profile-button" class="profile-button"><span><?php if (!empty($user['profile_photo'])): ?><img src="<?= htmlspecialchars($user['profile_photo'], ENT_QUOTES, 'UTF-8') ?>" alt="Profile photo"><?php else: ?><?= htmlspecialchars(strtoupper(substr($user['name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span><i><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></i></button>
                    <div id="profile-menu" class="profile-menu"><strong><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?></small><a href="profile.php" data-page="profile">View Profile</a><button id="logout-button">Logout</button></div>
                </div>
            </div>
        </header>
        <main id="content" class="content" tabindex="-1">

<section class="page-enter server-page-entry" data-page-controller="<?= htmlspecialchars($pageKey, ENT_QUOTES, 'UTF-8') ?>">
    <header class="page-header">
        <div><span class="breadcrumb"><?= htmlspecialchars($pageSection, ENT_QUOTES, 'UTF-8') ?></span><h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1><p><?= htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8') ?></p></div>
    </header>
    <div class="loading-state"><span class="loader"></span><p>Loading <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>...</p></div>
</section>
        </main>
    </section>
</div>
<div id="toast-root" class="toast-root" aria-live="polite"></div>
<div id="modal-root"></div>
<script nonce="<?= htmlspecialchars(security_nonce(), ENT_QUOTES, 'UTF-8') ?>">
window.POS_CONFIG = <?= html_script_json_encode([
    'api' => 'api.php',
    'csrf' => $_SESSION['csrf'],
    'loggedIn' => true,
    'businessName' => $businessName,
    'currency' => $settings['currency'] ?? 'BDT',
    'user' => $user,
    'initialPage' => $pageKey,
    'multiPage' => true,
]) ?>;
</script>
<script src="app.js?v=1.5.0"></script>
</body>
</html>
