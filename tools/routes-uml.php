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
 * Maps every route of Discord's REST API to the DiscordPHP code that sends it, and every gateway
 * event to the class that handles it, as data plus UML class diagrams (Mermaid) for the site.
 *
 * Usage: php tools/routes-uml.php <DiscordPHP checkout> <openapi_preview.json> <output directory> [<source tree>]
 *
 * The DiscordPHP checkout needs its `vendor/` installed: the route constants come from
 * discord-php/http, and the request detection is DiscordPHP's own `scripts/OpenApiCheck.php`, so
 * this map and `composer openapi` never disagree about what is sent. `<source tree>` reads the
 * code from another tree of the same repository (a worktree of a newer commit, say) while still
 * using the checkout's `vendor/`.
 */

use Discord\Scripts\OpenApiCheck;
use Discord\WebSockets\Handlers;

[, $checkout, $specFile, $outDir, $source] = $argv + [null, null, null, null, null];
if (! $checkout || ! $specFile || ! $outDir) {
    fwrite(STDERR, "Usage: php tools/routes-uml.php <DiscordPHP checkout> <openapi_preview.json> <output directory> [<source tree>]\n");
    exit(2);
}

$checkout = rtrim(str_replace('\\', '/', $checkout), '/');
$source = rtrim(str_replace('\\', '/', $source ?: $checkout), '/');
$loader = require $checkout.'/vendor/autoload.php';
if ($source !== $checkout) {
    $loader->addPsr4('Discord\\', $source.'/src/Discord', true);
}
require_once $source.'/scripts/OpenApiCheck.php';
$checkout = $source;

$spec = json_decode((string) file_get_contents($specFile), true, 512, JSON_THROW_ON_ERROR);
$operations = OpenApiCheck::operations($spec);
$constants = OpenApiCheck::endpointConstants();

// The check's own readers for comments and request methods, so both tools see the same thing.
$withoutComments = Closure::bind(static fn (string $source): string => OpenApiCheck::withoutComments($source), null, OpenApiCheck::class);
$methodsAt = Closure::bind(static fn (string $source, int $offset): array => OpenApiCheck::methodsAt($source, $offset), null, OpenApiCheck::class);

/** What a repository's `$endpoints` key is reached through. */
const REPOSITORY_API = [
    'all' => 'freshen()',
    'get' => 'fetch()',
    'create' => 'save()',
    'update' => 'save()',
    'delete' => 'delete()',
];

/**
 * Who sends each Endpoint constant: [constant => [[class, member, methods], ...]].
 *
 * @var array<string, list<array{class: string, fqcn: string, member: string, methods: list<string>}>>
 */
$senders = [];

/**
 * Each Part's (and the client's) repositories: [class => [property => repository class]].
 *
 * @var array<string, array<string, string>>
 */
