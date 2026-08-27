<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/product-images.php';

function installer_lock_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'installed.lock';
}

function installer_prepare_private_storage(): string
{
    $private = dirname(installer_lock_path());
    if (!is_dir($private) && !mkdir($private, 0750, true) && !is_dir($private)) {
        throw new RuntimeException('Could not create private application storage.');
    }
    $deny = $private . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\nDeny from all\n", LOCK_EX);
    }
    return $private;
}

function installer_runtime_directories(): array
{
    $projectParent = dirname(__DIR__);
    $preferred = $projectParent . DIRECTORY_SEPARATOR . 'cloud-core-pos-runtime';
    $root = is_dir($projectParent) && is_writable($projectParent)
        ? $preferred
        : installer_prepare_private_storage() . DIRECTORY_SEPARATOR . 'runtime';
    $logDir = $root . DIRECTORY_SEPARATOR . 'logs';
    $backupDir = $root . DIRECTORY_SEPARATOR . 'backups';
    foreach ([$root, $logDir, $backupDir] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create runtime directory: ' . basename($directory));
        }
    }
    return ['root' => $root, 'logs' => $logDir, 'backups' => $backupDir];
}

function installer_environment_checks(): array
{
    $requiredExtensions = ['pdo_mysql', 'mbstring', 'openssl', 'json', 'gd', 'exif'];
    $serverSoftware = trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI));
    $rewriteDetail = 'install.php fallback is available';
    if (function_exists('apache_get_modules')) {
        $rewriteDetail = in_array('mod_rewrite', apache_get_modules(), true) ? 'mod_rewrite detected' : 'mod_rewrite not detected; install.php fallback remains available';
    }
    $checks = [
        ['key' => 'server', 'label' => 'Web server', 'ok' => true, 'detail' => $serverSoftware !== '' ? $serverSoftware : 'Detected'],
        ['key' => 'rewrite', 'label' => 'Friendly /install URL', 'ok' => true, 'detail' => $rewriteDetail],
        ['key' => 'php', 'label' => 'PHP 8.1 or newer', 'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'detail' => PHP_VERSION],
        ['key' => 'session', 'label' => 'PHP sessions', 'ok' => session_status() === PHP_SESSION_ACTIVE, 'detail' => session_status() === PHP_SESSION_ACTIVE ? 'Ready' : 'Unavailable'],
        ['key' => 'root_write', 'label' => 'Configuration write access', 'ok' => is_writable(__DIR__), 'detail' => is_writable(__DIR__) ? 'Ready' : 'Project root is not writable'],
        ['key' => 'uploads', 'label' => 'Upload storage', 'ok' => is_dir(__DIR__ . '/uploads') && is_writable(__DIR__ . '/uploads'), 'detail' => __DIR__ . '/uploads'],
    ];
    foreach ($requiredExtensions as $extension) {
        $checks[] = ['key' => 'ext_' . $extension, 'label' => 'PHP extension: ' . $extension, 'ok' => extension_loaded($extension), 'detail' => extension_loaded($extension) ? 'Loaded' : 'Missing'];
    }
    return $checks;
}

function installer_requirements_ready(): bool
{
    foreach (installer_environment_checks() as $check) {
        if (!$check['ok']) return false;
    }
    return true;
}

function installer_validate_database_name(string $database): string
{
    $database = trim($database);
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
        throw new InvalidArgumentException('Database name may contain only letters, numbers and underscores.');
    }
    return $database;
}

function installer_database_connection(array $database, bool $withoutDatabase = false): PDO
{
    $host = trim((string) ($database['host'] ?? '127.0.0.1')) ?: '127.0.0.1';
    $port = max(1, min(65535, (int) ($database['port'] ?? 3306)));
    $name = installer_validate_database_name((string) ($database['name'] ?? ''));
    $user = trim((string) ($database['user'] ?? ''));
    $password = (string) ($database['password'] ?? '');
    if ($user === '') throw new InvalidArgumentException('Database username is required.');
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ($withoutDatabase ? '' : ';dbname=' . $name) . ';charset=utf8mb4';
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 6,
    ]);
}

