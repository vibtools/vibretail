<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/config.php';
require dirname(__DIR__, 2) . '/src/product-images.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only.');
}

$pdo = db();
$results = [];
$check = static function (string $name, callable $test) use (&$results): void {
    try {
        $passed = (bool) $test();
        $results[] = ['name' => $name, 'passed' => $passed, 'detail' => $passed ? 'OK' : 'FAILED'];
    } catch (Throwable $error) {
        $results[] = ['name' => $name, 'passed' => false, 'detail' => $error->getMessage()];
    }
};

$expectedTables = [
    'users','settings','contacts','brands','categories','subcategories','units','products','bank_accounts',
    'sales','sale_items','purchases','purchase_items','sale_returns','sale_return_items','purchase_returns',
    'purchase_return_items','expense_types','expenses','transactions','serials','rmas','services','quotations',
    'quotation_items','damages','investors','emis','emi_installments','emi_payments','employees','attendance',
    'attendance_schedules','roles','cheques','contact_payments','marketplace_requests','sms_purchases','activity_logs',
    'login_attempts','document_sequences','schema_migrations',
];

$check('Database connection', static fn(): bool => (int) $pdo->query('SELECT 1')->fetchColumn() === 1);
$check('PHP runtime', static fn(): bool => version_compare(PHP_VERSION, '8.1.0', '>=') && array_filter(['pdo_mysql','mbstring','openssl','gd','exif'], static fn(string $extension): bool => !extension_loaded($extension)) === [] && function_exists('getimagesizefromstring'));
$check('Platform environment', static fn(): bool => DB_USER !== '' && DB_NAME !== '' && (is_readable(PLATFORM_ENV_FILE) || env_value('POS_DB_USER', '') !== ''));
$check('External application logging', static fn(): bool => is_string($GLOBALS['POS_APPLICATION_LOG'] ?? null) && !str_starts_with((string) ($GLOBALS['POS_APPLICATION_LOG'] ?? ''), dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR));
$check('Service credential encryption key', static function (): bool { try { return strlen(service_credential_key()) === 32; } catch (Throwable) { return false; } });
$check('Product image storage', static function () use ($pdo): bool {
    $directory = ensure_product_image_directory();
    if (!is_dir($directory) || !is_writable($directory)) {
        return false;
    }
    $images = $pdo->query("SELECT image_data FROM products WHERE image_data IS NOT NULL AND image_data <> ''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($images as $image) {
        $absolutePath = product_image_absolute_path((string) $image);
        if ($absolutePath === null || !is_file($absolutePath)) {
            return false;
        }
    }
    return true;
});
$check('Required tables', static function () use ($pdo, $expectedTables): bool {
    $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=?');
    $stmt->execute([DB_NAME]);
    $actual = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return array_diff($expectedTables, $actual) === [];
});
$check('Contact advance balance schema', static function () use ($pdo): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=? AND table_name='contacts' AND column_name='advance_balance'");
    $stmt->execute([DB_NAME]);
    return (int) $stmt->fetchColumn() === 1;
});
$check('Schema migrations current', static function () use ($pdo): bool {
    require_once dirname(__DIR__, 2) . '/src/migrations.php';
    return migration_pending_ids($pdo, DB_NAME) === [];
});
$check('Contact CRUD transaction', static function () use ($pdo): bool {
    $token = 'UAT-' . bin2hex(random_bytes(5));
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO contacts (type,name,mobile,email,address,contact_person,opening_balance,advance_balance) VALUES ('customer',?,?,?,?,?,10.25,4.75)");
        $stmt->execute(['UAT Contact', $token, 'uat@example.invalid', 'UAT Address', 'UAT Person']);
        $id = (int) $pdo->lastInsertId();
        $row = $pdo->query('SELECT id,type,name,mobile,opening_balance,advance_balance FROM contacts WHERE id=' . $id)->fetch();
        if (!$row || (float) $row['advance_balance'] !== 4.75) { $pdo->rollBack(); return false; }
        $update = $pdo->prepare("UPDATE contacts SET type='supplier',advance_balance=7.50 WHERE id=?");
        $update->execute([$id]);
        $updated = $pdo->query('SELECT type,advance_balance FROM contacts WHERE id=' . $id)->fetch();
        $ok = $updated && $updated['type'] === 'supplier' && abs((float) $updated['advance_balance'] - 7.50) < 0.001;
        $pdo->rollBack();
        return $ok;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
});
$check('InnoDB storage', static function () use ($pdo): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND engine<>'InnoDB'");
    $stmt->execute([DB_NAME]);
    return (int) $stmt->fetchColumn() === 0;
});
$check('Business settings', static fn(): bool => (int) $pdo->query('SELECT COUNT(*) FROM settings WHERE id=1')->fetchColumn() === 1);
$check('VibRetail branding', static fn(): bool => in_array((string) $pdo->query('SELECT business_name FROM settings WHERE id=1')->fetchColumn(), [SOFTWARE_NAME, 'Cloud Core POS'], true));
$check('License files and About route', static fn(): bool => is_file(dirname(__DIR__, 2) . '/LICENSE') && is_file(dirname(__DIR__, 2) . '/src/LICENSE.md') && is_file(dirname(__DIR__, 2) . '/src/about.php') && !is_file(dirname(__DIR__, 2) . '/src/license.php') && DEVELOPER_NAME === 'Vib Tools');
$check('Active administrator', static fn(): bool => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status=1 AND LOWER(role) IN ('admin','administrator')")->fetchColumn() > 0);
$check('Administrator role permissions', static fn(): bool => (int) $pdo->query("SELECT COUNT(*) FROM roles WHERE LOWER(name)='administrator' AND permissions='all'")->fetchColumn() === 1);
$check('Password hashes', static function () use ($pdo): bool {
    foreach ($pdo->query('SELECT password FROM users')->fetchAll(PDO::FETCH_COLUMN) as $hash) {
        if (password_get_info((string) $hash)['algoName'] === 'unknown') return false;
    }
    return true;
});
$check('Sale item integrity', static fn(): bool => (int) $pdo->query('SELECT COUNT(*) FROM sale_items i LEFT JOIN sales s ON s.id=i.sale_id LEFT JOIN products p ON p.id=i.product_id WHERE s.id IS NULL OR p.id IS NULL')->fetchColumn() === 0);
$check('Purchase item integrity', static fn(): bool => (int) $pdo->query('SELECT COUNT(*) FROM purchase_items i LEFT JOIN purchases p ON p.id=i.purchase_id LEFT JOIN products x ON x.id=i.product_id WHERE p.id IS NULL OR x.id IS NULL')->fetchColumn() === 0);
$check('Sale totals', static fn(): bool => (int) $pdo->query('SELECT COUNT(*) FROM sales WHERE ABS(total-(subtotal-discount+vat+other_cost))>0.02 OR ABS(due-(total-paid))>0.02')->fetchColumn() === 0);
$check('Purchase totals', static fn(): bool => (int) $pdo->query('SELECT COUNT(*) FROM purchases WHERE ABS(total-(subtotal-discount+other_cost))>0.02 OR ABS(due-(total-paid))>0.02')->fetchColumn() === 0);
$check('Unique document numbers', static fn(): bool => (int) $pdo->query("SELECT (SELECT COUNT(*) FROM (SELECT invoice_no FROM sales GROUP BY invoice_no HAVING COUNT(*)>1) s)+(SELECT COUNT(*) FROM (SELECT invoice_no FROM purchases GROUP BY invoice_no HAVING COUNT(*)>1) p)")->fetchColumn() === 0);
$check('Document sequences', static fn(): bool => (int) $pdo->query("SELECT COUNT(*) FROM document_sequences q WHERE (q.document_type='sales' AND q.sequence_no<(SELECT COALESCE(MAX(id),0) FROM sales)) OR (q.document_type='purchases' AND q.sequence_no<(SELECT COALESCE(MAX(id),0) FROM purchases)) OR (q.document_type='services' AND q.sequence_no<(SELECT COALESCE(MAX(id),0) FROM services)) OR (q.document_type='quotations' AND q.sequence_no<(SELECT COALESCE(MAX(id),0) FROM quotations))")->fetchColumn() === 0);


$check('Legacy service credentials remediated', static fn(): bool => (int) $pdo->query("SELECT COUNT(*) FROM services WHERE COALESCE(device_password,'')<>'' AND device_password NOT LIKE 'enc:v1:%'")->fetchColumn() === 0);
$check('Terminal service credentials purged', static fn(): bool => (int) $pdo->query("SELECT COUNT(*) FROM services WHERE status IN ('delivered','cancelled') AND COALESCE(device_password,'')<>''")->fetchColumn() === 0);
$check('EMI payment ceiling', static fn(): bool => (int) $pdo->query("SELECT COUNT(*) FROM emis e WHERE COALESCE((SELECT SUM(p.amount) FROM emi_payments p WHERE p.emi_id=e.id),0) > (e.total-e.down_payment)+0.02")->fetchColumn() === 0);
$check('EMI schedule totals', static fn(): bool => (int) $pdo->query("SELECT COUNT(*) FROM emis e WHERE ABS(COALESCE((SELECT SUM(i.amount) FROM emi_installments i WHERE i.emi_id=e.id),0)-(e.total-e.down_payment))>0.02")->fetchColumn() === 0);
$check('Return quantity ceilings', static fn(): bool => (int) $pdo->query("SELECT (SELECT COUNT(*) FROM (SELECT i.sale_id,i.product_id,i.qty,COALESCE((SELECT SUM(ri.qty) FROM sale_return_items ri JOIN sale_returns r ON r.id=ri.sale_return_id WHERE r.sale_id=i.sale_id AND ri.product_id=i.product_id),0) returned FROM sale_items i HAVING returned>qty+0.0001) x)+(SELECT COUNT(*) FROM (SELECT i.purchase_id,i.product_id,i.qty,COALESCE((SELECT SUM(ri.qty) FROM purchase_return_items ri JOIN purchase_returns r ON r.id=ri.purchase_return_id WHERE r.purchase_id=i.purchase_id AND ri.product_id=i.product_id),0) returned FROM purchase_items i HAVING returned>qty+0.0001) y)")->fetchColumn() === 0);
$check('Phase-02 reference uniqueness', static fn(): bool => (int) $pdo->query("SELECT (SELECT COUNT(*) FROM (SELECT reference FROM sale_returns GROUP BY reference HAVING COUNT(*)>1) a)+(SELECT COUNT(*) FROM (SELECT reference FROM purchase_returns GROUP BY reference HAVING COUNT(*)>1) b)+(SELECT COUNT(*) FROM (SELECT reference_no FROM damages GROUP BY reference_no HAVING COUNT(*)>1) c)+(SELECT COUNT(*) FROM (SELECT rma_no FROM rmas GROUP BY rma_no HAVING COUNT(*)>1) d)")->fetchColumn() === 0);
$check('Phase-02 document sequences', static fn(): bool => (int) $pdo->query("SELECT COUNT(*) FROM document_sequences WHERE document_type IN ('sale_returns','purchase_returns','damages','transfers','rmas')")->fetchColumn() === 5);
$check('No inline product image blobs', static fn(): bool => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE image_data LIKE 'data:image/%'")->fetchColumn() === 0);
$check('Managed JPG privacy', static function (): bool {
    if (!function_exists('exif_read_data')) return false;
    foreach (glob(product_image_directory() . DIRECTORY_SEPARATOR . '*.jpg') ?: [] as $path) {
        $exif = @exif_read_data($path, null, true, false);
        if (is_array($exif) && isset($exif['GPS'])) return false;
    }
    return true;
});
$check('Audit log detail secret scan', static fn(): bool => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE details REGEXP '(enc:v1:|POS_SERVICE_CREDENTIAL_KEY|POS_DB_PASS|password[[:space:]]*[=:])'")->fetchColumn() === 0);

$failed = array_filter($results, static fn(array $result): bool => !$result['passed']);
foreach ($results as $result) {
    echo ($result['passed'] ? '[PASS] ' : '[FAIL] ') . $result['name'] . ($result['detail'] === 'OK' ? '' : ': ' . $result['detail']) . PHP_EOL;
}
echo PHP_EOL . count($results) . ' checks, ' . count($failed) . ' failed.' . PHP_EOL;
exit($failed ? 1 : 0);
