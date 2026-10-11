<?php

declare(strict_types=1);

require __DIR__.'/check-redirects.php';

$checks = 0;
$test = function (bool $condition, string $name) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('Failed: '.$name);
    }
};
$html = ['status' => 200, 'content_type' => 'text/html; charset=utf-8'];
$constantClock = fn () => 0.0;
$test(checkRedirectChain('https://discordphp.org/', fn () => $html, $constantClock)['passed'], 'canonical 200 HTML');
$test(checkRedirectChain('http://discordphp.org/', fn ($url) => str_starts_with($url, 'http:') ? ['status' => 301, 'location' => 'https://discordphp.org/'] : $html, $constantClock)['passed'], 'HTTP upgrades to canonical HTTPS');
$test(checkRedirectChain('https://www.discordphp.org/', fn ($url) => str_contains($url, 'www.') ? ['status' => 301, 'location' => 'https://discordphp.org/'] : $html, $constantClock)['passed'], 'www canonical redirect');
$test(!checkRedirectChain('https://discordphp.org/', fn () => ['status' => 301, 'location' => '/'], $constantClock)['passed'], 'loop');
$requests = 0;
$longChain = checkRedirectChain('https://discordphp.org/', function () use (&$requests): array {
    return ['status' => 302, 'location' => '/'.++$requests];
}, $constantClock);
$test(!$longChain['passed'] && $requests === 6, 'at most five redirects followed');
$test(!checkRedirectChain('https://discordphp.org/', fn () => ['status' => 301, 'location' => 'http://discordphp.org/'], $constantClock)['passed'], 'HTTPS downgrade');
$test(!checkRedirectChain('https://discordphp.org/', fn () => ['status' => 522], $constantClock)['passed'], 'edge failure');
$test(!checkRedirectChain('https://discordphp.org/', fn () => ['status' => 301], $constantClock)['passed'], 'missing Location');
$test(!checkRedirectChain('https://discordphp.org/', fn () => ['status' => 200, 'content_type' => 'application/json'], $constantClock)['passed'], 'non-HTML');
$test(!checkRedirectChain('https://discordphp.org/', fn () => $html + ['disposition' => 'attachment; filename=x'], $constantClock)['passed'], 'download');
$test(!checkRedirectChain('https://discord-php.github.io/DiscordPHP.org/', fn () => $html, $constantClock)['passed'], 'wrong canonical host');
$test(!checkRedirectChain('https://discordphp.org/', function (): array {
    throw new RuntimeException('certificate validation failed');
}, $constantClock)['passed'], 'TLS/network error');
$ticks = 0;
$test(!checkRedirectChain('https://discordphp.org/', fn () => $html, function () use (&$ticks): float {
    return $ticks++ === 0 ? 0.0 : 11.0;
})['passed'], 'total time budget');
$test(redirectTarget('https://discordphp.org/uml/routes.html', '../guides.html?q=1') === 'https://discordphp.org/guides.html?q=1', 'relative path');
$test(redirectTarget('https://discordphp.org/guides.html', '?q=1') === 'https://discordphp.org/guides.html?q=1', 'query redirect');
$test(redirectTarget('https://discordphp.org/guides.html', '//www.discordphp.org/') === 'https://www.discordphp.org/', 'scheme-relative URL');
$test(redirectTarget('https://discordphp.org/guides.html', '/') === 'https://discordphp.org/', 'root relative URL');
$test(!checkRedirectChain('https://discordphp.org/', fn () => ['status' => 302, 'location' => 'https://unrelated.example/'], $constantClock)['passed'], 'host boundary');
echo $checks." offline redirect assertions passed.\n";
