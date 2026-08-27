<?php
declare(strict_types=1);

require __DIR__ . '/local-gate-lib.php';

$options = getopt('', ['db', 'json', 'help']);
if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php tools/local-xampp-preflight.php\n";
    echo "  php tools/local-xampp-preflight.php --db\n";
    echo "  php tools/local-xampp-preflight.php --db --json\n";
    exit(0);
}

$withDb = array_key_exists('db', $options);
$asJson = array_key_exists('json', $options);
$checks = [];
$add = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

try {
    local_gate_assert_safe_target();
    $add('local target guard', true, 'APP_ENV=' . APP_ENV . ' DB_HOST=' . DB_HOST . ':' . DB_PORT);
} catch (Throwable $error) {
    $add('local target guard', false, $error->getMessage());
}

$add('PHP >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION);
foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'gd', 'exif'] as $extension) {
    $add('extension ' . $extension, extension_loaded($extension), extension_loaded($extension) ? 'loaded' : 'missing');
}

$add('environment file readable', is_file(PLATFORM_ENV_FILE) && is_readable(PLATFORM_ENV_FILE), PLATFORM_ENV_FILE);
$add('web installer disabled', !env_bool('POS_ALLOW_WEB_INSTALL', false), 'POS_ALLOW_WEB_INSTALL=' . (env_bool('POS_ALLOW_WEB_INSTALL', false) ? 'true' : 'false'));
$add('database user configured', DB_USER !== '', DB_USER === '' ? 'missing' : 'configured');
$add('service credential key', local_gate_service_key_fingerprint() !== 'NOT-CONFIGURED', 'fingerprint=' . local_gate_service_key_fingerprint());

$logPath = (string) ($GLOBALS['POS_APPLICATION_LOG'] ?? '');
$repoRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
$appRoot = $repoRoot . '/src';
$logNorm = str_replace('\\', '/', $logPath);
$add('application log outside project root', $logNorm !== '' && !str_starts_with(strtolower($logNorm), strtolower($appRoot) . '/'), $logPath);

$backupDir = trim(env_value('POS_BACKUP_DIR', ''));
$backupNorm = str_replace('\\', '/', $backupDir);
$backupExternal = $backupDir !== '' && !str_starts_with(strtolower($backupNorm), strtolower($appRoot) . '/');
$backupWritable = false;
if ($backupDir !== '') {
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0700, true);
    }
    $backupWritable = is_dir($backupDir) && is_writable($backupDir);
}
$add('backup directory external', $backupExternal, $backupDir === '' ? 'not configured' : $backupDir);
$add('backup directory writable', $backupWritable, $backupDir === '' ? 'not configured' : $backupDir);

foreach ([
    'mysql client binary' => mysql_client_binary(),
    'mysqldump binary' => mysql_dump_binary(),
] as $name => $path) {
    $add($name, is_file($path), $path);
}

if ($withDb) {
    try {
        local_gate_assert_safe_target();
        $server = db(true);
        $serverVersion = (string) $server->query('SELECT VERSION()')->fetchColumn();
        $stmt = $server->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');
        $stmt->execute([DB_NAME]);
        $exists = (int) $stmt->fetchColumn() === 1;
        $add('database server connection', true, $serverVersion);
        $add('configured database exists', $exists, DB_NAME);
        if ($exists) {
            $pdo = db();
            $add('configured database connection', (int) $pdo->query('SELECT 1')->fetchColumn() === 1, DB_NAME);
        }
    } catch (Throwable $error) {
        $add('database server connection', false, $error->getMessage());
    }
}

$failed = array_values(array_filter($checks, static fn(array $row): bool => !$row['ok']));

if ($asJson) {
    echo json_encode([
        'project' => 'Cloud Core POS',
        'candidate' => 'CCPOS-P2-2026.08.27-001',
        'scope_lock' => 'CCPOS-LX-SL-001',
        'php' => PHP_VERSION,
        'checks' => $checks,
        'failed' => count($failed),
        'environment' => local_gate_redacted_environment(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Cloud Core POS - Local XAMPP Preflight\n";
    echo "Candidate: CCPOS-EZ-2026.08.27-001\n";
    echo "Service key fingerprint: " . local_gate_service_key_fingerprint() . " (safe fingerprint, not the key)\n\n";
    foreach ($checks as $row) {
        echo ($row['ok'] ? '[PASS] ' : '[FAIL] ') . $row['name'];
        if ($row['detail'] !== '') echo ' - ' . $row['detail'];
        echo PHP_EOL;
    }
    echo PHP_EOL . count($checks) . ' checks, ' . count($failed) . " failed.\n";
}
exit($failed ? 1 : 0);
