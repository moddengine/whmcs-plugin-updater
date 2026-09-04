<?php

declare(strict_types=1);

use PluginUpdater\GitHubClient;
use PluginUpdater\Manifest;
use PluginUpdater\Release;
use PluginUpdater\Transaction;

$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
$_SERVER['HTTP_HOST'] = 'localhost:18080';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '18080';
require '/var/www/html/init.php';
require_once '/var/www/html/modules/addons/pluginupdater/lib/Core.php';

$archive = $argv[1] ?? '';
$releaseData = json_decode((string) file_get_contents($argv[2] ?? ''), true, 16, JSON_THROW_ON_ERROR);
$release = new Release(
    $releaseData['version'],
    'v' . $releaseData['version'],
    'https://github.com/acme/pluginupdater-crash/releases/tag/v' . $releaseData['version'],
    '',
    1,
    basename($archive),
    (int) filesize($archive),
    (string) hash_file('sha256', $archive),
);
$client = new class($archive) extends GitHubClient {
    public function __construct(private readonly string $archive)
    {
        parent::__construct();
    }

    public function download(string $repository, Release $release, string $destination): void
    {
        if (!copy($this->archive, $destination)) {
            throw new RuntimeException('Unable to copy crash-test release');
        }
    }
};
$installed = array_values(array_filter(
    Manifest::discover(ROOTDIR),
    static fn (Manifest $manifest): bool => $manifest->package === $releaseData['package'],
));
$transaction = new Transaction(
    ROOTDIR,
    '/var/www/html',
    '/var/lib/whmcs-plugin-updater',
    (string) WHMCS\Config\Setting::getValue('Version'),
    static fn (): string => (string) (localAPI('GetConfigurationValue', ['setting' => 'MaintenanceMode'])['value'] ?? ''),
    static function (string $value): void {
        localAPI('SetConfigurationValue', ['setting' => 'MaintenanceMode', 'value' => $value]);
    },
    static function (string $message): void {},
);
$transaction->apply($installed, $release, $client, true, true, true, true);
