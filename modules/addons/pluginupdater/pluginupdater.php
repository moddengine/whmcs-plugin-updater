<?php

declare(strict_types=1);

use PluginUpdater\Service;
use PluginUpdater\Transaction;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Whmcs.php';

function pluginupdater_config(): array
{
    return [
        'name' => 'Plugin Updater',
        'description' => 'Securely stage, update, and roll back manifest-enabled WHMCS plugins.',
        'author' => 'WHMCS Plugin Updater',
        'language' => 'english',
        'version' => '0.0.1',
        'fields' => [
            'storagePath' => [
                'FriendlyName' => 'Update Storage Path',
                'Type' => 'text',
                'Size' => '80',
                'Description' => 'Required absolute path outside both the web root and WHMCS root; create it with mode 0700 and the PHP worker as owner.',
            ],
            'githubToken' => [
                'FriendlyName' => 'GitHub Token',
                'Type' => 'password',
                'Size' => '50',
                'Description' => 'Optional fine-grained token with read-only Contents access. <a href="https://github.com/settings/personal-access-tokens/new?name=WHMCS%20Plugin%20Updater&amp;description=Read-only%20access%20to%20plugin%20release%20metadata%20and%20assets&amp;contents=read" target="_blank" rel="noopener noreferrer">Create a token on GitHub</a>, then limit it to the plugin repositories you want to update.',
            ],
            'maintenanceMode' => [
                'FriendlyName' => 'Maintenance Mode During Updates',
                'Type' => 'yesno',
                'Description' => 'Temporarily enable WHMCS Maintenance Mode during file swaps.',
                'Default' => 'on',
            ],
        ],
    ];
}

