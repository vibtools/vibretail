<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/scripts/local/local-gate-lib.php';

$options = getopt('', ['base-url:', 'help']);
if (isset($options['help']) || empty($options['base-url'])) {
    echo "Usage: set CCPOS_UAT_ADMIN_PHONE / CCPOS_UAT_ADMIN_PASSWORD, then:\n";
    echo "  php tools/runtime-contact-crud-check.php --base-url=http://localhost/cloudcorepos\n";
    echo "Credentials are read from environment variables and are never printed.\n";
    exit(isset($options['help']) ? 0 : 2);
}

local_gate_assert_safe_target();
$phone = (string) (getenv('CCPOS_UAT_ADMIN_PHONE') ?: '');
$password = (string) (getenv('CCPOS_UAT_ADMIN_PASSWORD') ?: '');
if ($phone === '' || $password === '') {
    fwrite(STDERR, "Set CCPOS_UAT_ADMIN_PHONE and CCPOS_UAT_ADMIN_PASSWORD in the current CMD session first.\n");
    exit(2);
}
$base = rtrim((string) $options['base-url'], '/');
$failed = 0;
$createdMobiles = [];

function crud_http(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array
{
    if ($body !== null) $headers[] = 'Content-Length: ' . strlen($body);
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0,
        'header' => implode("\r\n", $headers), 'content' => $body ?? '',
    ]]);
    $content = @file_get_contents($url, false, $ctx);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if ($responseHeaders && preg_match('#HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $m)) $status = (int) $m[1];
    return ['status'=>$status,'headers'=>$responseHeaders,'body'=>$content === false ? '' : $content];
}

function crud_last_cookie(array $headers, string $fallback = ''): string
{
    $cookie = $fallback;
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) {
            $value = trim(substr($header, 11));
            if (str_starts_with($value, 'POSSESSID=')) $cookie = trim(explode(';', $value, 2)[0]);
        }
    }
    return $cookie;
}

function crud_csrf(string $html): string
{
    if (!preg_match('/window\.POS_CONFIG\s*=\s*(\{.*?\});/s', $html, $m)) return '';
    $data = json_decode($m[1], true);
    return is_array($data) ? (string) ($data['csrf'] ?? '') : '';
}

function crud_json(array $response): array
{
    $data = json_decode($response['body'], true);
    return is_array($data) ? $data : [];
}

function crud_report(bool $ok, string $name, string $detail = ''): void
{
    global $failed;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (!$ok) $failed++;
}

try {
    $loginPage = crud_http($base . '/index.php');
    $cookie = crud_last_cookie($loginPage['headers']);
    $csrf = crud_csrf($loginPage['body']);
    crud_report($loginPage['status'] === 200 && $cookie !== '' && $csrf !== '', 'Login bootstrap');
    if ($failed) exit(1);

    $login = crud_http($base . '/api.php?action=login', 'POST', json_encode(['phone'=>$phone,'password'=>$password]), [
        'Content-Type: application/json', 'Accept: application/json', 'Cookie: ' . $cookie, 'X-CSRF-Token: ' . $csrf,
    ]);
    $loginData = crud_json($login);
    $cookie = crud_last_cookie($login['headers'], $cookie);
    crud_report($login['status'] === 200 && ($loginData['ok'] ?? false) === true, 'Administrator API login', 'HTTP ' . $login['status']);
    if ($failed) exit(1);

    $dashboard = crud_http($base . '/dashboard.php', 'GET', null, ['Cookie: ' . $cookie]);
    $csrf = crud_csrf($dashboard['body']);
    crud_report($dashboard['status'] === 200 && $csrf !== '', 'Authenticated CSRF refresh', 'HTTP ' . $dashboard['status']);
    if ($failed) exit(1);

    $token = 'EZUAT-' . strtoupper(bin2hex(random_bytes(4)));
    foreach ([['customer','Customer'],['supplier','Supplier']] as [$type,$label]) {
        $mobile = $token . '-' . strtoupper($type);
        $createdMobiles[] = $mobile;
        $payload = ['type'=>$type,'name'=>'EZ UAT ' . $label,'mobile'=>$mobile,'email'=>'','address'=>'Runtime UAT','contact_person'=>'','opening_balance'=>10.25,'advance_balance'=>4.75];
        $create = crud_http($base . '/api.php?action=contact_save', 'POST', json_encode($payload), ['Content-Type: application/json','Accept: application/json','Cookie: '.$cookie,'X-CSRF-Token: '.$csrf]);
        $createData = crud_json($create);
        crud_report($create['status'] === 200 && ($createData['ok'] ?? false) === true, $label . ' create API', 'HTTP ' . $create['status']);

        $list = crud_http($base . '/api.php?action=contacts&type=' . $type, 'GET', null, ['Accept: application/json','Cookie: '.$cookie]);
        $listData = crud_json($list);
        $match = null;
        foreach ((array) ($listData['data'] ?? []) as $row) if (($row['mobile'] ?? '') === $mobile) { $match = $row; break; }
        crud_report($list['status'] === 200 && is_array($match) && abs((float) ($match['advance_balance'] ?? -1) - 4.75) < .001, $label . ' list API + advance balance', 'HTTP ' . $list['status']);
        if (is_array($match)) {
            $payload['id'] = (int) $match['id'];
            $payload['name'] = 'EZ UAT ' . $label . ' Updated';
            $payload['advance_balance'] = 7.50;
            $update = crud_http($base . '/api.php?action=contact_save', 'POST', json_encode($payload), ['Content-Type: application/json','Accept: application/json','Cookie: '.$cookie,'X-CSRF-Token: '.$csrf]);
            $updateData = crud_json($update);
            crud_report($update['status'] === 200 && ($updateData['ok'] ?? false) === true, $label . ' update API', 'HTTP ' . $update['status']);
        }
    }
} finally {
    if ($createdMobiles) {
        try {
            $pdo = db();
            $placeholders = implode(',', array_fill(0, count($createdMobiles), '?'));
            $stmt = $pdo->prepare("DELETE FROM contacts WHERE mobile IN ({$placeholders})");
            $stmt->execute($createdMobiles);
            echo '[PASS] Runtime CRUD test data cleanup - rows=' . $stmt->rowCount() . PHP_EOL;
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, '[FAIL] Runtime CRUD cleanup - ' . $cleanupError->getMessage() . PHP_EOL);
            $failed++;
        }
    }
    putenv('CCPOS_UAT_ADMIN_PASSWORD');
}
exit($failed ? 1 : 0);