function installer_test_database(array $database, bool $createDatabase = false): array
{
    $name = installer_validate_database_name((string) ($database['name'] ?? ''));
    if ($createDatabase) {
        $server = installer_database_connection($database, true);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    $pdo = installer_database_connection($database, false);
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    return ['pdo' => $pdo, 'version' => $version, 'database' => $name];
}

function installer_schema_sql(): string
{
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    if (!is_string($schema) || trim($schema) === '') throw new RuntimeException('Could not read schema.sql.');
    return $schema;
}

function installer_seed_core(PDO $pdo, array $business): void
{
    $pdo->prepare('INSERT INTO settings (id,business_name,phone,email,address,currency) VALUES (1,?,?,?,?,?) ON DUPLICATE KEY UPDATE business_name=VALUES(business_name),phone=VALUES(phone),email=VALUES(email),address=VALUES(address),currency=VALUES(currency)')
        ->execute([
            trim((string) ($business['name'] ?? SOFTWARE_NAME)) ?: SOFTWARE_NAME,
            trim((string) ($business['phone'] ?? '')),
            trim((string) ($business['email'] ?? '')),
            trim((string) ($business['address'] ?? '')),
            trim((string) ($business['currency'] ?? 'BDT')) ?: 'BDT',
        ]);
    $pdo->prepare('UPDATE settings SET website=?,tagline=? WHERE id=1')->execute([DEVELOPER_COMPANY_URL, 'Developed by ' . DEVELOPER_NAME]);
    $pdo->exec("INSERT IGNORE INTO units (name,short_name) VALUES ('Piece','pcs'),('Box','box'),('Kilogram','kg')");
    $pdo->exec("INSERT IGNORE INTO brands (name) VALUES ('General')");
    $pdo->exec("INSERT IGNORE INTO categories (name) VALUES ('General')");
    $pdo->exec("INSERT IGNORE INTO expense_types (name) VALUES ('Office'),('Transport'),('Utility')");
    $pdo->exec("INSERT IGNORE INTO roles (name,permissions) VALUES ('Administrator','all'),('Manager','dashboard,customer,supplier,product,purchase,sale,warranty,service,quotation,damage,expense,bank,emi,hrm,report'),('Sales Representative','dashboard,customer,product,sale,quotation'),('Staff','dashboard')");
    if ((int) $pdo->query('SELECT COUNT(*) FROM bank_accounts')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO bank_accounts (name,account_no,bank_name,balance) VALUES (?,?,?,0)')->execute(['Cash Account', 'CASH-001', 'Cash']);
    }
}

function installer_seed_demo(PDO $pdo): array
{
    $pdo->beginTransaction();
    try {
        $categoryId = (int) $pdo->query("SELECT id FROM categories WHERE name='General' LIMIT 1")->fetchColumn();
        $brandId = (int) $pdo->query("SELECT id FROM brands WHERE name='General' LIMIT 1")->fetchColumn();
        $unitId = (int) $pdo->query("SELECT id FROM units WHERE name='Piece' LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO contacts (type,name,mobile,email,address,opening_balance,advance_balance) SELECT 'customer','Demo Customer','DEMO-CUSTOMER','','Demo Address',0,0 WHERE NOT EXISTS (SELECT 1 FROM contacts WHERE mobile='DEMO-CUSTOMER')")->execute();
        $pdo->prepare("INSERT INTO contacts (type,name,mobile,email,address,opening_balance,advance_balance) SELECT 'supplier','Demo Supplier','DEMO-SUPPLIER','','Demo Address',0,0 WHERE NOT EXISTS (SELECT 1 FROM contacts WHERE mobile='DEMO-SUPPLIER')")->execute();
        $stmt = $pdo->prepare("INSERT INTO products (name,brand_id,category_id,unit_id,sku,barcode,stock,cost_price,sale_price,dealer_price,alert_qty,manage_stock) SELECT 'Demo Product',?,?,?,'DEMO-SKU-001','DEMO-BARCODE-001',10,100,140,125,2,1 WHERE NOT EXISTS (SELECT 1 FROM products WHERE barcode='DEMO-BARCODE-001')");
        $stmt->execute([$brandId ?: null, $categoryId ?: null, $unitId ?: null]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return ['customer' => 1, 'supplier' => 1, 'product' => 1];
}

function installer_create_admin(PDO $pdo, array $admin): int
{
    $name = trim((string) ($admin['name'] ?? ''));
    $phone = trim((string) ($admin['phone'] ?? ''));
    $password = (string) ($admin['password'] ?? '');
    if ($name === '' || $phone === '') throw new InvalidArgumentException('Administrator name and login/mobile are required.');
    if (!password_is_strong($password)) throw new InvalidArgumentException('Administrator password must be 12-128 characters and include uppercase, lowercase, number and symbol.');
    if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE status=1 AND LOWER(role) IN ('admin','administrator')")->fetchColumn() > 0) {
        throw new RuntimeException('An active administrator already exists in this database. Use the upgrade flow instead.');
    }
    $stmt = $pdo->prepare('INSERT INTO users (name,phone,password,role,must_change_password,password_changed_at) VALUES (?,?,?,?,0,NOW())');
    $stmt->execute([$name, $phone, password_hash($password, PASSWORD_DEFAULT), 'Administrator']);
    return (int) $pdo->lastInsertId();
}

function installer_env_quote(string $value): string
{
    if ($value !== '' && preg_match('/^[A-Za-z0-9._:\/\\@+-]+$/', $value)) return $value;
    return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r']) . '"';
}

function installer_detect_base_url(): string
{
    if (PHP_SAPI === 'cli') return '';
    $https = request_is_https();
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
    $directory = rtrim(dirname($script), '/.');
    return ($https ? 'https://' : 'http://') . $host . ($directory === '' ? '' : $directory);
}

function installer_write_environment(array $database, array $business): string
{
    $runtime = installer_runtime_directories();
    $httpHost = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $httpHost = preg_replace('/:\d+$/', '', $httpHost) ?? $httpHost;
    // Database servers are commonly `localhost` even on public/shared hosting.
    // Environment mode must therefore be derived from the web host, not DB_HOST.
    $environment = in_array($httpHost, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($httpHost, '.localhost')
        || str_ends_with($httpHost, '.test')
        ? 'local'
        : 'production';
    $lines = [
        '# Generated by Cloud Core POS secure installer. Do not publish or commit this file.',
        'POS_APP_ENV=' . $environment,
        'POS_TIMEZONE=' . installer_env_quote(trim((string) ($business['timezone'] ?? 'Asia/Dhaka')) ?: 'Asia/Dhaka'),
        'POS_DB_HOST=' . installer_env_quote(trim((string) ($database['host'] ?? '127.0.0.1')) ?: '127.0.0.1'),
        'POS_DB_PORT=' . max(1, min(65535, (int) ($database['port'] ?? 3306))),
        'POS_DB_NAME=' . installer_env_quote(installer_validate_database_name((string) ($database['name'] ?? ''))),
        'POS_DB_USER=' . installer_env_quote(trim((string) ($database['user'] ?? ''))),
        'POS_DB_PASS=' . installer_env_quote((string) ($database['password'] ?? '')),
        'POS_SESSION_IDLE_SECONDS=7200',
        'POS_TRUST_PROXY=false',
        'POS_ALLOW_WEB_INSTALL=false',
        'POS_BACKUP_RETENTION_DAYS=30',
        'POS_LOG_DIR=' . installer_env_quote($runtime['logs']),
        'POS_BACKUP_DIR=' . installer_env_quote($runtime['backups']),
        'POS_SERVICE_CREDENTIAL_KEY=' . installer_env_quote('base64:' . base64_encode(random_bytes(32))),
        'POS_BASE_URL=' . installer_env_quote(installer_detect_base_url()),
        '',
    ];
    $target = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    $temporary = $target . '.tmp-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, implode(PHP_EOL, $lines), LOCK_EX) === false) {
        throw new RuntimeException('Could not write the application environment file.');
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $target)) {
        @unlink($temporary);
        throw new RuntimeException('Could not activate the application environment file.');
    }
    @chmod($target, 0600);
    return $target;
}

function installer_write_lock(array $details): void
{
    installer_prepare_private_storage();
    $details['installed_at'] = date(DATE_ATOM);
    $details['project'] = SOFTWARE_NAME;
    $encoded = json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || file_put_contents(installer_lock_path(), $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Could not create the installation lock.');
    }
    @chmod(installer_lock_path(), 0600);
}

function installer_existing_database(): ?PDO
{
    if (DB_USER === '' || DB_NAME === '') return null;
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name IN ('users','settings')");
        $stmt->execute([DB_NAME]);
        return (int) $stmt->fetchColumn() === 2 ? $pdo : null;
    } catch (Throwable) {
        return null;
    }
}

