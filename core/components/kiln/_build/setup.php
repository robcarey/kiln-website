<?php
/**
 * Kiln website — idempotent setup.
 *
 *   php core/components/kiln/_build/setup.php          create/update elements, settings, starter pages
 *   php core/components/kiln/_build/setup.php --bump   ...and bump kiln.asset_version (CSS/JS cache-buster)
 *
 * Safe to re-run:
 *  - templates/chunks/snippets are STATIC elements bound to the files in ../elements (files are the source of truth);
 *  - TVs are created or have their structure refreshed (values untouched);
 *  - system settings are created with defaults, but an existing VALUE is never overwritten;
 *  - pages are created only if their alias does not exist yet; existing content is never touched.
 */

use MODX\Revolution\modCategory;
use MODX\Revolution\modChunk;
use MODX\Revolution\modContentType;
use MODX\Revolution\modDocument;
use MODX\Revolution\modMenu;
use MODX\Revolution\modNamespace;
use MODX\Revolution\modResource;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarTemplate;
use MODX\Revolution\modUserGroup;
use MODX\Revolution\modX;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('MODX_API_MODE', true);
$siteRoot = dirname(__DIR__, 4) . '/';
require $siteRoot . 'config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';

$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('ECHO');

$bump = in_array('--bump', $argv, true);
$elementsDir = dirname(__DIR__) . '/elements/';
$data = require __DIR__ . '/data.php';

function say(string $msg): void
{
    echo $msg, "\n";
}

/* ------------------------------------------------------------------ namespace */
$ns = $modx->getObject(modNamespace::class, ['name' => 'kiln']) ?: $modx->newObject(modNamespace::class);
$ns->fromArray([
    'name' => 'kiln',
    'path' => '{core_path}components/kiln/',
    'assets_path' => '{assets_path}themes/kiln/',
], '', true);
$ns->save();
say('namespace kiln ok');

/* ------------------------------------------------------------------ manager page: Extras -> Kiln Licences */
/* Found by namespace + action, not by its text (the menu's key), so renaming it never makes a second one. */
$menu = $modx->getObject(modMenu::class, ['namespace' => 'kiln', 'action' => 'licenses']);
if (!$menu) {
    $menu = $modx->newObject(modMenu::class);
    $menu->fromArray(['text' => 'kiln.licenses'], '', true, true);
}
$menu->fromArray(['parent' => 'components', 'action' => 'licenses', 'namespace' => 'kiln', 'description' => 'kiln.licenses_desc',
    'icon' => '', 'menuindex' => 0, 'params' => '', 'handler' => '', 'permissions' => '']);
$menu->save();
say('menu Extras -> Kiln Licences ok');

/* ------------------------------------------------------------------ categories (under the existing KILN category) */
$root = null;
foreach ($modx->getCollection(modCategory::class, ['parent' => 0]) as $cat) {
    if (strcasecmp($cat->get('category'), 'KILN') === 0) {
        $root = $cat;
        break;
    }
}
if (!$root) {
    $root = $modx->newObject(modCategory::class);
    $root->fromArray(['category' => 'KILN', 'parent' => 0]);
    $root->save();
    say('created category KILN');
}
$categories = ['KILN' => (int) $root->get('id')];
foreach (['Layout', 'Home', 'Visuals', 'Interior', 'Forms', 'Account', 'Shop'] as $child) {
    $cat = $modx->getObject(modCategory::class, ['category' => $child, 'parent' => $root->get('id')]);
    if (!$cat) {
        $cat = $modx->newObject(modCategory::class);
        $cat->fromArray(['category' => $child, 'parent' => $root->get('id')]);
        $cat->save();
    }
    $categories[$child] = (int) $cat->get('id');
}

$categoryFor = static function (string $name) use ($categories): int {
    if (str_starts_with($name, 'cmp.home.') || str_starts_with($name, 'migx.tpl.')) {
        return $categories['Home'];
    }
    if (str_starts_with($name, 'viz.') || str_starts_with($name, 'stage.')) {
        return $categories['Visuals'];
    }
    if (in_array($name, ['cmp.PageHero', 'cmp.DownloadCards', 'cmp.CompareTable'], true)) {
        return $categories['Interior'];
    }
    if (str_starts_with($name, 'form.') || str_starts_with($name, 'email.')) {
        return $categories['Forms'];
    }
    if (str_starts_with($name, 'account.')) {
        return $categories['Account'];
    }
    if (str_starts_with($name, 'buy.')) {
        return $categories['Shop'];
    }
    if (str_starts_with($name, 'tpl.') || str_starts_with($name, 'kiln.')) {
        return $categories['KILN'];
    }
    return $categories['Layout'];
};

