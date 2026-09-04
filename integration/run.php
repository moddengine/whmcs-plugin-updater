<?php

declare(strict_types=1);

use PluginUpdater\Cache;
use PluginUpdater\Fs;
use PluginUpdater\GitHubClient;
use PluginUpdater\Manifest;
use PluginUpdater\Release;
use PluginUpdater\Service;
use PluginUpdater\Transaction;
use WHMCS\Database\Capsule;

$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
$_SERVER['HTTP_HOST'] = 'localhost:18080';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '18080';
require '/var/www/html/init.php';
require_once '/var/www/html/modules/addons/pluginupdater/pluginupdater.php';
require_once '/var/www/html/modules/addons/pluginupdater/hooks.php';

final class LocalReleaseClient extends GitHubClient
{
    public function __construct(private readonly string $archive)
    {
        parent::__construct();
    }

    public function download(string $repository, Release $release, string $destination): void
    {
        if (!copy($this->archive, $destination)) {
            throw new RuntimeException('Unable to copy local release fixture');
        }
        check(hash_file('sha256', $destination) === $release->digest, 'Fixture digest mismatch');
    }
}

const STORAGE = '/var/lib/whmcs-plugin-updater';
const FIXTURES = '/tmp/pluginupdater-integration-fixtures';

try {
    resetFixtures();
    testRuntimeAndActivation();
    testPreflightBoundaries();
    testBackoff();
    testSingleUpdateAndRollback();
    testGroupedUpdateAndRollback();
    testInterruptedUpdateRecovery();
    testSelfUpdateAndRollback();
    fwrite(STDOUT, "All licensed WHMCS integration checks passed.\n");
} finally {
    foreach ([
        ROOTDIR . '/modules/addons/pluginupdatersingle',
        ROOTDIR . '/modules/addons/pluginupdatergrouped',
        ROOTDIR . '/modules/servers/pluginupdatergrouped',
    ] as $path) {
        if (file_exists($path)) {
            Fs::remove($path);
        }
    }
    if (is_dir(FIXTURES)) {
        Fs::remove(FIXTURES);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $message), "Unexpected failure: {$e->getMessage()}");
        return;
    }
    throw new RuntimeException("Expected failure containing: {$message}");
}

function resetFixtures(): void
{
    $browserFixture = ROOTDIR . '/modules/addons/playwrightfixture';
    if (is_dir($browserFixture)) {
        Fs::remove($browserFixture);
    }
    foreach (new DirectoryIterator(STORAGE) as $entry) {
        if (!$entry->isDot()) {
            Fs::remove($entry->getPathname());
        }
    }
    if (is_dir(FIXTURES)) {
        Fs::remove(FIXTURES);
    }
    Fs::mkdir(FIXTURES, 0700);
    chmod(STORAGE, 0700);
}

function manifest(string $package, string $repository, string $type, string $name, string $version): array
{
    return [
        'schema' => 1,
        'package' => $package,
        'component' => ['type' => $type, 'name' => $name],
        'version' => $version,
        'github' => ['repository' => $repository, 'asset' => basename($repository) . '-{version}.zip'],
        'requires' => ['php_min' => '8.3.0', 'whmcs_min' => '8.13.0', 'whmcs_max_exclusive' => '10.0.0'],
    ];
}

