<?php
declare(strict_types=1);

final class MalformedJsonException extends RuntimeException {}
final class ApplicationConfigurationException extends RuntimeException {}

function load_env_file(string $path, bool $override = false, array $protectedEnvironment = []): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)
            || array_key_exists($name, $protectedEnvironment)
            || (!$override && array_key_exists($name, $GLOBALS['POS_FILE_ENVIRONMENT']))) {
            continue;
        }
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $quote = $value[0];
            $value = substr($value, 1, -1);
            if ($quote === '"') {
                $value = strtr($value, ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n", '\\r' => "\r"]);
            }
        }
        $GLOBALS['POS_FILE_ENVIRONMENT'][$name] = $value;
        $_ENV[$name] = $value;
    }
}

function env_value(string $name, string $default = ''): string
{
    if (array_key_exists($name, $GLOBALS['POS_EXTERNAL_ENVIRONMENT'])) {
        return (string) $GLOBALS['POS_EXTERNAL_ENVIRONMENT'][$name];
    }
    return array_key_exists($name, $GLOBALS['POS_FILE_ENVIRONMENT'])
        ? (string) $GLOBALS['POS_FILE_ENVIRONMENT'][$name]
        : $default;
}

function env_bool(string $name, bool $default = false): bool
{
    $value = env_value($name, $default ? 'true' : 'false');
    return filter_var($value, FILTER_VALIDATE_BOOL);
}

$externalEnvironment = function_exists('getenv') ? getenv() : [];
$externalEnvironment = is_array($externalEnvironment) ? $externalEnvironment : [];
foreach ([$_ENV, $_SERVER] as $environmentSource) {
    foreach ($environmentSource as $name => $value) {
        if (is_string($name) && str_starts_with($name, 'POS_') && is_scalar($value) && !array_key_exists($name, $externalEnvironment)) {
            $externalEnvironment[$name] = (string) $value;
        }
    }
}
$GLOBALS['POS_EXTERNAL_ENVIRONMENT'] = $externalEnvironment;
$GLOBALS['POS_FILE_ENVIRONMENT'] = [];
$canonicalEnvFile = __DIR__ . '/.env';
$platformEnvFile = env_value('POS_ENV_FILE', PHP_OS_FAMILY === 'Windows' ? '.env.windows' : '.env.server');
if (!preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $platformEnvFile)) {
    $platformEnvFile = __DIR__ . DIRECTORY_SEPARATOR . $platformEnvFile;
}

// New installations use .env. Legacy platform-specific env files remain a
// fallback so existing deployments upgrade without manual configuration edits.
if (is_file($canonicalEnvFile)) {
    load_env_file($canonicalEnvFile, false, $externalEnvironment);
    $activeEnvFile = $canonicalEnvFile;
} else {
    load_env_file($platformEnvFile, false, $externalEnvironment);
    $activeEnvFile = $platformEnvFile;
}

date_default_timezone_set(env_value('POS_TIMEZONE', 'Asia/Dhaka'));

define('APP_ENV', env_value('POS_APP_ENV', 'local'));
define('PLATFORM_ENV_FILE', $activeEnvFile);
define('IS_WINDOWS', PHP_OS_FAMILY === 'Windows');
define('SOFTWARE_NAME', 'VibRetail');
define('DEVELOPER_NAME', 'Vib Tools');
define('DEVELOPER_COMPANY', 'Vib Tools');
define('DEVELOPER_COMPANY_URL', 'https://vib.tools/');
define('DEVELOPER_CONTACT_URL', 'https://vib.tools/contact');
define('DEVELOPER_LOGO_URL', 'https://vibtools.github.io/vibtools-brand-assets/logos/icon-512.png');
define('DEVELOPER_GITHUB_URL', 'https://github.com/vibtools/vibretail');
define('DEVELOPER_GITHUB_ORG_URL', 'https://github.com/vibtools');
define('DEVELOPER_FACEBOOK_URL', 'https://www.facebook.com/vib.tools');
define('DEVELOPER_X_URL', 'https://x.com/vibtools');
define('DEVELOPER_INSTAGRAM_URL', 'https://www.instagram.com/vib.tools');
define('DEVELOPER_REDDIT_URL', 'https://www.reddit.com/user/VibTools/');
define('DEVELOPER_EMAIL', 'hello@vib.tools');
define('DEVELOPER_SUPPORT_EMAIL', 'support@vib.tools');
define('DEVELOPER_WHATSAPP_NUMBER', '+880 1795-470603');
define('DEVELOPER_WHATSAPP_URL', 'https://wa.me/8801795470603');