function installer_state(): array
{
    $locked = is_file(installer_lock_path());
    $pdo = installer_existing_database();
    if ($pdo !== null) {
        try {
            $admin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status=1 AND LOWER(role) IN ('admin','administrator')")->fetchColumn();
            if ($admin > 0) {
                $pending = migration_pending_ids($pdo, DB_NAME);
                // A future code update may have pending migrations even though an older
                // installation lock exists. Administrator authentication unlocks upgrade only.
                if ($pending) return ['mode' => 'upgrade', 'pending' => $pending, 'pdo' => $pdo];
                return ['mode' => 'installed', 'pending' => [], 'pdo' => $pdo];
            }
        } catch (Throwable) {
            // Fall through to lock/fresh handling below.
        }
    }
    if ($locked) {
        return ['mode' => 'installed', 'pending' => [], 'pdo' => null];
    }
    return ['mode' => 'fresh', 'pending' => array_keys(migration_definitions()), 'pdo' => null];
}

function installer_authenticate_admin(PDO $pdo, string $phone, string $password): int
{
    $stmt = $pdo->prepare("SELECT id,password FROM users WHERE phone=? AND status=1 AND LOWER(role) IN ('admin','administrator') LIMIT 1");
    $stmt->execute([trim($phone)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, (string) $user['password'])) {
        throw new InvalidArgumentException('Administrator login is incorrect.');
    }
    return (int) $user['id'];
}

