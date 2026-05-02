<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * Server-side sessions. Klient dostane jen opaque cookie token (sha256 hex).
 * Storage:
 *   - primárně Redis (pokud `cfg.redis.enabled=true` a Redis dostupný)
 *   - fallback `sessions` tabulka v MariaDB
 *
 * Session value:
 *   - user_id, csrf_token, ip, user_agent, created_at, last_seen, expires_at
 */
final class SessionManager
{
    public function __construct(
        private readonly Connection $db,
        private readonly RedisFactory $redis,
        private readonly Config $config,
    ) {}

    public function create(int $userId, string $ip, string $userAgent): array
    {
        $token = bin2hex(random_bytes(32));        // 64 znaků hex
        $csrf  = bin2hex(random_bytes(32));
        $lifetimeDays = (int) $this->config->get('session.lifetime_days', 30);
        $expiresAt = time() + ($lifetimeDays * 86400);

        $data = [
            'user_id'    => $userId,
            'csrf_token' => $csrf,
            'ip'         => $ip,
            'user_agent' => substr($userAgent, 0, 255),
            'created_at' => time(),
            'last_seen'  => time(),
            'expires_at' => $expiresAt,
        ];

        $this->store($token, $data);

        return [
            'token'      => $token,
            'csrf_token' => $csrf,
            'expires_at' => $expiresAt,
        ];
    }

    public function load(string $token): ?array
    {
        if ($token === '' || strlen($token) !== 64) {
            return null;
        }

        // Redis primary
        if (($r = $this->redis->client()) !== null) {
            $raw = $r->get('sess:' . $token);
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data) && ($data['expires_at'] ?? 0) >= time()) {
                    // Update last_seen + sliding expiration handled by Redis EXPIRE
                    return $data;
                }
            }
        }

        // DB fallback
        $stmt = $this->db->pdo()->prepare(
            'SELECT user_id, csrf_token, ip, user_agent,
                    UNIX_TIMESTAMP(created_at) AS created_at,
                    UNIX_TIMESTAMP(last_seen)  AS last_seen,
                    UNIX_TIMESTAMP(expires_at) AS expires_at
             FROM sessions WHERE id = ? AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['user_id']    = (int) $row['user_id'];
        $row['expires_at'] = (int) $row['expires_at'];
        return $row;
    }

    public function touch(string $token): void
    {
        if (($r = $this->redis->client()) !== null) {
            // Redis: jen prodlouži TTL
            $lifetimeDays = (int) $this->config->get('session.lifetime_days', 30);
            $r->expire('sess:' . $token, $lifetimeDays * 86400);
            return;
        }
        $stmt = $this->db->pdo()->prepare('UPDATE sessions SET last_seen = NOW() WHERE id = ?');
        $stmt->execute([$token]);
    }

    public function destroy(string $token): void
    {
        if (($r = $this->redis->client()) !== null) {
            $r->del('sess:' . $token);
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM sessions WHERE id = ?');
        $stmt->execute([$token]);
    }

    public function destroyAllForUser(int $userId, ?string $exceptToken = null): int
    {
        $count = 0;

        // Redis: musíme projít všechny sess:* klíče (drahé) — jen pro malý počet aktivních sessionů.
        // Pro produkci by bylo lepší index per user (sess:user:<id> = SET tokenů).
        // Zatím procházíme DB tabulku, kterou aktualizujeme zároveň.

        $stmt = $this->db->pdo()->prepare('SELECT id FROM sessions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $tokens = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        foreach ($tokens as $tok) {
            if ($exceptToken !== null && hash_equals($exceptToken, $tok)) {
                continue;
            }
            $this->destroy($tok);
            $count++;
        }
        return $count;
    }

    private function store(string $token, array $data): void
    {
        if (($r = $this->redis->client()) !== null) {
            $ttl = max(60, $data['expires_at'] - time());
            $r->setex('sess:' . $token, $ttl, json_encode($data, JSON_UNESCAPED_UNICODE));
            // Reflektuj i do DB jako backup (pokud Redis spadne, nepřijdeme o session)
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO sessions (id, user_id, csrf_token, ip, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?))
             ON DUPLICATE KEY UPDATE last_seen = NOW(), expires_at = FROM_UNIXTIME(?)'
        );
        $stmt->execute([
            $token,
            $data['user_id'],
            $data['csrf_token'],
            @inet_pton((string) $data['ip']) ?: '',
            $data['user_agent'],
            $data['expires_at'],
            $data['expires_at'],
        ]);
    }
}