function normalize_brand_settings(array $settings): array
{
    $businessName = trim((string) ($settings['business_name'] ?? ''));
    if ($businessName === '' || strcasecmp($businessName, 'Cloud Core POS') === 0) {
        $settings['business_name'] = SOFTWARE_NAME;
    }

    $tagline = trim((string) ($settings['tagline'] ?? ''));
    $legacyTaglines = [
        'Developed by Swapon Mahmud',
        'Cloud Core POS',
        'Cloudcore Soft',
        'Modern retail operations by Vib Tools',
    ];
    if ($tagline === '' || in_array($tagline, $legacyTaglines, true)) {
        $settings['tagline'] = 'Retail operations, simplified by Vib Tools.';
    }

    $website = trim((string) ($settings['website'] ?? ''));
    $legacyWebsites = [
        'https://cloudcoresoft.com',
        'http://cloudcoresoft.com',
        'cloudcoresoft.com',
    ];
    if ($website === '' || in_array(strtolower(rtrim($website, '/')), array_map(static fn(string $value): string => strtolower(rtrim($value, '/')), $legacyWebsites), true)) {
        $settings['website'] = DEVELOPER_COMPANY_URL;
    }

    return $settings;
}
define('DB_HOST', env_value('POS_DB_HOST', '127.0.0.1'));
define('DB_PORT', max(1, min(65535, (int) env_value('POS_DB_PORT', '3306'))));
define('DB_NAME', env_value('POS_DB_NAME', 'pos'));
define('DB_USER', env_value('POS_DB_USER', IS_WINDOWS ? 'root' : ''));
define('DB_PASS', env_value('POS_DB_PASS', ''));


function resolve_local_mysql_binary(string $envName, string $binary): string
{
    $configured = trim(env_value($envName, ''));
    if ($configured !== '' && is_file($configured)) {
        return $configured;
    }

    $candidates = [];
    if (IS_WINDOWS) {
        $phpBinary = PHP_BINARY;
        if ($phpBinary !== '') {
            $phpDir = dirname($phpBinary);
            $xamppRoot = dirname($phpDir);
            $candidates[] = $xamppRoot . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $binary . '.exe';
        }
        foreach (['C:\\xampp', 'D:\\xampp', 'D:\\App\\xampp'] as $xamppRoot) {
            $candidates[] = $xamppRoot . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $binary . '.exe';
        }
    } else {
        foreach (['/www/server/mysql/bin', '/usr/bin', '/usr/local/bin'] as $binDir) {
            $candidates[] = $binDir . DIRECTORY_SEPARATOR . $binary;
        }
    }

    foreach (array_values(array_unique($candidates)) as $candidate) {
        if (is_file($candidate)) {
            if ($configured !== '') {
                app_log('warning', 'Configured MySQL client binary was not found; using detected fallback.', [
                    'environment' => $envName,
                    'configured' => $configured,
                    'detected' => $candidate,
                ]);
            }
            return $candidate;
        }
    }

    return $configured !== '' ? $configured : (IS_WINDOWS ? $binary . '.exe' : $binary);
}

function mysql_dump_binary(): string
{
    return resolve_local_mysql_binary('POS_MYSQLDUMP_PATH', 'mysqldump');
}

