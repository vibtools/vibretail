<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$options = getopt('', ['base-url:', 'help']);
if (isset($options['help']) || empty($options['base-url'])) {
    echo "Usage after local XAMPP Apache/MySQL starts:\n";
    echo "  php tools/runtime-security-check.php --base-url=http://localhost/cloud-core-pos\n";
    echo "This probe is unauthenticated and does not require or print credentials.\n";
    exit(isset($options['help']) ? 0 : 2);
}

$base = rtrim((string) $options['base-url'], '/');
$failed = 0;

function http_probe(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array
{
    $headerLines = $headers;
    if ($body !== null) {
        $headerLines[] = 'Content-Length: ' . strlen($body);
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 8,
            'follow_location' => 0,
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
        ],
    ]);
    $content = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if ($responseHeaders && preg_match('#HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $matches)) {
        $status = (int) $matches[1];
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $content === false ? '' : $content];
}

function last_cookie(array $headers): string
{
    $cookie = '';
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) {
            $value = trim(substr($header, strlen('Set-Cookie:')));
            if (str_starts_with($value, 'POSSESSID=')) {
                $cookie = trim(explode(';', $value, 2)[0]);
            }
        }
    }
    return $cookie;
}

function header_count(array $headers, string $name): int
{
    $prefix = strtolower($name) . ':';
    return count(array_filter($headers, static fn(string $header): bool => str_starts_with(strtolower($header), $prefix)));
}

function extract_login_csrf(string $html): string
{
    if (!preg_match('/window\.POS_CONFIG\s*=\s*(\{.*?\});/s', $html, $matches)) {
        return '';
    }
    $decoded = json_decode($matches[1], true);
    return is_array($decoded) ? (string) ($decoded['csrf'] ?? '') : '';
}

function report_probe(bool $ok, string $name, string $detail = ''): void
{
    global $failed;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name;
    if ($detail !== '') echo ' - ' . $detail;
    echo PHP_EOL;
    if (!$ok) $failed++;
}

$login = http_probe($base . '/index.php');
report_probe($login['status'] === 200, 'login page reachable', 'HTTP ' . $login['status']);
$cookie = last_cookie($login['headers']);
$csrf = extract_login_csrf($login['body']);
report_probe($cookie !== '', 'session cookie issued', $cookie === '' ? 'missing' : 'present');
report_probe(header_count($login['headers'], 'Set-Cookie') === 1, 'single authoritative session cookie', 'count=' . header_count($login['headers'], 'Set-Cookie'));
foreach (['X-Content-Type-Options','X-Frame-Options','Referrer-Policy','Permissions-Policy'] as $securityHeader) {
    report_probe(header_count($login['headers'], $securityHeader) === 1, 'single response header ' . $securityHeader, 'count=' . header_count($login['headers'], $securityHeader));
}
report_probe(header_count($login['headers'], 'X-Powered-By') === 0, 'PHP technology header hidden');
report_probe((bool) preg_match('/^[a-f0-9]{48}$/', $csrf), 'login CSRF token issued', $csrf === '' ? 'missing' : 'present');

foreach (['/.env','/.env.server','/.env.windows','/error_log','/schema.sql','/migrations.php','/installer-lib.php','/backup.php','/restore.php','/install','/install.php','/storage/private/installed.lock','/tools/migrate-service-credentials.php','/tools/local-db-create.php','/probe.zip'] as $path) {
    $response = http_probe($base . $path);
    $ok = in_array($response['status'], [403,404], true);
    report_probe($ok, 'sensitive path ' . $path, 'HTTP ' . $response['status']);
}

$response = http_probe($base . '/api.php?action=dashboard');
report_probe($response['status'] === 401, 'unauthenticated API denied', 'HTTP ' . $response['status']);

if ($cookie !== '') {
    $response = http_probe(
        $base . '/api.php?action=login',
        'POST',
        json_encode(['phone' => 'runtime-probe', 'password' => 'invalid'], JSON_UNESCAPED_SLASHES),
        ['Content-Type: application/json', 'Cookie: ' . $cookie]
    );
    report_probe($response['status'] === 419, 'login rejects missing CSRF', 'HTTP ' . $response['status']);
}

if ($cookie !== '' && $csrf !== '') {
    $response = http_probe(
        $base . '/api.php?action=login',
        'POST',
        '{invalid-json',
        ['Content-Type: application/json', 'Cookie: ' . $cookie, 'X-CSRF-Token: ' . $csrf]
    );
    report_probe($response['status'] === 400, 'malformed JSON rejected', 'HTTP ' . $response['status']);
    $safeError = !str_contains(strtolower($response['body']), 'sqlstate')
        && !str_contains(strtolower($response['body']), 'stack trace')
        && !str_contains(strtolower($response['body']), 'pos_db_pass');
    report_probe($safeError, 'probe response contains no obvious internal secret/SQL trace');
}

exit($failed ? 1 : 0);
