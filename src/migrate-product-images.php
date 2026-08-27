<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/product-images.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only.');
}

try {
    $result = migrate_product_images(db());
    echo 'Product image migration complete. Migrated: ' . $result['migrated'] . PHP_EOL;
    echo 'Storage: ' . product_image_directory() . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Product image migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
