<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP.org website.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE file.
 */

/**
 * Looks up releases and Composer compatibility for the shared ecosystem catalog. Legacy
 * `data-version="vendor/name"` badges on any supplied page are also refreshed from Packagist
 * or GitHub when paired with `data-source="github:owner/repo"`.
 *
 * Usage: php tools/versions.php <output directory> <page.html>...
 *
 * GITHUB_TOKEN, when set, is sent to GitHub to lift its unauthenticated rate limit.
 */
[, $outDir] = $argv + [null, null];
$pages = array_slice($argv, 2);
if (! $outDir || [] === $pages) {
    fwrite(STDERR, "Usage: php tools/versions.php <output directory> <page.html>...\n");
    exit(2);
}

/** @return array<string, mixed>|null */
function fetchJson(string $url, array $headers = []): ?array
{
    $context = stream_context_create(['http' => [
        'timeout' => 30,
        'ignore_errors' => true,
        'header' => implode("\r\n", array_merge(['User-Agent: DiscordPHP.org (https://github.com/discord-php/DiscordPHP.org)'], $headers)),
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = (int) (preg_match('#^HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
    if (false === $body || 200 !== $status) {
        fwrite(STDERR, "{$url}: HTTP {$status}\n");

        return null;
    }

    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

/** The newest stable version Packagist lists for a package. */
function fromPackagist(string $package): ?array
{
    $data = fetchJson("https://repo.packagist.org/p2/{$package}.json");
    foreach ($data['packages'][$package] ?? [] as $release) {
        // Stable versions normalise to four numbers only: 10.60.0.0, not 1.0.0.0-beta1.
        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', (string) ($release['version_normalized'] ?? ''))) {
            return [
                'version' => (string) $release['version'],
                'time' => (string) ($release['time'] ?? ''),
                'reference' => (string) ($release['source']['reference'] ?? ''),
                'url' => 'https://packagist.org/packages/'.$package,
            ];
        }
    }

    return null;
}

/** A repository's latest GitHub release, for packages that are not on Packagist. */
function fromGitHub(string $repository): ?array
{
    $headers = ['Accept: application/vnd.github+json'];
    if ('' !== ($token = (string) getenv('GITHUB_TOKEN'))) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    $release = fetchJson("https://api.github.com/repos/{$repository}/releases/latest", $headers);

    return null === $release ? null : [
        'version' => (string) $release['tag_name'],
        'time' => (string) ($release['published_at'] ?? ''),
        'reference' => (string) ($release['tag_name'] ?? ''),
        'url' => (string) ($release['html_url'] ?? "https://github.com/{$repository}/releases"),
    ];
}

/** Read a project's runtime constraints at the exact release revision. */
function compatibility(string $repository, string $reference): array
{
    if ('' === $reference) {
        return ['available' => false];
    }

    $composer = fetchJson("https://raw.githubusercontent.com/{$repository}/{$reference}/composer.json");
    if (null === $composer) {
        return ['available' => false];
    }

    $requires = $composer['require'] ?? [];
    $extensions = [];
    foreach ($requires as $name => $constraint) {
        if (str_starts_with((string) $name, 'ext-')) {
            $extensions[$name] = (string) $constraint;
        }
    }
    ksort($extensions);

    return [
        'available' => true,
        'php' => isset($requires['php']) ? (string) $requires['php'] : null,
        'discordphp' => isset($requires['team-reflex/discord-php']) ? (string) $requires['team-reflex/discord-php'] : null,
        'extensions' => $extensions,
    ];
}

$wanted = [];
foreach ($pages as $page) {
    preg_match_all('/<[^>]*\bdata-version="([^"]+)"[^>]*>/', (string) file_get_contents($page), $tags, PREG_SET_ORDER);
    foreach ($tags as [$tag, $package]) {
        $wanted[$package] = preg_match('/\bdata-source="github:([^"]+)"/', $tag, $source) ? $source[1] : null;
    }
}
ksort($wanted);

$versions = [];
foreach ($wanted as $package => $repository) {
    $found = null === $repository ? fromPackagist($package) : fromGitHub($repository);
    if (null !== $found) {
        $versions[$package] = $found;
    }
    printf("%-40s %s\n", $package, $found['version'] ?? 'not found');
}

if (! is_dir($outDir)) {
    mkdir($outDir, 0o777, true);
}
file_put_contents(
    rtrim($outDir, '/\\').'/versions.json',
    json_encode(['generated' => gmdate('Y-m-d\TH:i:s\Z'), 'packages' => $versions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);

// The ecosystem catalog is the source of project metadata for both sites.
// Enrich each tagged project from Packagist or its GitHub release, then read
// Composer constraints from that exact tag so the compatibility view does not
// accidentally describe the default branch instead of the published release.
$catalogFile = rtrim($outDir, '/\\').'/ecosystem.json';
if (is_file($catalogFile)) {
    $catalog = json_decode((string) file_get_contents($catalogFile), true, 512, JSON_THROW_ON_ERROR);
    $releases = [];
    foreach ($catalog['projects'] ?? [] as $project) {
        $id = (string) ($project['id'] ?? '');
        $repository = (string) ($project['repository'] ?? '');
        if ('' === $id || '' === $repository) {
            continue;
        }

        $found = ! empty($project['package']) ? fromPackagist((string) $project['package']) : null;
        if (null === $found) {
            $found = fromGitHub($repository);
        }
        if (null === $found) {
            printf("%-32s %s\n", $id, 'no stable release');
            $releases[$id] = ['release' => null, 'compatibility' => []];
            continue;
        }

        printf("%-32s %s\n", $id, $found['version']);
        $releases[$id] = [
            'release' => [
                'version' => $found['version'],
                'time' => $found['time'],
                'url' => $found['url'],
            ],
            'compatibility' => compatibility($repository, $found['reference']),
        ];
    }

    file_put_contents(
        rtrim($outDir, '/\\').'/releases.json',
        json_encode(['generated' => gmdate('Y-m-d\TH:i:s\Z'), 'projects' => $releases], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
    );
}

// A package that could not be looked up keeps its badge hidden; that is not worth failing a deploy.
exit(0);