function pluginupdater_activate(): array
{
    try {
        if (!Capsule::schema()->hasTable('mod_pluginupdater_repositories')) {
            Capsule::schema()->create('mod_pluginupdater_repositories', static function ($table): void {
                $table->string('repository', 191)->primary();
                $table->string('etag', 255)->nullable();
                $table->mediumText('releases_json')->nullable();
                $table->string('configuration_fingerprint', 64)->default('');
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->dateTime('last_attempt_at')->nullable();
                $table->dateTime('next_attempt_at')->nullable();
                $table->string('status', 32)->default('new');
                $table->text('error')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
        return ['status' => 'success', 'description' => 'Plugin Updater activated. Configure an external update storage path before updating plugins.'];
    } catch (Throwable $e) {
        return ['status' => 'error', 'description' => 'Unable to activate Plugin Updater: ' . $e->getMessage()];
    }
}

function pluginupdater_deactivate(): array
{
    return ['status' => 'success', 'description' => 'Plugin Updater deactivated. Cached release data and external recovery copies were retained.'];
}

/** @param array<string,mixed> $vars */
function pluginupdater_output(array $vars): void
{
    $service = new Service($vars);
    $notices = [];
    $errors = [];

    try {
        $recovered = $service->recoverPending();
        if ($recovered > 0) {
            $notices[] = "Recovered {$recovered} interrupted update transaction(s).";
        }
    } catch (Throwable $e) {
        $errors[] = 'Recovery check failed: ' . $e->getMessage();
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            if (check_token('WHMCS.admin.default') === false) {
                throw new RuntimeException('Invalid or expired CSRF token');
            }
            $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
            $package = is_string($_POST['package'] ?? null) ? $_POST['package'] : '';
            if ($action === 'check') {
                $notices = $service->check(true);
                logActivity('[Plugin Updater] Manual release check requested');
            } elseif ($action === 'preflight') {
                $warnings = $service->preflight($package);
                $notices[] = $warnings === [] ? "{$package}: pre-flight passed" : "{$package}: pre-flight passed with warning: " . implode('; ', $warnings);
            } elseif ($action === 'update') {
                $service->update($package, isset($_POST['database_backup']), isset($_POST['timeout_ack']), isset($_POST['components_ack']));
                $notices[] = "{$package}: update completed";
            } elseif ($action === 'rollback') {
                $service->rollback($package, isset($_POST['database_backup']), isset($_POST['timeout_ack']));
                $notices[] = "{$package}: rollback completed";
            } else {
                throw new RuntimeException('Unknown action');
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    try {
        $status = $service->status();
    } catch (Throwable $e) {
        $status = [];
        $errors[] = $e->getMessage();
    }
    $timeoutWarnings = Transaction::executionTimeWarnings();
    $token = generate_token('plain');
    $moduleLink = (string) $vars['modulelink'];

    echo '<style>.pu-table{width:100%;border-collapse:collapse}.pu-table th,.pu-table td{padding:10px;border-bottom:1px solid #ddd;vertical-align:top}.pu-actions form{margin:0 0 8px}.pu-notice{padding:10px;margin:8px 0;background:#eaf7ea}.pu-error,.pu-warning{padding:10px;margin:8px 0;background:#fff1d6}.pu-error{background:#fdeaea}.pu-notes{max-width:52em;white-space:pre-wrap;max-height:18em;overflow:auto}</style>';
    echo '<h2>Plugin Updater</h2>';
    foreach ($notices as $notice) {
        echo '<div class="pu-notice">' . pluginupdater_escape($notice) . '</div>';
    }
    foreach ($errors as $error) {
        echo '<div class="pu-error">' . pluginupdater_escape($error) . '</div>';
    }
    foreach ($timeoutWarnings as $warning) {
        echo '<div class="pu-warning">' . pluginupdater_escape($warning) . '</div>';
    }
    echo '<form method="post" action="' . pluginupdater_escape($moduleLink) . '"><input type="hidden" name="token" value="' . pluginupdater_escape($token) . '"><input type="hidden" name="action" value="check"><button class="btn btn-default" type="submit">Check now</button></form>';

    if ($status === []) {
        echo '<p>No valid update manifests were found.</p>';
        return;
    }
    echo '<table class="pu-table"><thead><tr><th>Package</th><th>Installed components</th><th>Latest release</th><th>Actions</th></tr></thead><tbody>';
    foreach ($status as $package => $item) {
        $release = $item['release'];
        $cache = $item['cache'];
        $components = array_map(static fn ($manifest): string => $manifest->componentKey() . ' ' . $manifest->version, $item['manifests']);
        echo '<tr><td><strong>' . pluginupdater_escape($package) . '</strong><br><small>' . pluginupdater_escape($item['manifests'][0]->repository) . '</small></td>';
        echo '<td>' . implode('<br>', array_map('pluginupdater_escape', $components)) . '</td><td>';
        if ($release) {
            echo '<strong>' . pluginupdater_escape($release->version) . '</strong> &mdash; <a rel="noopener noreferrer" target="_blank" href="' . pluginupdater_escape($release->url) . '">GitHub release</a>';
            if ($release->notes !== '') {
                echo '<details><summary>Release notes</summary><pre class="pu-notes">' . pluginupdater_escape($release->notes) . '</pre></details>';
            }
        } else {
            echo 'No newer verified release';
        }
        if (($cache->error ?? null)) {
            echo '<div class="pu-warning">' . pluginupdater_escape((string) $cache->error) . '</div>';
        }
        if ($item['selection_error']) {
            echo '<div class="pu-warning">' . pluginupdater_escape($item['selection_error']) . '</div>';
        }
        echo '</td><td class="pu-actions">';
        echo pluginupdater_action_form($moduleLink, $token, 'preflight', $package, 'Run pre-flight checks', false, false);
        if ($release) {
            echo pluginupdater_action_form($moduleLink, $token, 'update', $package, 'Update to ' . $release->version, true, $timeoutWarnings !== []);
        }
        if (($rollbackVersion = $service->rollbackVersion($package)) !== null) {
            echo pluginupdater_action_form($moduleLink, $token, 'rollback', $package, 'Roll back to ' . $rollbackVersion, true, $timeoutWarnings !== []);
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<details><summary>What does the pre-flight check do?</summary><ul>'
        . '<li>Checks that update storage is outside the web and WHMCS roots and has secure permissions.</li>'
        . '<li>Checks that installed plugin directories exist, are readable, and contain no unsafe files.</li>'
        . '<li>Checks storage and plugin directory ownership.</li>'
        . '<li>Tests creating, writing, renaming, and removing temporary files.</li>'
        . '<li>Checks the PHP execution-time limit.</li>'
        . '</ul><p>It does not download a release, modify installed plugins, change the database, or enable maintenance mode.</p></details>';
}

function pluginupdater_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pluginupdater_action_form(string $link, string $token, string $action, string $package, string $label, bool $confirmBackup, bool $confirmTimeout): string
{
    $html = '<form method="post" action="' . pluginupdater_escape($link) . '"><input type="hidden" name="token" value="' . pluginupdater_escape($token) . '"><input type="hidden" name="action" value="' . pluginupdater_escape($action) . '"><input type="hidden" name="package" value="' . pluginupdater_escape($package) . '">';
    if ($confirmBackup) {
        $html .= '<label><input required type="checkbox" name="database_backup" value="1"> I have created and verified a current WHMCS database backup.</label><br>';
    }
    if ($action === 'update') {
        $html .= '<label><input required type="checkbox" name="components_ack" value="1"> I approve all addon, server, and registrar components declared by this release.</label><br>';
    }
    if ($confirmTimeout) {
        $html .= '<label><input required type="checkbox" name="timeout_ack" value="1"> I accept the PHP execution-time warning.</label><br>';
    }
    $html .= '<button class="btn btn-' . ($action === 'update' ? 'primary' : 'default') . '" type="submit">' . pluginupdater_escape($label) . '</button></form>';
    return $html;
}
