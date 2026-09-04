<?php

declare(strict_types=1);

$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
$_SERVER['HTTP_HOST'] = 'localhost:18080';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '18080';
require '/var/www/html/init.php';
require_once '/var/www/html/modules/addons/pluginupdater/pluginupdater.php';

$expected = $argv[1] ?? '';
$actual = (string) (pluginupdater_config()['version'] ?? '');
if ($actual !== $expected) {
    fwrite(STDERR, "Expected updater {$expected}, loaded {$actual}.\n");
    exit(1);
}
fwrite(STDOUT, "Fresh process loaded updater {$actual}.\n");