function mysql_client_binary(): string
{
    return resolve_local_mysql_binary('POS_MYSQL_PATH', 'mysql');
}


function html_script_json_encode(mixed $value): string
{
    $encoded = json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($encoded)) {
        throw new RuntimeException('Could not encode page configuration safely.');
    }
    return $encoded;
}

function positive_id(mixed $value, string $label = 'ID', bool $optional = false): ?int
{
    if ($optional && ($value === null || $value === '')) {
        return null;
    }
    if (is_int($value)) {
        $id = $value;
    } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value))) {
        $id = (int) trim($value);
    } elseif (is_float($value) && floor($value) === $value) {
        $id = (int) $value;
    } else {
        throw new InvalidArgumentException($label . ' must be a positive integer.');
    }
    if ($id <= 0) {
        throw new InvalidArgumentException($label . ' must be a positive integer.');
    }
    return $id;
}

function enum_value(mixed $value, array $allowed, string $label, ?string $default = null): string
{
    $candidate = clean_string($value, 60);
    if ($candidate === '' && $default !== null) {
        return $default;
    }
    if (!in_array($candidate, $allowed, true)) {
        throw new InvalidArgumentException($label . ' is invalid.');
    }
    return $candidate;
}

function strict_money(mixed $value, string $label, bool $allowZero = true): float
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === '' || $value === null || is_bool($value) || !is_numeric($value)) {
        throw new InvalidArgumentException($label . ' must be a valid amount.');
    }
    $amount = round((float) $value, 2);
    if ($amount < 0 || (!$allowZero && $amount <= 0)) {
        throw new InvalidArgumentException($label . ($allowZero ? ' cannot be negative.' : ' must be greater than zero.'));
    }
    return $amount;
}

function request_id(): string
{
    static $requestId;
    if ($requestId !== null) {
        return $requestId;
    }
    $incoming = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    if ($incoming !== '' && preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $incoming)) {
        return $requestId = $incoming;
    }
    return $requestId = bin2hex(random_bytes(12));
}

function configure_application_logging(): string
{
    $configured = trim(env_value('POS_LOG_DIR', ''));
    $directory = $configured !== ''
        ? $configured
        : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cloud-core-pos-logs';
    if (!is_dir($directory)) {
        @mkdir($directory, 0750, true);
    }
    if (!is_dir($directory) || !is_writable($directory)) {
        $directory = sys_get_temp_dir();
    }
    $logFile = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'application.log';
    ini_set('log_errors', '1');
    ini_set('error_log', $logFile);
    return $logFile;
}

$GLOBALS['POS_APPLICATION_LOG'] = configure_application_logging();

