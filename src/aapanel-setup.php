<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only.');
}

function setup_help(): never
{
    echo <<<TEXT
VibRetail aaPanel setup

Usage:
  php aapanel-setup.php [options]

Options:
  --db-host=HOST          Database host, default: localhost
  --db-port=PORT          Database port, default: 3306
  --db-name=NAME          aaPanel database name
  --db-user=USER          aaPanel database user
  --db-pass=PASSWORD      aaPanel database password
  --backup-dir=PATH       Backup directory, default: /www/backup/cloudcore-pos
  --log-dir=PATH          App log directory, default: /www/wwwlogs/cloud-core-pos-app
  --admin-phone=PHONE     Initial administrator phone (required on fresh install)
  --admin-name=NAME       Initial administrator name
  --admin-password=PASS   Initial administrator password (prompted securely when omitted)
  --trust-proxy=true      Use only behind a trusted reverse proxy/CDN
  --no-install            Write config without running migrations and UAT
  --dry-run               Validate input without writing files or connecting
  --help                  Show this help

Missing required values are requested interactively.
TEXT;
    exit(0);
}

function setup_prompt(string $label, string $default = '', bool $secret = false): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo $label . $suffix . ': ';
    $canHide = $secret && DIRECTORY_SEPARATOR === '/' && function_exists('system');
    if ($canHide) {
        system('stty -echo 2>/dev/null');
    }
    $value = trim((string) fgets(STDIN));
    if ($canHide) {
        system('stty echo 2>/dev/null');
        echo PHP_EOL;
    }
    return $value !== '' ? $value : $default;
}

function setup_env_value(string $value): string
{
    if (str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new InvalidArgumentException('Environment values cannot contain line breaks.');
    }
    return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"']) . '"';
}


function setup_existing_env_value(string $file, string $key): ?string
{
    if (!is_file($file) || !is_readable($file)) {
        return null;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return null;
    }
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_starts_with($trimmed, $key . '=')) {
            continue;
        }
        $value = trim(substr($trimmed, strlen($key) + 1));
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[strlen($value) - 1] === '"') || ($value[0] === "'" && $value[strlen($value) - 1] === "'"))) {
            $quote = $value[0];
            $value = substr($value, 1, -1);
            if ($quote === '"') {
                $value = strtr($value, ['\\"' => '"', '\\\\' => '\\']);
            }
        }
        return $value !== '' ? $value : null;
    }
    return null;
}

function setup_run(string $file, array $arguments = []): int
{
    if (!function_exists('passthru')) {
        fwrite(STDERR, "PHP passthru() is disabled. Run {$file} manually with the same PHP CLI.\n");
        return 1;
    }
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . $file);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    passthru($command, $exitCode);
    return $exitCode;
}

$options = getopt('', [
    'db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-pass:', 'backup-dir:', 'log-dir:', 'admin-phone:', 'admin-name:', 'admin-password:', 'trust-proxy:',
    'no-install', 'dry-run', 'help',
]);
if (isset($options['help'])) {
    setup_help();
}

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, 'VibRetail requires PHP 8.1 or newer. PHP 8.3 is recommended.' . PHP_EOL);
    exit(1);
}
$missingExtensions = array_values(array_filter(['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json', 'gd', 'exif'], static fn(string $extension): bool => !extension_loaded($extension)));
if ($missingExtensions) {
    fwrite(STDERR, 'Enable these PHP extensions first: ' . implode(', ', $missingExtensions) . PHP_EOL);
    exit(1);
}
if (!function_exists('getimagesizefromstring')) {
    fwrite(STDERR, 'Enable PHP image metadata support (getimagesizefromstring) before setup.' . PHP_EOL);
    exit(1);
}

