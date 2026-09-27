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
 * Renders every diagram in the site's data, and every `<pre class="mermaid">` in its pages, through
 * mermaid.ink, and fails if any of them does not come back as an SVG: Mermaid rejects a diagram
 * it cannot parse, and the page would show an error box where the diagram should be.
 *
 * Usage: php tools/check-diagrams.php [data/routes.json] [pages...]
 */
$data = $argv[1] ?? 'data/routes.json';
$pages = array_slice($argv, 2);

$diagrams = [];
$routes = json_decode((string) file_get_contents($data), true, 512, JSON_THROW_ON_ERROR);
foreach ($routes['groups'] as $group) {
    $diagrams[$group['name']] = $group['mermaid'];
}
foreach ($routes['eventGroups'] as $group) {
    $diagrams['Events: '.$group['name']] = $group['mermaid'];
}

foreach ($pages as $page) {
    preg_match_all('/<pre class="mermaid"[^>]*>(.*?)<\/pre>/s', (string) file_get_contents($page), $blocks);
    foreach ($blocks[1] as $index => $block) {
        $diagrams[basename($page).' #'.($index + 1)] = html_entity_decode($block, ENT_QUOTES | ENT_HTML5);
    }
}

$failed = 0;
foreach ($diagrams as $name => $code) {
    $json = json_encode(['code' => $code, 'mermaid' => ['theme' => 'default']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $pako = rtrim(strtr(base64_encode((string) gzcompress((string) $json, 9)), '+/', '-_'), '=');

    // mermaid.ink has its bad moments: a dropped connection or a 5xx is tried again, while a diagram
    // it rejects (a 4xx) fails at once.
    for ($attempt = 1; ; ++$attempt) {
        $http_response_header = [];
        $context = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true]]);
        $body = (string) @file_get_contents('https://mermaid.ink/svg/pako:'.$pako, false, $context);
        $status = (int) (preg_match('#^HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
        if ((0 !== $status && $status < 500) || $attempt >= 4) {
            break;
        }
        sleep(2 ** $attempt);
    }
    $ok = 200 === $status && str_contains(substr($body, 0, 400), '<svg');

    printf("%-45s HTTP %d %s\n", $name, $status, $ok ? 'svg' : 'FAILED '.substr((string) preg_replace('/\s+/', ' ', strip_tags($body)), 0, 120));
    $failed += (int) ! $ok;
}

printf("%d diagrams, %d failed\n", count($diagrams), $failed);
exit(0 === $failed ? 0 : 1);
