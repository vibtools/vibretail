<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI access only.'); }
$root = realpath($argv[1] ?? (dirname(__DIR__, 2) . '/src'));
if ($root === false || !is_dir($root)) { fwrite(STDERR, "Usage: php scripts/release/validate-release.php [release-or-src-root]\n"); exit(2); }
$errors=[]; $phpFiles=[]; $scanned=0;
$prohibitedExact=['.env','.env.server','.env.windows','error_log','database.sql','DATABASE-CHECKSUM.txt','installed.lock'];
$prohibitedExt=['log','bak','rar','zip','7z','tar','gz','tgz'];
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){
    if(!$file->isFile()) continue; $scanned++;
    $path=$file->getPathname(); $relative=str_replace('\\','/',substr($path,strlen($root)+1)); $base=$file->getBasename();
    if(str_starts_with($relative,'uploads/products/')) continue;
    if(in_array($base,$prohibitedExact,true)) $errors[]="Prohibited runtime artifact: {$relative}";
    $ext=strtolower(pathinfo($base,PATHINFO_EXTENSION));
    if(in_array($ext,$prohibitedExt,true)) $errors[]="Prohibited archive/log artifact: {$relative}";
    if($ext==='sql' && $relative!=='schema.sql' && !str_starts_with($relative,'migrations/')) $errors[]="Unexpected SQL artifact: {$relative}";
    if($ext==='php') $phpFiles[]=$path;
    if($file->getSize() <= 2_000_000 && $ext === 'php'){
        $text=(string)@file_get_contents($path);
        if(preg_match('/password_hash\s*\(\s*[\'\"][^\'\"]{6,}[\'\"]\s*,/i',$text)) $errors[]="Hardcoded password literal pattern: {$relative}";
        if(preg_match('/POS_(?:DB_PASS|SERVICE_CREDENTIAL_KEY)\s*=\s*[\'\"][A-Za-z0-9+\/_=-]{8,}[\'\"]/i',$text)) $errors[]="Possible embedded POS secret literal: {$relative}";
    }
}
foreach($phpFiles as $file){
    $cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1'; $output=[]; $code=0; exec($cmd,$output,$code);
    if($code!==0) $errors[]='PHP lint failed: '.str_replace('\\','/',substr($file,strlen($root)+1)).' :: '.implode(' ',$output);
}
if($errors){ foreach($errors as $error) fwrite(STDERR,"[FAIL] {$error}\n"); fwrite(STDERR,"Release validation FAILED with ".count($errors)." issue(s).\n"); exit(1); }
echo "[PASS] Release validation: {$scanned} files scanned; ".count($phpFiles)." PHP files linted; no prohibited runtime artifacts or obvious embedded secrets found.\n";
