<?php

namespace App\Tests\Spam;

use AcMarche\Mercredi\Spam\TimeTrap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TimeTrapTest extends TestCase
{
    public function testTokenIsRefusedUntilAHumanCouldHaveFilledTheForm(): void
    {
        $clock = new MockClock();
        $trap = new TimeTrap($clock, 'secret');
        $token = $trap->generate();

        self::assertFalse($trap->isValid($token));

        $clock->sleep(TimeTrap::MIN_SECONDS);
        self::assertTrue($trap->isValid($token));

        $clock->sleep(TimeTrap::MAX_SECONDS);
        self::assertFalse($trap->isValid($token));
    }

    public function testMissingOrForgedTokenIsRefused(): void
    {
        $clock = new MockClock();
        $trap = new TimeTrap($clock, 'secret');
        $old = $clock->now()->getTimestamp() - 60;

        self::assertFalse($trap->isValid(null));
        self::assertFalse($trap->isValid(''));
        self::assertFalse($trap->isValid((string) $old));
        self::assertFalse($trap->isValid($old.'.'.hash_hmac('sha256', 'time-trap.'.$old, 'other secret')));
    }
}
