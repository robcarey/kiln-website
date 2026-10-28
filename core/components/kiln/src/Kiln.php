<?php

namespace KilnWeb;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;

/**
 * One entry point for the website's account, licence and checkout code.
 * Snippets call Kiln::get($modx) and take what they need from here.
 */
final class Kiln
{
    private static ?self $instance = null;
    private array $services = [];

    private function __construct(public readonly modX $modx)
    {
    }

    public static function get(modX $modx): self
    {
        return self::$instance ??= new self($modx);
    }

    public function opt(string $key, mixed $default = null): mixed
    {
        return $this->modx->getOption($key, null, $default);
    }

    private function make(string $class): object
    {
        return $this->services[$class] ??= new $class($this);
    }

    public function store(): Store { return $this->make(Store::class); }
    public function web(): Web { return $this->make(Web::class); }
    public function mailer(): Mailer { return $this->make(Mailer::class); }
    public function codes(): Codes { return $this->make(Codes::class); }
    public function accounts(): Accounts { return $this->make(Accounts::class); }
    public function social(): Social { return $this->make(Social::class); }
    public function licenses(): LicenseClient { return $this->make(LicenseClient::class); }
    public function account(): AccountController { return $this->make(AccountController::class); }
    public function shop(): Shop { return $this->make(Shop::class); }
    public function buy(): BuyController { return $this->make(BuyController::class); }

    /** The signed-in customer on the web context, if any. */
    public function user(): ?modUser
    {
        $u = $this->modx->user;
        return $u && $u->get('id') && $u->isAuthenticated('web') ? $u : null;
    }

    /**
     * The accounts switch, kiln.accounts: open | hidden. While hidden, the
     * sign-in link and every account page are gone for the public; someone
     * signed in to the manager still sees them, to try them out.
     */
    public function accountsOpen(): bool
    {
        return strtolower(trim((string) $this->opt('kiln.accounts', 'hidden'))) === 'open';
    }

    public function accountsVisible(): bool
    {
        $u = $this->modx->user;
        return $this->accountsOpen() || ($u && $u->get('id') && $u->isAuthenticated('mgr'));
    }

    public function log(string $msg): void
    {
        $this->modx->log(modX::LOG_LEVEL_ERROR, '[kiln] ' . $msg);
    }
}
