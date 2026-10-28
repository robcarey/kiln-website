<?php

namespace KilnWeb;

/** The website's own tables (modx_kiln_*), through MODX's PDO connection. */
final class Store
{
    private \PDO $pdo;
    public readonly string $prefix;

    public function __construct(Kiln $kiln)
    {
        $kiln->modx->connect();
        $this->pdo = $kiln->modx->pdo;
        $this->prefix = $kiln->modx->getOption('table_prefix') . 'kiln_';
    }

    public function t(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
    }

    public function run(string $sql, array $args = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st;
    }

    public function one(string $sql, array $args = []): ?array
    {
        $row = $this->run($sql, $args)->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $args = []): array
    {
        return $this->run($sql, $args)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function value(string $sql, array $args = []): mixed
    {
        $v = $this->run($sql, $args)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /** Fixed-window counter: true while $bucket has had at most $max hits in this window. */
    public function throttle(string $bucket, int $max, int $window): bool
    {
        $start = intdiv(time(), $window) * $window;
        $this->run(
            'INSERT INTO ' . $this->t('throttle') . ' (bucket, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = IF(window_start = VALUES(window_start), hits + 1, 1), window_start = VALUES(window_start)',
            [substr($bucket, 0, 120), $start]
        );
        return (int) $this->value('SELECT hits FROM ' . $this->t('throttle') . ' WHERE bucket = ?', [substr($bucket, 0, 120)]) <= $max;
    }

    /** CREATE TABLE IF NOT EXISTS for everything above; run by _build/setup.php. */
    public function install(): array
    {
        $tables = [
            'auth_codes' => '
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(254) NOT NULL,
                purpose VARCHAR(16) NOT NULL,
                code_hash CHAR(64) NOT NULL,
                user_id INT UNSIGNED NULL,
                payload TEXT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                expires_at INT UNSIGNED NOT NULL,
                used_at INT UNSIGNED NULL,
                ip VARCHAR(45) NULL,
                created_at INT UNSIGNED NOT NULL,
                KEY idx_email_purpose (email, purpose),
                KEY idx_ip_created (ip, created_at)',
            'identities' => '
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                provider VARCHAR(20) NOT NULL,
                provider_uid VARCHAR(191) NOT NULL,
                email VARCHAR(254) NULL,
                display_name VARCHAR(191) NULL,
                created_at INT UNSIGNED NOT NULL,
                last_used_at INT UNSIGNED NULL,
                UNIQUE KEY uniq_provider_uid (provider, provider_uid),
                KEY idx_user (user_id)',
            'throttle' => '
                bucket VARCHAR(120) NOT NULL PRIMARY KEY,
                window_start INT NOT NULL,
                hits INT NOT NULL',
            // Shop: one order per checkout, its items, and every provider webhook (applied once per event id).
            'orders' => '
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ref VARCHAR(16) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                email VARCHAR(254) NOT NULL,
                status VARCHAR(16) NOT NULL,
                currency CHAR(3) NOT NULL,
                total_cents INT UNSIGNED NOT NULL,
                provider VARCHAR(20) NOT NULL,
                provider_ref VARCHAR(191) NULL,
                provider_sub VARCHAR(64) NULL,
                provider_customer VARCHAR(64) NULL,
                test TINYINT(1) NOT NULL DEFAULT 0,
                period_end DATE NULL,
                auto_renew TINYINT(1) NOT NULL DEFAULT 1,
                created_at INT UNSIGNED NOT NULL,
                paid_at INT UNSIGNED NULL,
                fulfilled_at INT UNSIGNED NULL,
                refunded_at INT UNSIGNED NULL,
                cancelled_at INT UNSIGNED NULL,
                emailed_at INT UNSIGNED NULL,
                UNIQUE KEY uniq_ref (ref),
                KEY idx_user (user_id),
                KEY idx_status (status)',
            'order_items' => '
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                order_id INT UNSIGNED NOT NULL,
                plan VARCHAR(16) NOT NULL,
                qty INT UNSIGNED NOT NULL,
                seats INT UNSIGNED NULL,
                unit_cents INT UNSIGNED NOT NULL,
                line_cents INT UNSIGNED NOT NULL,
                license_ids TEXT NULL,
                team_id VARCHAR(20) NULL,
                KEY idx_order (order_id)',
            'payment_events' => '
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                provider VARCHAR(20) NOT NULL,
                event_id VARCHAR(191) NOT NULL,
                type VARCHAR(16) NOT NULL,
                order_ref VARCHAR(16) NOT NULL,
                provider_txn VARCHAR(64) NULL,
                amount_cents INT NULL,
                period_end DATE NULL,
                received_at INT UNSIGNED NOT NULL,
                processed_at INT UNSIGNED NULL,
                error TEXT NULL,
                UNIQUE KEY uniq_event (provider, event_id),
                KEY idx_order (order_ref)',
        ];
        $made = [];
        foreach ($tables as $name => $cols) {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->t($name) . " ($cols) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $made[] = $this->prefix . $name;
        }
        // Columns added after a table first shipped (CREATE TABLE IF NOT EXISTS leaves old tables alone).
        $added = [
            'orders' => ['provider_sub' => 'VARCHAR(64) NULL AFTER provider_ref', 'provider_customer' => 'VARCHAR(64) NULL AFTER provider_sub'],
            'payment_events' => ['provider_txn' => 'VARCHAR(64) NULL AFTER order_ref'],
        ];
        foreach ($added as $name => $cols) {
            $have = array_column($this->all('SHOW COLUMNS FROM ' . $this->t($name)), 'Field');
            foreach ($cols as $col => $def) {
                if (!in_array($col, $have, true)) {
                    $this->pdo->exec('ALTER TABLE ' . $this->t($name) . " ADD COLUMN $col $def");
                    $made[] = "$this->prefix$name.$col";
                }
            }
        }
        return $made;
    }
}