$target = __DIR__ . DIRECTORY_SEPARATOR . '.env.server';
$existingDbHost = setup_existing_env_value($target, 'POS_DB_HOST') ?? 'localhost';
$existingDbPort = setup_existing_env_value($target, 'POS_DB_PORT') ?? '3306';
$existingDbName = setup_existing_env_value($target, 'POS_DB_NAME') ?? '';
$existingDbUser = setup_existing_env_value($target, 'POS_DB_USER') ?? '';
$existingBackupDirectory = setup_existing_env_value($target, 'POS_BACKUP_DIR') ?? '/www/backup/cloudcore-pos';
$existingLogDirectory = setup_existing_env_value($target, 'POS_LOG_DIR') ?? '/www/wwwlogs/cloud-core-pos-app';
$existingTrustProxy = setup_existing_env_value($target, 'POS_TRUST_PROXY') ?? 'false';
$existingTimezone = setup_existing_env_value($target, 'POS_TIMEZONE') ?? 'Asia/Dhaka';
$existingSessionIdle = setup_existing_env_value($target, 'POS_SESSION_IDLE_SECONDS') ?? '7200';
$existingBackupRetention = setup_existing_env_value($target, 'POS_BACKUP_RETENTION_DAYS') ?? '30';

$dbHost = trim((string) ($options['db-host'] ?? setup_prompt('Database host', $existingDbHost)));
$dbPort = max(1, min(65535, (int) ($options['db-port'] ?? $existingDbPort)));
$dbName = trim((string) ($options['db-name'] ?? setup_prompt('Database name', $existingDbName)));
$dbUser = trim((string) ($options['db-user'] ?? setup_prompt('Database user', $existingDbUser)));
$environmentPassword = function_exists('getenv') ? getenv('POS_SETUP_DB_PASS') : ($_ENV['POS_SETUP_DB_PASS'] ?? false);
$dbPass = (string) ($options['db-pass'] ?? $environmentPassword ?: setup_prompt('Database password', '', true));
$backupDirectory = trim((string) ($options['backup-dir'] ?? $existingBackupDirectory));
$logDirectory = trim((string) ($options['log-dir'] ?? $existingLogDirectory));
$adminPhone = trim((string) ($options['admin-phone'] ?? setup_prompt('Initial administrator phone (leave blank if an admin already exists)')));
$adminName = trim((string) ($options['admin-name'] ?? 'System Administrator'));
$adminPassword = '';
if ($adminPhone !== '') {
    $setupAdminEnvironment = function_exists('getenv') ? getenv('POS_SETUP_ADMIN_PASS') : false;
    $adminPassword = (string) ($options['admin-password'] ?? ($setupAdminEnvironment ?: setup_prompt('Initial administrator password', '', true)));
}
$trustProxy = filter_var((string) ($options['trust-proxy'] ?? $existingTrustProxy), FILTER_VALIDATE_BOOL) ? 'true' : 'false';

if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPass === '') {
    fwrite(STDERR, 'Database host, name, user and password are required.' . PHP_EOL);
    exit(2);
}
if (!preg_match('/^[A-Za-z0-9_.-]+$/', $dbHost) || !preg_match('/^[A-Za-z0-9_$-]+$/', $dbName) || !preg_match('/^[A-Za-z0-9_$.-]+$/', $dbUser)) {
    fwrite(STDERR, 'Database host, name or user contains unsupported characters.' . PHP_EOL);
    exit(2);
}
if (!str_starts_with($backupDirectory, '/') || !str_starts_with($logDirectory, '/')) {
    fwrite(STDERR, 'The aaPanel backup and log directories must be absolute Linux paths.' . PHP_EOL);
    exit(2);
}

$serviceCredentialKey = setup_existing_env_value($target, 'POS_SERVICE_CREDENTIAL_KEY') ?? ('base64:' . base64_encode(random_bytes(32)));

