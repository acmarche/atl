<?php

namespace App\Tests\Mailer;

use AcMarche\Mercredi\Mailer\Debug\MailDebugger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class MailDebuggerTest extends KernelTestCase
{
    private MailDebugger $debugger;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->debugger = self::getContainer()->get(MailDebugger::class);
        $this->mailerLimiter()->create()->reset();
    }

    protected function tearDown(): void
    {
        $this->mailerLimiter()->create()->reset();
        parent::tearDown();
    }

    public function testTheAsyncQueueIsRateLimited(): void
    {
        self::assertTrue(self::getContainer()->get('messenger.rate_limiter_locator')->has('async'));
    }

    public function testHourlyQuotaDefaultsTo100(): void
    {
        self::assertSame(['remaining' => 100, 'limit' => 100], $this->debugger->hourlyQuota());
    }

    public function testDirectSendUsesTheQuota(): void
    {
        $this->debugger->sendDirect($this->debugger->createTestEmail('a@example.com', 'b@example.com', 'Test'));

        self::assertSame(99, $this->debugger->hourlyQuota()['remaining']);
    }

    public function testQueueIsNotProcessedWhenTheQuotaIsUsedUp(): void
    {
        $this->mailerLimiter()->create()->consume(100);

        self::assertStringContainsString('Limite horaire', $this->debugger->consumeQueue());
        $result = $this->debugger->sendDirect($this->debugger->createTestEmail('a@example.com', 'b@example.com', 'Test'));
        self::assertFalse($result['success']);
    }

    private function mailerLimiter(): RateLimiterFactoryInterface
    {
        return self::getContainer()->get('limiter.mailer');
    }

    public function testReportsThatMailsAreSentDirectly(): void
    {
        self::assertSame([], $this->debugger->mailRouting());
        self::assertStringNotContainsString('secret', $this->debugger->maskedDsn());
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['smtp://user%40x.be:secret@ssl0.ovh.net:465', 'smtp://user%40x.be:****@ssl0.ovh.net:465'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['smtp://user@x.be:secret@ssl0.ovh.net:587', 'smtp://user@x.be:****@ssl0.ovh.net:587'], 'raw e-mail address as user')]
    #[\PHPUnit\Framework\Attributes\TestWith(['smtp://user:se@cr:et%!@ssl0.ovh.net:587', 'smtp://user:****@ssl0.ovh.net:587'], 'password with @ and :')]
    #[\PHPUnit\Framework\Attributes\TestWith(['smtp://user@x.be:se@cret@ssl0.ovh.net:587?verify_peer=0', 'smtp://user@x.be:****@ssl0.ovh.net:587?verify_peer=0'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['ovh://default', 'ovh://default'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['null://null', 'null://null'])]
    public function testMasksThePasswordOfTheDsn(string $dsn, string $expected): void
    {
        $debugger = new \ReflectionClass(MailDebugger::class);
        $instance = $debugger->newInstanceWithoutConstructor();
        $debugger->getProperty('mailerDsn')->setValue($instance, $dsn);

        self::assertSame($expected, $instance->maskedDsn());
        self::assertStringNotContainsString('cret', $instance->maskedDsn());
    }

    public function testSendsDirectlyAndReportsSuccess(): void
    {
        $email = $this->debugger->createTestEmail('factures@example.com', 'parent@example.com', 'Test');

        $result = $this->debugger->sendDirect($email);

        self::assertTrue($result['success']);
        self::assertNull($result['error']);
    }

    public function testQueueNeverThrows(): void
    {
        // Without a database the error is returned so the page can show it
        $queue = $this->debugger->queue('async');

        self::assertArrayHasKey('error', $queue);
        self::assertIsArray($queue['messages']);
        if (null === $queue['error']) {
            self::assertIsInt($queue['count']);
        }
    }
}
