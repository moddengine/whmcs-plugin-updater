<?php

declare(strict_types=1);

use PluginUpdater\Archive;
use PluginUpdater\Fs;
use PluginUpdater\GitHubClient;
use PluginUpdater\Manifest;
use PluginUpdater\Recovery;
use PluginUpdater\Release;
use PluginUpdater\Transaction;

require_once __DIR__ . '/../modules/addons/pluginupdater/lib/Core.php';

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Transaction tests must not run as root.\n");
    exit(2);
}

$base = sys_get_temp_dir() . '/pluginupdater-test-' . bin2hex(random_bytes(6));
mkdir($base, 0700, true);

try {
    testManifestAndRelease($base);
    testArchiveSafety($base);
    testTransactionAndRollback($base);
    testRecovery($base);
    fwrite(STDOUT, "All plugin updater checks passed.\n");
} finally {
    Fs::remove($base);
}

function expectException(callable $callable, string $contains): void
{
    try {
        $callable();
    } catch (Throwable $e) {
        assert(str_contains($e->getMessage(), $contains), $e->getMessage());
        return;
    }
    throw new RuntimeException("Expected exception containing: {$contains}");
}

function manifestData(string $type, string $name, string $version = '1.0.0'): array
{
    return [
        'schema' => 1,
        'package' => 'acme/example',
        'component' => ['type' => $type, 'name' => $name],
        'version' => $version,
        'github' => ['repository' => 'acme/example', 'asset' => 'example-{version}.zip'],
        'requires' => ['php_min' => '8.3.0', 'whmcs_min' => '8.13.0', 'whmcs_max_exclusive' => '10.0.0'],
    ];
}

function writeJson(string $path, array $data): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function testManifestAndRelease(string $base): void
{
    $path = $base . '/manifest.json';
    writeJson($path, manifestData('addon', 'example'));
    $manifest = Manifest::fromFile($path);
    assert($manifest->componentKey() === 'addon:example');
    assert(Manifest::tagVersion('v2.3.4') === '2.3.4');
    assert(Manifest::tagVersion('2.3') === null);

    $bad = manifestData('addon', 'example');
    $bad['destination'] = '/tmp/owned';
    writeJson($path, $bad);
    expectException(fn () => Manifest::fromFile($path), 'Unknown manifest field');

    writeJson($path, manifestData('addon', 'example'));
    $releases = [[
        'draft' => false,
        'prerelease' => false,
        'tag_name' => 'v1.2.0',
        'html_url' => 'https://github.com/acme/example/releases/tag/v1.2.0',
        'body' => '<script>alert(1)</script>',
        'assets' => [[
            'id' => 42,
            'name' => 'example-1.2.0.zip',
            'size' => 100,
            'digest' => 'sha256:' . str_repeat('a', 64),
        ]],
    ]];
    $release = Release::select($releases, [$manifest]);
    assert($release->version === '1.2.0');
    assert($release->assetId === 42);
    $brokenLatest = $releases[0];
    $brokenLatest['tag_name'] = 'v1.3.0';
    $brokenLatest['assets'] = [];
    expectException(fn () => Release::select([$releases[0], $brokenLatest], [$manifest]), 'must contain exactly one');
}

function testArchiveSafety(string $base): void
{
    $safe = $base . '/safe.zip';
    $zip = new ZipArchive();
    assert($zip->open($safe, ZipArchive::CREATE) === true);
    $zip->addFromString('wrapper/addon/' . Manifest::FILENAME, json_encode(manifestData('addon', 'example'), JSON_THROW_ON_ERROR));
    $zip->addFromString('wrapper/addon/example.php', '<?php return true;');
    $zip->close();
    Archive::extract($safe, $base . '/safe-out');
    assert(count(Archive::manifests($base . '/safe-out')) === 1);

    $traversal = $base . '/traversal.zip';
    $zip = new ZipArchive();
    $zip->open($traversal, ZipArchive::CREATE);
    $zip->addFromString('../escape.php', 'bad');
    $zip->close();
    expectException(fn () => Archive::extract($traversal, $base . '/traversal-out'), 'unsafe path');

    $symlink = $base . '/symlink.zip';
    $zip = new ZipArchive();
    $zip->open($symlink, ZipArchive::CREATE);
    $zip->addFromString('link', '/etc/passwd');
    $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    expectException(fn () => Archive::extract($symlink, $base . '/symlink-out'), 'link or special file');
}

