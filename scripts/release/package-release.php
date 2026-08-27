<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$options = getopt('', ['source:', 'output:', 'tag:', 'commit:', 'help']);
if (isset($options['help']) || empty($options['source']) || empty($options['output'])) {
    echo "Usage: php scripts/release/package-release.php --source=/release/tree --output=/dist/VibRetail-x-cpanel.zip [--tag=vX.Y.Z] [--commit=SHA]\n";
    exit(isset($options['help']) ? 0 : 2);
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "[FAIL] PHP ZipArchive extension is required for packaging.\n");
    exit(2);
}

$source = realpath((string) $options['source']);
if ($source === false || !is_dir($source)) {
    fwrite(STDERR, "[FAIL] Release source directory does not exist.\n");
    exit(2);
}

$output = (string) $options['output'];
if (!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $output)) {
    $output = getcwd() . DIRECTORY_SEPARATOR . $output;
}
$outputDir = dirname($output);
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    throw new RuntimeException('Unable to create output directory.');
}
if (strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'zip') {
    fwrite(STDERR, "[FAIL] Output must be a .zip file.\n");
    exit(2);
}

$sourceNorm = rtrim(str_replace('\\', '/', $source), '/');
$outputNorm = str_replace('\\', '/', $output);
if (str_starts_with(strtolower($outputNorm), strtolower($sourceNorm . '/'))) {
    fwrite(STDERR, "[FAIL] ZIP output must be outside the release source tree.\n");
    exit(2);
}

$required = ['index.php', 'install.php', 'schema.sql', 'LICENSE-REPOSITORY.txt', 'INSTALLATION.md'];
foreach ($required as $requiredFile) {
    if (!is_file($source . DIRECTORY_SEPARATOR . $requiredFile)) {
        fwrite(STDERR, "[FAIL] Required release file missing: {$requiredFile}\n");
        exit(1);
    }
}

$entries = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
    if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
        fwrite(STDERR, "[FAIL] Unsafe release path: {$relative}\n");
        exit(1);
    }
    if (preg_match('#^(src|tests|scripts|project|\.github|\.git)/#i', $relative)) {
        fwrite(STDERR, "[FAIL] Repository-only path entered release package: {$relative}\n");
        exit(1);
    }
    if (preg_match('#^uploads/products/(?!\.gitkeep$)#i', $relative)) {
        fwrite(STDERR, "[FAIL] Runtime product upload entered release package: {$relative}\n");
        exit(1);
    }
    if (preg_match('#(^|/)\.env(?:$|\.)#i', $relative) && !str_ends_with(strtolower($relative), '.example')) {
        fwrite(STDERR, "[FAIL] Active environment file entered release package: {$relative}\n");
        exit(1);
    }
    $entries[$relative] = $file->getPathname();
}
ksort($entries, SORT_STRING);

@unlink($output);
$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Unable to create release ZIP.');
}

foreach ($entries as $relative => $absolute) {
    if (!$zip->addFile($absolute, $relative)) {
        $zip->close();
        @unlink($output);
        throw new RuntimeException('Unable to add release file: ' . $relative);
    }
}

$tag = trim((string) ($options['tag'] ?? ''));
$commit = trim((string) ($options['commit'] ?? ''));
$manifest = [
    'Product: VibRetail',
    'Package: cPanel / shared-hosting deployment',
    'Tag: ' . ($tag !== '' ? $tag : 'unspecified'),
    'Commit: ' . ($commit !== '' ? $commit : 'unspecified'),
    'Built-UTC: ' . gmdate('Y-m-d\TH:i:s\Z'),
    'Entrypoint: index.php',
    'Installer: install.php',
    'Repository: https://github.com/vibtools/vibretail',
    '',
];
$zip->addFromString('RELEASE-MANIFEST.txt', implode("\n", $manifest));
$zip->close();

$verify = new ZipArchive();
if ($verify->open($output) !== true) {
    throw new RuntimeException('Unable to reopen release ZIP for verification.');
}
$names = [];
for ($i = 0; $i < $verify->numFiles; $i++) {
    $name = (string) $verify->getNameIndex($i);
    $names[$name] = true;
    if ($name === '' || str_starts_with($name, '/') || str_contains($name, '../')) {
        $verify->close();
        @unlink($output);
        throw new RuntimeException('Unsafe path found in packaged ZIP: ' . $name);
    }
}
$verify->close();

foreach (array_merge($required, ['RELEASE-MANIFEST.txt']) as $requiredEntry) {
    if (!isset($names[$requiredEntry])) {
        @unlink($output);
        throw new RuntimeException('Packaged ZIP is missing root entry: ' . $requiredEntry);
    }
}

$sha256 = hash_file('sha256', $output);
if (!is_string($sha256) || strlen($sha256) !== 64) {
    @unlink($output);
    throw new RuntimeException('Unable to calculate release SHA-256.');
}
$checksumFile = $output . '.sha256';
file_put_contents($checksumFile, $sha256 . '  ' . basename($output) . PHP_EOL, LOCK_EX);

echo '[PASS] Release ZIP: ' . $output . PHP_EOL;
echo '[PASS] Root entries verified: index.php / install.php / manifest.' . PHP_EOL;
echo '[PASS] SHA256: ' . $sha256 . PHP_EOL;
echo '[PASS] Checksum file: ' . $checksumFile . PHP_EOL;
