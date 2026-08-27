<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI access only.'); }
$withDb = in_array('--db',$argv,true); $required=['pdo_mysql','mbstring','openssl','json','gd','exif']; $failed=0;
echo 'VibRetail readiness check' . PHP_EOL;
echo 'PHP=' . PHP_VERSION . PHP_EOL;
foreach ($required as $ext) { $ok=extension_loaded($ext); echo ($ok?'[PASS] ':'[FAIL] ') . 'extension ' . $ext . PHP_EOL; if(!$ok)$failed++; }
$checks=[
 'environment file configured'=>is_readable(PLATFORM_ENV_FILE) || env_value('POS_DB_USER','')!=='',
 'service key configured'=>(function(){try{return strlen(service_credential_key())===32;}catch(Throwable){return false;}})(),
 'log directory external'=>!str_starts_with((string)($GLOBALS['POS_APPLICATION_LOG']??''),dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR),
 'web installer disabled'=>!env_bool('POS_ALLOW_WEB_INSTALL',false),
];
foreach($checks as $name=>$ok){echo ($ok?'[PASS] ':'[FAIL] ').$name.PHP_EOL;if(!$ok)$failed++;}
if($withDb){try{$pdo=db();$ok=(int)$pdo->query('SELECT 1')->fetchColumn()===1;echo ($ok?'[PASS] ':'[FAIL] ').'database connection'.PHP_EOL;if(!$ok)$failed++;}catch(Throwable $e){echo '[FAIL] database connection request_id='.request_id().PHP_EOL;$failed++;}}
exit($failed?1:0);
