<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI access only.'); }
function backup_log(string $directory,string $message):void{file_put_contents($directory.DIRECTORY_SEPARATOR.'backup.log','['.date('c').'] '.$message.PHP_EOL,FILE_APPEND|LOCK_EX);}
function copy_backup_tree(string $source,string $destination):array{
    $inventory=[]; if(!is_dir($source)) return $inventory;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as $item){$relative=substr($item->getPathname(),strlen($source)+1);$target=$destination.DIRECTORY_SEPARATOR.$relative;
        if($item->isDir()){if(!is_dir($target)&&!mkdir($target,0700,true)&&!is_dir($target))throw new RuntimeException('Could not create backup asset directory.');continue;}
        if(!is_dir(dirname($target))&&!mkdir(dirname($target),0700,true)&&!is_dir(dirname($target)))throw new RuntimeException('Could not create backup asset directory.');
        if(!copy($item->getPathname(),$target))throw new RuntimeException('Could not copy upload asset: '.$relative);
        $inventory[]=['path'=>str_replace('\\','/',$relative),'bytes'=>filesize($target),'sha256'=>hash_file('sha256',$target)];
    } return $inventory;
}
$defaultBackupDirectory=IS_WINDOWS?dirname(__DIR__).DIRECTORY_SEPARATOR.'pos-backups':'/www/backup/cloudcore-pos';
$backupDirectory=env_value('POS_BACKUP_DIR',$defaultBackupDirectory);$retentionDays=max(7,(int)env_value('POS_BACKUP_RETENTION_DAYS','30'));
$dumpBinary=mysql_dump_binary();
if(!function_exists('proc_open')){fwrite(STDERR,"PHP proc_open() is disabled.\n");exit(1);} if(!is_dir($backupDirectory)&&!mkdir($backupDirectory,0700,true)&&!is_dir($backupDirectory)){fwrite(STDERR,"Could not create backup directory.\n");exit(1);} if(!is_writable($backupDirectory)){fwrite(STDERR,"Backup directory is not writable.\n");exit(1);}
$timestamp=date('Y-m-d_H-i-s');$base='pos-'.$timestamp;$finalPath=$backupDirectory.DIRECTORY_SEPARATOR.$base.'.sql';$temporaryPath=$finalPath.'.tmp';$defaultsFile=tempnam(sys_get_temp_dir(),'pos-db-');if($defaultsFile===false)throw new RuntimeException('Could not create database credential file.');
$escapedPassword=addcslashes(DB_PASS,"\\\"");file_put_contents($defaultsFile,"[client]\nhost=".DB_HOST."\nport=".DB_PORT."\nuser=".DB_USER."\npassword=\"".$escapedPassword."\"\ndefault-character-set=utf8mb4\n",LOCK_EX);@chmod($defaultsFile,0600);
$command=[$dumpBinary,'--defaults-extra-file='.$defaultsFile,'--single-transaction','--routines','--triggers','--events','--hex-blob','--set-charset','--add-drop-table',DB_NAME];
$descriptors=[0=>['pipe','r'],1=>['file',$temporaryPath,'wb'],2=>['pipe','w']];$process=proc_open($command,$descriptors,$pipes,__DIR__);if(!is_resource($process)){@unlink($defaultsFile);throw new RuntimeException('Could not start mysqldump.');}fclose($pipes[0]);$errorOutput=stream_get_contents($pipes[2]);fclose($pipes[2]);$exitCode=proc_close($process);@unlink($defaultsFile);
$head=is_file($temporaryPath)?(string)file_get_contents($temporaryPath,false,null,0,100000):'';$valid=$exitCode===0&&is_file($temporaryPath)&&filesize($temporaryPath)>500&&str_contains($head,'CREATE TABLE');if(!$valid){@unlink($temporaryPath);backup_log($backupDirectory,'FAILED: '.trim($errorOutput));fwrite(STDERR,'Backup failed.'.PHP_EOL);exit(1);}if(!rename($temporaryPath,$finalPath))throw new RuntimeException('Could not finalize SQL backup.');
$sqlHash=hash_file('sha256',$finalPath);file_put_contents($finalPath.'.sha256',$sqlHash.'  '.basename($finalPath).PHP_EOL,LOCK_EX);
$assetRoot=$backupDirectory.DIRECTORY_SEPARATOR.$base.'-uploads';$inventory=copy_backup_tree(__DIR__.DIRECTORY_SEPARATOR.'uploads',$assetRoot);
$manifest=['format'=>'cloud-core-pos-backup-v2','created_at'=>date(DATE_ATOM),'database'=>DB_NAME,'sql'=>['file'=>basename($finalPath),'bytes'=>filesize($finalPath),'sha256'=>$sqlHash],'uploads'=>['directory'=>basename($assetRoot),'files'=>count($inventory),'bytes'=>array_sum(array_column($inventory,'bytes')),'inventory'=>$inventory]];
$manifestPath=$backupDirectory.DIRECTORY_SEPARATOR.$base.'.manifest.json';file_put_contents($manifestPath,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL,LOCK_EX);file_put_contents($manifestPath.'.sha256',hash_file('sha256',$manifestPath).'  '.basename($manifestPath).PHP_EOL,LOCK_EX);
$cutoff=time()-($retentionDays*86400);foreach(glob($backupDirectory.DIRECTORY_SEPARATOR.'pos-*.sql')?:[] as $old){if(filemtime($old)<$cutoff){$oldBase=substr($old,0,-4);@unlink($old);@unlink($old.'.sha256');@unlink($oldBase.'.manifest.json');@unlink($oldBase.'.manifest.json.sha256');$dir=$oldBase.'-uploads';if(is_dir($dir)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}@rmdir($dir);}}}
backup_log($backupDirectory,'OK: '.basename($finalPath).' sha256='.$sqlHash.' uploads='.count($inventory));echo 'Backup created: '.$finalPath.PHP_EOL;echo 'Manifest: '.$manifestPath.PHP_EOL;echo 'SHA-256: '.$sqlHash.PHP_EOL;echo 'Uploads copied: '.count($inventory).PHP_EOL;