function writeManifest(string $root, array $manifest): void
{
    Fs::mkdir($root, 0755);
    file_put_contents($root . '/' . Manifest::FILENAME, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

/** @param list<array{manifest:array<string,mixed>,files:array<string,string>}> $components */
function releaseFixture(string $name, array $components): array
{
    $path = FIXTURES . '/' . $name . '.zip';
    $zip = new ZipArchive();
    check($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Unable to create fixture ZIP');
    $zip->addFromString('wrapper/not-installed.txt', 'outside every manifest root');
    foreach ($components as $index => $component) {
        $root = 'wrapper/component-' . $index . '/';
        $zip->addFromString($root . Manifest::FILENAME, json_encode($component['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        foreach ($component['files'] as $relative => $contents) {
            $zip->addFromString($root . $relative, $contents);
        }
    }
    $zip->close();
    $version = $components[0]['manifest']['version'];
    return [$path, new Release(
        $version,
        'v' . $version,
        'https://github.com/' . $components[0]['manifest']['github']['repository'] . '/releases/tag/v' . $version,
        "Integration fixture {$version}",
        1,
        $name . '.zip',
        (int) filesize($path),
        (string) hash_file('sha256', $path),
    )];
}

function transaction(): Transaction
{
    return new Transaction(
        ROOTDIR,
        '/var/www/html',
        STORAGE,
        (string) WHMCS\Config\Setting::getValue('Version'),
        static function (): string {
            $result = localAPI('GetConfigurationValue', ['setting' => 'MaintenanceMode']);
            check(($result['result'] ?? null) === 'success', 'Unable to read Maintenance Mode');
            return (string) ($result['value'] ?? '');
        },
        static function (string $value): void {
            $result = localAPI('SetConfigurationValue', ['setting' => 'MaintenanceMode', 'value' => $value]);
            check(($result['result'] ?? null) === 'success', 'Unable to change Maintenance Mode');
        },
        static function (string $message): void {
            logActivity('[Plugin Updater integration] ' . $message);
        },
    );
}

/** @return list<Manifest> */
function installedPackage(string $package): array
{
    return array_values(array_filter(
        Manifest::discover(ROOTDIR),
        static fn (Manifest $manifest): bool => $manifest->package === $package,
    ));
}

function testRuntimeAndActivation(): void
{
    $expectedVersion = getenv('WHMCS_EXPECTED_VERSION') ?: '';
    check($expectedVersion !== '' && str_starts_with((string) WHMCS\Config\Setting::getValue('Version'), $expectedVersion), 'Unexpected WHMCS version');
    check(PHP_VERSION_ID >= 80300 && extension_loaded('ionCube Loader') && extension_loaded('zip'), 'Incomplete PHP runtime');
    check(posix_geteuid() !== 0 && fileowner(ROOTDIR . '/modules/addons') === posix_geteuid(), 'Tests must run as the web-server owner');
    $result = pluginupdater_activate();
    check(($result['status'] ?? null) === 'success', 'Addon activation failed');
    check(Capsule::schema()->hasTable('mod_pluginupdater_repositories'), 'Updater cache table was not created');
    fwrite(STDOUT, "PASS runtime, licence bootstrap, and addon activation\n");
}

function testPreflightBoundaries(): void
{
    Fs::validateStorage(STORAGE, '/var/www/html', ROOTDIR);
    chmod(STORAGE, 0770);
    expectFailure(static fn () => Fs::validateStorage(STORAGE, '/var/www/html', ROOTDIR), 'mode 0700');
    chmod(STORAGE, 0700);
    expectFailure(static fn () => Fs::validateStorage(ROOTDIR . '/templates_c', '/var/www/html', ROOTDIR), 'outside the web');
    expectFailure(static fn () => Fs::validateOwners(['/source']), 'Owner mismatch');
    check(Transaction::executionTimeWarnings() === [], 'set_time_limit(120) should succeed in the normal runtime');
    check((int) ini_get('max_execution_time') >= 120, 'Execution time was not increased to 120 seconds');
    fwrite(STDOUT, "PASS external storage, owner, and execution-time preflight\n");
}

function testBackoff(): void
{
    $repository = 'acme/pluginupdater-backoff-fixture';
    Capsule::table('mod_pluginupdater_repositories')->where('repository', $repository)->delete();
    Cache::failure($repository, 'fixture timeout', 0, [], 'fixture');
    $first = Cache::row($repository);
    Cache::failure($repository, 'fixture timeout', 0, [], 'fixture');
    $second = Cache::row($repository);
    $firstDelay = strtotime($first->next_attempt_at . ' UTC') - strtotime($first->last_attempt_at . ' UTC');
    $secondDelay = strtotime($second->next_attempt_at . ' UTC') - strtotime($second->last_attempt_at . ' UTC');
    check($firstDelay === 3600 && $secondDelay === 7200, 'Daily-cron backoff is not strict exponential backoff');
    check(!Cache::mayAttempt($second, 'fixture'), 'Backoff allowed an early retry');
    Capsule::table('mod_pluginupdater_repositories')->where('repository', $repository)->delete();
    fwrite(STDOUT, "PASS persisted exponential backoff\n");
}

function testSingleUpdateAndRollback(): void
{
    $package = 'acme/pluginupdater-single';
    $repository = 'acme/pluginupdater-single';
    $root = ROOTDIR . '/modules/addons/pluginupdatersingle';
    $v1 = manifest($package, $repository, 'addon', 'pluginupdatersingle', '1.0.0');
    writeManifest($root, $v1);
    file_put_contents($root . '/old.php', 'old');

    (new Service(['storagePath' => STORAGE, 'maintenanceMode' => 'on']))->preflight($package);
    check(is_file(STORAGE . '/recover.php'), 'External recovery command was not installed');
    file_put_contents(STORAGE . '/recover.php', 'stale');
    (new Service(['storagePath' => STORAGE, 'maintenanceMode' => 'on']))->preflight($package);
    check(hash_file('sha256', STORAGE . '/recover.php') === hash_file('sha256', ROOTDIR . '/modules/addons/pluginupdater/bin/recover.php'), 'External recovery command was not refreshed');
    check((fileperms(STORAGE . '/recover.php') & 0777) === 0700, 'External recovery command has unsafe permissions');

    $v11 = manifest($package, $repository, 'addon', 'pluginupdatersingle', '1.1.0');
    [$zip, $release] = releaseFixture('pluginupdater-single-1.1.0', [[
        'manifest' => $v11,
        'files' => ['new.php' => 'new', 'nested/value.txt' => '1.1.0'],
    ]]);
    Cache::success($repository, null, [[
        'draft' => false,
        'prerelease' => false,
        'tag_name' => 'v1.1.0',
        'body' => '<b>Fixture release notes</b>',
        'assets' => [[
            'id' => 1,
            'name' => 'pluginupdater-single-1.1.0.zip',
            'size' => filesize($zip),
            'digest' => 'sha256:' . hash_file('sha256', $zip),
        ]],
    ]], 'fixture', false);
    $cache = Cache::row($repository);
    check(strtotime($cache->next_attempt_at . ' UTC') - strtotime($cache->last_attempt_at . ' UTC') === 7 * 86400, 'Successful check was not cached for one week');
    $service = new Service(['storagePath' => STORAGE, 'maintenanceMode' => 'on']);
    check($service->status()[$package]['release']?->version === '1.1.0', 'Cached update was not selected');
    $widget = new PluginUpdaterWidget();
    check(str_contains($widget->generateOutput($widget->getData()), '1</strong> update(s) available'), 'Dashboard widget did not show the cached update');
    ob_start();
    pluginupdater_output(['storagePath' => STORAGE, 'maintenanceMode' => 'on', 'modulelink' => 'addonmodules.php?module=pluginupdater']);
    $html = (string) ob_get_clean();
    check(str_contains($html, '&lt;b&gt;Fixture release notes&lt;/b&gt;'), 'Release notes were absent or not HTML-escaped');
    check(str_contains($html, 'I have created and verified a current WHMCS database backup.'), 'Update form omitted the database-backup confirmation');

    transaction()->apply(installedPackage($package), $release, new LocalReleaseClient($zip), true, true, true, true);
    check(is_file($root . '/new.php') && !file_exists($root . '/old.php'), 'Single update did not replace the complete tree');
    check(!file_exists(ROOTDIR . '/modules/addons/not-installed.txt'), 'File above manifest root escaped into WHMCS');
    check(localAPI('GetConfigurationValue', ['setting' => 'MaintenanceMode'])['value'] === '', 'Maintenance Mode was not restored');
    check(count(glob(STORAGE . '/backups/' . hash('sha256', $package) . '/*') ?: []) === 1, 'Expected one rollback copy');

    transaction()->rollback(installedPackage($package), true, true, true);
    check(is_file($root . '/old.php') && !file_exists($root . '/new.php'), 'Single rollback did not restore the baseline tree');
    fwrite(STDOUT, "PASS cached presentation, normal update, complete replacement, maintenance, and rollback\n");
}

function testGroupedUpdateAndRollback(): void
{
    $package = 'acme/pluginupdater-grouped';
    $repository = 'acme/pluginupdater-grouped';
    $addon = ROOTDIR . '/modules/addons/pluginupdatergrouped';
    $server = ROOTDIR . '/modules/servers/pluginupdatergrouped';
    writeManifest($addon, manifest($package, $repository, 'addon', 'pluginupdatergrouped', '1.0.0'));
    writeManifest($server, manifest($package, $repository, 'server', 'pluginupdatergrouped', '1.0.0'));
    file_put_contents($addon . '/old-addon.php', 'old addon');
    file_put_contents($server . '/old-server.php', 'old server');

    $addonV2 = manifest($package, $repository, 'addon', 'pluginupdatergrouped', '2.0.0');
    $serverV2 = manifest($package, $repository, 'server', 'pluginupdatergrouped', '2.0.0');
    [$zip, $release] = releaseFixture('pluginupdater-grouped-2.0.0', [
        ['manifest' => $addonV2, 'files' => ['new-addon.php' => 'new addon']],
        ['manifest' => $serverV2, 'files' => ['new-server.php' => 'new server']],
    ]);
    transaction()->apply(installedPackage($package), $release, new LocalReleaseClient($zip), true, true, true, true);
    check(is_file($addon . '/new-addon.php') && is_file($server . '/new-server.php'), 'Grouped components were not both installed');
    check(!file_exists($addon . '/new-server.php') && !file_exists($server . '/new-addon.php'), 'Grouped manifest roots crossed destinations');

    transaction()->rollback(installedPackage($package), true, true, true);
    check(is_file($addon . '/old-addon.php') && is_file($server . '/old-server.php'), 'Grouped rollback was incomplete');
    fwrite(STDOUT, "PASS grouped addon/server update and rollback\n");
}

function testInterruptedUpdateRecovery(): void
{
    $package = 'acme/pluginupdater-crash';
    $repository = 'acme/pluginupdater-crash';
    $components = [];
    $roots = [];
    for ($index = 0; $index < 20; ++$index) {
        $name = sprintf('pluginupdatercrash%02d', $index);
        $root = ROOTDIR . '/modules/addons/' . $name;
        $roots[] = $root;
        writeManifest($root, manifest($package, $repository, 'addon', $name, '1.0.0'));
        file_put_contents($root . '/old.php', 'old ' . $index);
        $components[] = [
            'manifest' => manifest($package, $repository, 'addon', $name, '1.1.0'),
            'files' => ['new.php' => 'new ' . $index],
        ];
    }
    [$zip] = releaseFixture('pluginupdater-crash-1.1.0', $components);
    $metadata = FIXTURES . '/crash-release.json';
    file_put_contents($metadata, json_encode(['package' => $package, 'version' => '1.1.0'], JSON_THROW_ON_ERROR));

    $process = proc_open(
        [PHP_BINARY, '/source/integration/crash-apply.php', $zip, $metadata],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
    );
    check(is_resource($process), 'Unable to start crash-test update');
    $observedSwap = false;
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        if ((glob(ROOTDIR . '/modules/addons/.pluginupdater-old-*-pluginupdatercrash*') ?: []) !== []) {
            $observedSwap = true;
            proc_terminate($process, 9);
            break;
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(500);
    }
    proc_close($process);
    check($observedSwap, 'Could not interrupt an update during its rename sequence');

    $recovery = proc_open(
        [PHP_BINARY, STORAGE . '/recover.php', '--latest'],
        [STDIN, ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
    );
    check(is_resource($recovery), 'Unable to start external recovery command');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($recovery) === 0 && str_contains($output, 'Recovered transaction'), 'External recovery failed: ' . $error . $output);
    foreach ($roots as $index => $root) {
        check(is_file($root . '/old.php') && !file_exists($root . '/new.php'), 'Crash recovery left a mixed component at index ' . $index);
        Fs::remove($root);
    }
    localAPI('SetConfigurationValue', ['setting' => 'MaintenanceMode', 'value' => '']);
    fwrite(STDOUT, "PASS process-death interruption and external multi-component recovery\n");
}

function testSelfUpdateAndRollback(): void
{
    $package = 'acme/pluginupdater-self';
    $repository = 'acme/pluginupdater-self';
    $root = ROOTDIR . '/modules/addons/pluginupdater';
    writeManifest($root, manifest($package, $repository, 'addon', 'pluginupdater', '0.0.1'));
    file_put_contents($root . '/self-version.txt', "0.0.1\n");

    $next = manifest($package, $repository, 'addon', 'pluginupdater', '0.0.2');
    $files = [];
    $source = '/source/modules/addons/pluginupdater';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->getFilename() === Manifest::FILENAME) {
            continue;
        }
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $contents = (string) file_get_contents($entry->getPathname());
        if ($relative === 'pluginupdater.php') {
            $contents = str_replace("'version' => '0.0.1'", "'version' => '0.0.2'", $contents);
        }
        $files[$relative] = $contents;
    }
    $files['self-version.txt'] = "0.0.2\n";
    [$zip, $release] = releaseFixture('pluginupdater-self-0.0.2', [['manifest' => $next, 'files' => $files]]);

    transaction()->apply(installedPackage($package), $release, new LocalReleaseClient($zip), true, true, true, true);
    check(trim((string) file_get_contents($root . '/self-version.txt')) === '0.0.2', 'Self-update marker was not replaced');
    runSelfCheck('0.0.2');

    transaction()->rollback(installedPackage($package), true, true, true);
    check(trim((string) file_get_contents($root . '/self-version.txt')) === '0.0.1', 'Self-update rollback marker was not restored');
    runSelfCheck('0.0.1');
    fwrite(STDOUT, "PASS self-update, fresh-process load, OPcache invalidation, and rollback\n");
}

function runSelfCheck(string $version): void
{
    $process = proc_open([PHP_BINARY, '/source/integration/self-check.php', $version], [STDIN, ['pipe', 'w'], STDERR], $pipes);
    check(is_resource($process), 'Unable to start fresh self-update check');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    check(proc_close($process) === 0, 'Fresh self-update process failed');
    fwrite(STDOUT, $output);
}
