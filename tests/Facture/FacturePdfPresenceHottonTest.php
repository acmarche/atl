<?php

namespace App\Tests\Facture;

use AcMarche\Mercredi\Contrat\Facture\FacturePdfPresenceInterface;
use AcMarche\Mercredi\Entity\Facture\Facture;
use AcMarche\Mercredi\Entity\Facture\FacturePresence;
use AcMarche\Mercredi\Entity\Organisation;
use AcMarche\Mercredi\Entity\Tuteur;
use AcMarche\Mercredi\Facture\FactureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class FacturePdfPresenceHottonTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ?string $previousDatabaseUrl = null;
    /** @var list<string> */
    private array $qrCodes = [];

    protected function setUp(): void
    {
        $this->previousDatabaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        new SchemaTool($this->em)->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $organisation = new Organisation();
        $organisation->setNom('Accueil Temps Libre');
        $organisation->setEmail('atl@example.com');
        $this->em->persist($organisation);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->qrCodes);
        if (null === $this->previousDatabaseUrl) {
            unset($_SERVER['DATABASE_URL'], $_ENV['DATABASE_URL']);
        } else {
            $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $this->previousDatabaseUrl;
        }
        parent::tearDown();
    }

    public function testTariffsPrintedAreThoseBilledOnTheInvoice(): void
    {
        $facture = $this->createFacture();
        // Des prix volontairement différents de ceux des variables MERCREDI_*_PRIX*
        $this->addLine($facture, 'Léa', FactureInterface::OBJECT_ACCUEIL, 0.75, heure: 'Matin');
        $this->addLine($facture, 'Léa', FactureInterface::OBJECT_PRESENCE, 6.25);
        $this->addLine($facture, 'Tom', FactureInterface::OBJECT_PRESENCE, 4.25);
        $this->addLine($facture, 'Tom', FactureInterface::OBJECT_PRESENCE, 4.25);
        $this->addLine($facture, 'Léa', FactureInterface::OBJECT_PRESENCE, 7.5, pedagogique: true);
        $this->em->flush();

        $text = $this->renderAsText($facture);

        self::assertStringContainsString('Accueils matin 0,75 € la demi-heure', $text);
        self::assertStringContainsString('Accueils soir 0,75 € la demi-heure', $text);
        self::assertStringContainsString('Mercredis récréatifs 6,25 €, 4,25 € (selon le nbre d\'enfants)', $text);
        self::assertStringContainsString('Journée pédagogique 7,50 € (journée ou 1/2 journée)', $text);
        self::assertStringNotContainsString('price not found', $text);
    }

    public function testNoTariffIsInventedForWhatWasNotBilled(): void
    {
        $facture = $this->createFacture();
        $this->addLine($facture, 'Léa', FactureInterface::OBJECT_PRESENCE, 6.25);
        $this->em->flush();

        $text = $this->renderAsText($facture);

        self::assertStringContainsString('Mercredis récréatifs 6,25 € (selon le nbre d\'enfants)', $text);
        self::assertStringNotContainsString('demi-heure', $text);
        self::assertStringNotContainsString('(journée ou 1/2 journée)', $text);
    }

    private function renderAsText(Facture $facture): string
    {
        $previousLocale = \Locale::getDefault();
        \Locale::setDefault('fr_BE');
        try {
            $html = self::getContainer()->get(FacturePdfPresenceInterface::class)->render($facture);
        } finally {
            \Locale::setDefault($previousLocale);
        }
        $this->qrCodes[] = self::getContainer()->getParameter('kernel.project_dir').'/public/qrcode/'.$facture->getUuid().'.png';

        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5);

        // Espaces insécables d'ICU et retours à la ligne ramenés à une espace simple
        return preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text);
    }

    private function createFacture(): Facture
    {
        $tuteur = new Tuteur();
        $tuteur->setNom('Dupont');
        $tuteur->setUserAdd('test');
        $this->em->persist($tuteur);

        $facture = new Facture($tuteur);
        $facture->setNom('Dupont');
        $facture->setMois('01-2099');
        $facture->setFactureLe(new \DateTime());
        $facture->setCommunication('+++000/0000/00097+++');
        $facture->setUserAdd('test');
        $this->em->persist($facture);

        return $facture;
    }

    private function addLine(
        Facture $facture,
        string $prenom,
        string $type,
        float $prix,
        bool $pedagogique = false,
        ?string $heure = null,
    ): void {
        static $id = 0;
        $line = new FacturePresence($facture, 1, ++$id, $type);
        $line->setNom('Dupont');
        $line->setPrenom($prenom);
        $line->setPresenceDate(new \DateTime('2099-01-07'));
        $line->setPedagogique($pedagogique);
        $line->setCoutBrut($prix);
        $line->setCoutCalculated($prix);
        if (null !== $heure) {
            $line->setHeure($heure);
            $line->setDuree(1);
        }
        $this->em->persist($line);
        $facture->addFacturePresence($line);
    }
}
