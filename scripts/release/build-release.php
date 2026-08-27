<?php
declare(strict_types=1);

$options = getopt('', ['target:', 'force', 'help']);
if (isset($options['help']) || empty($options['target'])) {
    echo "Usage: php scripts/release/build-release.php --target=C:\\path\\to\\release [--force]\n";
    exit(isset($options['help']) ? 0 : 2);
}
$repoRoot = realpath(dirname(__DIR__, 2));
$source = realpath($repoRoot . DIRECTORY_SEPARATOR . 'src');
if ($repoRoot === false || $source === false) { fwrite(STDERR, "Unable to resolve repository/source root.\n"); exit(2); }
$target = (string) $options['target'];
$targetAbs = preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $target) ? $target : getcwd() . DIRECTORY_SEPARATOR . $target;
$targetNorm = strtolower(str_replace('\\', '/', $targetAbs));
$repoNorm = strtolower(str_replace('\\', '/', $repoRoot));
if (str_starts_with($targetNorm, $repoNorm . '/')) { fwrite(STDERR, "Target must be outside the repository root.\n"); exit(2); }
if (is_dir($targetAbs)) {
    if (!array_key_exists('force', $options)) { fwrite(STDERR, "Target exists; use --force to rebuild.\n"); exit(2); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetAbs, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($targetAbs);
}
if (!mkdir($targetAbs, 0755, true) && !is_dir($targetAbs)) { throw new RuntimeException('Could not create release target.'); }
$excludedNames = ['.env', '.env.windows', '.env.server', 'error_log', 'installed.lock'];
$excludedExt = ['bak','rar','zip','7z','tar','gz','tgz'];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $path = $file->getPathname();
    $rel = str_replace('\\', '/', substr($path, strlen($source) + 1));
    $base = basename($rel);
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    if (in_array($base, $excludedNames, true)) continue;
    if (str_starts_with($base, '.env.') && !str_ends_with($base, '.example')) continue;
    if (in_array($ext, $excludedExt, true)) continue;
    if (preg_match('#^uploads/products/(?!\\.gitkeep$)#', $rel)) continue;
    if (str_starts_with($rel, 'storage/private/runtime/')) continue;
    if ($rel === 'storage/private/installed.lock') continue;
    $dest = $targetAbs . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true) && !is_dir(dirname($dest))) throw new RuntimeException('Could not create directory: '.dirname($dest));
    if (!copy($path, $dest)) throw new RuntimeException('Copy failed: '.$rel);
}
// Include public release metadata without exposing repository-internal project/.
$publicCopies = [
    $repoRoot . '/LICENSE' => $targetAbs . '/LICENSE-REPOSITORY.txt',
    $repoRoot . '/docs/getting-started/installation.md' => $targetAbs . '/INSTALLATION.md',
];
foreach ($publicCopies as $from => $to) if (is_file($from)) copy($from, $to);
echo "Release tree built from src/: {$targetAbs}\n";
