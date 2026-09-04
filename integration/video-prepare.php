<?php

declare(strict_types=1);

use PluginUpdater\Fs;
use PluginUpdater\Manifest;
use WHMCS\Database\Capsule;

$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
require '/var/www/html/init.php';
require_once '/var/www/html/modules/addons/pluginupdater/pluginupdater.php';

$activation = pluginupdater_activate();
if (($activation['status'] ?? null) !== 'success') {
    throw new RuntimeException('Plugin Updater activation failed');
}
Capsule::table('tbladdonmodules')->where('module', 'pluginupdater')->delete();
foreach ([
    'version' => '1.0.0',
    'access' => '1',
    'storagePath' => '/var/lib/whmcs-plugin-updater',
    'githubToken' => '',
    'maintenanceMode' => 'on',
] as $setting => $value) {
    Capsule::table('tbladdonmodules')->updateOrInsert(
        ['module' => 'pluginupdater', 'setting' => $setting],
        ['value' => $value],
    );
}
Capsule::table('mod_pluginupdater_repositories')->where('repository', 'acme/playwright-fixture')->delete();

$storage = '/var/lib/whmcs-plugin-updater';
foreach (new DirectoryIterator($storage) as $entry) {
    if (!$entry->isDot()) {
        Fs::remove($entry->getPathname());
    }
}
chmod($storage, 0700);

$fixture = ROOTDIR . '/modules/addons/playwrightfixture';
if (is_dir($fixture)) {
    Fs::remove($fixture);
}
Fs::mkdir($fixture, 0755);
$manifest = [
    'schema' => 1,
    'package' => 'acme/playwright-fixture',
    'component' => ['type' => 'addon', 'name' => 'playwrightfixture'],
    'version' => '1.0.0',
    'github' => [
        'repository' => 'acme/playwright-fixture',
        'asset' => 'playwright-fixture-{version}.zip',
    ],
    'requires' => [
        'php_min' => '8.3.0',
        'whmcs_min' => '8.13.0',
        'whmcs_max_exclusive' => '10.0.0',
    ],
];
file_put_contents(
    $fixture . '/' . Manifest::FILENAME,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
file_put_contents($fixture . '/version.txt', "1.0.0\n");
file_put_contents($fixture . '/old.php', "<?php return 'baseline';\n");
localAPI('SetConfigurationValue', ['setting' => 'MaintenanceMode', 'value' => '']);
localAPI('SetConfigurationValue', ['setting' => 'SystemURL', 'value' => 'http://localhost']);

fwrite(STDOUT, "Prepared WHMCS browser update fixture 1.0.0.\n");
