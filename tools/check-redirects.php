<?php

declare(strict_types=1);

function redirectTarget(string $base, string $location): string
{
    $parts = parse_url($base);
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location)) {
        return $location;
    }
    if (str_starts_with($location, '//')) {
        return $parts['scheme'].':'.$location;
    }
    $origin = $parts['scheme'].'://'.$parts['host'];
    $path = $parts['path'] ?? '/';
    if (str_starts_with($location, '?')) {
        return $origin.$path.$location;
    }
    $relative = parse_url($location);
    $nextPath = $relative['path'] ?? $path;
    if (!str_starts_with($nextPath, '/')) {
        $nextPath = substr($path, 0, strrpos($path, '/') + 1).$nextPath;
    }
    $segments = [];
    foreach (explode('/', $nextPath) as $segment) {
        if ($segment === '..') {
            array_pop($segments);
        } elseif ($segment !== '.' && $segment !== '') {
            $segments[] = $segment;
        }
    }

    return $origin.'/'.implode('/', $segments).($segments && str_ends_with($nextPath, '/') ? '/' : '').(isset($relative['query']) ? '?'.$relative['query'] : '');
}

// Inject both HTTP and clock so tests never contact a service or wait.
function checkRedirectChain(string $start, callable $fetch, ?callable $clock = null): array
{
    $clock ??= fn () => hrtime(true) / 1e9;
    $deadline = $clock() + 10;
    $url = $start;
    $seen = [];
    $trace = [];
    try {
        for ($hop = 0; $hop <= 5; ++$hop) {
            $url = explode('#', $url, 2)[0];
            $parts = parse_url($url);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true) || !in_array($parts['host'] ?? null, ['discordphp.org', 'www.discordphp.org', 'discord-php.github.io'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
                throw new RuntimeException('Redirect leaves the known public site hosts or uses an invalid URL.');
            }
            if (isset($seen[$url])) {
                throw new RuntimeException('Repeated URL: redirect loop.');
            }
            $seen[$url] = true;
            $remaining = $deadline - $clock();
            if ($remaining <= 0) {
                throw new RuntimeException('Ten-second chain budget exceeded.');
            }
            $response = $fetch($url, $remaining);
            $trace[] = ['url' => $url] + $response;
            if ($clock() > $deadline) {
                throw new RuntimeException('Ten-second chain budget exceeded.');
            }
            $status = $response['status'];
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if (empty($response['location'])) {
                    throw new RuntimeException('Redirect has no Location.');
                }
                $next = redirectTarget($url, $response['location']);
                if ($parts['scheme'] === 'https' && parse_url($next, PHP_URL_SCHEME) === 'http') {
                    throw new RuntimeException('HTTPS redirect downgrades to HTTP.');
                }
                $url = $next;
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('Terminal HTTP status is not 2xx.');
            }
            if ($parts['scheme'] !== 'https' || $parts['host'] !== 'discordphp.org' || !preg_match('~^(text/html|application/xhtml\+xml)(;|$)~i', $response['content_type'] ?? '') || str_starts_with(strtolower($response['disposition'] ?? ''), 'attachment')) {
                throw new RuntimeException('Expected canonical HTTPS HTML without attachment disposition.');
            }

            return ['passed' => true, 'trace' => $trace];
        }
        throw new RuntimeException('More than five redirects.');
    } catch (Throwable $error) {
        return ['passed' => false, 'trace' => $trace, 'error' => $error->getMessage()];
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $fetch = function (string $url, float $remaining): array {
        $handle = curl_init($url);
        $headers = [];
        curl_setopt_array($handle, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, max(1, (int) ($remaining * 1000))),
            CURLOPT_TIMEOUT_MS => max(1, (int) ($remaining * 1000)),
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        if (curl_exec($handle) === false) {
            throw new RuntimeException(curl_error($handle));
        }

        return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'location' => $headers['location'] ?? null, 'content_type' => $headers['content-type'] ?? null, 'disposition' => $headers['content-disposition'] ?? null];
    };
    $failed = false;
    foreach (array_slice($argv, 1) ?: ['https://discordphp.org/', 'https://www.discordphp.org/', 'https://discord-php.github.io/DiscordPHP.org/'] as $start) {
        $result = checkRedirectChain($start, $fetch);
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $failed = $failed || !$result['passed'];
    }
    exit($failed ? 1 : 0);
}
