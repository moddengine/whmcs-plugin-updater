<?php

declare(strict_types=1);

$root = '/var/www/html';
if (is_file($root . '/configuration.php')) {
    fwrite(STDOUT, "WHMCS is already installed.\n");
    exit(0);
}

$license = trim((string) file_get_contents('/run/whmcs-license'));
if ($license === '') {
    fwrite(STDERR, "The mounted WHMCS licence is empty.\n");
    exit(1);
}

$configuration = json_encode([
    'admin' => [
        'username' => 'integration-admin',
        'password' => 'IntegrationOnly-ChangeMe-8137!',
    ],
    'configuration' => [
        'license' => $license,
        'db_host' => getenv('WHMCS_DB_HOST') ?: 'db',
        'db_username' => getenv('WHMCS_DB_USER') ?: 'whmcs',
        'db_password' => getenv('WHMCS_DB_PASSWORD') ?: 'integration-only',
        'db_name' => getenv('WHMCS_DB_NAME') ?: 'whmcs',
        'cc_encryption_hash' => bin2hex(random_bytes(32)),
        'mysql_charset' => 'utf8mb4',
    ],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

$process = proc_open(
    [PHP_BINARY, '-f', $root . '/install/bin/installer.php', '--', '-i', '-n', '-c'],
    [['pipe', 'r'], STDOUT, STDERR],
    $pipes,
    $root,
);
if (!is_resource($process)) {
    fwrite(STDERR, "Unable to start the WHMCS installer.\n");
    exit(1);
}
fwrite($pipes[0], $configuration);
fclose($pipes[0]);
exit(proc_close($process));