/* ------------------------------------------------------------------ static elements */
function syncStatic(modX $modx, string $class, string $nameField, string $name, string $relFile, string $absFile, int $category, string $description): int
{
    $el = $modx->getObject($class, [$nameField => $name]) ?: $modx->newObject($class);
    $content = file_get_contents($absFile);
    $el->fromArray([
        $nameField => $name,
        'description' => $description,
        'category' => $category,
        'static' => true,
        'source' => 0,
        'static_file' => $relFile,
    ]);
    // Templates keep their body in `content`; chunks and snippets in `snippet`.
    $el->set($class === modTemplate::class ? 'content' : 'snippet', $content);
    if (!$el->save()) {
        say("!! failed to save {$name}");
    }
    return (int) $el->get('id');
}

$templateIds = [];
foreach (glob($elementsDir . 'templates/*.html') as $file) {
    $name = basename($file, '.html');
    $templateIds[$name] = syncStatic($modx, modTemplate::class, 'templatename', $name, 'kiln/elements/templates/' . basename($file), $file, $categories['KILN'], 'Kiln website template (static: core/components/kiln/elements/templates).');
}
say('templates: ' . implode(', ', array_keys($templateIds)));

$chunkCount = 0;
foreach (glob($elementsDir . 'chunks/*.html') as $file) {
    $name = basename($file, '.html');
    syncStatic($modx, modChunk::class, 'name', $name, 'kiln/elements/chunks/' . basename($file), $file, $categoryFor($name), 'Kiln website chunk (static).');
    $chunkCount++;
}
say("chunks: {$chunkCount}");

foreach (glob($elementsDir . 'snippets/*.php') as $file) {
    $name = basename($file, '.php');
    syncStatic($modx, modSnippet::class, 'name', $name, 'kiln/elements/snippets/' . basename($file), $file, $categories['KILN'], 'Kiln website snippet (static).');
    say("snippet: {$name}");
}

/* ------------------------------------------------------------------ template variables */
$home = $templateIds['tpl.Homepage'];
$interior = $templateIds['tpl.Interior'];

