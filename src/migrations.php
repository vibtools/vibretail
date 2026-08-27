<?php
declare(strict_types=1);

/**
 * Versioned database migrations shared by fresh installation and upgrades.
 * Migration IDs are immutable once shipped.
 */
function migration_table_exists(PDO $pdo, string $database): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name='schema_migrations'");
    $stmt->execute([$database]);
    return (int) $stmt->fetchColumn() === 1;
}

function migration_column_exists(PDO $pdo, string $database, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?');
    $stmt->execute([$database, $table, $column]);
    return (int) $stmt->fetchColumn() === 1;
}

function migration_ensure_column(PDO $pdo, string $database, string $table, string $column, string $definition): void
{
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) {
        throw new InvalidArgumentException('Unsafe migration identifier.');
    }
    if (!migration_column_exists($pdo, $database, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function migration_definitions(): array
{
    return [
        '20260827_001_legacy_compatibility' => static function (PDO $pdo, string $database): void {
            $columns = [
                ['users', 'address', "VARCHAR(255) DEFAULT ''"], ['users', 'language', "VARCHAR(20) NOT NULL DEFAULT 'English'"],
                ['users', 'profile_photo', 'LONGTEXT NULL'], ['users', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 1'],
                ['users', 'auth_version', 'INT UNSIGNED NOT NULL DEFAULT 1'], ['users', 'password_changed_at', 'TIMESTAMP NULL'],
                ['users', 'last_login_at', 'TIMESTAMP NULL'], ['settings', 'vat_percentage', 'DECIMAL(7,2) NOT NULL DEFAULT 0'],
                ['settings', 'tin_number', "VARCHAR(80) DEFAULT ''"], ['settings', 'tagline', "VARCHAR(180) DEFAULT ''"],
                ['settings', 'website', "VARCHAR(180) DEFAULT ''"], ['settings', 'invoice_footer', "VARCHAR(255) DEFAULT ''"],
                ['settings', 'sms_invoice', 'TINYINT(1) NOT NULL DEFAULT 1'], ['settings', 'product_code', 'TINYINT(1) NOT NULL DEFAULT 0'],
                ['settings', 'vat_on_product', 'TINYINT(1) NOT NULL DEFAULT 0'], ['settings', 'printer_size', "VARCHAR(20) NOT NULL DEFAULT '80mm'"],
                ['settings', 'default_invoice', "VARCHAR(30) NOT NULL DEFAULT 'Invoice 1'"], ['settings', 'marketplace_status', "VARCHAR(30) NOT NULL DEFAULT 'inactive'"],
                ['settings', 'sms_balance', 'INT UNSIGNED NOT NULL DEFAULT 0'], ['settings', 'logo_data', 'MEDIUMTEXT NULL'],
                ['transactions', 'contact_id', 'INT UNSIGNED NULL'], ['transactions', 'created_by', 'INT UNSIGNED NULL'],
                ['services', 'technician_id', 'INT UNSIGNED NULL'], ['services', 'serial_no', "VARCHAR(160) DEFAULT ''"],
                ['services', 'device_password', 'TEXT NULL'], ['services', 'device_condition', "VARCHAR(180) DEFAULT ''"],
                ['services', 'technician_notes', 'TEXT NULL'], ['services', 'service_charge', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
                ['services', 'refund', 'DECIMAL(14,2) NOT NULL DEFAULT 0'], ['employees', 'role_id', 'INT UNSIGNED NULL'],
                ['employees', 'salary_day', 'TINYINT UNSIGNED NOT NULL DEFAULT 1'], ['employees', 'manage_business', 'TINYINT(1) NOT NULL DEFAULT 0'],
                ['employees', 'is_sr', 'TINYINT(1) NOT NULL DEFAULT 0'], ['emis', 'frequency', "ENUM('monthly','weekly','daily') NOT NULL DEFAULT 'monthly'"],
                ['quotations', 'bill_to', "VARCHAR(180) DEFAULT ''"], ['quotations', 'profit', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
                ['quotations', 'created_by', 'INT UNSIGNED NULL'], ['products', 'image_data', 'MEDIUMTEXT NULL'],
                ['damages', 'reference_no', "VARCHAR(40) NOT NULL DEFAULT ''"], ['damages', 'serial_no', "VARCHAR(160) DEFAULT ''"],
                ['damages', 'purchase_price', 'DECIMAL(14,2) NOT NULL DEFAULT 0'], ['damages', 'total', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
            ];
            foreach ($columns as [$table, $column, $definition]) {
                migration_ensure_column($pdo, $database, $table, $column, $definition);
            }
            $pdo->exec('ALTER TABLE services MODIFY device_password TEXT NULL');
        },
        '20260827_002_contacts_advance_balance' => static function (PDO $pdo, string $database): void {
            migration_ensure_column($pdo, $database, 'contacts', 'advance_balance', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER opening_balance');
        },
        '20260827_003_document_sequences_and_serial_status' => static function (PDO $pdo, string $database): void {
            foreach (['sales', 'purchases', 'services', 'quotations', 'sale_returns', 'purchase_returns', 'damages', 'rmas'] as $table) {
                $max = (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM `{$table}`")->fetchColumn();
                $stmt = $pdo->prepare('INSERT INTO document_sequences (document_type,sequence_no) VALUES (?,?) ON DUPLICATE KEY UPDATE sequence_no=GREATEST(sequence_no,VALUES(sequence_no))');
                $stmt->execute([$table, $max]);
            }
            $pdo->prepare('INSERT INTO document_sequences (document_type,sequence_no) VALUES (?,0) ON DUPLICATE KEY UPDATE sequence_no=sequence_no')->execute(['transfers']);
            $pdo->exec("ALTER TABLE serials MODIFY status ENUM('stock','sold','rma','returned','damaged') NOT NULL DEFAULT 'stock'");
        },
    ];
}

function migration_applied_ids(PDO $pdo, string $database): array
{
    if (!migration_table_exists($pdo, $database)) {
        return [];
    }
    return array_map('strval', $pdo->query('SELECT migration_id FROM schema_migrations ORDER BY migration_id')->fetchAll(PDO::FETCH_COLUMN));
}

function migration_pending_ids(PDO $pdo, string $database): array
{
    return array_values(array_diff(array_keys(migration_definitions()), migration_applied_ids($pdo, $database)));
}

function run_schema_migrations(PDO $pdo, string $database): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_id VARCHAR(120) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $applied = array_fill_keys(migration_applied_ids($pdo, $database), true);
    $ran = [];
    foreach (migration_definitions() as $id => $migration) {
        if (isset($applied[$id])) {
            continue;
        }
        $migration($pdo, $database);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration_id) VALUES (?)');
        $stmt->execute([$id]);
        $ran[] = $id;
    }
    return $ran;
}