function testTransactionAndRollback(string $base): void
{
    $fixture = $base . '/transaction';
    $whmcs = $fixture . '/whmcs';
    $web = $fixture . '/web';
    $storage = $fixture . '/storage';
    foreach ([$whmcs . '/modules/addons/example', $whmcs . '/modules/servers/example', $whmcs . '/modules/registrars', $web, $storage] as $directory) {
        mkdir($directory, 0700, true);
    }
    chmod($storage, 0700);
    expectException(fn () => Fs::validateStorage($web, $web, $whmcs), 'outside the web');
    chmod($storage, 0770);
    expectException(fn () => Fs::validateStorage($storage, $web, $whmcs), 'mode 0700');
    chmod($storage, 0700);
    Fs::validateStorage($storage, $web, $whmcs);
    writeJson($whmcs . '/modules/addons/example/' . Manifest::FILENAME, manifestData('addon', 'example'));
    writeJson($whmcs . '/modules/servers/example/' . Manifest::FILENAME, manifestData('server', 'example'));
    file_put_contents($whmcs . '/modules/addons/example/old.php', 'old-addon');
    file_put_contents($whmcs . '/modules/servers/example/old.php', 'old-server');

    $releaseZip = $fixture . '/release.zip';
    $zip = new ZipArchive();
    $zip->open($releaseZip, ZipArchive::CREATE);
    foreach (['addon', 'server', 'registrar'] as $type) {
        $zip->addFromString("package/{$type}/" . Manifest::FILENAME, json_encode(manifestData($type, 'example', '1.1.0'), JSON_THROW_ON_ERROR));
        $zip->addFromString("package/{$type}/new.php", "new-{$type}");
    }
    $zip->close();
    $release = new Release('1.1.0', 'v1.1.0', 'https://github.com/acme/example/releases/tag/v1.1.0', '', 1, 'example-1.1.0.zip', filesize($releaseZip), hash_file('sha256', $releaseZip));
    $github = new class($releaseZip) extends GitHubClient {
        public function __construct(private readonly string $fixture) { parent::__construct(); }
        public function download(string $repository, Release $release, string $destination): void { copy($this->fixture, $destination); }
    };
    $maintenanceState = '';
    $changes = [];
    $transaction = new Transaction(
        $whmcs,
        $web,
        $storage,
        '9.0.0',
        fn (): string => $maintenanceState,
        function (string $value) use (&$maintenanceState, &$changes): void { $maintenanceState = $value; $changes[] = $value; },
        static function (string $message): void {},
    );
    $installed = Manifest::discover($whmcs);
    expectException(fn () => $transaction->apply($installed, $release, $github, true, true, true, false), 'adds components');
    assert(!file_exists($whmcs . '/modules/registrars/example'));
    mkdir($whmcs . '/modules/registrars/example', 0700);
    file_put_contents($whmcs . '/modules/registrars/example/unmanaged.php', 'unmanaged');
    expectException(fn () => $transaction->apply($installed, $release, $github, true, true, true, true), 'destination already exists');
    assert(file_get_contents($whmcs . '/modules/registrars/example/unmanaged.php') === 'unmanaged');
    Fs::remove($whmcs . '/modules/registrars/example');
    $transaction->apply($installed, $release, $github, true, true, true, true);
    assert(file_get_contents($whmcs . '/modules/addons/example/new.php') === 'new-addon');
    assert(!file_exists($whmcs . '/modules/addons/example/old.php'));
    assert(file_get_contents($whmcs . '/modules/registrars/example/new.php') === 'new-registrar');
    assert($changes === ['on', '']);

    $transaction->rollback(Manifest::discover($whmcs), true, true, true);
    assert(file_get_contents($whmcs . '/modules/addons/example/old.php') === 'old-addon');
    assert(!file_exists($whmcs . '/modules/addons/example/new.php'));
    assert(!file_exists($whmcs . '/modules/registrars/example'));
}