$owns = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($checkout.'/src/Discord', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ('php' !== $file->getExtension()) {
        continue;
    }

    $code = $withoutComments((string) file_get_contents($file->getPathname()));
    if (! preg_match('/^namespace\s+([^;]+);/m', $code, $namespace)
        || ! preg_match('/^(?:(?:final|abstract|readonly)\s+)*(?:class|trait|interface|enum)\s+(\w+)/m', $code, $declared)
    ) {
        continue;
    }
    $class = $declared[1];

    if (preg_match('/\$repositories\s*=\s*\[(.*?)\];/s', $code, $table)) {
        preg_match_all("/'(\\w+)'\\s*=>\\s*(\\w+)::class/", $table[1], $pairs, PREG_SET_ORDER);
        foreach ($pairs as [, $property, $repository]) {
            $owns[$class][$property] = $repository;
        }
    }

    preg_match_all('/\bfunction\s+(\w+)\s*\(/', $code, $functions, PREG_OFFSET_CAPTURE);
    $enclosing = static function (int $offset) use ($functions): ?string {
        $name = null;
        foreach ($functions[1] as $index => [$function]) {
            if ($functions[0][$index][1] > $offset) {
                break;
            }
            $name = $function;
        }

        return $name;
    };

    preg_match_all('/Endpoint::([A-Z][A-Z0-9_]*)\b/', $code, $uses, PREG_OFFSET_CAPTURE);
    foreach ($uses[1] as $index => [$constant]) {
        $offset = $uses[0][$index][1];
        $methods = array_values(array_filter($methodsAt($code, $offset), static fn (string $m): bool => '?' !== $m));
        if ([] === $methods) {
            continue; // Not a request: an image URL, a route shown in a message, …
        }

        $lineStart = strrpos(substr($code, 0, $offset), "\n");
        $line = substr($code, false === $lineStart ? 0 : $lineStart + 1, $offset - (false === $lineStart ? 0 : $lineStart + 1));

        $members = [];
        if (preg_match("/'(\\w+)'\\s*=>\\s*$/", $line, $key)) {
            // A repository's endpoint table: reached through the repository's API, or through the
            // methods that read $this->endpoints['key'].
            if (isset(REPOSITORY_API[$key[1]])) {
                $members[] = REPOSITORY_API[$key[1]];
            } else {
                preg_match_all("/endpoints\\['".preg_quote($key[1], '/')."'\\]/", $code, $reads, PREG_OFFSET_CAPTURE);
                foreach ($reads[0] as [, $at]) {
                    if (null !== ($name = $enclosing($at))) {
                        $members[] = $name.'()';
                    }
                }
            }
        } elseif (null !== ($name = $enclosing($offset))) {
            $members[] = $name.'()';
        }

        foreach (array_unique($members) as $member) {
            $senders[$constant][] = ['class' => $class, 'fqcn' => $namespace[1].'\\'.$class, 'member' => $member, 'methods' => $methods];
        }
    }
}

$byRoute = [];
foreach ($constants as $name => $template) {
    $byRoute[OpenApiCheck::route($template)][] = $name;
}

/** How the site titles each top-level resource. Anything new shows up under its own name. */
const RESOURCES = [
    'applications' => 'Applications',
    'channels' => 'Channels',
    'gateway' => 'Gateway',
    'guilds' => 'Guilds',
    'interactions' => 'Interactions',
    'invites' => 'Invites',
    'lobbies' => 'Lobbies',
    'oauth2' => 'OAuth2',
    'partner-sdk' => 'Partner SDK',
    'skus' => 'SKUs',
    'soundboard-default-sounds' => 'Soundboard',
    'stage-instances' => 'Stage instances',
    'sticker-packs' => 'Stickers',
    'stickers' => 'Stickers',
    'users' => 'Users',
    'voice' => 'Voice',
    'webhooks' => 'Webhooks',
];

/**
 * The big resources, split by what follows their ID. A sub-path not listed falls under `*`, so a
 * route Discord adds tomorrow still lands in a sensible diagram.
 */
const FAMILIES = [
    'applications' => ['commands' => 'commands', '*' => 'the application'],
    'channels' => [
        'messages' => 'messages', 'pins' => 'messages', 'polls' => 'messages', 'typing' => 'messages',
        'threads' => 'threads', 'thread-members' => 'threads', 'users' => 'threads',
        '*' => 'the channel',
    ],
    'guilds' => [
        'members' => 'members, roles and bans', 'roles' => 'members, roles and bans', 'bans' => 'members, roles and bans',
        'bulk-ban' => 'members, roles and bans', 'prune' => 'members, roles and bans',
        'channels' => 'channels, threads and voice', 'threads' => 'channels, threads and voice',
        'voice-states' => 'channels, threads and voice', 'webhooks' => 'channels, threads and voice',
        'emojis' => 'emojis, stickers and sounds', 'stickers' => 'emojis, stickers and sounds',
        'soundboard-sounds' => 'emojis, stickers and sounds',
        'scheduled-events' => 'events and moderation', 'auto-moderation' => 'events and moderation',
        '*' => 'the guild',
    ],
];

/** The diagram a route belongs to: its resource, and for the big ones which part of it. */
$groupOf = static function (string $path): string {
    $named = array_values(array_filter(
        explode('/', trim($path, '/')),
        static fn (string $s): bool => '' !== $s && ! str_starts_with($s, '{'),
    ));
    $root = $named[0] ?? 'root';
    $title = RESOURCES[$root] ?? ucfirst($root);

    if (isset(FAMILIES[$root])) {
        $sub = in_array('commands', $named, true) ? 'commands' : ($named[1] ?? '*');

        return $title.': '.(FAMILIES[$root][$sub] ?? FAMILIES[$root]['*']);
    }

    return $title;
};

