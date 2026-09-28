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
 * Draws the class diagram of DiscordPHP's main classes, and the table of the packages they come from,
 * from a DiscordPHP release, and checks that the hand-written diagrams and prose still match it.
 *
 * Usage: php tools/architecture-uml.php <DiscordPHP checkout> <uml/architecture.json> <pages...> [--source=<tree>]
 *
 * `uml/architecture.json` lists the classes and members to show and how they relate; their parameters
 * and types come from the code, so the diagram cannot fall behind a release. The first page holding the
 * `<!-- architecture-uml: … -->` markers is rewritten between them. Every page is then checked:
 *
 * - each call in a sequence diagram's message must be a method of the class of a participant it
 *   passes between (the participants' classes are listed in the spec),
 * - `op` numbers must be the values of the Op constants they are named after,
 * - gateway event names such as MESSAGE_CREATE must be Event constants,
 * - `Class::member` and `$discord->method()` in the prose must exist.
 *
 * Anything that no longer exists is listed and the page is left as it was, so a release that renames
 * or removes a method fails the build instead of publishing a stale diagram. `--source` reads the code
 * from another tree of the repository while still using the checkout's `vendor/`, as for routes-uml.php.
 */

$arguments = array_slice($argv, 1);
$source = null;
foreach ($arguments as $index => $argument) {
    if (str_starts_with($argument, '--source=')) {
        $source = substr($argument, 9);
        unset($arguments[$index]);
    }
}
[$checkout, $specFile] = array_values($arguments) + [null, null];
$pages = array_slice(array_values($arguments), 2);
if (! $checkout || ! $specFile || [] === $pages) {
    fwrite(STDERR, "Usage: php tools/architecture-uml.php <DiscordPHP checkout> <uml/architecture.json> <pages...> [--source=<tree>]\n");
    exit(2);
}

$checkout = rtrim(str_replace('\\', '/', $checkout), '/');
$source = rtrim(str_replace('\\', '/', $source ?: $checkout), '/');
$loader = require $checkout.'/vendor/autoload.php';
if ($source !== $checkout) {
    $loader->addPsr4('Discord\\', $source.'/src/Discord', true);
}

$spec = json_decode((string) file_get_contents($specFile), true, 512, JSON_THROW_ON_ERROR);

/** What went wrong, as sentences: the build fails on any. */
$problems = [];

/** A class's reflection, or null (and a problem) when the release has no such class. */
$reflect = static function (string $class, string $where) use (&$problems): ?ReflectionClass {
    if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)) {
        $problems[] = "{$where}: {$class} does not exist.";

        return null;
    }

    return new ReflectionClass($class);
};

/**
 * A type as the diagram shows it: short class names, without null, and `|` between the rest. Void,
 * mixed and missing types show nothing.
 */
$typeName = static function (?string $type, ReflectionClass $in): string {
    if (null === $type || '' === $type) {
        return '';
    }
    $type = (string) preg_replace('/<.*>|\{.*\}/s', '', $type);
    $parts = [];
    foreach (preg_split('/[|&]/', ltrim($type, '?')) as $part) {
        $part = trim($part, ' \\()');
        if (in_array(strtolower($part), ['', 'null', 'void', 'mixed', 'never'], true)) {
            continue;
        }
        if (in_array(strtolower($part), ['self', 'static', '$this'], true)) {
            $part = $in->getShortName();
        }
        $parts[] = ltrim((string) strrchr('\\'.$part, '\\'), '\\');
    }

    return implode('|', array_unique($parts));
};

/** A `@tag Type $name` or `@return Type` from a docblock, or null. */
$docType = static function (string|false $doc, string $tag, ?string $name = null): ?string {
    $pattern = null === $name
        ? '/@'.$tag.'\s+(\S+)/'
        : '/@'.$tag.'\s+(\S+)\s+\$'.preg_quote($name, '/').'\b/';

    return false !== $doc && preg_match($pattern, $doc, $match) ? $match[1] : null;
};

