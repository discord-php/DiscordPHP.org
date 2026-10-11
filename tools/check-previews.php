<?php

declare(strict_types=1);

require __DIR__.'/preview-payload.php';

function checkPreviewPage(string $html, string $path, string $root): array
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    $scripts = $xpath->query('//*[@id="discord:component-embed"]');
    if ($scripts->length !== 1 || $scripts->item(0)->nodeName !== 'script' || $scripts->item(0)->parentNode->nodeName !== 'head' || $scripts->item(0)->getAttribute('type') !== 'application/json') {
        throw new RuntimeException('Expected one inline application/json preview script in head.');
    }
    $stats = validatePreview($scripts->item(0)->textContent);
    $meta = function (string $key) use ($xpath): string {
        $nodes = $xpath->query('/html/head/meta[@property="'.$key.'" or @name="'.$key.'"]');
        if ($nodes->length !== 1 || $nodes->item(0)->getAttribute('content') === '') {
            throw new RuntimeException('Missing, empty or duplicate metadata: '.$key);
        }

        return $nodes->item(0)->getAttribute('content');
    };
    $url = 'https://discordphp.org/'.($path === 'index.html' ? '' : ($path === 'uml/index.html' ? 'uml/' : $path));
    $title = $xpath->query('/html/head/title')->item(0)->textContent;
    $description = $meta('description');
    foreach (['og:title', 'twitter:title'] as $key) {
        if ($meta($key) !== $title) {
            throw new RuntimeException('Preview title differs from page title.');
        }
    }
    foreach (['og:description', 'twitter:description'] as $key) {
        if ($meta($key) !== $description) {
            throw new RuntimeException('Preview description differs from page description.');
        }
    }
    $canonical = $xpath->query('/html/head/link[@rel="canonical"]');
    if ($meta('og:url') !== $url || $canonical->length !== 1 || $canonical->item(0)->getAttribute('href') !== $url || $meta('og:type') !== 'website' || $meta('og:site_name') !== 'DiscordPHP' || $meta('twitter:card') !== 'summary' || $meta('theme-color') !== '#5b4fd6') {
        throw new RuntimeException('Incorrect canonical URL or fallback layout/branding.');
    }
    $image = $meta('og:image');
    previewUrl($image, true);
    if ($image !== 'https://discordphp.org/assets/images/discordphp.png' || $meta('twitter:image') !== $image || $meta('og:image:type') !== 'image/png') {
        throw new RuntimeException('Incorrect fallback image.');
    }
    $size = getimagesize($root.'/assets/images/discordphp.png');
    if (!$size || $size[2] !== IMAGETYPE_PNG || $meta('og:image:width') !== (string) $size[0] || $meta('og:image:height') !== (string) $size[1]) {
        throw new RuntimeException('Missing raster asset or incorrect image dimensions.');
    }
    $meta('og:image:alt');
    $meta('twitter:image:alt');
    $payload = json_decode($scripts->item(0)->textContent, true, 64, JSON_THROW_ON_ERROR);
    $checkLinks = function (array $node) use (&$checkLinks, $root): void {
        foreach ($node as $key => $value) {
            if ($key === 'url' && is_string($value) && parse_url($value, PHP_URL_HOST) === 'discordphp.org') {
                $local = $root.'/'.ltrim(parse_url($value, PHP_URL_PATH) ?? '', '/');
                if (is_dir($local)) {
                    $local = rtrim($local, '/').'/index.html';
                }
                if (!is_file($local)) {
                    throw new RuntimeException('Missing preview target: '.$value);
                }
            }
            if (is_array($value)) {
                $checkLinks($value);
            }
        }
    };
    $checkLinks($payload);

    return $stats;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $root = $argv[1] ?? dirname(__DIR__);
    try {
        foreach (previewPages() as $path => $_) {
            $html = file_get_contents($root.'/'.$path);
            $stats = checkPreviewPage($html, $path, $root);
            echo $path.': '.json_encode($stats)."\n";
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'Preview check failed: '.$error->getMessage()."\n");
        exit(1);
    }
}
