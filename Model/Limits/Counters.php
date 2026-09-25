<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Limits;

use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Limits\Exception\LimitExceeded;

final class Counters
{
    private const CACHE_TAG = 'AIAGENT_LIMITS';
    private const SESSION_TTL = 600;
    private const IP_TTL = 60;
    private const SESSION_RETRY_AFTER = 60;
    private const IP_RETRY_AFTER = 20;
    private const LOCK_PREFIX = 'aiagent:counters:';
    private const LOCK_TIMEOUT = 2;

    public function __construct(
        private readonly \Magento\Framework\App\CacheInterface $cache,
        private readonly \Magento\Framework\Lock\LockManagerInterface $lockManager,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function bump(string $sessionId, string $browserSessionId, string $ip, AgentConfig $config): void
    {
        $windowAnchor = $browserSessionId !== '' ? $browserSessionId : $sessionId;
        $sessionKey = $this->bucketKey('aiagent_cnt_s_', $windowAnchor, self::SESSION_TTL);
        $ipKey = $this->bucketKey('aiagent_cnt_ip_', $ip, self::IP_TTL);
        $lockName = $this->lockName($sessionKey, $ipKey);
        $this->lockManager->lock($lockName, self::LOCK_TIMEOUT);
        try {
            $sessionCount = (int)$this->cache->load($sessionKey);
            $ipCount = (int)$this->cache->load($ipKey);
            if ($sessionCount >= $config->turnsPerSessionWindow) {
                $this->trip($sessionId, 'window');
                throw new LimitExceeded('session turns per window exceeded', self::SESSION_RETRY_AFTER);
            }
            if ($ipCount >= $config->turnsPerIpMinute) {
                $this->trip($sessionId, 'ip');
                throw new LimitExceeded('turns per ip minute exceeded', self::IP_RETRY_AFTER);
            }
            $this->cache->save((string)($sessionCount + 1), $sessionKey, [self::CACHE_TAG], self::SESSION_TTL);
            $this->cache->save((string)($ipCount + 1), $ipKey, [self::CACHE_TAG], self::IP_TTL);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    private function lockName(string $sessionKey, string $ipKey): string
    {
        return self::LOCK_PREFIX . substr(sha1($sessionKey . '|' . $ipKey), 0, 24);
    }

    private function bucketKey(string $prefix, string $subject, int $ttl): string
    {
        return $prefix . substr(sha1($subject), 0, 24) . '_' . intdiv(time(), $ttl);
    }

    private function trip(string $sessionId, string $kind): void
    {
        $this->logger->info(sprintf('limit session=%s kind=%s', substr(sha1($sessionId), 0, 12), $kind));
    }
}
