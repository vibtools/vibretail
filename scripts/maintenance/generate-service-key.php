<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI access only.'); }
echo 'POS_SERVICE_CREDENTIAL_KEY=base64:' . base64_encode(random_bytes(32)) . PHP_EOL;