function installer_self_test(PDO $pdo, string $database): array
{
    $checks = [];
    $required = ['users','settings','contacts','products','roles','document_sequences','schema_migrations'];
    foreach ($required as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?');
        $stmt->execute([$database, $table]);
        $checks['table_' . $table] = (int) $stmt->fetchColumn() === 1;
    }
    $checks['contacts_advance_balance'] = migration_column_exists($pdo, $database, 'contacts', 'advance_balance');
    $checks['administrator'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status=1 AND LOWER(role) IN ('admin','administrator')")->fetchColumn() > 0;
    $checks['migrations_current'] = migration_pending_ids($pdo, $database) === [];
    return $checks;
}

function installer_perform_fresh_install(array $payload): array
{
    if (!installer_requirements_ready()) throw new RuntimeException('Server requirements are not ready.');
    $database = is_array($payload['database'] ?? null) ? $payload['database'] : [];
    $admin = is_array($payload['admin'] ?? null) ? $payload['admin'] : [];
    $business = is_array($payload['business'] ?? null) ? $payload['business'] : [];
    if (($admin['password'] ?? '') !== ($admin['password_confirm'] ?? '')) throw new InvalidArgumentException('Administrator passwords do not match.');

    $test = installer_test_database($database, !empty($payload['create_database']));
    /** @var PDO $pdo */
    $pdo = $test['pdo'];
    $pdo->exec(installer_schema_sql());
    $migrations = run_schema_migrations($pdo, $test['database']);
    installer_seed_core($pdo, $business);
    $adminId = installer_create_admin($pdo, $admin);
    $demo = !empty($payload['demo_data']) ? installer_seed_demo($pdo) : [];
    migrate_product_images($pdo);
    $checks = installer_self_test($pdo, $test['database']);
    if (in_array(false, $checks, true)) throw new RuntimeException('Post-install self-test did not pass.');
    installer_write_environment($database, $business);
    installer_write_lock(['database' => $test['database'], 'admin_id' => $adminId, 'schema_version' => end($migrations) ?: 'current']);
    return ['database_version' => $test['version'], 'migrations' => $migrations, 'demo' => (bool) $demo, 'checks' => $checks, 'admin_login' => trim((string) ($admin['phone'] ?? ''))];
}

function installer_perform_upgrade(PDO $pdo, string $phone, string $password): array
{
    $adminId = installer_authenticate_admin($pdo, $phone, $password);
    $pdo->exec(installer_schema_sql());
    $migrations = run_schema_migrations($pdo, DB_NAME);
    $checks = installer_self_test($pdo, DB_NAME);
    if (in_array(false, $checks, true)) throw new RuntimeException('Post-upgrade self-test did not pass.');
    installer_write_lock(['database' => DB_NAME, 'admin_id' => $adminId, 'schema_version' => end($migrations) ?: 'current', 'upgrade' => true]);
    return ['migrations' => $migrations, 'checks' => $checks];
}
