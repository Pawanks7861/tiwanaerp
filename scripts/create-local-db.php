<?php

// Creates the local development database named in .env (if missing). Never drops or alters anything.
// Usage: php scripts/create-local-db.php

$env = [];
foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim($value, " \"'");
}

if (($env['APP_ENV'] ?? '') !== 'local') {
    fwrite(STDERR, "Refusing to run: APP_ENV is not 'local'.\n");
    exit(1);
}

$name = $env['DB_DATABASE'] ?? '';
if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    fwrite(STDERR, "Invalid DB_DATABASE name.\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306'),
    $env['DB_USERNAME'] ?? 'root',
    $env['DB_PASSWORD'] ?? '',
);

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

echo "Database ready: {$name} (MySQL ".$pdo->query('SELECT VERSION()')->fetchColumn().")\n";
