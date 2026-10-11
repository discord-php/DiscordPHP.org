<?php

declare(strict_types=1);

// Offline subset of Discord's display-only component-embed contract.
// https://docs.discord.com/developers/link-previews/component-embeds
function previewJson(array $payload): string
{
    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function previewUrl(mixed $url, bool $media = false): void
{
    if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        throw new RuntimeException('Expected an absolute HTTP(S) URL.');
    }
    if ($media && !preg_match('/\.(png|gif|jpe?g|webp|avif|mp4|webm|mov)$/i', parse_url($url, PHP_URL_PATH) ?? '')) {
        throw new RuntimeException('Unsupported preview media format.');
    }
}

function validatePreview(string $json): array
{
    if (strlen($json) > 3000) {
        throw new RuntimeException('Preview exceeds 3,000 UTF-8 bytes.');
    }
    if (str_contains($json, '<')) {
        throw new RuntimeException('Escape HTML-sensitive characters in inline JSON.');
    }
    $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_keys($payload) !== ['component'] || !is_array($payload['component']) || ($payload['component']['type'] ?? null) !== 17) {
        throw new RuntimeException('Expected exactly one root Container.');
    }
    $count = 0;
    $galleryItems = 0;
    $walk = function (array $node, ?int $parent = null) use (&$walk, &$count, &$galleryItems): void {
        if (++$count > 40) {
            throw new RuntimeException('Preview exceeds 40 components including root.');
        }
        $type = $node['type'] ?? null;
        $allowed = match ($parent) {
            null => [17], 17 => [1, 9, 10, 12, 14], 1 => [2], 9 => [10, 2, 11], default => [],
        };
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException('Unsupported component or invalid nesting.');
        }
        if ($type === 2) {
            if (array_diff(array_keys($node), ['id', 'type', 'url', 'style', 'label', 'emoji', 'disabled']) || ($node['style'] ?? null) !== 5 || (empty($node['label']) && empty($node['emoji']))) {
                throw new RuntimeException('Only display-only link buttons are allowed.');
            }
            previewUrl($node['url'] ?? null);
        }
        if ($type === 10 && (!is_string($node['content'] ?? null) || $node['content'] === '' || strlen($node['content']) > 4000)) {
            throw new RuntimeException('Expected nonempty Text Display content.');
        }
        if ($type === 11) {
            previewUrl($node['media']['url'] ?? null, true);
        }
        if ($type === 12) {
            if (!is_array($node['items'] ?? null) || !array_is_list($node['items']) || !$node['items']) {
                throw new RuntimeException('Expected gallery items.');
            }
            $galleryItems += count($node['items']);
            if ($galleryItems > 10) {
                throw new RuntimeException('Preview exceeds 10 gallery items across all galleries.');
            }
            foreach ($node['items'] as $item) {
                previewUrl($item['media']['url'] ?? null, true);
            }
        }
        if (in_array($type, [17, 1, 9], true)) {
            $children = $node['components'] ?? null;
            $max = $type === 1 ? 5 : ($type === 9 ? 3 : 39);
            if (!is_array($children) || !array_is_list($children) || !$children || count($children) > $max) {
                throw new RuntimeException('Invalid component children.');
            }
            foreach ($children as $child) {
                if (!is_array($child) || ($type === 9 && ($child['type'] ?? null) !== 10)) {
                    throw new RuntimeException('Invalid component child.');
                }
                $walk($child, $type);
            }
        } elseif (isset($node['components']) || isset($node['accessory'])) {
            throw new RuntimeException('Unexpected component children.');
        }
        if ($type === 9) {
            if (!is_array($node['accessory'] ?? null) || !in_array($node['accessory']['type'] ?? null, [2, 11], true)) {
                throw new RuntimeException('A Section requires a button or thumbnail accessory.');
            }
            $walk($node['accessory'], 9);
        }
    };
    $walk($payload['component']);

    return ['bytes' => strlen($json), 'components' => $count, 'gallery_items' => $galleryItems];
}

function previewPages(): array
{
    return [
        'index.html' => ['Read the guide', 'https://discord-php.github.io/DiscordPHP/guide/', 'Browse libraries', 'https://discordphp.org/libraries.html'],
        'libraries.html' => ['Browse libraries', 'https://discordphp.org/libraries.html', 'Check compatibility', 'https://discordphp.org/ecosystem.html'],
        'ecosystem.html' => ['Check releases', 'https://discordphp.org/ecosystem.html', 'Library references', 'https://discordphp.org/guides.html'],
        'guides.html' => ['Read the guide', 'https://discord-php.github.io/DiscordPHP/guide/', 'API reference', 'https://discord-php.github.io/DiscordPHP/'],
        'uml/index.html' => ['Explore architecture', 'https://discordphp.org/uml/', 'REST route map', 'https://discordphp.org/uml/routes.html'],
        'uml/routes.html' => ['Explore REST routes', 'https://discordphp.org/uml/routes.html', 'Gateway events', 'https://discordphp.org/uml/events.html'],
        'uml/events.html' => ['Explore gateway events', 'https://discordphp.org/uml/events.html', 'Architecture', 'https://discordphp.org/uml/'],
    ];
}
