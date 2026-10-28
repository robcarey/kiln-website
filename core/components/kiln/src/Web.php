<?php

namespace KilnWeb;

/** Request, session and page helpers shared by the account pages. */
final class Web
{
    public function __construct(private Kiln $kiln)
    {
    }

    public function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    public function post(string $key, int $max = 1000): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }

    /** Raw (untrimmed) for passwords. */
    public function secret(string $key): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? mb_substr($v, 0, 200) : '';
    }

    public function query(string $key, int $max = 200): string
    {
        $v = $_GET[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    // ---- session state -----------------------------------------------------------

    public function session(string $key, mixed $value = null, bool $clear = false): mixed
    {
        if ($clear) {
            unset($_SESSION['kiln'][$key]);
            return null;
        }
        if ($value !== null) {
            $_SESSION['kiln'][$key] = $value;
        }
        return $_SESSION['kiln'][$key] ?? null;
    }

    public function csrf(): string
    {
        return $this->session('csrf') ?? $this->session('csrf', bin2hex(random_bytes(16)));
    }

    public function checkCsrf(): void
    {
        $sent = $_POST['csrf'] ?? $_GET['t'] ?? '';
        if (!is_string($sent) || !hash_equals($this->csrf(), $sent)) {
            throw new UserError('Your session timed out. Please try that again.');
        }
    }

    /** One-shot message shown on the next page. */
    public function flash(?string $message = null, string $kind = 'ok'): ?array
    {
        if ($message !== null) {
            $this->session('flash', ['message' => $message, 'kind' => $kind]);
            return null;
        }
        $f = $this->session('flash');
        $this->session('flash', null, true);
        return $f;
    }

    // ---- pages -------------------------------------------------------------------

    /**
     * Full URL of a kiln.page_* page, e.g. url('signin', ['return' => 'account']).
     * Always the web context, so links in mail sent from the manager are right too.
     */
    public function url(string $page, array $params = []): string
    {
        $id = (int) $this->kiln->opt('kiln.page_' . $page, 0);
        return $id ? $this->kiln->modx->makeUrl($id, 'web', $params, 'full') : $this->kiln->opt('site_url');
    }

    public function redirect(string $url): never
    {
        $this->kiln->modx->sendRedirect($url);
        exit;
    }

    /** Only ever return to one of our own account pages. */
    public function returnTo(string $fallback = 'account'): string
    {
        $r = $this->session('return') ?? '';
        $this->session('return', null, true);
        return $this->url(in_array($r, ['account', 'buy'], true) ? $r : $fallback);
    }

    public function chunk(string $name, array $ph = []): string
    {
        return (string) $this->kiln->modx->getChunk($name, $ph + [
            'csrf' => $this->csrf(),
            'url_account' => $this->url('account'),
            'url_signin' => $this->url('signin'),
            'url_register' => $this->url('register'),
            'url_verify' => $this->url('verify'),
            'url_forgot' => $this->url('forgot'),
            'url_signout' => $this->url('signout', ['t' => $this->csrf()]),
            'url_connect' => $this->url('connect'),
        ]);
    }

    /** HTML-escape, and defuse [[ ]] so visitor text can never become a MODX tag. */
    public function e(?string $s): string
    {
        return strtr(htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ['[' => '&#91;', ']' => '&#93;']);
    }

    /** Cloudflare Turnstile, when kiln.turnstile_secret is set; otherwise always passes. */
    public function turnstileOk(): bool
    {
        $secret = (string) $this->kiln->opt('kiln.turnstile_secret', '');
        if ($secret === '') {
            return true;
        }
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $this->post('cf-turnstile-response', 4096), 'remoteip' => $this->ip()]),
        ]);
        $res = json_decode((string) curl_exec($ch), true);
        return !empty($res['success']);
    }

    public function turnstileWidget(): string
    {
        $key = (string) $this->kiln->opt('kiln.turnstile_site_key', '');
        return $key === '' ? '' : '<div class="cf-turnstile" data-sitekey="' . $this->e($key) . '" data-theme="auto"></div><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }
}
