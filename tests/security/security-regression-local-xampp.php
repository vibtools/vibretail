<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$repoRoot=dirname(__DIR__, 2);$root=$repoRoot.'/src';$fail=0;
$check=function(bool $ok,string $name)use(&$fail){echo ($ok?'[PASS] ':'[FAIL] ').$name.PHP_EOL;if(!$ok)$fail++;};
$config=(string)file_get_contents($root.'/config.php');
$backup=(string)file_get_contents($root.'/backup.php');
$restore=(string)file_get_contents($root.'/restore.php');
$builder=(string)file_get_contents($repoRoot.'/scripts/release/build-release.php');
$runtime=(string)file_get_contents($repoRoot.'/tests/runtime/runtime-security-check.php');
$preflight=(string)file_get_contents($repoRoot.'/scripts/local/local-xampp-preflight.php');$gateLib=(string)file_get_contents($repoRoot.'/scripts/local/local-gate-lib.php');
$check(str_contains($config,'function resolve_local_mysql_binary'),'XAMPP MySQL binary autodetection helper exists');
$check(str_contains($backup,'mysql_dump_binary()'),'Backup uses resolved mysqldump binary');
$check(str_contains($restore,'mysql_client_binary()'),'Restore uses resolved mysql binary');
$check(str_contains($preflight,'local target guard')&&str_contains($gateLib,"'127.0.0.1'")&&str_contains($gateLib,'POS_APP_ENV=production'),'Preflight enforces local target guard');
$check(is_file($repoRoot.'/scripts/local/local-db-create.php')&&str_contains((string)file_get_contents($repoRoot.'/scripts/local/local-db-create.php'),'LOCAL-XAMPP-DB-CREATE'),'Local DB create requires explicit confirmation');
$check(is_file($repoRoot.'/scripts/local/local-db-state.php'),'Read-only local DB state tool exists');
$check(str_contains($runtime,'login rejects missing CSRF')&&str_contains($runtime,'malformed JSON rejected'),'Runtime HTTP probe covers CSRF and malformed JSON');
$check(str_contains($runtime,"'/.env.windows'")&&str_contains($runtime,"'/tools/local-db-create.php'"),'Runtime HTTP probe covers local sensitive paths');
$check(str_contains($builder, "realpath(\$repoRoot . DIRECTORY_SEPARATOR . 'src')") && !str_contains($builder, "scripts/local"), 'Clean release is built only from src and excludes repository-local tools');
$envExample=(string)file_get_contents($root.'/.env.windows.example');
$check(str_contains($envExample,'POS_APP_ENV=local')&&str_contains($envExample,'POS_MYSQLDUMP_PATH=')&&str_contains($envExample,'POS_MYSQL_PATH='),'Windows example supports XAMPP autodetection');
exit($fail?1:0);
