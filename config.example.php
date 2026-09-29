<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => getenv('DAVESTUNES_APP_NAME') ?: "Dave's Tunes",
        'url' => rtrim((string)(getenv('DAVESTUNES_APP_URL') ?: ''), '/'),
        'env' => getenv('DAVESTUNES_APP_ENV') ?: 'production',
        'key' => getenv('DAVESTUNES_APP_KEY') ?: '',
    ],
    'db' => [
        'dsn' => getenv('DAVESTUNES_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=davestunes;charset=utf8mb4',
        'user' => getenv('DAVESTUNES_DB_USER') ?: '',
        'pass' => getenv('DAVESTUNES_DB_PASS') ?: '',
    ],
];
