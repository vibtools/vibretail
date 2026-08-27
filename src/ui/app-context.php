<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (!isset($pageKey, $pageTitle, $pageSubtitle, $pageSection)) {
    throw new RuntimeException('Page metadata is required.');
}

try {
    $pdo = db();
    $settings = normalize_brand_settings($pdo->query('SELECT * FROM settings WHERE id = 1')->fetch() ?: []);
} catch (Throwable) {
    header('Location: install.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

$user = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare('SELECT id, name, phone, role, profile_photo, auth_version FROM users WHERE id = ? AND status = 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
}

if ($user && (!isset($_SESSION['auth_version']) || (int) $_SESSION['auth_version'] !== (int) $user['auth_version'])) {
    $user = null;
}

if (!$user) {
    session_unset();
    $returnPage = basename((string) ($_SERVER['PHP_SELF'] ?? 'dashboard.php'));
    header('Location: index.php?return=' . rawurlencode($returnPage));
    exit;
}

$businessName = (string) ($settings['business_name'] ?? SOFTWARE_NAME);