/** A member of a class as a Mermaid class body line, or null (and a problem) when it does not exist. */
$member = static function (ReflectionClass $class, string $name, string $where) use (&$problems, $typeName, $docType): ?string {
    $visibility = static fn (ReflectionMethod|ReflectionProperty|ReflectionClassConstant $r): string => $r->isPrivate() ? '-' : ($r->isProtected() ? '#' : '+');

    if (str_ends_with($name, '()')) {
        $method = substr($name, 0, -2);
        if (! $class->hasMethod($method)) {
            $problems[] = "{$where}: {$class->getName()} has no method {$method}().";

            return null;
        }
        $reflection = $class->getMethod($method);
        $parameters = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            // A parameter shows its type only when that is one class: `Channel channel`, not `string|Endpoint url`.
            $shown = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $typeName($type->getName(), $class).' ' : '';
            $parameters[] = $shown.($parameter->isVariadic() ? '...' : '').$parameter->getName();
        }
        $returns = $reflection->hasReturnType()
            ? $typeName((string) $reflection->getReturnType(), $class)
            : $typeName($docType($reflection->getDocComment(), 'return'), $class);

        return rtrim($visibility($reflection).$method.'('.implode(', ', $parameters).') '.$returns);
    }

    if (str_starts_with($name, '$')) {
        $property = substr($name, 1);
        if ($class->hasProperty($property)) {
            $reflection = $class->getProperty($property);
            $type = $reflection->hasType() ? (string) $reflection->getType() : $docType($reflection->getDocComment(), 'var');

            return rtrim($visibility($reflection).$typeName($type, $class).' '.$property);
        }
        // A magic property, from the @property tags of the class or a parent.
        for ($owner = $class; $owner; $owner = $owner->getParentClass()) {
            foreach (['property', 'property-read'] as $tag) {
                if (null !== ($type = $docType($owner->getDocComment(), $tag, $property))) {
                    return '+'.$typeName($type, $class).' '.$property;
                }
            }
        }
        $problems[] = "{$where}: {$class->getName()} has no property \${$property}.";

        return null;
    }

    if (! $class->hasConstant($name)) {
        $problems[] = "{$where}: {$class->getName()} has no constant {$name}.";

        return null;
    }
    $reflection = $class->getReflectionConstant($name);
    $value = $reflection->getValue();

    return $visibility($reflection).$name.(is_scalar($value) ? ' = '.var_export($value, true) : '');
};

// The class diagram.

$architecture = $spec['architecture'];
$classes = [];
$lines = ['classDiagram', '    direction '.($architecture['direction'] ?? 'TB')];
foreach ($architecture['classes'] as $name => $entry) {
    if (null === ($class = $reflect($entry['class'], "Class {$name}"))) {
        continue;
    }
    $classes[$name] = $class;

    $body = [];
    if ($class->isInterface()) {
        $body[] = '<<interface>>';
    } elseif ($class->isAbstract()) {
        $body[] = '<<abstract>>';
    }
    foreach ($entry['members'] ?? [] as $wanted) {
        if (null !== ($line = $member($class, $wanted, "Class {$name}"))) {
            $body[] = $line;
        }
    }

    if ([] === $body) {
        $lines[] = "    class {$name}";
        continue;
    }
    $lines[] = "    class {$name} {";
    foreach ($body as $line) {
        $lines[] = '        '.$line;
    }
    $lines[] = '    }';
}

// Mermaid's arrows, and for inheritance which end is the child: `A <|-- B` is B extends A.
const INHERITANCE = ['<|--' => 'right', '<|..' => 'right', '--|>' => 'left', '..|>' => 'left'];
foreach ($architecture['relations'] as $relation) {
    if (! preg_match('/^(\w+)\s*(?:"[^"]*"\s*)?(<\|--|<\|\.\.|--\|>|\.\.\|>|\*--|--\*|o--|--o|-->|<--|\.\.>|<\.\.|--|\.\.)\s*(?:"[^"]*"\s*)?(\w+)(?:\s*:.*)?$/u', $relation, $match)) {
        $problems[] = "Relation \"{$relation}\" is not one Mermaid draws between two classes.";
        continue;
    }
    [, $left, $arrow, $right] = $match;
    foreach ([$left, $right] as $end) {
        if (! isset($architecture['classes'][$end])) {
            $problems[] = "Relation \"{$relation}\": {$end} is not a class of the diagram.";
            continue 2;
        }
    }
    if (isset(INHERITANCE[$arrow], $classes[$left], $classes[$right])) {
        [$child, $parent] = 'right' === INHERITANCE[$arrow] ? [$classes[$right], $classes[$left]] : [$classes[$left], $classes[$right]];
        if (! $child->isSubclassOf($parent->getName())) {
            $problems[] = "Relation \"{$relation}\": {$child->getName()} does not extend or implement {$parent->getName()}.";
        }
    }
    $lines[] = '    '.$relation;
}
$diagram = implode("\n", $lines);

// The table of packages, in the order the diagram first uses them.

