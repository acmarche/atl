<?php

namespace App\Tests\Mailer;

use AcMarche\Mercredi\Mailer\Events\MailerEventsSubscriber;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailerEventsSubscriberTest extends KernelTestCase
{
    /**
     * Mail failures must not go to the "app" channel: in prod that channel is mailed to the
     * webmaster, so a broken SMTP server would make each failure send a new failing mail.
     */
    public function testLogsOnTheMailChannel(): void
    {
        $subscriber = self::getContainer()->get(MailerEventsSubscriber::class);
        $logger = (new \ReflectionProperty($subscriber, 'mercredilogger'))->getValue($subscriber);

        self::assertInstanceOf(Logger::class, $logger);
        self::assertSame('mail', $logger->getName());
    }

    public function testSeparatesRecipientsInSentLog(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $email = (new \Symfony\Component\Mime\Email())
            ->from('sender@example.com')
            ->to('a@example.com', 'b@example.com')
            ->text('body');
        $envelope = \Symfony\Component\Mailer\Envelope::create($email);
        $sent = new \Symfony\Component\Mailer\SentMessage($email, $envelope);

        (new MailerEventsSubscriber($logger))->onMessageSent(
            new \Symfony\Component\Mailer\Event\SentMessageEvent($sent)
        );

        self::assertSame(
            ['[MERCREDI] Mail sent from sender@example.com to a@example.com, b@example.com'],
            $logger->messages
        );
    }
}
