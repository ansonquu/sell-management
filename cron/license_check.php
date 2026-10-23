<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../includes/functions.php';

$result = verifyLicenseCode(true);
fwrite($result['ok'] ? STDOUT : STDERR, '[' . currentDateTime() . '] ' . $result['message'] . PHP_EOL);
exit($result['ok'] ? 0 : 1);