$groups = [];
$totals = ['operations' => 0, 'sent' => 0, 'not sent' => 0, 'deprecated' => 0];
foreach ($operations as $key => $operation) {
    $sentBy = [];
    foreach ($byRoute[$operation['route']] ?? [] as $constant) {
        foreach ($senders[$constant] ?? [] as $sender) {
            if (in_array($operation['method'], $sender['methods'], true)) {
                $sentBy[$sender['class'].'::'.$sender['member']] = ['class' => $sender['class'], 'fqcn' => $sender['fqcn'], 'member' => $sender['member']];
            }
        }
    }
    ksort($sentBy);

    $group = $groupOf($operation['path']);
    $groups[$group][] = [
        'method' => $operation['method'],
        'path' => $operation['path'],
        'id' => $operation['id'],
        'auth' => $operation['auth'],
        'deprecated' => $operation['deprecated'],
        'constants' => $byRoute[$operation['route']] ?? [],
        'sentBy' => array_values($sentBy),
    ];

    ++$totals['operations'];
    ++$totals[[] === $sentBy ? 'not sent' : 'sent'];
    $totals['deprecated'] += (int) $operation['deprecated'];
}
ksort($groups);

/** A route as Mermaid can print it inside a class body: `{id}` becomes `:id`. */
$shown = static fn (string $method, string $path): string => $method.' '.preg_replace('/\{([^}]+)\}/', ':$1', ltrim($path, '/'));

$diagrams = [];
foreach ($groups as $group => $routes) {
    $classes = [];
    $missing = [];
    foreach ($routes as $route) {
        $label = $shown($route['method'], $route['path']).($route['deprecated'] ? ' (deprecated)' : '');
        if ([] === $route['sentBy']) {
            $missing[] = $label;
            continue;
        }
        foreach ($route['sentBy'] as $sender) {
            $classes[$sender['class']][] = '+'.$sender['member'].' '.$label;
        }
    }
    ksort($classes);

    $lines = ['classDiagram', '    direction LR'];
    foreach ($classes as $class => $members) {
        $lines[] = "    class {$class} {";
        foreach (array_unique($members) as $member) {
            $lines[] = '        '.$member;
        }
        $lines[] = '    }';
    }
    if ([] !== $missing) {
        $lines[] = '    class NotSent["Not sent by DiscordPHP"] {';
        foreach ($missing as $label) {
            $lines[] = '        '.$label;
        }
        $lines[] = '    }';
    }
    // Ownership between the classes on this diagram: a Part and the repositories it holds.
    foreach ($owns as $owner => $repositories) {
        foreach ($repositories as $property => $repository) {
            if (isset($classes[$owner], $classes[$repository])) {
                $lines[] = "    {$owner} *-- {$repository} : {$property}";
            }
        }
    }

    $diagrams[$group] = implode("\n", $lines);
}

/**
 * How the site groups gateway events, by name prefix; the longest matching prefix wins. An event
 * Discord adds tomorrow still gets a diagram, under `Other` if nothing here matches it.
 */
