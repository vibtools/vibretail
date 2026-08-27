<?php
declare(strict_types=1);

const PRODUCT_IMAGE_RELATIVE_DIRECTORY = 'uploads/products';
const PRODUCT_IMAGE_MAX_BYTES = 3 * 1024 * 1024;
const PRODUCT_IMAGE_MAX_PIXELS = 25_000_000;

function product_image_apply_ownership(string $path): void
{
    if (DIRECTORY_SEPARATOR !== '/') return;
    $applicationFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    $owner = @fileowner($applicationFile); $group = @filegroup($applicationFile);
    if ($owner !== false) @chown($path, $owner);
    if ($group !== false) @chgrp($path, $group);
}

function product_image_directory(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
}

function ensure_product_image_directory(): string
{
    $uploadRoot = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    $directory = product_image_directory();
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Could not create the product image directory.');
    foreach ([$uploadRoot, $directory] as $path) { @chmod($path, 0755); product_image_apply_ownership($path); }
    if (!is_writable($directory)) throw new RuntimeException('Product image directory is not writable: ' . $directory);
    return $directory;
}

function product_image_orientation(string $binary, string $mime): int
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) return 1;
    $temporary = tempnam(sys_get_temp_dir(), 'pos-img-');
    if ($temporary === false) return 1;
    try {
        if (file_put_contents($temporary, $binary, LOCK_EX) === false) return 1;
        $exif = @exif_read_data($temporary, 'IFD0', true, false);
        return max(1, min(8, (int) ($exif['IFD0']['Orientation'] ?? 1)));
    } finally { @unlink($temporary); }
}

function product_image_apply_orientation(GdImage $image, int $orientation): GdImage
{
    if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
    $angle = match ($orientation) { 3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
    if ($angle !== 0) {
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated instanceof GdImage) { imagedestroy($image); return $rotated; }
    }
    return $image;
}

function product_image_decode_data_url(mixed $value): array
{
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        throw new RuntimeException('PHP GD extension is required for privacy-safe image processing.');
    }
    $dataUrl = trim((string) $value);
    if (!preg_match('#^data:image/(png|jpe?g|webp|bmp);base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUrl, $matches)) throw new InvalidArgumentException('Image must be PNG, JPG, WEBP or BMP and no larger than 3 MB.');
    $binary = base64_decode(str_replace(["\r", "\n"], '', $matches[2]), true);
    if ($binary === false || strlen($binary) === 0 || strlen($binary) > PRODUCT_IMAGE_MAX_BYTES) throw new InvalidArgumentException('Image must be PNG, JPG, WEBP or BMP and no larger than 3 MB.');
    $details = @getimagesizefromstring($binary);
    $mime = is_array($details) ? (string) ($details['mime'] ?? '') : '';
    $width = (int) ($details[0] ?? 0); $height = (int) ($details[1] ?? 0);
    if (!in_array($mime, ['image/png','image/jpeg','image/webp','image/bmp','image/x-ms-bmp'], true) || $width < 1 || $height < 1 || $width * $height > PRODUCT_IMAGE_MAX_PIXELS) throw new InvalidArgumentException('The selected file is not a valid supported image.');
    $image = @imagecreatefromstring($binary);
    if (!$image instanceof GdImage) throw new InvalidArgumentException('The selected file could not be decoded safely.');
    $image = product_image_apply_orientation($image, product_image_orientation($binary, $mime));
    $extension = match ($mime) { 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => 'png' };
    ob_start();
    $ok = match ($extension) {
        'jpg' => imagejpeg($image, null, 88),
        'webp' => function_exists('imagewebp') ? imagewebp($image, null, 88) : imagepng($image, null, 6),
        default => imagepng($image, null, 6),
    };
    $sanitized = (string) ob_get_clean();
    imagedestroy($image);
    if (!$ok || $sanitized === '' || strlen($sanitized) > PRODUCT_IMAGE_MAX_BYTES) throw new InvalidArgumentException('The sanitized image is too large. Choose a smaller image.');
    if ($extension === 'webp' && !function_exists('imagewebp')) $extension = 'png';
    return [$sanitized, $extension];
}

function store_product_image(mixed $value): string
{
    [$binary, $extension] = product_image_decode_data_url($value);
    $directory = ensure_product_image_directory();
    $filename = 'product-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $absolutePath = $directory . DIRECTORY_SEPARATOR . $filename; $temporaryPath = $absolutePath . '.tmp';
    if (file_put_contents($temporaryPath, $binary, LOCK_EX) !== strlen($binary)) { @unlink($temporaryPath); throw new RuntimeException('Could not write the product image.'); }
    @chmod($temporaryPath, 0644); product_image_apply_ownership($temporaryPath);
    if (!rename($temporaryPath, $absolutePath)) { @unlink($temporaryPath); throw new RuntimeException('Could not finalize the product image.'); }
    @chmod($absolutePath, 0644); product_image_apply_ownership($absolutePath);
    return PRODUCT_IMAGE_RELATIVE_DIRECTORY . '/' . $filename;
}

function is_managed_product_image(string $path): bool { return preg_match('#^uploads/products/product-[a-f0-9]{32}\.(?:png|jpg|webp|bmp)$#', $path) === 1; }
function product_image_absolute_path(string $path): ?string { return is_managed_product_image($path) ? __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path) : null; }
function delete_product_image(string $path): void { $absolutePath = product_image_absolute_path($path); if ($absolutePath !== null && is_file($absolutePath)) @unlink($absolutePath); }
function migrate_product_images(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id, image_data FROM products WHERE image_data LIKE 'data:image/%'")->fetchAll();
    if (!$rows) { ensure_product_image_directory(); return ['migrated' => 0]; }
    $createdPaths = []; $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE products SET image_data=? WHERE id=?');
        foreach ($rows as $row) { $path = store_product_image((string) $row['image_data']); $createdPaths[] = $path; $update->execute([$path, (int) $row['id']]); }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($createdPaths as $path) delete_product_image($path);
        throw $error;
    }
    return ['migrated' => count($createdPaths)];
}