function text_limit(string $value, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

function redact_log_text(string $text): string
{
    foreach ([DB_PASS, env_value('POS_SERVICE_CREDENTIAL_KEY', '')] as $secret) {
        if ($secret !== '' && strlen($secret) >= 6) {
            $text = str_replace($secret, '[REDACTED]', $text);
        }
    }
    $patterns = [
        '/(password|passwd|pwd|token|secret|api[_-]?key|authorization)\s*[=:]\s*([^\s,;]+)/i',
        '/(POS_(?:DB_PASS|SERVICE_CREDENTIAL_KEY))\s*=\s*([^\s]+)/i',
    ];
    foreach ($patterns as $pattern) {
        $text = preg_replace($pattern, '$1=[REDACTED]', $text) ?? $text;
    }
    return text_limit($text, 2000);
}

function app_log(string $level, string $message, array $context = []): void
{
    $safeContext = [];
    foreach ($context as $key => $value) {
        if (preg_match('/password|passwd|pwd|token|secret|key|credential/i', (string) $key)) {
            $safeContext[$key] = '[REDACTED]';
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $safeContext[$key] = redact_log_text((string) $value);
        }
    }
    $line = strtoupper($level) . ' request_id=' . request_id() . ' ' . redact_log_text($message);
    if ($safeContext) {
        $encoded = json_encode($safeContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            $line .= ' context=' . $encoded;
        }
    }
    error_log($line);
}

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    app_log('critical', 'Fatal PHP shutdown error', [
        'type' => (int) ($error['type'] ?? 0),
        'message' => (string) ($error['message'] ?? ''),
        'file' => basename((string) ($error['file'] ?? '')),
        'line' => (int) ($error['line'] ?? 0),
    ]);
});

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return env_bool('POS_TRUST_PROXY') && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name('POSSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$sessionNow = time();
$sessionIdleLimit = max(900, (int) env_value('POS_SESSION_IDLE_SECONDS', '7200'));
if (isset($_SESSION['last_activity']) && $sessionNow - (int) $_SESSION['last_activity'] > $sessionIdleLimit) {
    session_unset();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}
// A brand-new anonymous request keeps the session id issued by session_start().
// Login and periodic authenticated rotation still regenerate the identifier.
if (!isset($_SESSION['last_regenerated'])) {
    $_SESSION['last_regenerated'] = $sessionNow;
} elseif (!empty($_SESSION['user_id']) && $sessionNow - (int) $_SESSION['last_regenerated'] > 900) {
    session_regenerate_id(true);
    $_SESSION['last_regenerated'] = $sessionNow;
}
$_SESSION['last_activity'] = $sessionNow;

function security_nonce(): string
{
    static $nonce;
    return $nonce ??= base64_encode(random_bytes(18));
}

function send_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff', true);
    header('X-Frame-Options: DENY', true);
    header('Referrer-Policy: strict-origin-when-cross-origin', true);
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()', true);
    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains', true);
    }
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . security_nonce() . "'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://vibtools.github.io; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'", true);
}

send_security_headers();
// Keep JSON/HTML responses valid in every environment; local diagnostics are
// written to the external application log rather than injected into responses.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

function db(bool $withoutDatabase = false): PDO
{
    if (DB_USER === '') {
        throw new RuntimeException('Database is not configured. Visit /install to complete setup.');
    }
    static $connections = [];
    $key = $withoutDatabase ? 'server' : 'database';
    if (isset($connections[$key])) {
        return $connections[$key];
    }

    $dsn = $withoutDatabase
        ? 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4'
        : 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $connections[$key] = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    return $connections[$key];
}

function json_input(): array
{
    static $loaded = false;
    static $cached = [];
    if ($loaded) {
        return $cached;
    }
    $loaded = true;
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $cached = $_POST;
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new MalformedJsonException('Malformed JSON request body.', 0, $error);
    }
    if (!is_array($data)) {
        throw new MalformedJsonException('JSON request body must be an object.');
    }
    return $cached = $data;
}

function error_code_for_status(int $status): string
{
    return match ($status) {
        400 => 'BAD_REQUEST',
        401 => 'AUTH_REQUIRED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        409 => 'CONFLICT',
        419 => 'CSRF_FAILED',
        422 => 'VALIDATION_ERROR',
        428 => 'PASSWORD_CHANGE_REQUIRED',
        429 => 'RATE_LIMITED',
        503 => 'SERVICE_UNAVAILABLE',
        default => $status >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_FAILED',
    };
}

function set_http_status(int $status): void
{
    if ($status === 419) {
        // Some PHP/Apache stacks translate http_response_code(419) to 500.
        $protocol = preg_match('/^HTTP\/\d(?:\.\d)?$/', (string) ($_SERVER['SERVER_PROTOCOL'] ?? ''))
            ? (string) $_SERVER['SERVER_PROTOCOL']
            : 'HTTP/1.1';
        header($protocol . ' 419 Authentication Timeout', true);
        return;
    }
    http_response_code($status);
}