$serverEnvironment = implode(PHP_EOL, [
    'POS_APP_ENV=production',
    'POS_TIMEZONE=' . setup_env_value($existingTimezone),
    'POS_SESSION_IDLE_SECONDS=' . setup_env_value($existingSessionIdle),
    'POS_BACKUP_RETENTION_DAYS=' . setup_env_value($existingBackupRetention),
    'POS_DB_HOST=' . setup_env_value($dbHost),
    'POS_DB_PORT=' . $dbPort,
    'POS_DB_NAME=' . setup_env_value($dbName),
    'POS_DB_USER=' . setup_env_value($dbUser),
    'POS_DB_PASS=' . setup_env_value($dbPass),
    'POS_BACKUP_DIR=' . setup_env_value($backupDirectory),
    'POS_LOG_DIR=' . setup_env_value($logDirectory),
    'POS_MYSQLDUMP_PATH="/www/server/mysql/bin/mysqldump"',
    'POS_MYSQL_PATH="/www/server/mysql/bin/mysql"',
    'POS_TRUST_PROXY=' . $trustProxy,
    'POS_ALLOW_WEB_INSTALL=false',
    'POS_SERVICE_CREDENTIAL_KEY=' . setup_env_value($serviceCredentialKey),
]) . PHP_EOL;

if (isset($options['dry-run'])) {
    echo "Dry run passed. PHP " . PHP_VERSION . ' and all required extensions are available.' . PHP_EOL;
    echo "Target: {$dbUser}@{$dbHost}/{$dbName}; backup: {$backupDirectory}; logs: {$logDirectory}" . PHP_EOL;
    exit(0);
}

try {
    $test = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 8,
    ]);
    $test->query('SELECT 1')->fetchColumn();
} catch (Throwable $error) {
    fwrite(STDERR, 'Database connection failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

$temporary = $target . '.tmp';
if (file_put_contents($temporary, $serverEnvironment, LOCK_EX) === false) {
    fwrite(STDERR, 'Could not write the server environment file.' . PHP_EOL);
    exit(1);
}
$applicationFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
$applicationOwner = @fileowner($applicationFile);
$applicationGroup = @filegroup($applicationFile);
if (DIRECTORY_SEPARATOR === '/' && $applicationOwner !== false) {
    @chown($temporary, $applicationOwner);
}
if (DIRECTORY_SEPARATOR === '/' && $applicationGroup !== false) {
    @chgrp($temporary, $applicationGroup);
}
@chmod($temporary, 0600);
if (!rename($temporary, $target)) {
    @unlink($temporary);
    fwrite(STDERR, 'Could not activate the server environment file.' . PHP_EOL);
    exit(1);
}
@chmod($target, 0600);

echo 'Created protected environment profile: ' . $target . PHP_EOL;
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    fwrite(STDERR, 'Warning: backup directory could not be created. Configure aaPanel Cron backup instead.' . PHP_EOL);
} else {
    @chmod($backupDirectory, 0700);
}
if (!is_dir($logDirectory) && !mkdir($logDirectory, 0750, true) && !is_dir($logDirectory)) {
    fwrite(STDERR, 'Warning: log directory could not be created. Create it before production cutover.' . PHP_EOL);
} else {
    @chmod($logDirectory, 0750);
}

if (!isset($options['no-install'])) {
    echo 'Running database migrations...' . PHP_EOL;
    $installArguments = [];
    if ($adminPhone !== '') $installArguments[] = '--admin-phone=' . $adminPhone;
    if ($adminName !== '') $installArguments[] = '--admin-name=' . $adminName;
    if ($adminPassword !== '') putenv('POS_BOOTSTRAP_ADMIN_PASSWORD=' . $adminPassword);
    $installCode = setup_run('install.php', $installArguments);
    if ($adminPassword !== '') putenv('POS_BOOTSTRAP_ADMIN_PASSWORD');
    if ($installCode !== 0) {
        exit(1);
    }
    echo 'Running production UAT...' . PHP_EOL;
    if (setup_run('uat.php') !== 0) {
        exit(1);
    }
}

echo PHP_EOL . 'aaPanel profile is ready.' . PHP_EOL;
echo 'Set the site to PHP 8.3, enable SSL/Force HTTPS, and apply aapanel-nginx.conf when using Nginx.' . PHP_EOL;
