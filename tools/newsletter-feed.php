<?php

declare(strict_types=1);

/*
 * Build a standards-compatible RSS feed from the shared, tagged newsletter.
 * Usage: php tools/newsletter-feed.php <output path>
 */

[, $output] = $argv + [null, null];
if (! $output) {
    fwrite(STDERR, "Usage: php tools/newsletter-feed.php <output path>\n");
    exit(2);
}

$newsletter = json_decode((string) file_get_contents(__DIR__.'/../data/newsletter.json'), true, 512, JSON_THROW_ON_ERROR);
$catalog = json_decode((string) file_get_contents(__DIR__.'/../data/ecosystem.json'), true, 512, JSON_THROW_ON_ERROR);
$labels = [];
foreach ($catalog['newsletterTags'] ?? [] as $tag) {
    $labels[(string) $tag['id']] = (string) $tag['label'];
}

$xml = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
$plain = static function (string $markdown): string {
    $text = preg_replace('/\[([^\]]+)\]\(https:\/\/[^)]+\)/', '$1', $markdown) ?? $markdown;
    $text = preg_replace('/[`*_#>]/', '', $text) ?? $text;

    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
};

$items = [];
foreach ($newsletter['editions'] ?? [] as $edition) {
    $key = (string) ($edition['key'] ?? '');
    $date = (string) ($edition['date'] ?? '');
    if ('' === $key || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        continue;
    }

    $url = 'https://discordphp.org/newsletter.html#'.rawurlencode($key);
    $parts = [(string) ($edition['intro'] ?? '')];
    foreach ($edition['sections'] ?? [] as $section) {
        $parts[] = (string) ($section['title'] ?? '');
        $parts[] = (string) ($section['body'] ?? '');
    }
    $description = $plain(implode("\n", $parts));
    $published = strtotime((string) ($edition['published_at'] ?? $date.'T12:00:00Z')) ?: strtotime($date.'T12:00:00Z');
    $categories = '';
    foreach ($edition['tags'] ?? [] as $tag) {
        $tag = (string) $tag;
        if (isset($labels[$tag])) {
            $categories .= '<category>'.$xml($labels[$tag]).'</category>';
        }
    }

    $items[] = '<item><title>'.$xml((string) ($edition['headline'] ?? $key)).'</title>'
        .'<link>'.$xml($url).'</link><guid isPermaLink="true">'.$xml($url).'</guid>'
        .'<pubDate>'.gmdate(DATE_RSS, $published).'</pubDate><description>'.$xml($description).'</description>'
        .$categories.'</item>';
}

$feed = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    .'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
    .'<title>DiscordPHP and Valgorithms newsletter</title>'
    .'<link>https://discordphp.org/newsletter.html</link>'
    .'<description>Tagged updates from the DiscordPHP and Valgorithms ecosystem.</description>'
    .'<language>en</language><atom:link href="https://discordphp.org/newsletter.xml" rel="self" type="application/rss+xml"/>'
    .implode('', $items).'</channel></rss>' . "\n";

if (false === file_put_contents($output, $feed)) {
    fwrite(STDERR, "Could not write RSS feed to {$output}\n");
    exit(1);
}
