<?php
declare(strict_types=1);

require __DIR__ . '/local-gate-lib.php';

$options = getopt('', ['json', 'help']);
if (isset($options['help'])) {
    echo "Usage: php tools/local-db-state.php [--json]\n";
    exit(0);
}

try {
    local_gate_assert_safe_target();
    $pdo = db();
    $tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=" . $pdo->quote(DB_NAME))->fetchColumn();
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $counts = [];
    foreach (['users','products','contacts','sales','purchases','services','rmas','transactions','activity_logs','document_sequences','schema_migrations'] as $table) {
        try {
            $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        } catch (Throwable) {
            $counts[$table] = null;
        }
    }
    $legacyCredentials = null;
    $terminalCredentials = null;
    try {
        $legacyCredentials = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE COALESCE(device_password,'')<>'' AND device_password NOT LIKE 'enc:v1:%'")->fetchColumn();
        $terminalCredentials = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE status IN ('delivered','cancelled') AND COALESCE(device_password,'')<>''")->fetchColumn();
    } catch (Throwable) {}

    $result = [
        'project' => 'VibRetail',
        'candidate' => 'CCPOS-EZ-2026.08.27-001',
        'db_host' => DB_HOST,
        'db_name' => DB_NAME,
        'server_version' => $version,
        'table_count' => $tables,
        'row_counts' => $counts,
        'legacy_service_credentials' => $legacyCredentials,
        'terminal_service_credentials' => $terminalCredentials,
        'contacts_advance_balance' => (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=" . $pdo->quote(DB_NAME) . " AND table_name='contacts' AND column_name='advance_balance'")->fetchColumn() === 1,
        'service_key_fingerprint' => local_gate_service_key_fingerprint(),
    ];
    if (array_key_exists('json', $options)) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo "VibRetail - Local DB State\n";
        echo 'DB: ' . DB_NAME . '@' . DB_HOST . ' server=' . $version . PHP_EOL;
        echo 'Tables: ' . $tables . PHP_EOL;
        foreach ($counts as $table => $count) echo $table . '=' . ($count === null ? 'MISSING' : $count) . PHP_EOL;
        echo 'legacy_service_credentials=' . ($legacyCredentials === null ? 'N/A' : $legacyCredentials) . PHP_EOL;
        echo 'terminal_service_credentials=' . ($terminalCredentials === null ? 'N/A' : $terminalCredentials) . PHP_EOL;
        echo 'contacts_advance_balance=' . ($result['contacts_advance_balance'] ? 'YES' : 'NO') . PHP_EOL;
        echo 'service_key_fingerprint=' . local_gate_service_key_fingerprint() . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Local DB state check failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
