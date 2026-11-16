<?php

namespace App\Tests\Facture;

use AcMarche\Mercredi\Entity\Facture\Facture;
use AcMarche\Mercredi\Entity\Facture\FactureCron;
use AcMarche\Mercredi\Entity\Tuteur;
use AcMarche\Mercredi\Facture\Factory\FactureFactory;
use AcMarche\Mercredi\Facture\Handler\FactureCronHandler;
use AcMarche\Mercredi\Facture\Repository\FactureCronRepository;
use AcMarche\Mercredi\Facture\Repository\FactureRepository;
use AcMarche\Mercredi\Mailer\Factory\AdminEmailFactory;
use AcMarche\Mercredi\Mailer\Factory\FactureEmailFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\RateLimiter\Policy\NoLimiter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class FactureCronHandlerTest extends KernelTestCase
{
    private const string MONTH = '01-2099';
    private const string FROM = 'expedition@example.com';

    private EntityManagerInterface $em;
    private FactureFactory $factureFactory;
    /** @var TransportInterface&object{sent: list<Email>, failInvoices: bool, failSummary: bool} */
    private TransportInterface $transport;
    private FactureCronHandler $handler;
    private ?string $previousDatabaseUrl = null;

    protected function setUp(): void
    {
        $this->previousDatabaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';

        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        new SchemaTool($this->em)->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->factureFactory = $container->get(FactureFactory::class);
        $this->transport = new class implements TransportInterface {
            /** @var list<Email> */
            public array $sent = [];
            public bool $failInvoices = false;
            public bool $failSummary = false;

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                \assert($message instanceof Email);
                // Seules les factures portent une pièce jointe
                $isInvoice = [] !== $message->getAttachments();
                if ($isInvoice ? $this->failInvoices : $this->failSummary) {
                    throw new TransportException('Connection refused');
                }
                $this->sent[] = $message;

                return null;
            }

            public function __toString(): string
            {
                return 'test://';
            }
        };

        $limiter = $this->createStub(RateLimiterFactoryInterface::class);
        $limiter->method('create')->willReturn(new NoLimiter());

        $this->handler = new FactureCronHandler(
            $container->get(FactureCronRepository::class),
            $container->get(FactureRepository::class),
            $container->get(FactureEmailFactory::class),
            $this->factureFactory,
            $container->get(AdminEmailFactory::class),
            $this->transport,
            $limiter,
        );
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->factureFactory->getBasePathFacture(self::MONTH));
        if (null === $this->previousDatabaseUrl) {
            unset($_SERVER['DATABASE_URL'], $_ENV['DATABASE_URL']);
        } else {
            $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $this->previousDatabaseUrl;
        }
        parent::tearDown();
    }

    public function testInvoiceWithoutMailIsSkippedAndCronIsClosedWithSummary(): void
    {
        $sent = $this->createFacture('Dupont', 'parent@example.com');
        $noMail = $this->createFacture('Sansmail', null);
        $cron = $this->createCron();

        $this->handler->execute();

        self::assertSame(Facture::ENVOI_OK, $sent->getEnvoiResultat());
        self::assertNotNull($sent->getEnvoyeLe());
        self::assertSame('Pas de mail', $noMail->getEnvoiResultat());
        self::assertTrue($noMail->hasEnvoiErreur());
        self::assertNull($noMail->getEnvoyeLe());

        self::assertTrue($cron->getDone());
        self::assertSame(1, $cron->results['envoyees']);
        self::assertSame(
            [['facture' => $noMail->getId(), 'tuteur' => 'Sansmail', 'erreur' => 'Pas de mail']],
            $cron->results['erreurs']
        );

        // La facture envoyée, puis le résumé à l'adresse d'expédition : aucune alerte par erreur
        self::assertCount(2, $this->transport->sent);
        self::assertSame('parent@example.com', $this->transport->sent[0]->getTo()[0]->getAddress());
        $summary = $this->transport->sent[1];
        self::assertSame(self::FROM, $summary->getTo()[0]->getAddress());
        self::assertStringContainsString('Factures envoyées : 1', $summary->getTextBody());
        self::assertStringContainsString('Factures en erreur : 1', $summary->getTextBody());
        self::assertStringContainsString('- Facture '.$noMail->getId().' (Sansmail) : Pas de mail', $summary->getTextBody());
    }

    public function testTransportFailureIsRetriedBeforeBeingAbandoned(): void
    {
        $facture = $this->createFacture('Dupont', 'parent@example.com');
        $cron = $this->createCron();
        $this->transport->failInvoices = true;

        for ($run = 1; $run < FactureCronHandler::MAX_TENTATIVES; ++$run) {
            $this->handler->execute();

            self::assertSame($run, $facture->getEnvoiTentatives());
            self::assertNull($facture->getEnvoiResultat(), 'la facture reste à envoyer');
            self::assertFalse($cron->getDone());
            self::assertSame([], $this->transport->sent);
            $cron->setDateLastSync(null);
        }

        $this->handler->execute();

        self::assertStringContainsString('Connection refused', $facture->getEnvoiResultat());
        self::assertNull($facture->getEnvoyeLe());
        self::assertTrue($cron->getDone());
        self::assertSame(0, $cron->results['envoyees']);
        self::assertCount(1, $cron->results['erreurs']);
        self::assertCount(1, $this->transport->sent, 'seul le résumé est parti');
    }

    public function testRetriedInvoiceIsSentOnceTheTransportIsBack(): void
    {
        $facture = $this->createFacture('Dupont', 'parent@example.com');
        $cron = $this->createCron();

        $this->transport->failInvoices = true;
        $this->handler->execute();
        self::assertFalse($cron->getDone());

        $this->transport->failInvoices = false;
        $cron->setDateLastSync(null);
        $this->handler->execute();

        self::assertSame(Facture::ENVOI_OK, $facture->getEnvoiResultat());
        self::assertTrue($cron->getDone());
        self::assertSame(['envoyees' => 1, 'erreurs' => []], $cron->results);
    }

    public function testCronStaysOpenUntilTheSummaryIsSent(): void
    {
        $this->createFacture('Dupont', 'parent@example.com');
        $cron = $this->createCron();

        $this->transport->failSummary = true;
        $this->handler->execute();

        self::assertFalse($cron->getDone());
        self::assertCount(1, $this->transport->sent, 'la facture est partie, pas le résumé');

        $this->transport->failSummary = false;
        $cron->setDateLastSync(null);
        $this->handler->execute();

        self::assertTrue($cron->getDone());
        self::assertCount(2, $this->transport->sent, 'la facture n\'est pas renvoyée');
        self::assertSame(self::FROM, $this->transport->sent[1]->getTo()[0]->getAddress());
    }

    private function createCron(): FactureCron
    {
        $cron = new FactureCron(self::FROM, 'Votre facture', 'Bonjour', self::MONTH);
        $cron->setUserAdd('test');
        $this->em->persist($cron);
        $this->em->flush();

        return $cron;
    }

    private function createFacture(string $nom, ?string $email): Facture
    {
        $tuteur = new Tuteur();
        $tuteur->setNom($nom);
        $tuteur->setEmail($email);
        $tuteur->setUserAdd('test');
        $this->em->persist($tuteur);

        $facture = new Facture($tuteur);
        $facture->setNom($nom);
        $facture->setMois(self::MONTH);
        $facture->setFactureLe(new \DateTime());
        $facture->setUserAdd('test');
        $this->em->persist($facture);
        $this->em->flush();

        // Le pdf existe déjà : le handler ne lance pas wkhtmltopdf
        new Filesystem()->dumpFile(
            $this->factureFactory->getBasePathFacture(self::MONTH).'facture-'.$facture->getId().'.pdf',
            '%PDF-1.4'
        );

        return $facture;
    }
}