$installed = json_decode((string) file_get_contents($checkout.'/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$installed = $installed['packages'] ?? $installed;
$root = json_decode((string) file_get_contents($checkout.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$repositoryOf = static function (array $package): ?string {
    $url = $package['source']['url'] ?? $package['support']['source'] ?? (isset($package['support']['issues']) ? dirname($package['support']['issues']) : null);

    return null === $url ? null : (string) preg_replace('/\.git$/', '', $url);
};

$packages = [];
foreach ($classes as $name => $class) {
    $file = str_replace('\\', '/', (string) $class->getFileName());
    if (preg_match('#/vendor/([^/]+/[^/]+)/#', $file, $match)) {
        $package = $match[1];
        $found = array_values(array_filter($installed, static fn (array $p): bool => $p['name'] === $package));
        $url = [] === $found ? null : $repositoryOf($found[0]);
    } else {
        $package = $root['name'];
        $url = $repositoryOf($root);
    }
    $packages[$package] ??= ['url' => $url, 'names' => [], 'classes' => []];
    $packages[$package]['names'][] = $name;
    $packages[$package]['classes'][] = $class->getName();
}

$html = static fn (string $text): string => htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
$rows = [];
foreach ($packages as $package => $entry) {
    $link = null === $entry['url'] ? $html($package) : '<a href="'.htmlspecialchars($entry['url'], ENT_QUOTES).'">'.$html($package).'</a>';
    $rows[] = '              <tr><td>'.$html(implode(', ', $entry['names'])).'</td><td>'.$link.'</td><td>'
        .implode(', ', array_map(static fn (string $class): string => '<code>'.$html($class).'</code>', $entry['classes'])).'</td></tr>';
}

// What the page's other diagrams and prose may name.

$names = $spec['names'] ?? [];
$opcodes = isset($names['opcodes']) ? $reflect($names['opcodes'], 'names.opcodes') : null;
$events = isset($names['events']) ? $reflect($names['events'], 'names.events') : null;
$known = [];
foreach ($classes as $name => $class) {
    $known[$name] = $class;
}
foreach ([$opcodes, $events] as $class) {
    if (null !== $class) {
        $known[$class->getShortName()] ??= $class;
    }
}
$hasMethod = static fn (array $among, string $method): bool => [] !== array_filter($among, static fn (ReflectionClass $c): bool => $c->hasMethod($method));
$isEventName = static fn (string $name): bool => (null !== $events && $events->hasConstant($name)) || (null !== $opcodes && ($opcodes->hasConstant($name) || $opcodes->hasConstant('OP_'.$name)));

/** Checks a sequence diagram against its participants' classes. */
$checkSequence = static function (string $id, string $code, array $entry, string $where) use (&$problems, $reflect, $hasMethod, $isEventName, $opcodes): void {
    $participants = [];
    foreach ($entry['participants'] as $alias => $list) {
        $participants[$alias] = array_values(array_filter(array_map(static fn (string $class) => $reflect($class, "{$where}, participant {$alias}"), $list)));
    }
    $also = array_values(array_filter(array_map(static fn (string $class) => $reflect($class, "{$where}, also"), $entry['also'] ?? [])));

    $lines = explode("\n", $code);
    foreach ($lines as $number => $line) {
        if (preg_match('/^\s*(?:participant|actor)\s+(\w+)/', $line, $match) && ! isset($participants[$match[1]])) {
            $problems[] = "{$where}: participant {$match[1]} is not listed under sequences.{$id} in the spec.";
        }

        if (preg_match('/^\s*(\w+)\s*-{1,2}(?:>>|>|x|\))[+-]?\s*(\w+)\s*:\s*(.*)$/', $line, $message)) {
            [, $from, $to, $text] = $message;
            $among = [...($participants[$from] ?? []), ...($participants[$to] ?? []), ...$also];
            preg_match_all('/(?<![\w$])(?:\$?\w+->)*([A-Za-z_]\w*)\s*\(/', $text, $calls);
            foreach (array_unique($calls[1]) as $call) {
                if (! $hasMethod($among, $call)) {
                    $classes = implode(', ', array_map(static fn (ReflectionClass $c): string => $c->getShortName(), $among)) ?: 'no class';
                    $problems[] = "{$where}: \"{$from}->>{$to}: {$text}\" calls {$call}(), which is not a method of {$classes}.";
                }
            }
        }

        // `alt op 0, DISPATCH`, or `else op 1, 7 or 9` with the names in the next message: `heartbeat, reconnect or hello`.
        if (null !== $opcodes && preg_match('/^\s*(?:alt|else|opt)\s+op\s+([\d\s,]+(?:\s+or\s+\d+)?)(?:,\s*([A-Z][A-Z_]*))?\s*$/', $line, $op)) {
            preg_match_all('/\d+/', $op[1], $numbers);
            $labels = [];
            if (isset($op[2]) && '' !== $op[2]) {
                $labels = [$op[2]];
            } elseif (isset($lines[$number + 1]) && preg_match('/:\s*(.+)$/', $lines[$number + 1], $next)) {
                $labels = preg_split('/\s*,\s*|\s+or\s+/', trim($next[1]));
            }
            if (count($labels) !== count($numbers[0])) {
                $problems[] = "{$where}: \"".trim($line).'" gives '.count($numbers[0]).' opcodes but names '.count($labels).'.';
                continue;
            }
            foreach ($numbers[0] as $index => $value) {
                $constant = 'OP_'.strtoupper((string) preg_replace('/\W+/', '_', trim($labels[$index])));
                if (! $opcodes->hasConstant($constant) || (int) $value !== $opcodes->getConstant($constant)) {
                    $problems[] = "{$where}: op {$value} is named {$labels[$index]}, but {$opcodes->getShortName()}::{$constant} ".($opcodes->hasConstant($constant) ? 'is '.var_export($opcodes->getConstant($constant), true) : 'does not exist').'.';
                }
            }
        }
    }

    preg_match_all('/\b[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+\b/', $code, $constants);
    foreach (array_unique($constants[0]) as $name) {
        if (! $isEventName($name)) {
            $problems[] = "{$where}: {$name} is not a gateway event or opcode DiscordPHP knows.";
        }
    }
};

// Rewrite the first page that has the markers, then check every page as it will be published.

$written = null;
$contents = [];
foreach ($pages as $page) {
    $contents[$page] = (string) file_get_contents($page);
}
$replace = static function (string $page, string $marker, string $body) use (&$contents): bool {
    $pattern = '/(<!-- architecture-uml: '.preg_quote($marker, '/').' -->\n).*?(\n[ \t]*<!-- \/architecture-uml -->)/s';
    if (! preg_match($pattern, $contents[$page])) {
        return false;
    }
    $contents[$page] = (string) preg_replace_callback($pattern, static fn (array $m): string => $m[1].$body.$m[2], $contents[$page], 1);

    return true;
};
foreach ($pages as $page) {
    if ($replace($page, 'diagram', '<pre class="mermaid">'.$html($diagram).'</pre>')) {
        $replace($page, 'packages', implode("\n", $rows));
        $written = $page;
        break;
    }
}
if (null === $written) {
    $problems[] = 'No page has the <!-- architecture-uml: diagram --> marker.';
}

foreach ($contents as $page => $content) {
    $text = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    foreach ($spec['sequences'] ?? [] as $id => $entry) {
        if ('about' === $id || ! preg_match('/<section id="'.preg_quote($id, '/').'"[^>]*>.*?<pre class="mermaid"[^>]*>(.*?)<\/pre>/s', $content, $block)) {
            continue;
        }
        $checkSequence($id, html_entity_decode($block[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $entry, basename($page).' #'.$id);
    }

    // Class::member in the prose and diagrams, for the classes the spec knows.
    preg_match_all('/\b([A-Z]\w*)::(\$?[A-Za-z_]\w*)(\s*\()?/', $text, $references, PREG_SET_ORDER);
    foreach ($references as $reference) {
        [, $short, $name] = $reference;
        if (! isset($known[$short])) {
            continue;
        }
        $class = $known[$short];
        $exists = isset($reference[3]) ? $class->hasMethod($name) : ($class->hasConstant($name) || $class->hasProperty(ltrim($name, '$')));
        if (! $exists) {
            $problems[] = basename($page).": {$short}::{$name}".(isset($reference[3]) ? '()' : '').' does not exist in '.$class->getName().'.';
        }
    }

    // $discord->method() and the like.
    foreach ($names['variables'] ?? [] as $variable => $class) {
        if (null === ($class = $reflect($class, "names.variables.{$variable}"))) {
            continue;
        }
        preg_match_all('/'.preg_quote($variable, '/').'->(\w+)\s*\(/', $text, $calls);
        foreach (array_unique($calls[1]) as $call) {
            if (! $class->hasMethod($call)) {
                $problems[] = basename($page).": {$variable}->{$call}() is not a method of {$class->getName()}.";
            }
        }
    }
}

if ([] !== $problems) {
    fwrite(STDERR, "The UML pages do not match DiscordPHP:\n  ".implode("\n  ", array_unique($problems))."\n");
    exit(1);
}

$changed = $contents[$written] !== file_get_contents($written);
if ($changed) {
    file_put_contents($written, $contents[$written]);
}
printf(
    "%s: %d classes from %d packages, %d relations%s; %d pages checked.\n",
    $written,
    count($classes),
    count($packages),
    count($architecture['relations']),
    $changed ? ', page updated' : ', page unchanged',
    count($pages),
);
