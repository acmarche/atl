<?php

namespace App\Tests\Mailer;

use AcMarche\Mercredi\Entity\Organisation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class EmailLayoutTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    public function testResetPasswordEmailHasAClickableInlinedButton(): void
    {
        $html = $this->twig->render('@AcMarcheMercrediEmail/front/request_password.html.twig', [
            'email' => ['subject' => 'Réinitialisation de votre mot de passe'],
            'resetToken' => new class {
                public string $token = 'tok123';
            },
            'organisation' => $this->organisation(),
        ]);

        self::assertDoesNotMatchRegularExpression('/<button[^>]*href/', $html);
        self::assertMatchesRegularExpression(
            '#<a href="[^"]*/tok123" class="btn btn-primary" style="[^"]*background-color: \#c2410c#',
            $html,
            'The reset link must be a real anchor with its button styles inlined.',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function welcomeTemplates(): iterable
    {
        yield 'auto login' => ['welcome/_mail_auto_login.html.twig', 'Me connecter'];
        yield 'welcome animateur' => ['welcome/_mail_welcome_animateur.html.twig', 'Me connecter'];
        yield 'welcome parent' => ['welcome/_mail_welcome_parent.html.twig', 'Me connecter et compléter mes informations'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('welcomeTemplates')]
    public function testWelcomeEmailsShowTheLoginLinkAsAButton(string $template, string $label): void
    {
        $html = $this->twig->render('@AcMarcheMercrediEmail/'.$template, [
            'email' => ['subject' => 'Bienvenue'],
            'user' => new class {
                public string $prenom = 'Marie';
                public string $nom = 'Dupont';
                public string $email = 'marie@example.com';
            },
            'tokenUrl' => 'https://example.com/login/tok456',
            'password' => null,
            'organisation' => $this->organisation(),
        ]);

        self::assertStringNotContainsString('alert-info', $html);
        self::assertMatchesRegularExpression(
            '#<a href="https://example.com/login/tok456" class="btn btn-primary" style="[^"]*background-color: \#c2410c[^"]*">'.preg_quote($label, '#').'</a>#',
            $html,
        );
    }

    public function testLayoutUsesSubjectAsTitleAndHeading(): void
    {
        $html = $this->twig->render('@AcMarcheMercrediEmail/admin/mail.html.twig', [
            'email' => ['subject' => 'Inscriptions de Noël'],
            'texte' => "Bonjour,\nÀ bientôt",
            'organisation' => $this->organisation(),
        ]);

        self::assertStringContainsString('<html lang="fr"', $html);
        self::assertStringContainsString('<title>Inscriptions de Noël</title>', $html);
        self::assertMatchesRegularExpression('#<h1[^>]*>\s*Inscriptions de Noël\s*</h1>#', $html);
        self::assertStringNotContainsString('Company', $html);
        self::assertStringContainsString('© '.date('Y').' ATL Marche', $html);
    }

    public function testLayoutFallsBackToDefaultNameWithoutOrganisation(): void
    {
        $html = $this->twig->render('@AcMarcheMercrediEmail/front/contact.html.twig', [
            'email' => ['subject' => 'Contact'],
            'courriel' => 'parent@example.com',
            'body' => 'Message',
        ]);

        self::assertStringContainsString('parent@example.com', $html);
        self::assertMatchesRegularExpression('#class="em-brand"[^>]*>\s*Accueil Temps Libre\s*</p>#', $html);
        self::assertStringContainsString('© '.date('Y').' Accueil Temps Libre', $html);
    }

    private function organisation(): Organisation
    {
        $organisation = new Organisation();
        $organisation->setNom('ATL Marche');

        return $organisation;
    }
}
