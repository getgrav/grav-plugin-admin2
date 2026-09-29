<?php

/**
 * Tests for Admin2Plugin::anyUsersExist(), the check that decides whether a
 * frontend request is sent to first-run setup.
 *
 * admin2 has no Composer or PHPUnit setup (it ships a single PHP file), so this
 * is a plain CLI script that loads Grav core's autoloader, builds the few
 * services the check reads, and calls the private method directly.
 *
 *   php tests/any-users-exist.php
 *   GRAV_ROOT=/path/to/grav php tests/any-users-exist.php
 *
 * Grav core is found through GRAV_ROOT, the Grav install the plugin sits in
 * (user/plugins/admin2), or a `grav` checkout next to the plugin repo.
 * Exits non-zero on any failure.
 */

declare(strict_types=1);

use Grav\Common\Config\Config;
use Grav\Common\Flex\Types\Users\Storage\UserFileStorage;
use Grav\Common\Flex\Types\Users\Storage\UserFolderStorage;
use Grav\Common\Grav;
use Grav\Framework\File\Formatter\YamlFormatter;
use Grav\Plugin\Admin2Plugin;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$autoloader = null;
foreach ([
    getenv('GRAV_ROOT') ? rtrim((string) getenv('GRAV_ROOT'), '/') . '/vendor/autoload.php' : null,
    __DIR__ . '/../../../../vendor/autoload.php',
    __DIR__ . '/../../grav/vendor/autoload.php',
] as $candidate) {
    if ($candidate && is_file($candidate)) {
        $autoloader = $candidate;
        break;
    }
}
if ($autoloader === null) {
    fwrite(STDERR, "Grav core not found. Set GRAV_ROOT=/path/to/grav.\n");
    exit(2);
}
// Grav resolves account:// relative to GRAV_ROOT, as on a real site
// (user/accounts), so root it in a throwaway folder before core loads.
$tmpRoot = sys_get_temp_dir() . '/admin2-any-users-' . bin2hex(random_bytes(4));
mkdir($tmpRoot, 0777, true);
$tmpRoot = realpath($tmpRoot);
define('GRAV_ROOT', $tmpRoot);
define('GRAV_DOTENV_DISABLE', true);
require $autoloader;
require_once __DIR__ . '/../admin2.php';

/** Accounts service stand-in that records how often count() is called. */
final class CountingAccounts
{
    public int $calls = 0;

    public function __construct(private int $total)
    {
    }

    public function count(): int
    {
        $this->calls++;

        return $this->total;
    }
}

final class FakeFlex
{
    public function __construct(private ?object $storage)
    {
    }

    public function getDirectory(string $type): ?object
    {
        if ($type !== 'user-accounts' || $this->storage === null) {
            return null;
        }

        return new class ($this->storage) {
            public function __construct(private object $storage)
            {
            }

            public function getStorage(): object
            {
                return $this->storage;
            }
        };
    }
}

$grav = Grav::instance();

/**
 * Sets up a fresh account:// folder and services, then returns a new plugin
 * instance (so nothing is memoized across cases).
 *
 * @param array<string,string> $files  Paths relative to account:// => contents
 * @param callable|object|null $accounts  Accounts service, or a closure that throws
 */
$setup = static function (string $name, array $files, string $type, $accounts, ?callable $storage = null) use ($grav, $tmpRoot): Admin2Plugin {
    $dir = "{$tmpRoot}/{$name}/accounts";
    mkdir($dir, 0777, true);
    foreach ($files as $path => $contents) {
        @mkdir(dirname("{$dir}/{$path}"), 0777, true);
        file_put_contents("{$dir}/{$path}", $contents);
    }

    $locator = new UniformResourceLocator(GRAV_ROOT);
    $locator->addPath('account', '', "{$name}/accounts");
    $locator->addPath('elsewhere', '', $name);

    foreach (['locator', 'accounts', 'flex'] as $service) {
        unset($grav[$service]);
    }
    $grav['locator'] = static fn () => $locator;
    if ($accounts instanceof Closure) {
        $grav['accounts'] = $accounts;
    } else {
        $grav['accounts'] = static fn () => $accounts;
    }
    $grav['flex'] = static fn () => new FakeFlex($storage ? $storage() : null);

    $plugin = (new ReflectionClass(Admin2Plugin::class))->newInstanceWithoutConstructor();
    $set = static function (string $prop, $value) use ($plugin): void {
        $ref = new ReflectionProperty($plugin, $prop);
        $ref->setValue($plugin, $value);
    };
    $set('grav', $grav);
    $set('config', new Config(['system' => ['accounts' => ['type' => $type]]]));

    return $plugin;
};

