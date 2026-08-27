<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
$repoRoot=dirname(__DIR__, 2);$root=$repoRoot.'/src';$fail=0;$check=function(bool $ok,string $name)use(&$fail){echo ($ok?'[PASS] ':'[FAIL] ').$name.PHP_EOL;if(!$ok)$fail++;};
$config=(string)file_get_contents($root.'/config.php');$api=(string)file_get_contents($root.'/api.php');$app=(string)file_get_contents($root.'/app.js');$backup=(string)file_get_contents($root.'/backup.php');$restore=(string)file_get_contents($root.'/restore.php');$install=(string)file_get_contents($root.'/install.php');
$check(str_contains($config,'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT'),'HTML script JSON uses JSON_HEX flags');
$legacy=0;foreach(glob($root.'/*.php')?:[] as $page){$t=(string)file_get_contents($page);if(str_contains($t,'window.POS_CONFIG = <?= json_encode(['))$legacy++;}$check($legacy===0,'No legacy POS_CONFIG json_encode sink remains');
$check(!str_contains($api,"ON DUPLICATE KEY UPDATE product_id=VALUES(product_id),status='stock'"),'Purchase cannot resurrect duplicate serial');
$check(str_contains($api,'Serial is not available in stock:'),'Sale serial transition is verified');
$check(str_contains($api,'Payment exceeds the remaining EMI balance'),'EMI overpayment rejected');
$check(str_contains($api,'next_number($pdo, \'sale_returns\', \'SRT\')')&&str_contains($api,'next_number($pdo, \'purchase_returns\', \'PRT\')')&&str_contains($api,'next_number($pdo, \'damages\', \'DMG\')'),'Concurrent-safe return/damage references');
$check(str_contains($api,'next_number($pdo, \'transfers\', \'TR\')'),'Concurrent-safe transfer references');
$check(str_contains($api,"assert_unique_product_items(\$items, 'Sale')")&&str_contains($api,"assert_unique_product_items(\$items, 'Purchase')"),'Duplicate product lines rejected before stock/account mutation');
$check(str_contains($api,'ORDER BY id FOR UPDATE')&&str_contains($api,'WHERE id IN (?,?)'),'Transfer accounts lock in deterministic order');
$check(str_contains($api,"next_number(\$pdo, 'rmas', 'RMA')")&&str_contains($api,"status='sold',reference_type='rma'"),'RMA uses safe sequence and terminal serial restoration');
$check(str_contains($api,'Generated browser backup')&&str_contains($api,'Sensitive credential fields excluded'),'Sensitive browser backup action is audited');
$check(str_contains($app,'pendingWrites')&&str_contains($app,'state.pendingWrites.has(pendingKey)'),'Concurrent duplicate browser submissions suppressed');
$check(str_contains($backup,'cloud-core-pos-backup-v2')&&str_contains($backup,"'uploads'=>"),'Backup manifest includes uploads inventory');
$check(str_contains($restore,'--isolated-target')||str_contains($restore,"'isolated-target:'"),'Restore requires isolated-target assertion');
$toolMap=['migrate-service-credentials.php'=>$repoRoot.'/scripts/maintenance/migrate-service-credentials.php','sanitize-existing-product-images.php'=>$repoRoot.'/scripts/maintenance/sanitize-existing-product-images.php','readiness-check.php'=>$repoRoot.'/scripts/local/readiness-check.php','build-release.php'=>$repoRoot.'/scripts/release/build-release.php','runtime-security-check.php'=>$repoRoot.'/tests/runtime/runtime-security-check.php'];foreach($toolMap as $tool=>$path)$check(is_file($path),'Tool exists: '.$tool);
$check(str_contains((string)file_get_contents($repoRoot.'/scripts/maintenance/migrate-service-credentials.php'),'PHASE2-SERVICE-CREDENTIALS'),'Credential migration confirmation guard');
$check(str_contains((string)file_get_contents($repoRoot.'/scripts/maintenance/sanitize-existing-product-images.php'),'PHASE2-IMAGE-SANITIZE'),'Image sanitizer confirmation guard');
$migrations=(string)file_get_contents($root.'/migrations.php');
$check(str_contains($migrations,"'sale_returns', 'purchase_returns', 'damages', 'rmas'")&&str_contains($migrations,"['transfers']"),'Migration engine seeds Phase-02 document sequences');
exit($fail?1:0);
