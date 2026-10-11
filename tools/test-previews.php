<?php

declare(strict_types=1);

require __DIR__.'/check-previews.php';

$checks = 0;
function expectPreview(bool $condition, string $name): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('Failed: '.$name);
    }
}
function rejectPreview(callable $check, string $name): void
{
    try {
        $check();
    } catch (Throwable $error) {
        expectPreview(true, $name);

        return;
    }
    expectPreview(false, $name);
}
function textPayload(string $content): array
{
    return ['component' => ['type' => 17, 'components' => [['type' => 10, 'content' => $content]]]];
}

$base = previewJson(textPayload('x'));
$boundary = previewJson(textPayload(str_repeat('x', 3000 - strlen($base) + 1)));
expectPreview(validatePreview($boundary)['bytes'] === 3000, 'exact 3000-byte boundary');
rejectPreview(fn () => validatePreview($boundary.' '), '3001 bytes');
$unicode = previewJson(textPayload(str_repeat('é', 1450)));
expectPreview(validatePreview($unicode)['bytes'] === strlen($unicode), 'UTF-8 bytes, not characters');
rejectPreview(fn () => validatePreview(previewJson(textPayload(str_repeat('é', 1500)))), 'multibyte overflow');
$danger = '</ScRiPt><script>alert("x")</script>&';
$safe = previewJson(textPayload($danger));
expectPreview(!str_contains($safe, '<') && json_decode($safe, true)['component']['components'][0]['content'] === $danger, 'HTML script escape round trip');
validatePreview($safe);
rejectPreview(fn () => validatePreview(json_encode(textPayload($danger), JSON_UNESCAPED_SLASHES)), 'raw closing script');
rejectPreview(fn () => validatePreview(previewJson(textPayload(str_repeat('<', 500)))), 'escape expansion counts toward byte limit');
rejectPreview(fn () => validatePreview('{broken'), 'malformed JSON');
rejectPreview(fn () => validatePreview('{"components":[]}'), 'missing root');
rejectPreview(fn () => validatePreview('{"component":{"type":17,"components":[]},"extra":{}}'), 'extra root');
rejectPreview(fn () => validatePreview(previewJson(['component' => ['type' => 17, 'components' => [['type' => 17, 'components' => [['type' => 10, 'content' => 'x']]]]]])), 'nested container');
foreach ([3, 4, 13, 18] as $type) {
    rejectPreview(fn () => validatePreview(previewJson(['component' => ['type' => 17, 'components' => [['type' => $type]]]])), 'unsupported component '.$type);
}
$many = ['component' => ['type' => 17, 'components' => array_fill(0, 39, ['type' => 10, 'content' => 'x'])]];
expectPreview(validatePreview(previewJson($many))['components'] === 40, 'root counted at 40');
// Use row children so 41 fails the total limit, rather than root child arity.
$many['component']['components'][0] = ['type' => 1, 'components' => [['type' => 2, 'style' => 5, 'label' => 'x', 'url' => 'https://example.com']]];
rejectPreview(fn () => validatePreview(previewJson($many)), '41 components');
$button = ['type' => 2, 'style' => 5, 'url' => 'https://example.com', 'label' => 'Open'];
$row = fn ($b) => ['component' => ['type' => 17, 'components' => [['type' => 1, 'components' => [$b]]]]];
validatePreview(previewJson($row($button)));
rejectPreview(fn () => validatePreview(previewJson($row($button + ['custom_id' => 'interaction']))), 'custom_id');
rejectPreview(fn () => validatePreview(previewJson($row($button + ['unexpected' => true]))), 'extra button keys');
rejectPreview(fn () => validatePreview(previewJson($row(array_replace($button, ['style' => 1])))), 'interactive button');
rejectPreview(fn () => validatePreview(previewJson($row(array_replace($button, ['url' => 'javascript:alert(1)'])))), 'non-HTTP button');
rejectPreview(fn () => validatePreview(previewJson($row(array_replace($button, ['label' => ''])))), 'unlabelled button');
$gallery = ['type' => 12, 'items' => array_fill(0, 5, ['media' => ['url' => 'https://example.com/logo.png']])];
$galleries = ['component' => ['type' => 17, 'components' => [$gallery, $gallery]]];
expectPreview(validatePreview(previewJson($galleries))['gallery_items'] === 10, 'gallery total 10');
$galleries['component']['components'][1]['items'][] = ['media' => ['url' => 'https://example.com/logo.png']];
rejectPreview(fn () => validatePreview(previewJson($galleries)), 'gallery total 11 across two galleries');
rejectPreview(fn () => previewUrl('https://example.com/logo.svg', true), 'SVG media');
rejectPreview(fn () => previewUrl('https://example.com/'.str_repeat('a', 2048)), 'URL limit');
$root = dirname(__DIR__);
$publicPages = array_merge(glob($root.'/*.html'), glob($root.'/uml/*.html'));
$expectedPages = [];
foreach ($publicPages as $publicPage) {
    $path = str_replace('\\', '/', substr($publicPage, strlen($root) + 1));
    if ($path !== '404.html') {
        $expectedPages[] = $path;
    }
}
$coveredPages = array_keys(previewPages());
sort($expectedPages);
sort($coveredPages);
expectPreview($coveredPages === $expectedPages, 'all public content pages covered except 404');
foreach ($coveredPages as $path) {
    expectPreview(checkPreviewPage(file_get_contents($root.'/'.$path), $path, $root)['components'] > 0, 'valid preview and fallback for '.$path);
}
$html = file_get_contents($root.'/index.html');
checkPreviewPage($html, 'index.html', $root);
rejectPreview(fn () => checkPreviewPage(str_replace('property="og:title"', 'property="missing:title"', $html), 'index.html', $root), 'missing fallback');
rejectPreview(fn () => checkPreviewPage(str_replace('id="discord:component-embed"', 'id="other"', $html), 'index.html', $root), 'missing script');
preg_match('/<script id="discord:component-embed".*?<\/script>/s', $html, $script);
expectPreview(checkPreviewPage(str_replace($script[0], '', $html), 'index.html', $root, true) === [], 'fallback without component script');
expectPreview(checkPreviewPage(str_replace($script[0], '<script id="discord:component-embed" type="application/json">{broken</script>', $html), 'index.html', $root, true) === [], 'fallback with invalid component script');
rejectPreview(fn () => checkPreviewPage(str_replace('</head>', '<meta property="og:title" content="Duplicate"></head>', $html), 'index.html', $root), 'duplicate fallback title');
rejectPreview(fn () => checkPreviewPage(str_replace($script[0], '', str_replace('</body>', $script[0].'</body>', $html)), 'index.html', $root), 'script in body');
rejectPreview(fn () => checkPreviewPage(str_replace('</head>', $script[0].'</head>', $html), 'index.html', $root), 'duplicate script');
echo $checks." focused preview assertions passed.\n";
