<?php
/**
 * Starter content for the Kiln website. Used by setup.php ONLY when a resource is first
 * created or first switched to its Kiln template — edits made in the manager are never overwritten.
 *
 * Copy rules: never describe Kiln as inspired by / based on / a successor to anything, no comparisons,
 * and don't promise unbuilt features (no AI, remote preview, seats, auto-update).
 */

$features = [
    ['title' => 'Apache and nginx. At the same time.', 'body' => 'Both run on the standard ports, side by side. Pick the server per site and match production instead of guessing at it.', 'icon' => 'server', 'visual' => 'servers', 'size' => 'lg', 'pro' => '0'],
    ['title' => 'HTTPS your browser trusts', 'body' => 'Kiln runs its own local certificate authority, issues a certificate for every site and renews it before it expires. No warnings to click through.', 'icon' => 'lock', 'visual' => 'https', 'size' => '', 'pro' => '0'],
    ['title' => 'Any PHP. Per site.', 'body' => 'Keep a legacy client on 7.4 while a new build runs 8.5 — at the same time. Toggle extensions and edit php.ini without leaving the app.', 'icon' => 'code', 'visual' => 'php', 'size' => '', 'pro' => '0'],
    ['title' => 'Every email, caught', 'body' => 'A built-in mail catcher grabs everything your sites send, so you can test password resets and receipts without a message escaping to a real inbox.', 'icon' => 'mail', 'visual' => 'mail', 'size' => '', 'pro' => '0'],
    ['title' => 'A terminal that knows the site', 'body' => 'Open a shell in any site’s folder where php, composer, wp and mysql are the exact versions that site runs. No PATH surgery.', 'icon' => 'terminal', 'visual' => 'terminal', 'size' => '', 'pro' => '0'],
    ['title' => 'A design studio for your theme', 'body' => 'Generate palettes, check contrast, set type and spacing, then build a Bootstrap, Tailwind CSS, Bulma, UIkit or Pico CSS theme with a live preview.', 'icon' => 'palette', 'visual' => 'design', 'size' => 'md', 'pro' => '1'],
    ['title' => 'WordPress, MODX, Joomla and Grav in one click', 'body' => 'Pick a version from the official release feed. Kiln creates the database and user, then opens the installer with the details on screen.', 'icon' => 'zap', 'visual' => 'cms', 'size' => 'md', 'pro' => '1'],
    ['title' => 'Test on your phone', 'body' => 'Sites are reachable from phones and tablets on the same Wi-Fi, so real-device testing is a tap away.', 'icon' => 'phone', 'visual' => 'phone', 'size' => '', 'pro' => '0'],
    ['title' => 'Push and pull to your host', 'body' => 'SFTP, FTP, FTPS or SSH, with a preview of every change before it runs. Saved profiles, exclude rules and encrypted credentials.', 'icon' => 'sync', 'visual' => 'sync', 'size' => '', 'pro' => '0'],
    ['title' => 'Green lights in one pass', 'body' => 'The setup check tests PHP, servers, ports, certificates, MariaDB and mail — and every problem it finds comes with a Fix button.', 'icon' => 'checks', 'visual' => 'check', 'size' => '', 'pro' => '0'],
    ['title' => 'Move a site in one file', 'body' => 'Export a site to a .kiln file — with its files and databases if you like — then import it on another machine to bring it back to life.', 'icon' => 'package', 'visual' => 'export', 'size' => 'full', 'pro' => '0'],
];

$steps = [
    ['title' => 'Install Kiln', 'body' => 'One signed installer for Windows or macOS. No package managers, no dependencies to hunt down.', 'visual' => 'install'],
    ['title' => 'Get every light green', 'body' => 'The setup check looks at PHP, web servers, ports, certificates, MariaDB and mail. Anything amiss gets a Fix button.', 'visual' => 'check'],
    ['title' => 'Add a site', 'body' => 'Pick a folder, a hostname, a web server and a PHP version. Kiln writes the config, the hosts entry and the certificate for you.', 'visual' => 'site'],
    ['title' => 'Open https://yoursite.test', 'body' => 'A real hostname with a trusted padlock — and reachable from your phone on the same Wi-Fi. Time to build.', 'visual' => 'open'],
];

$faqs = [
    ['question' => 'Is Kiln really free?', 'answer' => 'Yes. The free version runs one site with the full stack: Apache or nginx, any PHP version, MariaDB, trusted HTTPS, the mail catcher, the per-site terminal and file sync. Kiln Pro unlocks unlimited sites, one-click CMS installs, database sync, the site rules editor and design studio theme builds.'],
    ['question' => 'Which computers does Kiln run on?', 'answer' => 'Windows 10 and 11 (64-bit), and macOS on both Apple Silicon and Intel Macs. Each platform has its own signed installer; the download page lists them all.'],
    ['question' => 'Can I run different PHP versions at the same time?', 'answer' => 'Yes — that’s the point. Each site picks its own PHP version and they all run at once. Windows supports PHP 7.0 through 8.5, Apple Silicon Macs 7.4 through 8.5, and Intel Macs 8.1 through 8.5.'],
    ['question' => 'Apache or nginx?', 'answer' => 'Both, at the same time, on the standard ports. Choose the server per site so your local setup matches what runs in production.'],
    ['question' => 'What does Kiln change on my system?', 'answer' => 'Very little. Runtimes, configs, certificates and logs live in Kiln’s own data folder. Kiln adds a block of entries to your hosts file for your site names and installs its local certificate authority so browsers trust your sites. Uninstall Kiln from Settings and it removes those again — plus the data folder, if you choose.'],
    ['question' => 'Will test emails reach real people?', 'answer' => 'No. The built-in mail catcher receives everything your sites send and only listens on your own machine, so nothing leaves your computer.'],
    ['question' => 'Can I test on my phone?', 'answer' => 'Yes. Kiln can make your sites reachable from phones and tablets on the same Wi-Fi network, using your computer’s network address.'],
    ['question' => 'How do I move a site to another computer?', 'answer' => 'Export it to a .kiln file — optionally with its files and databases — and import it in Kiln on the other machine.'],
];

$wrap = static function (array $rows): string {
    $out = [];
    foreach ($rows as $i => $row) {
        $out[] = ['MIGX_id' => (string) ($i + 1)] + $row;
    }
    return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

return [
    'features' => $wrap($features),
    'steps' => $wrap($steps),
    'faqs' => $wrap($faqs),
];