$migx = static function (array $fields, array $columns, string $caption, string $button): array {
    return [
        'formtabs' => json_encode([['caption' => $caption, 'fields' => $fields]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'columns' => json_encode($columns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'btntext' => $button,
        'configs' => '',
        'previewurl' => '',
        'jsonvarkey' => '',
        'autoResourceFolders' => 'false',
    ];
};

$tvs = [
    'heroEyebrow' => ['type' => 'text', 'caption' => 'Hero eyebrow', 'description' => 'Small line above the headline (homepage pill / interior eyebrow).', 'templates' => [$home, $interior]],
    'heroTitle' => ['type' => 'text', 'caption' => 'Hero headline', 'description' => 'First line of the homepage headline.', 'templates' => [$home]],
    'heroHighlight' => ['type' => 'text', 'caption' => 'Hero headline (glowing)', 'description' => 'Second line of the homepage headline, drawn in the heat gradient.', 'templates' => [$home]],
    'heroSubtitle' => ['type' => 'textarea', 'caption' => 'Hero subtitle', 'description' => 'Paragraph under the headline.', 'templates' => [$home, $interior]],
    'heroStyle' => ['type' => 'listbox', 'caption' => 'Page header style', 'description' => 'Embers plays the sparks video; Glow is a CSS glow only; Plain is flat; Compact shows only the breadcrumb (account pages).', 'elements' => 'Glow==glow||Embers (video)==embers||Plain==plain||Compact (breadcrumb only)==compact', 'default' => 'glow', 'templates' => [$interior]],
    'showToc' => ['type' => 'listbox', 'caption' => 'Table of contents', 'description' => 'Show an “On this page” list built from the H2 headings.', 'elements' => 'No==0||Yes==1', 'default' => '0', 'templates' => [$interior]],
    'proseWide' => ['type' => 'listbox', 'caption' => 'Full-width content', 'description' => 'Let the content use the full container width (tables, cards).', 'elements' => 'No==0||Yes==1', 'default' => '0', 'templates' => [$interior]],
    'showCta' => ['type' => 'listbox', 'caption' => '“Fire it up” band', 'description' => 'Show the download call-to-action band at the bottom of the page.', 'elements' => 'Yes==1||No==0', 'default' => '1', 'templates' => [$interior]],
    'features' => ['type' => 'migx', 'caption' => 'Feature tiles', 'description' => 'The animated feature grid on the homepage.', 'templates' => [$home],
        'input_properties' => $migx(
            [
                ['field' => 'title', 'caption' => 'Title'],
                ['field' => 'body', 'caption' => 'Text', 'inputTVtype' => 'textarea'],
                ['field' => 'icon', 'caption' => 'Icon', 'description' => 'Icon name: server, lock, code, mail, terminal, palette, zap, phone, sync, checks, package, database, globe, shield'],
                ['field' => 'visual', 'caption' => 'Animated visual', 'inputTVtype' => 'listbox', 'inputOptionValues' => 'servers||https||php||mail||terminal||design||cms||phone||sync||check||export'],
                ['field' => 'size', 'caption' => 'Tile size', 'inputTVtype' => 'listbox', 'inputOptionValues' => 'Small==||Medium==md||Large==lg||Full width==full'],
                ['field' => 'pro', 'caption' => 'Pro feature', 'inputTVtype' => 'listbox', 'inputOptionValues' => 'No==0||Yes==1'],
            ],
            [['header' => 'Title', 'dataIndex' => 'title'], ['header' => 'Visual', 'dataIndex' => 'visual'], ['header' => 'Size', 'dataIndex' => 'size'], ['header' => 'Pro', 'dataIndex' => 'pro']],
            'Feature', 'Add feature'
        )],
    'steps' => ['type' => 'migx', 'caption' => 'Getting-started steps', 'description' => 'The “From download to https://yoursite.test” steps.', 'templates' => [$home],
        'input_properties' => $migx(
            [
                ['field' => 'title', 'caption' => 'Title'],
                ['field' => 'body', 'caption' => 'Text', 'inputTVtype' => 'textarea'],
                ['field' => 'visual', 'caption' => 'Stage visual', 'inputTVtype' => 'listbox', 'inputOptionValues' => 'install||check||site||open'],
            ],
            [['header' => 'Title', 'dataIndex' => 'title'], ['header' => 'Visual', 'dataIndex' => 'visual']],
            'Step', 'Add step'
        )],
    'faqs' => ['type' => 'migx', 'caption' => 'FAQ', 'description' => 'Questions and answers (also published as FAQ structured data).', 'templates' => [$home],
        'input_properties' => $migx(
            [
                ['field' => 'question', 'caption' => 'Question'],
                ['field' => 'answer', 'caption' => 'Answer', 'inputTVtype' => 'textarea'],
            ],
            [['header' => 'Question', 'dataIndex' => 'question']],
            'FAQ', 'Add question'
        )],
];

$rank = 0;
foreach ($tvs as $name => $def) {
    $tv = $modx->getObject(modTemplateVar::class, ['name' => $name]) ?: $modx->newObject(modTemplateVar::class);
    $tv->fromArray([
        'name' => $name,
        'caption' => $def['caption'],
        'description' => $def['description'],
        'type' => $def['type'],
        'elements' => $def['elements'] ?? '',
        'default_text' => $def['default'] ?? '',
        'category' => $categories['KILN'],
        'rank' => $rank++,
        'display' => 'default',
    ]);
    $tv->set('input_properties', $def['input_properties'] ?? ['allowBlank' => 'true']);
    $tv->save();
    foreach ($def['templates'] as $tplId) {
        if (!$modx->getObject(modTemplateVarTemplate::class, ['tmplvarid' => $tv->get('id'), 'templateid' => $tplId])) {
            $link = $modx->newObject(modTemplateVarTemplate::class);
            $link->fromArray(['tmplvarid' => $tv->get('id'), 'templateid' => $tplId, 'rank' => $tv->get('rank')], '', true, true);
            $link->save();
        }
    }
}
say('TVs: ' . implode(', ', array_keys($tvs)));

/* ------------------------------------------------------------------ system settings (never overwrite values) */
function ensureSetting(modX $modx, string $key, $value, string $area, string $xtype = 'textfield', string $namespace = 'kiln'): modSystemSetting
{
    $s = $modx->getObject(modSystemSetting::class, ['key' => $key]);
    if (!$s) {
        $s = $modx->newObject(modSystemSetting::class);
        $s->set('key', $key);
        $s->set('value', (string) $value);
        say("  + setting {$key}");
    }
    $s->set('xtype', $xtype);
    $s->set('namespace', $namespace);
    $s->set('area', $area);
    $s->save();
    return $s;
}

$settings = [
    'kiln.theme_url' => ['/assets/themes/kiln/', 'Theme'],
    'kiln.base_theme_url' => ['/assets/templates/kiln/assets/themes/kiln/', 'Theme'],
    'kiln.asset_version' => ['1', 'Theme'],
    'kiln.tagline' => ['Heat up your dev stack.', 'Site'],
    'kiln.support_email' => ['hello@kilndev.com', 'Site'],
    'kiln.price_pro_display' => ['', 'Site'],
    'kiln.current_version' => ['0.1.6', 'Downloads'],
    'kiln.download_win_url' => ['https://releases.kilndev.com/win/Kiln-0.1.6-setup.exe', 'Downloads'],
    'kiln.download_win_size' => ['113 MB', 'Downloads'],
    'kiln.download_win_sha256' => ['50d72269b014b0c7ebf7cbddb193a1c84c2fa3f94ad717f04cd99acb80525069', 'Downloads'],
    'kiln.download_mac_arm_url' => ['https://releases.kilndev.com/mac/Kiln-0.1.6-arm64.dmg', 'Downloads'],
    'kiln.download_mac_arm_size' => ['129 MB', 'Downloads'],
    'kiln.download_mac_arm_sha256' => ['fce9d0bc86b84a5c6527f374e4f4231fd77d50cff6d49d989a77fd3b62b0b9fc', 'Downloads'],
    'kiln.download_mac_x64_url' => ['https://releases.kilndev.com/mac/Kiln-0.1.6-x64.dmg', 'Downloads'],
    'kiln.download_mac_x64_size' => ['134 MB', 'Downloads'],
    'kiln.download_mac_x64_sha256' => ['ab36aebaf02925e99869c3f64065e6388d71b4e47a89114560714b21f3216273', 'Downloads'],
    'kiln.mail_from' => ['hello@kilndev.com', 'Accounts'],
    'kiln.mail_from_name' => ['Kiln', 'Accounts'],
    'kiln.license_api_url' => ['http://license.kilndev.test', 'Licensing'],
    'kiln.license_api_client' => ['modx', 'Licensing'],
    'kiln.license_api_resolve' => ['', 'Licensing'],
    'kiln.trials_public' => ['0', 'Licensing'],
    // The accounts switch: hidden | open (hidden = no sign-in link, account pages 404 except to manager users).
    'kiln.accounts' => ['hidden', 'Accounts'],
    'kiln.oauth_google_id' => ['', 'Accounts'],
    'kiln.oauth_github_id' => ['', 'Accounts'],
    'kiln.oauth_facebook_id' => ['', 'Accounts'],
    'kiln.turnstile_site_key' => ['', 'Accounts'],
    // The sales switch: soon | open | paused (Extras -> Kiln Licences flips it and clears the cache).
    'kiln.sales' => ['soon', 'Sales'],
    'kiln.sales_message' => ['', 'Sales'],
    'kiln.currency' => ['USD', 'Sales'],
    'kiln.price_annual' => ['49', 'Sales'],
    'kiln.price_lifetime' => ['129', 'Sales'],
    'kiln.price_team_seat' => ['39', 'Sales'],
    'kiln.team_min_seats' => ['3', 'Sales'],
    'kiln.payment_provider' => ['test', 'Sales'],
    'kiln.payments_test_mode' => ['0', 'Sales'],
    // Paddle (kiln.payment_provider = paddle). _build/paddle-setup.php fills the products and the webhook secret.
    'kiln.paddle_env' => ['sandbox', 'Paddle'],
    'kiln.paddle_client_token' => ['', 'Paddle'],
    'kiln.paddle_product_annual' => ['', 'Paddle'],
    'kiln.paddle_product_lifetime' => ['', 'Paddle'],
    'kiln.paddle_product_team' => ['', 'Paddle'],
];
$xtypes = ['kiln.trials_public' => 'combo-boolean', 'kiln.payments_test_mode' => 'combo-boolean', 'kiln.sales_message' => 'textarea'];
foreach ($settings as $key => [$value, $area]) {
    ensureSetting($modx, $key, $value, $area, $xtypes[$key] ?? 'textfield');
}
// Secrets: masked in the manager. app_secret signs the 4-letter codes and is made once, here.
foreach (['kiln.license_api_secret' => ['', 'Licensing'], 'kiln.app_secret' => [bin2hex(random_bytes(32)), 'Accounts'],
          'kiln.oauth_google_secret' => ['', 'Accounts'], 'kiln.oauth_github_secret' => ['', 'Accounts'],
          'kiln.oauth_facebook_secret' => ['', 'Accounts'], 'kiln.turnstile_secret' => ['', 'Accounts'],
          'kiln.paddle_api_key' => ['', 'Paddle'], 'kiln.paddle_webhook_secret' => ['', 'Paddle']] as $key => [$value, $area]) {
    ensureSetting($modx, $key, $value, $area, 'text-password');
}

/* ------------------------------------------------------------------ accounts: tables and the Customers group */
require_once dirname(__DIR__) . '/bootstrap.php';
$tables = KilnWeb\Kiln::get($modx)->store()->install();
say('tables: ' . implode(', ', $tables));
if (!$modx->getObject(modUserGroup::class, ['name' => KilnWeb\Accounts::GROUP])) {
    $g = $modx->newObject(modUserGroup::class);
    $g->set('name', KilnWeb\Accounts::GROUP);
    $g->set('description', 'People with a kilndev.com account (no manager access).');
    $g->save();
    say('  + user group ' . KilnWeb\Accounts::GROUP);
}
if ($bump) {
    $v = ensureSetting($modx, 'kiln.asset_version', '1', 'Theme');
    $v->set('value', date('YmdHis'));
    $v->save();
    say('  bumped kiln.asset_version');
}

/* Core settings — only moved off their install defaults, never off a value someone chose. */
$core = static function (string $key, $from, $to) use ($modx): void {
    $s = $modx->getObject(modSystemSetting::class, ['key' => $key]);
    if ($s && in_array((string) $s->get('value'), (array) $from, true)) {
        $s->set('value', (string) $to);
        $s->save();
        $modx->setOption($key, (string) $to); // pages made later in this run must see it
        say("  core {$key} -> {$to}");
    }
};
$core('site_name', ['MODX Revolution'], 'Kiln');
$core('link_tag_scheme', ['-1', ''], 'abs');
$core('use_alias_path', ['', '0'], '1'); // /account/sign-in; every other page is top-level, so no URL changes

/* Extension-less HTML URLs: the app links to /compare and /buy */
$html = $modx->getObject(modContentType::class, ['mime_type' => 'text/html']);
if ($html && $html->get('file_extensions') === '.html') {
    $html->set('file_extensions', '');
    $html->save();
    say('  HTML content type: no file extension');
}

/* ------------------------------------------------------------------ pages */
$content = static fn(string $f): string => file_get_contents(__DIR__ . '/content/' . $f);

function ensurePage(modX $modx, array $fields, array $tvValues = []): modResource
{
    $page = $modx->getObject(modResource::class, ['alias' => $fields['alias'], 'parent' => $fields['parent'] ?? 0, 'context_key' => 'web']);
    if ($page) {
        return $page;
    }
    $page = $modx->newObject(modDocument::class);
    $page->fromArray(array_merge([
        'parent' => 0,
        'context_key' => 'web',
        'class_key' => modDocument::class,
        'content_type' => 1,
        'published' => 1,
        'publishedon' => time(),
        'createdon' => time(),
        'createdby' => 1,
        'richtext' => 0,
        'searchable' => 1,
        'cacheable' => 1,
        'isfolder' => 0,
    ], $fields));
    $page->save();
    foreach ($tvValues as $tv => $value) {
        $page->setTVValue($tv, $value);
    }
    say("  + page /{$fields['alias']} (id {$page->get('id')})");
    return $page;
}

$pages = [];
$pages['features'] = ensurePage($modx, [
    'pagetitle' => 'Features', 'longtitle' => 'Everything Kiln does for you', 'alias' => 'features', 'menuindex' => 1,
    'description' => 'Apache and nginx side by side, any PHP version per site, MariaDB, trusted HTTPS, a mail catcher, CMS installs, sync and a design studio — all in Kiln.',
    'template' => $interior, 'content' => $content('features.html'),
], ['heroEyebrow' => 'Features', 'heroSubtitle' => 'Everything a real PHP site needs, configured and supervised by one app on your own machine.', 'heroStyle' => 'embers', 'showToc' => '1']);

$pages['compare'] = ensurePage($modx, [
    'pagetitle' => 'Free vs Pro', 'longtitle' => 'Kiln Free and Kiln Pro', 'menutitle' => 'Free vs Pro', 'alias' => 'compare', 'menuindex' => 2,
    'description' => 'Compare Kiln Free and Kiln Pro: the free version runs one site with the full stack; Pro unlocks unlimited sites and time-saving tools.',
    'template' => $interior, 'content' => $content('compare.html'),
], ['heroEyebrow' => 'Pricing', 'heroSubtitle' => 'The free version is the real thing. Pro takes the limits off.', 'heroStyle' => 'glow', 'proseWide' => '1']);

$pages['download'] = ensurePage($modx, [
    'pagetitle' => 'Download', 'longtitle' => 'Download Kiln', 'alias' => 'download', 'menuindex' => 3,
    'description' => 'Download Kiln for Windows 10/11, macOS on Apple Silicon or macOS on Intel. Signed installers, free for your first site.',
    'template' => $interior, 'content' => $content('download.html'),
], ['heroEyebrow' => 'Free for your first site', 'heroSubtitle' => 'Signed installers for Windows and both kinds of Mac.', 'heroStyle' => 'embers', 'proseWide' => '1', 'showCta' => '0']);

$pages['contact'] = ensurePage($modx, [
    'pagetitle' => 'Contact', 'longtitle' => 'Get in touch', 'alias' => 'contact', 'menuindex' => 4,
    'description' => 'Questions about Kiln, licensing or support? Send us a message.',
    'template' => $interior, 'content' => $content('contact.html'), 'cacheable' => 0,
], ['heroEyebrow' => 'Contact', 'heroSubtitle' => 'Real people, real answers.', 'heroStyle' => 'glow', 'showCta' => '0']);

$pages['buy'] = ensurePage($modx, [
    'pagetitle' => 'Kiln Pro', 'longtitle' => 'Get Kiln Pro', 'alias' => 'buy', 'menuindex' => 5, 'hidemenu' => 1,
    'description' => 'Kiln Pro unlocks unlimited sites, one-click CMS installs, database sync, the site rules editor and design studio theme builds.',
    'template' => $interior, 'content' => $content('buy.html'),
], ['heroEyebrow' => 'Kiln Pro', 'heroSubtitle' => 'Unlimited sites and the tools that save you hours.', 'heroStyle' => 'embers', 'showCta' => '0', 'proseWide' => '1']);

$pages['404'] = ensurePage($modx, [
    'pagetitle' => 'Page not found', 'alias' => 'page-not-found', 'menuindex' => 90, 'hidemenu' => 1, 'searchable' => 0,
    'template' => $interior, 'content' => $content('404.html'),
], ['heroEyebrow' => '404', 'heroStyle' => 'plain', 'showCta' => '0']);

foreach (['privacy' => 'Privacy Policy', 'terms' => 'Terms of Use'] as $alias => $title) {
    $pages[$alias] = ensurePage($modx, [
        'pagetitle' => $title, 'alias' => $alias, 'menuindex' => 95, 'hidemenu' => 1, 'published' => 0, 'publishedon' => 0,
        'template' => $interior, 'content' => $content('legal-draft.html'),
    ], ['heroStyle' => 'plain', 'showCta' => '0']);
}

/* Account pages: /account and its children. Uncached, out of menus and search. */
$acct = static fn (array $f, string $eyebrow, string $subtitle) => [
    array_merge(['template' => $interior, 'hidemenu' => 1, 'searchable' => 0, 'cacheable' => 0], $f),
    ['heroEyebrow' => $eyebrow, 'heroSubtitle' => $subtitle, 'heroStyle' => 'compact', 'showCta' => '0', 'proseWide' => '1'],
];
$pages['account'] = ensurePage($modx, ...$acct(
    ['pagetitle' => 'Your account', 'alias' => 'account', 'menuindex' => 80, 'isfolder' => 1, 'content' => '[[!kiln.Account? &action=`dashboard`]]'],
    'Account', 'Licences, downloads and teams.'
));
foreach ([
    'signin' => ['sign-in', 'Sign in', 'Account', 'Welcome back.'],
    'register' => ['register', 'Create an account', 'Account', 'One account for your licences, downloads and team.'],
    'verify' => ['verify', 'Check your email', 'Account', 'A 4-letter code is on its way.'],
    'forgot' => ['forgot', 'Reset your password', 'Account', 'We’ll email you a code.'],
    'signout' => ['sign-out', 'Signing out', 'Account', ''],
    'connect' => ['connect', 'Connect', 'Account', 'Signing you in.'],
] as $key => [$alias, $title, $eyebrow, $sub]) {
    $pages[$key] = ensurePage($modx, ...$acct(
        ['pagetitle' => $title, 'alias' => $alias, 'parent' => $pages['account']->get('id'), 'menuindex' => count($pages), 'content' => "[[!kiln.Account? &action=`$key`]]"],
        $eyebrow, $sub
    ));
}

/* The payment webhook: providers POST to /payment-webhook?provider=…. No template, never cached or searched. */
$pages['webhook'] = ensurePage($modx, [
    'pagetitle' => 'Payment webhook', 'alias' => 'payment-webhook', 'template' => 0, 'menuindex' => 99,
    'hidemenu' => 1, 'searchable' => 0, 'cacheable' => 0, 'content' => '[[!kiln.Buy? &action=`webhook`]]',
]);

/* /buy became the shop on 2026-10-27: the starter text is swapped for the one holding [[!kiln.Buy]], only if nobody edited it. */
if (sha1(trim((string) $pages['buy']->get('content'))) === '3ff3de43c1ff189ac39f6dff1fcd4de4329be373') {
    $pages['buy']->set('content', $content('buy.html'));
    $pages['buy']->save();
    $pages['buy']->setTVValue('proseWide', '1');
    say('  /buy now holds the shop');
}

foreach (['features', 'compare', 'buy', 'download', 'contact', 'account', 'signin', 'register', 'verify', 'forgot', 'signout', 'connect', 'webhook'] as $key) {
    $s = ensureSetting($modx, "kiln.page_{$key}", '', 'Pages', 'numberfield');
    if ((int) $s->get('value') === 0) {
        $s->set('value', (string) $pages[$key]->get('id'));
        $s->save();
    }
}
$core('error_page', [(string) $modx->getOption('site_start'), '1'], $pages['404']->get('id'));

/* ------------------------------------------------------------------ homepage (first conversion only) */
$homePage = $modx->getObject(modResource::class, (int) $modx->getOption('site_start', null, 1));
if ($homePage && (int) $homePage->get('template') !== $home && trim((string) $homePage->getTVValue('features')) === '') {
    $homePage->fromArray([
        'template' => $home,
        'pagetitle' => 'Home',
        'longtitle' => 'Kiln — Heat up your dev stack',
        'description' => 'Kiln is the local web stack for Windows and macOS: Apache and nginx side by side, any PHP version per site, MariaDB, trusted HTTPS and a mail catcher. Free for your first site.',
        'hidemenu' => 1,
        'richtext' => 0,
        'content' => '',
    ]);
    $homePage->save();
    $homePage->setTVValue('heroEyebrow', 'Now on Windows & macOS');
    $homePage->setTVValue('heroTitle', 'Heat up your');
    $homePage->setTVValue('heroHighlight', 'dev stack.');
    $homePage->setTVValue('heroSubtitle', 'Real Apache or nginx, any PHP version per site, MariaDB, trusted HTTPS and a mail catcher — running on your own machine in minutes. Self-contained from the very first click.');
    $homePage->setTVValue('features', $data['features']);
    $homePage->setTVValue('steps', $data['steps']);
    $homePage->setTVValue('faqs', $data['faqs']);
    say("  home (id {$homePage->get('id')}) switched to tpl.Homepage with starter content");
}

modResource::refreshURIs($modx); // nested URLs (/account/sign-in) follow use_alias_path
$modx->cacheManager->refresh();
say('URIs rebuilt, cache cleared — done.');
