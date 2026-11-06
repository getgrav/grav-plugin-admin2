<?php

/**
 * Tests for the strict Content-Security-Policy support (admin2#212):
 * addScriptNonces(), contentSecurityPolicy() and parseExtraCsp().
 *
 * A plain CLI script like any-users-exist.php: it loads Grav core's
 * autoloader and calls the private methods directly.
 *
 *   php tests/csp.php
 *   GRAV_ROOT=/path/to/grav php tests/csp.php
 *
 * Exits non-zero on any failure.
 */

declare(strict_types=1);

use Grav\Common\Config\Config;
use Grav\Plugin\Admin2Plugin;

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
define('GRAV_DOTENV_DISABLE', true);
require $autoloader;
require_once __DIR__ . '/../admin2.php';

/** @param array<string, mixed> $config */
$plugin = static function (array $config): Admin2Plugin {
    $plugin = (new ReflectionClass(Admin2Plugin::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($plugin, 'config'))->setValue($plugin, new Config($config));

    return $plugin;
};
$call = static fn (Admin2Plugin $p, string $method, ...$args) => (new ReflectionMethod($p, $method))->invoke($p, ...$args);

$failures = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     {$detail}") . "\n";
    if (!$ok) {
        $failures++;
    }
};

$nonce = 'AbC+/123==';
$p = $plugin([]);

// Nonces go on every script and modulepreload link, nothing else.
$html = '<head><script>a()</script><SCRIPT type="module" src="x.js"></SCRIPT><scripts></scripts>'
    . '<link href="a.js" rel="modulepreload"><link rel="stylesheet" href="a.css"></head>';
$out = $call($p, 'addScriptNonces', $html, $nonce);
$check('inline script gets the nonce', str_contains($out, '<script nonce="AbC+/123==">a()</script>'), $out);
$check('module script gets the nonce', str_contains($out, '<script nonce="AbC+/123==" type="module" src="x.js">'), $out);
$check('a tag that only starts with "script" is left alone', str_contains($out, '<scripts></scripts>'), $out);
$check('modulepreload link gets the nonce', str_contains($out, '<link nonce="AbC+/123==" href="a.js" rel="modulepreload">'), $out);
$check('stylesheet link is left alone', str_contains($out, '<link rel="stylesheet" href="a.css">'), $out);

// The base policy.
$policy = $call($p, 'contentSecurityPolicy', $nonce);
foreach ([
    "script-src 'self' 'nonce-AbC+/123==' 'strict-dynamic'",
    "object-src 'none'",
    "base-uri 'self'",
    "frame-ancestors 'self'",
    "worker-src 'self';",
    "connect-src 'self';",
] as $part) {
    $check("base policy has {$part}", str_contains($policy, $part), $policy);
}
$check('no wasm or blob: worker without the Cap captcha', !str_contains($policy, 'wasm-unsafe-eval') && !str_contains($policy, 'worker-src \'self\' blob:'), $policy);

// The configured captcha adds what it needs.
$cap = $call($plugin(['plugins' => ['api' => ['captcha' => ['enabled' => true, 'provider' => 'cap']]]]), 'contentSecurityPolicy', $nonce);
$check('cap adds wasm-unsafe-eval and a blob: worker', str_contains($cap, "'strict-dynamic' 'wasm-unsafe-eval'") && str_contains($cap, "worker-src 'self' blob:"), $cap);
$ts = $call($plugin(['plugins' => ['api' => ['captcha' => ['enabled' => true, 'provider' => 'turnstile']]]]), 'contentSecurityPolicy', $nonce);
$check('turnstile adds its frame', str_contains($ts, "frame-src 'self' https://challenges.cloudflare.com"), $ts);
$rc = $call($plugin(['plugins' => ['api' => ['captcha' => ['enabled' => true, 'provider' => 'recaptcha']]]]), 'contentSecurityPolicy', $nonce);
$check('recaptcha adds its frames', str_contains($rc, 'https://www.google.com/recaptcha/') && str_contains($rc, 'https://recaptcha.google.com/recaptcha/'), $rc);
$off = $call($plugin(['plugins' => ['api' => ['captcha' => ['enabled' => false, 'provider' => 'cap']]]]), 'contentSecurityPolicy', $nonce);
$check('a captcha that is off adds nothing', !str_contains($off, 'wasm-unsafe-eval'), $off);

// csp.extra.
$extra = "connect-src https://hub.example.com wss://hub.example.com\nscript-src 'unsafe-eval'; img-src https://cdn.example.com evil,x\r\n"
    . "upgrade-insecure-requests\nBad_Name x\nconnect-src https://hub.example.com";
$merged = $call($plugin(['plugins' => ['admin2' => ['csp' => ['extra' => $extra]]]]), 'contentSecurityPolicy', $nonce);
$check('extra sources join an existing directive once', str_contains($merged, "connect-src 'self' https://hub.example.com wss://hub.example.com;"), $merged);
$check('extra keywords join script-src', str_contains($merged, "'strict-dynamic' 'unsafe-eval'"), $merged);
$check('a source with a comma is dropped', str_contains($merged, 'img-src \'self\' data: blob: https: https://cdn.example.com;') && !str_contains($merged, 'evil'), $merged);
$check('a new directive without sources is added', str_contains($merged, '; upgrade-insecure-requests'), $merged);
$check('a malformed directive name is dropped', !str_contains($merged, 'Bad_Name') && !str_contains($merged, 'bad_name'), $merged);
$check('no line breaks reach the header', !preg_match('/[\r\n]/', $merged), $merged);

exit($failures === 0 ? 0 : 1);
