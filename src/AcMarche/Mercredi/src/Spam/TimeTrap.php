<?php

namespace AcMarche\Mercredi\Spam;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signed timestamp placed in a hidden form field: a form posted faster than a human
 * can fill it in, or with a missing, forged or stale token, comes from a robot.
 */
final readonly class TimeTrap
{
    public const int MIN_SECONDS = 4;
    public const int MAX_SECONDS = 7200;

    public function __construct(
        private ClockInterface $clock,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function generate(): string
    {
        $time = (string) $this->clock->now()->getTimestamp();

        return $time.'.'.$this->sign($time);
    }

    public function isValid(?string $token): bool
    {
        [$time, $signature] = explode('.', (string) $token, 2) + [1 => ''];
        if (!ctype_digit($time) || !hash_equals($this->sign($time), $signature)) {
            return false;
        }

        $elapsed = $this->clock->now()->getTimestamp() - (int) $time;

        return $elapsed >= self::MIN_SECONDS && $elapsed <= self::MAX_SECONDS;
    }

    private function sign(string $time): string
    {
        return hash_hmac('sha256', 'time-trap.'.$time, $this->secret);
    }
}