function respond(array $payload, int $status = 200, ?string $errorCode = null): never
{
    set_http_status($status);
    header('Content-Type: application/json; charset=utf-8', true);
    header('X-Request-ID: ' . request_id());
    $payload['request_id'] ??= request_id();
    if (($payload['ok'] ?? true) === false && !isset($payload['error'])) {
        $payload['error'] = ['code' => $errorCode ?? error_code_for_status($status)];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function pdo_exception_log_context(PDOException $error, string $action = ''): array
{
    $info = is_array($error->errorInfo ?? null) ? $error->errorInfo : [];
    $context = [
        'exception' => get_class($error),
        'sqlstate' => (string) ($info[0] ?? $error->getCode()),
        'driver_code' => (string) ($info[1] ?? ''),
        'action' => $action,
    ];
    if (in_array(APP_ENV, ['local', 'staging', 'development'], true)) {
        $context['driver_message'] = text_limit(redact_log_text((string) ($info[2] ?? $error->getMessage())), 500);
    }
    return $context;
}

function require_auth(): int
{
    if (empty($_SESSION['user_id'])) {
        respond(['ok' => false, 'message' => 'Your session has expired. Please sign in again.'], 401, 'AUTH_REQUIRED');
    }
    $userId = (int) $_SESSION['user_id'];
    $stmt = db()->prepare('SELECT role,status,must_change_password,auth_version FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['status'] || !isset($_SESSION['auth_version']) || (int) $_SESSION['auth_version'] !== (int) $user['auth_version']) {
        session_unset();
        respond(['ok' => false, 'message' => 'Your account is no longer active.'], 401, 'ACCOUNT_INACTIVE');
    }
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['must_change_password'] = (bool) $user['must_change_password'];
    return $userId;
}

function require_http_method(string $method): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        respond(['ok' => false, 'message' => 'Method not allowed.'], 405, 'METHOD_NOT_ALLOWED');
    }
}

function permission_catalog(): array
{
    return [
        'dashboard' => 'Dashboard',
        'customer' => 'Customers',
        'supplier' => 'Suppliers',
        'product' => 'Products / Inventory',
        'purchase' => 'Purchases',
        'sale' => 'Sales',
        'warranty' => 'Warranty / RMA',
        'service' => 'Service / Repair',
        'service.credential_reveal' => 'Service Credential Reveal',
        'quotation' => 'Quotations',
        'damage' => 'Damage / Stock Loss',
        'expense' => 'Expenses',
        'bank' => 'Bank / Transactions',
        'bank.accounts_manage' => 'Bank Account Management',
        'bank.transfer' => 'Balance Transfer',
        'emi' => 'EMI / Installments',
        'hrm' => 'HRM / Attendance',
        'report' => 'Reports',
        'settings' => 'Business Settings',
        'admin.users' => 'User Administration',
        'admin.roles' => 'Role / Permission Administration',
        'admin.backup' => 'Backup / Export',
    ];
}

function normalize_role_name(string $role): string
{
    $normalized = strtolower(trim($role));
    $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
    $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    return in_array($normalized, ['admin', 'administrator'], true) ? 'administrator' : $normalized;
}

function parse_permissions(string $raw): array
{
    $allowed = array_keys(permission_catalog());
    $result = [];
    foreach (preg_split('/\s*,\s*/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $permission) {
        $permission = strtolower(trim($permission));
        if ($permission === 'all' || in_array($permission, $allowed, true)) {
            $result[$permission] = true;
        }
    }
    return array_keys($result);
}

function current_user_permissions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id=? AND status=1 LIMIT 1');
    $stmt->execute([$userId]);
    $roleName = (string) $stmt->fetchColumn();
    $roleKey = normalize_role_name($roleName);
    if ($roleKey === 'administrator') {
        return ['all'];
    }
    $roles = $pdo->query('SELECT name, permissions FROM roles')->fetchAll();
    foreach ($roles as $role) {
        if (normalize_role_name((string) $role['name']) === $roleKey) {
            return array_values(array_diff(parse_permissions((string) ($role['permissions'] ?? '')), ['all']));
        }
    }
    return [];
}