function testRecovery(string $base): void
{
    $root = $base . '/recovery';
    $storage = $root . '/storage';
    $parent = $root . '/modules/addons';
    mkdir($storage . '/transactions', 0700, true);
    mkdir($parent, 0700, true);
    $destination = $parent . '/example';
    $old = $parent . '/.pluginupdater-old-abc-example';
    $deployment = $parent . '/.pluginupdater-new-abc-example';
    mkdir($destination, 0700);
    mkdir($old, 0700);
    file_put_contents($destination . '/new.php', 'new');
    file_put_contents($old . '/old.php', 'old');
    writeJson($storage . '/transactions/abc.json', [
        'schema' => 1,
        'id' => 'abc',
        'whmcs_root' => $root,
        'status' => 'swapping',
        'maintenance_original' => '',
        'maintenance_changed' => true,
        'swaps' => [[
            'destination' => $destination,
            'old' => $old,
            'deployment' => $deployment,
            'had_original' => true,
            'swapped' => true,
        ]],
    ]);
    $maintenance = 'on';
    assert(Recovery::pending($storage, function (string $value) use (&$maintenance): void { $maintenance = $value; }, static function (string $message): void {}) === 1);
    assert(file_get_contents($destination . '/old.php') === 'old');
    assert($maintenance === '');

    $committedOld = $parent . '/.pluginupdater-old-done-example';
    $committedDeployment = $parent . '/.pluginupdater-new-done-example';
    mkdir($committedOld, 0700);
    file_put_contents($committedOld . '/stale.php', 'stale');
    writeJson($storage . '/transactions/done.json', [
        'schema' => 1,
        'id' => 'done',
        'whmcs_root' => $root,
        'status' => 'committed',
        'swaps' => [[
            'destination' => $destination,
            'old' => $committedOld,
            'deployment' => $committedDeployment,
            'had_original' => true,
            'swapped' => true,
            'remove_only' => false,
        ]],
    ]);
    assert(Recovery::pending($storage, static function (string $value): void {}, static function (string $message): void {}) === 0);
    assert(!file_exists($committedOld));
    assert(file_get_contents($destination . '/old.php') === 'old');

    $newDestination = $parent . '/newcomponent';
    mkdir($newDestination, 0700);
    file_put_contents($newDestination . '/new.php', 'new');
    $newSwap = [
        'destination' => $newDestination,
        'old' => $parent . '/.pluginupdater-old-gap-newcomponent',
        'deployment' => $parent . '/.pluginupdater-new-gap-newcomponent',
        'had_original' => false,
        'swapped' => false,
        'remove_only' => false,
    ];
    writeJson($storage . '/transactions/gap.json', [
        'schema' => 1,
        'id' => 'gap',
        'whmcs_root' => $root,
        'status' => 'swapping',
        'swaps' => [$newSwap],
    ]);
    Recovery::pending($storage, static function (string $value): void {}, static function (string $message): void {});
    assert(!file_exists($newDestination));

    mkdir($newDestination, 0700);
    file_put_contents($newDestination . '/new.php', 'new');
    writeJson($storage . '/transactions/standalone.json', [
        'schema' => 1,
        'id' => 'standalone',
        'whmcs_root' => $root,
        'status' => 'swapping',
        'swaps' => [$newSwap],
    ]);
    copy(__DIR__ . '/../modules/addons/pluginupdater/bin/recover.php', $storage . '/recover.php');
    $process = proc_open([PHP_BINARY, $storage . '/recover.php', '--latest'], [STDIN, STDOUT, STDERR], $pipes);
    assert(is_resource($process) && proc_close($process) === 0);
    assert(!file_exists($newDestination));
}
