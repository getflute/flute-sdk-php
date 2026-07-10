<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Flute\Sdk\Flute;

$config = [
    'clientId' => (string) getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => (string) getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
];

// Request 1: acquire a token (your app would store this in its cache).
$first = new Flute($config);
$token = $first->sessions->getAccessToken();
echo 'Acquired token of length ' . strlen($token) . PHP_EOL;

// Request 2: reuse the cached token — no token endpoint call is made.
$second = new Flute($config + ['accessToken' => $token]);
echo $second->sessions->getAccessToken() === $token
    ? 'Token reused without re-authentication' . PHP_EOL
    : 'Unexpected: token was re-acquired' . PHP_EOL;