function user_has_permission(array $permissions, string $required): bool
{
    return in_array('all', $permissions, true) || in_array($required, $permissions, true);
}

function permissions_are_delegable(array $actorPermissions, array $targetPermissions): bool
{
    if (in_array('all', $actorPermissions, true)) {
        return true;
    }
    foreach ($targetPermissions as $permission) {
        if ($permission === 'all' || !in_array($permission, $actorPermissions, true)) {
            return false;
        }
    }
    return true;
}

function role_permissions_by_name(PDO $pdo, string $roleName): ?array
{
    $target = normalize_role_name($roleName);
    if ($target === 'administrator') {
        return ['all'];
    }
    foreach ($pdo->query('SELECT name,permissions FROM roles')->fetchAll() as $role) {
        if (normalize_role_name((string) $role['name']) === $target) {
            return array_values(array_diff(parse_permissions((string) ($role['permissions'] ?? '')), ['all']));
        }
    }
    return null;
}

function api_action_permission_map(): array
{
    return [
        'login' => 'public', 'logout' => 'self', 'bootstrap' => 'authenticated', 'dashboard' => 'dashboard',
        'contacts' => 'contact.dynamic', 'contact_save' => 'contact.dynamic',
        'lookups' => 'lookup.dynamic', 'lookup_save' => 'lookup.dynamic',
        'products' => 'product', 'product_form_data' => 'product', 'product_save' => 'product',
        'sale_save' => 'sale', 'purchase_save' => 'purchase', 'invoices' => 'document.dynamic',
        'return_source' => 'document.dynamic', 'returns' => 'document.dynamic',
        'sale_return_save' => 'sale', 'purchase_return_save' => 'purchase',
        'serials' => 'warranty', 'serial_save' => 'warranty', 'rmas' => 'warranty',
        'rma_save' => 'warranty', 'rma_status' => 'warranty', 'invoice_detail' => 'document.dynamic',
        'expenses' => 'expense', 'expense_save' => 'expense',
        'accounts' => 'bank', 'account_save' => 'bank.accounts_manage', 'transfer_save' => 'bank.transfer',
        'transactions' => 'bank', 'cheques' => 'bank', 'cheque_save' => 'bank', 'cheque_status' => 'bank',
        'contact_ledger' => 'bank', 'contact_payment_save' => 'bank', 'contact_payments' => 'bank',
        'sms_packages' => 'bank', 'sms_purchase' => 'bank',
        'services' => 'service', 'service_save' => 'service', 'service_status' => 'service',
        'service_credential_reveal' => 'service.credential_reveal',
        'quotation_save' => 'quotation', 'quotations' => 'quotation', 'quotation_details' => 'quotation',
        'damage_save' => 'damage', 'damages' => 'damage',
        'investor_save' => 'bank', 'investors' => 'bank',
        'emi_save' => 'emi', 'emi_payment' => 'emi', 'emis' => 'emi', 'installments' => 'emi',
        'employee_save' => 'hrm', 'employees' => 'hrm', 'attendance_save' => 'hrm',
        'attendance' => 'hrm', 'attendance_schedule' => 'hrm', 'attendance_schedule_save' => 'hrm',
        'roles' => 'admin.roles', 'role_save' => 'admin.roles',
        'profile' => 'self', 'profile_save' => 'self', 'password_change' => 'self',
        'marketplace' => 'settings', 'marketplace_request' => 'settings',
        'report' => 'report', 'settings_save' => 'settings', 'backup' => 'admin.backup',
        'admin_data' => 'admin.users', 'user_save' => 'admin.users',
    ];
}

