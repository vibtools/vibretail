<?php
declare(strict_types=1);

require __DIR__ . '/local-gate-lib.php';

$options = getopt('', ['confirm:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php tools/local-db-create.php --confirm=LOCAL-XAMPP-DB-CREATE\n";
    echo "Creates POS_DB_NAME only on a localhost/127.x DB server. Existing databases are left unchanged.\n";
    exit(0);
}
if (($options['confirm'] ?? '') !== 'LOCAL-XAMPP-DB-CREATE') {
    fwrite(STDERR, "Refusing database creation without --confirm=LOCAL-XAMPP-DB-CREATE\n");
    exit(2);
}

try {
    local_gate_assert_safe_target();
    $server = db(true);
    $stmt = $server->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');
    $stmt->execute([DB_NAME]);
    if ((int) $stmt->fetchColumn() > 0) {
        echo 'Database already exists; no change: ' . DB_NAME . PHP_EOL;
        exit(0);
    }
    $quoted = '`' . str_replace('`', '``', DB_NAME) . '`';
    $server->exec('CREATE DATABASE ' . $quoted . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo 'Created local database: ' . DB_NAME . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Local database creation failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
