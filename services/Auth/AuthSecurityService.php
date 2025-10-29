<?php

namespace app\services\Auth;

use Yii;
use yii\base\Component;
use yii\caching\CacheInterface;
use yii\db\Connection;

/**
 * Service that wraps anti-abuse logic for auth flows (rate limit, honeypot, TZ storage).
 */
class AuthSecurityService extends Component
{
    /** @var CacheInterface|null */
    public $cacheComponent = null;
    /** @var Connection|null */
    public $dbConnection = null;

    private CacheInterface $cache;
    private Connection $db;

    public function init(): void
    {
        parent::init();
        $this->cache = $this->cacheComponent instanceof CacheInterface
            ? $this->cacheComponent
            : Yii::$app->get('cache');
        $this->db = $this->dbConnection instanceof Connection
            ? $this->dbConnection
            : Yii::$app->getDb();
    }

    public function tooFastSubmit(int $renderTs, int $minSec = 2): bool
    {
        return ($renderTs === 0) || (time() - $renderTs < $minSec);
    }

    public function allowAttempt(string $route, string $email, string $ip, int $limit, int $windowSec): bool
    {
        $key = $this->rateLimitKey($route, $email, $ip);

        $data = $this->cache->get($key);
        if (!$data) {
            $this->cache->set($key, ['c' => 1, 'ts' => time()], $windowSec);
            return true;
        }
        $count = (int)$data['c'];
        $ts = (int)$data['ts'];

        if (time() - $ts > $windowSec) {
            $this->cache->set($key, ['c' => 1, 'ts' => time()], $windowSec);
            return true;
        }
        if ($count >= $limit) {
            return false;
        }
        $data['c'] = $count + 1;
        $this->cache->set($key, $data, $windowSec);
        return true;
    }

    public function backoffOnError(string $route, string $email, string $ip): void
    {
        $key = $this->rateLimitKey($route, $email, $ip) . ':err';
        $n = (int)$this->cache->get($key) + 1;
        $this->cache->set($key, $n, 900);
        usleep(min(500000, 100000 * $n));
    }

    public function isDisposableEmail(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        return (bool)preg_match('~@(?:mailinator\\.com|guerrillamail\\.com|10minutemail\\.com|tempmail\\.|yopmail\\.com)$~', $email);
    }

    public function rememberTimezone(int $userId, ?string $tz): void
    {
        if (!$tz || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            return;
        }

        $affected = $this->db->createCommand()
            ->update('{{%user}}', ['timezone' => $tz], ['id' => $userId])
            ->execute();

        if ($affected) {
            Yii::info("Saved timezone for uid={$userId}: {$tz}", __METHOD__);
        }
    }

    public function sanitizeTimezone(?string $tz): ?string
    {
        if (!$tz) {
            return null;
        }
        $tz = urldecode($tz);
        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : null;
    }

    private function rateLimitKey(string $route, string $email, string $ip): string
    {
        return 'rl:' . $route . ':' . sha1($ip . '|' . mb_strtolower(trim((string)$email)));
    }
}