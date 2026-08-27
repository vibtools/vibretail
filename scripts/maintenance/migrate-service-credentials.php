<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI access only.'); }
$options = getopt('', ['apply', 'confirm:', 'help']);
if (isset($options['help'])) {
    echo "Dry run: php tools/migrate-service-credentials.php\n";
    echo "Apply:   php tools/migrate-service-credentials.php --apply --confirm=PHASE2-SERVICE-CREDENTIALS\n";
    exit(0);
}
$apply = array_key_exists('apply', $options);
if ($apply && ($options['confirm'] ?? '') !== 'PHASE2-SERVICE-CREDENTIALS') {
    fwrite(STDERR, "Refusing destructive migration without --confirm=PHASE2-SERVICE-CREDENTIALS\n"); exit(2);
}
$pdo = db();
service_credential_key();
$rows = $pdo->query("SELECT id,status,device_password FROM services WHERE COALESCE(device_password,'')<>'' ORDER BY id")->fetchAll();
$counts = ['rows'=>count($rows),'already_encrypted'=>0,'legacy_active'=>0,'terminal_purge'=>0,'changed'=>0];
if ($apply) $pdo->beginTransaction();
try {
    $encrypt = $apply ? $pdo->prepare('UPDATE services SET device_password=? WHERE id=?') : null;
    $purge = $apply ? $pdo->prepare('UPDATE services SET device_password=NULL WHERE id=?') : null;
    foreach ($rows as $row) {
        $stored = (string) $row['device_password']; $id = (int) $row['id']; $status = (string) $row['status'];
        if (str_starts_with($stored, 'enc:v1:')) { decrypt_service_credential($stored); $counts['already_encrypted']++; continue; }
        if (in_array($status, ['delivered','cancelled'], true)) {
            $counts['terminal_purge']++; if ($apply) { $purge->execute([$id]); $counts['changed'] += $purge->rowCount(); } continue;
        }
        $counts['legacy_active']++;
        if ($apply) { $encrypt->execute([encrypt_service_credential($stored), $id]); $counts['changed'] += $encrypt->rowCount(); }
    }
    if ($apply) $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Migration failed. Request ID: ' . request_id() . PHP_EOL); app_log('error','Service credential migration failed',['message'=>$e->getMessage()]); exit(1);
}
echo ($apply ? 'APPLY' : 'DRY-RUN') . " service credential migration\n";
foreach ($counts as $k=>$v) echo $k . '=' . $v . PHP_EOL;
echo "No credential plaintext was printed.\n";
