<?php
/**
 * kiln.FaqSchema — FAQPage JSON-LD from a MIGX FAQ TV (fields: question, answer).
 *
 * [[kiln.FaqSchema? &tv=`faqs`]]
 *
 * @var \MODX\Revolution\modX $modx
 * @var array $scriptProperties
 */
$tvName = $modx->getOption('tv', $scriptProperties, 'faqs');
$docId = (int) $modx->getOption('docid', $scriptProperties, $modx->resource ? $modx->resource->get('id') : 0);
if (!$docId) {
    return '';
}

$resource = ($modx->resource && $modx->resource->get('id') === $docId)
    ? $modx->resource
    : $modx->getObject(\MODX\Revolution\modResource::class, $docId);
if (!$resource) {
    return '';
}

$items = json_decode((string) $resource->getTVValue($tvName), true);
if (!is_array($items) || !$items) {
    return '';
}

$entities = [];
foreach ($items as $item) {
    $q = trim(strip_tags((string) ($item['question'] ?? '')));
    $a = trim(strip_tags((string) ($item['answer'] ?? '')));
    if ($q === '' || $a === '') {
        continue;
    }
    $entities[] = [
        '@type' => 'Question',
        'name' => html_entity_decode($q, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'acceptedAnswer' => [
            '@type' => 'Answer',
            'text' => html_entity_decode($a, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ],
    ];
}
if (!$entities) {
    return '';
}

$json = json_encode(
    ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
);

return '<script type="application/ld+json">' . $json . '</script>';