<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/config.php';
require dirname(__DIR__, 2) . '/src/product-images.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI access only.'); }
$options = getopt('', ['apply','confirm:','help']);
if (isset($options['help'])) { echo "Dry run: php tools/sanitize-existing-product-images.php\nApply: php tools/sanitize-existing-product-images.php --apply --confirm=PHASE2-IMAGE-SANITIZE\n"; exit(0); }
$apply = array_key_exists('apply',$options);
if ($apply && ($options['confirm'] ?? '') !== 'PHASE2-IMAGE-SANITIZE') { fwrite(STDERR,"Refusing write mode without --confirm=PHASE2-IMAGE-SANITIZE\n"); exit(2); }
if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) { fwrite(STDERR,"GD is required.\n"); exit(1); }
$dir = product_image_directory();
$files = array_values(array_filter(glob($dir . DIRECTORY_SEPARATOR . '*') ?: [], static fn($p)=>is_file($p) && preg_match('/\.(?:jpe?g)$/i',$p)));
$backupBase = rtrim(env_value('POS_BACKUP_DIR', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_runtime' . DIRECTORY_SEPARATOR . 'backups'), DIRECTORY_SEPARATOR);
$run = 'image-sanitize-' . date('Y-m-d_H-i-s'); $backupDir = $backupBase . DIRECTORY_SEPARATOR . $run;
$manifest = ['run'=>$run,'mode'=>$apply?'apply':'dry-run','created_at'=>date(DATE_ATOM),'files'=>[]];
if ($apply && !is_dir($backupDir) && !mkdir($backupDir,0700,true) && !is_dir($backupDir)) { throw new RuntimeException('Could not create image backup directory.'); }
foreach ($files as $path) {
    $binary = file_get_contents($path); if ($binary === false) throw new RuntimeException('Could not read ' . basename($path));
    $before = hash('sha256',$binary); $exif = function_exists('exif_read_data') ? @exif_read_data($path, null, true, false) : false;
    $hasGps = is_array($exif) && isset($exif['GPS']); $orientation = product_image_orientation($binary,'image/jpeg');
    $image = @imagecreatefromstring($binary); if (!$image instanceof GdImage) throw new RuntimeException('Could not decode ' . basename($path));
    $image = product_image_apply_orientation($image,$orientation); ob_start(); $ok=imagejpeg($image,null,88); $san=(string)ob_get_clean(); imagedestroy($image);
    if (!$ok || $san==='') throw new RuntimeException('Could not sanitize ' . basename($path));
    $after = hash('sha256',$san);
    if ($apply) {
        $backup = $backupDir . DIRECTORY_SEPARATOR . basename($path); if (!copy($path,$backup)) throw new RuntimeException('Backup failed for ' . basename($path));
        $tmp=$path.'.phase2.tmp'; if (file_put_contents($tmp,$san,LOCK_EX)!==strlen($san) || !rename($tmp,$path)) { @unlink($tmp); throw new RuntimeException('Write failed for ' . basename($path)); }
    }
    $manifest['files'][]=['file'=>basename($path),'before_sha256'=>$before,'after_sha256'=>$after,'gps_before'=>$hasGps,'changed'=>!hash_equals($before,$after)];
}
if ($apply) file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL,LOCK_EX);
echo ($apply?'APPLY':'DRY-RUN') . ' jpg_files=' . count($files) . ' gps_detected=' . count(array_filter($manifest['files'],fn($x)=>$x['gps_before'])) . PHP_EOL;
if ($apply) echo 'Rollback originals: ' . $backupDir . PHP_EOL;
