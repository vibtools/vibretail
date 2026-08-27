<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only.');
}

function local_gate_is_local_host(string $host): bool
{
    $normalized = strtolower(trim($host));
    if (in_array($normalized, ['127.0.0.1', 'localhost', '::1'], true)) {
        return true;
    }
    if (str_starts_with($normalized, '127.')) {
        return true;
    }
    return false;
}

function local_gate_assert_safe_target(bool $requireDatabaseName = true): void
{
    if (APP_ENV === 'production') {
        throw new RuntimeException('Local runtime gate refuses POS_APP_ENV=production.');
    }
    if (!local_gate_is_local_host(DB_HOST)) {
        throw new RuntimeException('Local runtime gate refuses non-local POS_DB_HOST: ' . DB_HOST);
    }
    if ($requireDatabaseName && !preg_match('/^[A-Za-z0-9_]{1,64}$/', DB_NAME)) {
        throw new RuntimeException('POS_DB_NAME must contain only letters, numbers and underscores for the local gate.');
    }
}

function local_gate_service_key_fingerprint(): string
{
    try {
        $key = service_credential_key();
        return substr(hash('sha256', $key), 0, 16);
    } catch (Throwable) {
        return 'NOT-CONFIGURED';
    }
}

function local_gate_redacted_environment(): array
{
    return [
        'app_env' => APP_ENV,
        'env_file' => PLATFORM_ENV_FILE,
        'db_host' => DB_HOST,
        'db_port' => DB_PORT,
        'db_name' => DB_NAME,
        'db_user_configured' => DB_USER !== '',
        'db_password_configured' => DB_PASS !== '',
        'service_key_fingerprint' => local_gate_service_key_fingerprint(),
        'php_binary' => PHP_BINARY,
        'mysql_binary' => mysql_client_binary(),
        'mysqldump_binary' => mysql_dump_binary(),
        'application_log' => (string) ($GLOBALS['POS_APPLICATION_LOG'] ?? ''),
        'backup_dir' => env_value('POS_BACKUP_DIR', ''),
    ];
}
