<?php

declare(strict_types=1);

require __DIR__.'/preview-payload.php';

$root = dirname(__DIR__);
$image = 'https://discordphp.org/assets/images/discordphp.png';
$size = getimagesize($root.'/assets/images/discordphp.png');
foreach (previewPages() as $path => $links) {
    $html = file_get_contents($root.'/'.$path);
    preg_match('/<title>(.*?)<\/title>/s', $html, $titleMatch);
    preg_match('/<meta name="description" content="([^"]+)"\s*\/?>/', $html, $descriptionMatch);
    $title = html_entity_decode($titleMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $description = html_entity_decode($descriptionMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $url = 'https://discordphp.org/'.($path === 'index.html' ? '' : ($path === 'uml/index.html' ? 'uml/' : $path));
    $payload = ['component' => ['type' => 17, 'accent_color' => 0x5b4fd6, 'components' => [
        ['type' => 9, 'components' => [['type' => 10, 'content' => '# '.$title."\n".$description]], 'accessory' => ['type' => 11, 'media' => ['url' => $image], 'description' => 'DiscordPHP logo']],
        ['type' => 14, 'spacing' => 1],
        ['type' => 1, 'components' => [
            ['type' => 2, 'style' => 5, 'label' => $links[0], 'url' => $links[1]],
            ['type' => 2, 'style' => 5, 'label' => $links[2], 'url' => $links[3]],
        ]],
    ]]];
    $json = previewJson($payload);
    validatePreview($json);
    $tags = [
        'og:site_name' => 'DiscordPHP', 'og:title' => $title, 'og:description' => $description,
        'og:type' => 'website', 'og:url' => $url, 'og:image' => $image,
        'og:image:type' => 'image/png', 'og:image:width' => (string) $size[0], 'og:image:height' => (string) $size[1], 'og:image:alt' => 'DiscordPHP logo',
        'twitter:card' => 'summary', 'twitter:title' => $title, 'twitter:description' => $description,
        'twitter:image' => $image, 'twitter:image:alt' => 'DiscordPHP logo', 'theme-color' => '#5b4fd6',
    ];
    $block = "  <!-- link-preview: start -->\n";
    $block .= '  <link rel="canonical" href="'.$url.'">'."\n";
    foreach ($tags as $key => $value) {
        $attribute = str_starts_with($key, 'og:') ? 'property' : 'name';
        $block .= '  <meta '.$attribute.'="'.$key.'" content="'.htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8').'">'."\n";
    }
    $block .= '  <script id="discord:component-embed" type="application/json">'.$json.'</script>'."\n  <!-- link-preview: end -->\n";
    if (str_contains($html, '<!-- link-preview: start -->')) {
        $html = preg_replace('/  <!-- link-preview: start -->.*?<!-- link-preview: end -->\r?\n/s', $block, $html, 1);
    } else {
        $html = str_replace('</head>', $block.'</head>', $html);
    }
    file_put_contents($root.'/'.$path, $html);
    echo $path.': '.strlen($json)." bytes\n";
}
