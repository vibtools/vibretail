<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#00b86b">
    <meta name="author" content="<?= htmlspecialchars(DEVELOPER_NAME, ENT_QUOTES, 'UTF-8') ?>">
    <title>License | <?= htmlspecialchars(SOFTWARE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="style.css?v=1.2.1">
</head>
<body class="license-view">
<main class="license-shell">
    <header class="license-hero">
        <a class="license-logo" href="index.php" aria-label="Open Cloud Core POS">C</a>
        <div><span class="eyebrow">FREE NON-COMMERCIAL SOFTWARE</span><h1><?= htmlspecialchars(SOFTWARE_NAME, ENT_QUOTES, 'UTF-8') ?></h1><p>Free to use and develop with permanent developer attribution. Resale is prohibited.</p></div>
    </header>

    <section class="license-grid">
        <article class="license-card identity-card">
            <span class="breadcrumb">DEVELOPER CREDENTIALS</span>
            <h2><?= htmlspecialchars(DEVELOPER_NAME, ENT_QUOTES, 'UTF-8') ?></h2>
            <p>Developer of <?= htmlspecialchars(SOFTWARE_NAME, ENT_QUOTES, 'UTF-8') ?></p>
            <div class="license-links">
                <a href="<?= htmlspecialchars(DEVELOPER_COMPANY_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars(DEVELOPER_COMPANY, ENT_QUOTES, 'UTF-8') ?></a>
                <a href="<?= htmlspecialchars(DEVELOPER_FACEBOOK_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Facebook</a>
                <a href="<?= htmlspecialchars(DEVELOPER_GITHUB_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">GitHub</a>
            </div>
        </article>

        <article class="license-card terms-card">
            <span class="breadcrumb">LICENSE 1.0</span>
            <h2>Allowed</h2>
            <ul><li>Free personal and internal business use</li><li>Study, copy, modify, and develop</li><li>Share free copies under the same license</li></ul>
            <h2>Not allowed</h2>
            <ul class="not-allowed"><li>Selling, renting, paid licensing, or paid hosted access</li><li>Changing, hiding, or removing developer credentials</li><li>Claiming authorship or ownership</li></ul>
            <p class="license-note">Operational login passwords may be changed for security. Protected developer identity and attribution may not be changed.</p>
            <a class="button button-primary" href="LICENSE.md">Read Complete License</a>
        </article>
    </section>

    <footer class="license-footer">Copyright &copy; 2026 <?= htmlspecialchars(DEVELOPER_NAME, ENT_QUOTES, 'UTF-8') ?> · <a href="<?= htmlspecialchars(DEVELOPER_COMPANY_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars(DEVELOPER_COMPANY, ENT_QUOTES, 'UTF-8') ?></a></footer>
</main>
</body>
</html>
