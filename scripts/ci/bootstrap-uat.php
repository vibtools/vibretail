<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__, 2) . '/src/config.php';
require dirname(__DIR__, 2) . '/src/installer-lib.php';

if (getenv('VIBRETAIL_CI_CONFIRM') !== 'CI-UAT-RESET') {
    fwrite(STDERR, "[FAIL] Refusing CI database reset without VIBRETAIL_CI_CONFIRM=CI-UAT-RESET.\n");
    exit(2);
}
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "[FAIL] CI bootstrap only permits a local database host.\n");
    exit(2);
}
if (!preg_match('/^vibretail_ci(?:_[a-z0-9_]+)?$/i', DB_NAME)) {
    fwrite(STDERR, "[FAIL] CI database name must start with vibretail_ci.\n");
    exit(2);
}
if (DB_USER === '') {
    fwrite(STDERR, "[FAIL] CI database user is required.\n");
    exit(2);
}

$adminPassword = (string) getenv('VIBRETAIL_CI_ADMIN_PASSWORD');
if (!password_is_strong($adminPassword)) {
    fwrite(STDERR, "[FAIL] Ephemeral CI administrator password is missing or not strong enough.\n");
    exit(2);
}

$databaseDsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($databaseDsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
]);

$tableCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=" . $pdo->quote(DB_NAME)
)->fetchColumn();
if ($tableCount !== 0) {
    fwrite(STDERR, "[FAIL] CI database is not empty; refusing to overwrite existing tables.\n");
    exit(2);
}

$schema = installer_schema_sql();
$pdo->exec($schema);
run_schema_migrations($pdo, DB_NAME);
installer_seed_core($pdo, [
    'name' => SOFTWARE_NAME,
    'phone' => '',
    'email' => '',
    'address' => 'CI',
    'currency' => 'BDT',
]);
installer_create_admin($pdo, [
    'name' => 'CI Administrator',
    'phone' => '01700000000',
    'password' => $adminPassword,
]);

$private = dirname(__DIR__, 2) . '/src/storage/private';
$uploads = dirname(__DIR__, 2) . '/src/uploads/products';
foreach ([$private, $uploads] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create CI runtime directory: ' . $directory);
    }
}

echo '[PASS] Clean VibRetail CI database bootstrapped: ' . DB_NAME . PHP_EOL;
