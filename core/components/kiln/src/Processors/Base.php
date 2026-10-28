<?php

namespace KilnWeb\Processors;

use KilnWeb\AccountController;
use KilnWeb\Kiln;
use KilnWeb\LicenseApiError;
use KilnWeb\UserError;
use MODX\Revolution\modUser;
use MODX\Revolution\Processors\Processor;

/**
 * The manager's "Kiln Licenses" page (controllers/licenses.class.php) calls these
 * through MODX's own connector by class name, e.g.
 * action=KilnWeb\Processors\Licenses\GetList.
 *
 * That connector also answers ?ctx=web, so a signed-in customer could reach a
 * processor with their own web token. Every one of these therefore insists on a
 * manager session belonging to an Administrator (or a sudo user).
 */
abstract class Base extends Processor
{
    protected Kiln $kiln;

    public function checkPermissions()
    {
        $u = $this->modx->user;
        return $this->modx->context && $this->modx->context->get('key') === 'mgr'
            && $u && $u->get('id') && $u->isAuthenticated('mgr')
            && ($u->get('sudo') || $u->isMember('Administrator'));
    }

    public function initialize()
    {
        $this->kiln = Kiln::get($this->modx);
        return true;
    }

    public function process()
    {
        try {
            return $this->handle();
        } catch (LicenseApiError $ex) {
            return $this->failure($this->explain($ex));
        } catch (UserError $ex) {
            return $this->failure($ex->getMessage());
        } catch (\RuntimeException $ex) {
            $this->kiln->log('manager: ' . $ex->getMessage());
            return $this->failure('Could not reach the licence server: ' . $ex->getMessage());
        }
    }

    /** @return mixed what process() would return: success(), failure() or outputArray() */
    abstract protected function handle(): mixed;

    /** A licence API call; writes are stamped with who did it. */
    protected function api(string $method, string $path, ?array $body = null): mixed
    {
        if ($body !== null) {
            $body['actor'] = 'admin:' . $this->modx->user->get('username');
        }
        return $this->kiln->licenses()->call($method, $path, $body);
    }

    protected function query(array $params): string
    {
        return http_build_query(array_filter($params, static fn ($v) => $v !== null && $v !== ''));
    }

    protected function str(string $key, int $max = 254): string
    {
        return mb_substr(trim((string) $this->getProperty($key, '')), 0, $max);
    }

    protected function flag(string $key): bool
    {
        return in_array((string) $this->getProperty($key, ''), ['1', 'true', 'on'], true);
    }

    /** A YYYY-MM-DD from an Ext date field ("2027-11-10" or "2027-11-10T00:00:00"), or null. */
    protected function date(string $key): ?string
    {
        $v = $this->str($key, 40);
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $v, $m)) {
            throw new UserError("$key must be a date");
        }
        return $m[1];
    }

    /** The active customer account for an email, if there is one (licences then go straight onto it). */
    protected function customer(string $email): ?modUser
    {
        $u = $this->kiln->accounts()->find($email);
        return $u && $u->get('active') && !$this->kiln->accounts()->isStaff($u) ? $u : null;
    }

    protected function email(array $licence, string $to = '', string $giftFrom = ''): bool
    {
        $to = $to !== '' ? $to : $licence['email'];
        $user = $this->customer($to);
        $name = $user ? $this->kiln->accounts()->nameOf($user) : '';
        return $this->kiln->account()->sendLicenceEmail($to, $licence, $name, $giftFrom);
    }

    /** Give $email a seat on a team; returns whether their key was emailed. */
    protected function seat(string $teamId, string $email, bool $send, string $giftFrom): bool
    {
        $user = $this->customer($email);
        $team = $this->api('POST', 'teams/' . rawurlencode($teamId) . '/members', ['email' => $email, 'accountRef' => $user ? (string) $user->get('id') : null]);
        $seat = array_values(array_filter($team['members'], fn ($l) => $l['email'] === $email && $l['status'] === 'active'))[0] ?? null;
        return $send && $seat && $this->email($seat, '', $giftFrom);
    }

    /** One row for a manager grid: the licence plus its MODX account and a readable plan. */
    protected function row(array $l, array $users = []): array
    {
        $l['planLabel'] = AccountController::PLAN_LABELS[$l['plan']] ?? $l['plan'];
        $l['state'] = $l['status'] === 'revoked' ? 'revoked' : ($l['expired'] ? 'expired' : 'active');
        $u = $l['accountRef'] !== null ? ($users[(int) $l['accountRef']] ?? null) : null;
        $l['account'] = $u ? $u->get('username') : '';
        return $l;
    }

    /** @return array<int, modUser> keyed by id, for the accountRefs in these licences */
    protected function users(array $licences): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($l) => (int) ($l['accountRef'] ?? 0), $licences))));
        $out = [];
        if ($ids) {
            foreach ($this->modx->getIterator(modUser::class, ['id:IN' => $ids]) as $u) {
                $out[(int) $u->get('id')] = $u;
            }
        }
        return $out;
    }

    private function explain(LicenseApiError $ex): string
    {
        return match ($ex->errorCode) {
            'trial_used' => 'This email has already had a trial. Tick “Allow a second trial” to give another one.',
            'no_seats' => 'Every seat on this team is in use. Add seats to the team first.',
            'not_found' => 'The licence server has no record of that.',
            'team_cancelled' => 'That team is cancelled.',
            'unauthorized', 'bad_signature', 'stale', 'forbidden' => 'The licence server refused this website (' . $ex->errorCode . '). Check kiln.license_api_secret and the server clock.',
            default => 'Licence server: ' . $ex->getMessage(),
        };
    }
}
