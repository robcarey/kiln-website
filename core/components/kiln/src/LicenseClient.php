<?php

namespace KilnWeb;

/**
 * Client for license.kilndev.com's internal API (see licensing/README.md in
 * H:\SITES\KILN SERVER). Every request is HMAC-signed; writes carry an
 * Idempotency-Key so a retried purchase never mints twice.
 *
 * Settings: kiln.license_api_url, kiln.license_api_client, kiln.license_api_secret,
 * kiln.license_api_resolve (on the server "127.0.0.1": the call stays on the
 * box and still validates the certificate).
 */
final class LicenseClient
{
    public function __construct(private Kiln $kiln)
    {
    }

    public function configured(): bool
    {
        return $this->kiln->opt('kiln.license_api_url', '') !== '' && $this->kiln->opt('kiln.license_api_secret', '') !== '';
    }

    /** @throws \RuntimeException on transport errors or an {ok:false} reply (code in getCode() is the HTTP status) */
    public function call(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): mixed
    {
        if (!$this->configured()) {
            throw new \RuntimeException('licence server is not configured (kiln.license_api_url / kiln.license_api_secret)');
        }
        $base = rtrim((string) $this->kiln->opt('kiln.license_api_url'), '/');
        $uri = '/v1/' . ltrim($path, '/');
        $raw = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = (string) time();
        $canonical = strtoupper($method) . "\n" . $uri . "\n" . $ts . "\n" . hash('sha256', $raw);
        $headers = [
            'Content-Type: application/json',
            'X-Kiln-Client: ' . $this->kiln->opt('kiln.license_api_client', 'modx'),
            'X-Kiln-Ts: ' . $ts,
            'X-Kiln-Sig: ' . hash_hmac('sha256', $canonical, (string) $this->kiln->opt('kiln.license_api_secret')),
        ];
        if (strtoupper($method) !== 'GET') {
            $headers[] = 'Idempotency-Key: ' . ($idempotencyKey ?? bin2hex(random_bytes(12)));
        }

        $ch = curl_init($base . $uri);
        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
        ];
        if ($raw !== '') {
            $opts[CURLOPT_POSTFIELDS] = $raw;
        }
        $resolve = (string) $this->kiln->opt('kiln.license_api_resolve', '');
        if ($resolve !== '') {
            $host = parse_url($base, PHP_URL_HOST);
            $port = parse_url($base, PHP_URL_PORT) ?: (str_starts_with($base, 'https') ? 443 : 80);
            $opts[CURLOPT_RESOLVE] = ["$host:$port:$resolve"];
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) {
            throw new \RuntimeException('licence server unreachable: ' . curl_error($ch));
        }
        $json = json_decode((string) $res, true);
        if (!is_array($json) || empty($json['ok'])) {
            $err = $json['error'] ?? ['code' => 'bad_reply', 'message' => substr((string) $res, 0, 200)];
            throw new LicenseApiError((string) ($err['message'] ?? 'error'), $status, (string) ($err['code'] ?? 'error'));
        }
        return $json['data'];
    }

    // ---- the calls the website makes -------------------------------------------

    public function forAccount(int $userId): array
    {
        return $this->call('GET', 'licenses?accountRef=' . $userId . '&limit=200');
    }

    public function teamsOwnedBy(int $userId): array
    {
        return $this->call('GET', 'teams?members=1&ownerAccountRef=' . $userId);
    }

    public function claim(string $email, int $userId): array
    {
        return $this->call('POST', 'claim', ['email' => $email, 'accountRef' => (string) $userId, 'actor' => "web:user:$userId"]);
    }
}