function required_permission_for_action(string $action): ?string
{
    $map = api_action_permission_map();
    if (!array_key_exists($action, $map)) {
        return null;
    }
    $permission = $map[$action];
    if ($permission === 'contact.dynamic') {
        $type = $action === 'contacts'
            ? clean_string($_GET['type'] ?? 'customer', 20)
            : clean_string(json_input()['type'] ?? 'customer', 20);
        return $type === 'supplier' ? 'supplier' : ($type === 'both' ? 'customer' : 'customer');
    }
    if ($permission === 'lookup.dynamic') {
        $type = $action === 'lookups'
            ? clean_string($_GET['type'] ?? 'brand', 20)
            : clean_string(json_input()['type'] ?? '', 20);
        return $type === 'expense_type' ? 'expense' : 'product';
    }
    if ($permission === 'document.dynamic') {
        $type = clean_string($_GET['type'] ?? 'sale', 12);
        return $type === 'purchase' ? 'purchase' : 'sale';
    }
    return $permission;
}

function require_action_permission(PDO $pdo, int $userId, string $action): array
{
    $required = required_permission_for_action($action);
    if ($required === null) {
        respond(['ok' => false, 'message' => 'Unknown API action.'], 404, 'UNKNOWN_ACTION');
    }
    $permissions = current_user_permissions($pdo, $userId);
    if (in_array($required, ['self', 'authenticated'], true)) {
        return $permissions;
    }
    if (!user_has_permission($permissions, $required)) {
        app_log('warning', 'RBAC denied API action', ['user_id' => $userId, 'action' => $action, 'required_permission' => $required]);
        respond(['ok' => false, 'message' => 'You do not have permission to perform this action.'], 403, 'PERMISSION_DENIED');
    }
    return $permissions;
}

function require_admin(PDO $pdo, int $userId): void
{
    if (!in_array('all', current_user_permissions($pdo, $userId), true)) {
        respond(['ok' => false, 'message' => 'Administrator access is required.'], 403, 'ADMIN_REQUIRED');
    }
}

function role_name_exists(PDO $pdo, string $roleName): bool
{
    return role_permissions_by_name($pdo, $roleName) !== null;
}

function service_credential_key(): string
{
    $encoded = trim(env_value('POS_SERVICE_CREDENTIAL_KEY', ''));
    if (str_starts_with($encoded, 'base64:')) {
        $encoded = substr($encoded, 7);
    }
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) {
        throw new ApplicationConfigurationException('Service credential encryption is not configured.');
    }
    return $key;
}

function encrypt_service_credential(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        throw new ApplicationConfigurationException('OpenSSL is required for service credential protection.');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        service_credential_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'CloudCorePOS:service-device:v1',
        16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Could not protect the service credential.');
    }
    return 'enc:v1:' . base64_encode($nonce . $tag . $ciphertext);
}

function decrypt_service_credential(string $protected): string
{
    if (!str_starts_with($protected, 'enc:v1:')) {
        throw new InvalidArgumentException('Legacy service credential requires Phase 2 migration before reveal.');
    }
    $payload = base64_decode(substr($protected, 7), true);
    if ($payload === false || strlen($payload) < 29) {
        throw new RuntimeException('Stored service credential is invalid.');
    }
    $nonce = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        service_credential_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'CloudCorePOS:service-device:v1'
    );
    if ($plaintext === false) {
        throw new RuntimeException('Stored service credential could not be decrypted.');
    }
    return $plaintext;
}

function password_is_strong(string $password): bool
{
    return strlen($password) >= 12
        && strlen($password) <= 128
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/\d/', $password)
        && preg_match('/[^a-zA-Z\d]/', $password);
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (env_bool('POS_TRUST_PROXY') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function require_csrf(): void
{
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        respond(['ok' => false, 'message' => 'Invalid security token. Refresh the page and try again.'], 419, 'CSRF_FAILED');
    }
}

function clean_string(mixed $value, int $max = 255): string
{
    $value = trim((string) $value);
    return text_limit($value, $max);
}

function money(mixed $value): float
{
    return round(max(0, (float) $value), 2);
}

function record_activity(PDO $pdo, int $userId, string $action, string $details = ''): void
{
    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $action, text_limit(redact_log_text($details), 255)]);
}