const EVENT_CATEGORIES = [
    'APPLICATION_' => 'Applications',
    'AUTO_MODERATION_' => 'Auto moderation',
    'CHANNEL_' => 'Channels',
    'ENTITLEMENT_' => 'Monetization',
    'SUBSCRIPTION_' => 'Monetization',
    'GAME_DIRECT_MESSAGE_' => 'Social SDK',
    'LOBBY_MESSAGE_' => 'Social SDK',
    'GUILD_' => 'Guilds',
    'GUILD_AUDIT_LOG_' => 'Guild moderation',
    'GUILD_BAN_' => 'Guild moderation',
    'GUILD_EMOJIS_' => 'Emojis, stickers and sounds',
    'GUILD_STICKERS_' => 'Emojis, stickers and sounds',
    'GUILD_SOUNDBOARD_' => 'Emojis, stickers and sounds',
    'SOUNDBOARD_' => 'Emojis, stickers and sounds',
    'GUILD_MEMBER_' => 'Members and roles',
    'GUILD_ROLE_' => 'Members and roles',
    'GUILD_JOIN_REQUEST_' => 'Members and roles',
    'GUILD_SCHEDULED_EVENT_' => 'Scheduled events',
    'GUILD_INTEGRATIONS_' => 'Integrations',
    'INTEGRATION_' => 'Integrations',
    'INTERACTION_' => 'Interactions',
    'INVITE_' => 'Invites',
    'MESSAGE_' => 'Messages',
    'MESSAGE_REACTION_' => 'Reactions and polls',
    'MESSAGE_POLL_' => 'Reactions and polls',
    'PRESENCE_' => 'Users and presence',
    'TYPING_' => 'Users and presence',
    'USER_' => 'Users and presence',
    'RATE_LIMITED' => 'Gateway',
    'STAGE_INSTANCE_' => 'Stage instances',
    'THREAD_' => 'Threads',
    'VOICE_' => 'Voice',
    'WEBHOOKS_' => 'Webhooks',
];

$categoryOf = static function (string $event): string {
    $best = '';
    foreach (array_keys(EVENT_CATEGORIES) as $prefix) {
        if (str_starts_with($event, $prefix) && strlen($prefix) > strlen($best)) {
            $best = $prefix;
        }
    }

    return '' === $best ? 'Other' : EVENT_CATEGORIES[$best];
};

// Gateway: every dispatch event and the class that handles it.
$handlers = (new Handlers())->getHandlers();
ksort($handlers);
$events = [];
foreach ($handlers as $event => $handler) {
    $class = is_array($handler) ? (string) ($handler['class'] ?? reset($handler)) : (string) $handler;
    $events[] = [
        'event' => (string) $event,
        'category' => $categoryOf((string) $event),
        'handler' => substr((string) strrchr('\\'.$class, '\\'), 1),
        'fqcn' => ltrim($class, '\\'),
    ];
}

// One small flowchart per category: the event arrives, its handler updates the cache and emits.
$byCategory = [];
foreach ($events as $event) {
    $byCategory[$event['category']][] = $event;
}
ksort($byCategory);
$eventGroups = [];
foreach ($byCategory as $category => $members) {
    $lines = ['flowchart LR', '    gateway(("Gateway"))'];
    foreach ($members as $i => $event) {
        $lines[] = sprintf('    gateway --> e%d["%s"] --> h%d["%s"]', $i, $event['event'], $i, $event['handler']);
    }
    $eventGroups[] = ['name' => $category, 'events' => array_column($members, 'event'), 'mermaid' => implode("\n", $lines)];
}

$git = static fn (string $arguments): string => trim((string) shell_exec('git -C '.escapeshellarg($checkout).' '.$arguments.' 2>'.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
$sha = $git('rev-parse HEAD');
// The release this commit is, when it is one: the site says which version its maps show.
$release = $git('describe --tags --exact-match HEAD');
$data = [
    'generated' => gmdate('Y-m-d\TH:i:s\Z'),
    'discordphp' => $sha,
    'release' => '' === $release ? null : $release,
    'spec' => ['title' => $spec['info']['title'] ?? 'Discord HTTP API', 'version' => $spec['info']['version'] ?? ''],
    'totals' => $totals + ['groups' => count($groups), 'events' => count($events)],
    'groups' => array_map(
        static fn (string $group): array => ['name' => $group, 'routes' => $groups[$group], 'mermaid' => $diagrams[$group]],
        array_keys($groups),
    ),
    'events' => $events,
    'eventGroups' => $eventGroups,
];

if (! is_dir($outDir)) {
    mkdir($outDir, 0o777, true);
}
file_put_contents(rtrim($outDir, '/\\').'/routes.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

printf(
    "DiscordPHP %s: %d operations in %d groups: %d sent, %d not sent (%d deprecated); %d gateway events.\n",
    '' === $release ? substr($sha, 0, 9) : $release,
    $totals['operations'],
    count($groups),
    $totals['sent'],
    $totals['not sent'],
    $totals['deprecated'],
    count($events),
);