$check = static function (Admin2Plugin $plugin): bool {
    return (new ReflectionMethod($plugin, 'anyUsersExist'))->invoke($plugin);
};

$failures = 0;
$assert = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$fileStorage = static fn (string $folder) => static fn () => new UserFileStorage([
    'folder' => $folder,
    'formatter' => ['class' => YamlFormatter::class],
    'pattern' => '{FOLDER}/{KEY}{EXT}',
    'key' => 'username',
    'indexed' => true,
    'case_sensitive' => false,
]);
$folderStorage = static fn () => new UserFolderStorage([
    'folder' => 'account://',
    'formatter' => ['class' => YamlFormatter::class],
    'file' => 'user',
    'pattern' => '{FOLDER}/{KEY:2}/{KEY}/{FILE}{EXT}',
    'key' => 'storage_key',
    'indexed' => true,
    'case_sensitive' => false,
]);

try {
    echo "Regular accounts\n";

    $accounts = new CountingAccounts(0);
    $plugin = $setup('regular-yaml', ['admin.yaml' => "email: a@example.com\n"], 'regular', $accounts);
    $assert('a YAML account answers true', $check($plugin) === true);
    $assert('without calling count()', $accounts->calls === 0);

    $accounts = new CountingAccounts(10000);
    $plugin = $setup('regular-db-only', [], 'regular', $accounts);
    $assert('no YAML but the collection counts accounts answers true', $check($plugin) === true);
    $assert('by calling count() once', $accounts->calls === 1);
    $check($plugin);
    $assert('and the answer is memoized for the request', $accounts->calls === 1);

    $accounts = new CountingAccounts(0);
    $plugin = $setup('regular-none', ['.hidden.yaml' => '', 'notes.txt' => '', 'sub/user.yaml' => ''], 'regular', $accounts);
    $assert('no accounts anywhere answers false', $check($plugin) === false);
    $assert('after asking count()', $accounts->calls === 1);
    $check($plugin);
    $assert('and false is memoized too', $accounts->calls === 1);

    echo "Flex accounts, file storage\n";

    $accounts = new CountingAccounts(0);
    $plugin = $setup('flex-file-yaml', ['admin.yaml' => ''], 'flex', $accounts, $fileStorage('account://'));
    $assert('a YAML account answers true', $check($plugin) === true);
    $assert('without calling count()', $accounts->calls === 0);

    $accounts = new CountingAccounts(3);
    $plugin = $setup('flex-file-db-only', [], 'flex', $accounts, $fileStorage('account://'));
    $assert('no YAML but the collection counts accounts answers true', $check($plugin) === true);
    $assert('by calling count()', $accounts->calls === 1);

    $accounts = new CountingAccounts(0);
    $plugin = $setup('flex-file-none', [], 'flex', $accounts, $fileStorage('account://'));
    $assert('no accounts anywhere answers false', $check($plugin) === false);

    $accounts = new CountingAccounts(0);
    $plugin = $setup('flex-file-elsewhere', ['stray.yaml' => ''], 'flex', $accounts, $fileStorage('elsewhere://'));
    $assert('file storage in another folder ignores YAML in account:// and answers from count()', $check($plugin) === false && $accounts->calls === 1);

    echo "Flex accounts, folder storage\n";

    $accounts = new CountingAccounts(0);
    $plugin = $setup('flex-folder-stray', ['leftover.yaml' => ''], 'flex', $accounts, $folderStorage);
    $assert('a top-level YAML file is not trusted, count() decides (false)', $check($plugin) === false && $accounts->calls === 1);

    $accounts = new CountingAccounts(1);
    $plugin = $setup('flex-folder-nested', ['ad/admin/user.yaml' => ''], 'flex', $accounts, $folderStorage);
    $assert('a nested account answers true through count()', $check($plugin) === true && $accounts->calls === 1);

    $accounts = new CountingAccounts(0);
    $plugin = $setup('flex-no-directory', ['admin.yaml' => ''], 'flex', $accounts);
    $assert('no user-accounts directory: count() decides', $check($plugin) === false && $accounts->calls === 1);

    echo "Accounts service unavailable\n";

    $throws = static function () {
        throw new RuntimeException('not ready');
    };
    $plugin = $setup('broken-yaml', ['admin.yaml' => ''], 'flex', $throws, $folderStorage);
    $assert('falls back to the YAML scan (true)', $check($plugin) === true);
    $plugin = $setup('broken-none', [], 'flex', $throws, $folderStorage);
    $assert('falls back to the YAML scan (false)', $check($plugin) === false);
} finally {
    exec('rm -rf ' . escapeshellarg($tmpRoot));
}

echo $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
