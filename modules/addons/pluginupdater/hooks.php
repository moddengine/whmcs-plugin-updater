<?php

declare(strict_types=1);

use PluginUpdater\Service;
use WHMCS\Module\AbstractWidget;
use WHMCS\Module\Addon\Setting as AddonSetting;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}
require_once __DIR__ . '/lib/Whmcs.php';

function pluginupdater_hook_settings(): array
{
    return [
        'storagePath' => AddonSetting::getSettingValueForModule('pluginupdater', 'storagePath') ?? '',
        'githubToken' => AddonSetting::getSettingValueForModule('pluginupdater', 'githubToken') ?? '',
        'maintenanceMode' => AddonSetting::getSettingValueForModule('pluginupdater', 'maintenanceMode') ?? 'on',
    ];
}

add_hook('DailyCronJob', 1, static function (): void {
    try {
        (new Service(pluginupdater_hook_settings()))->check();
    } catch (Throwable $e) {
        logActivity('[Plugin Updater] Scheduled release check failed: ' . $e->getMessage());
    }
});

add_hook('AdminHomeWidgets', 1, static function (): PluginUpdaterWidget {
    return new PluginUpdaterWidget();
});

final class PluginUpdaterWidget extends AbstractWidget
{
    protected $title = 'Plugin Updates';
    protected $description = 'Manifest-managed plugin updates';
    protected $weight = 150;
    protected $columns = 1;
    protected $cache = false;
    protected $requiredPermission = 'Configure Addon Modules';

    public function getData(): array
    {
        try {
            $status = (new Service(pluginupdater_hook_settings()))->status();
            $updates = count(array_filter($status, static fn (array $item): bool => $item['release'] !== null));
            $errors = count(array_filter($status, static fn (array $item): bool => !empty($item['cache']->error)));
            return ['updates' => $updates, 'errors' => $errors];
        } catch (Throwable $e) {
            return ['updates' => 0, 'errors' => 1];
        }
    }

    public function generateOutput($data): string
    {
        $updates = (int) ($data['updates'] ?? 0);
        $errors = (int) ($data['errors'] ?? 0);
        return '<div class="widget-content-padded"><strong>' . $updates . '</strong> update(s) available'
            . ($errors ? '<br><span class="text-warning">' . $errors . ' check error(s)</span>' : '')
            . '<br><a href="addonmodules.php?module=pluginupdater">Open Plugin Updater</a></div>';
    }
}
