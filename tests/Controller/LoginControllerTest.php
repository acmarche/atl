<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Walks the parent sign-in page the way a browser does: email link first,
 * password behind a disclosure, errors next to the field they concern.
 */
final class LoginControllerTest extends WebTestCase
{
    private const DATABASE_URL = 'sqlite:///%kernel.project_dir%/var/login_test.db';

    private static ?string $previousDatabaseUrl = null;

    private KernelBrowser $client;

    /**
     * The test environment has no PostgreSQL: run this class against an empty
     * SQLite schema, so the page renders with no organisation, pages or users.
     */
    public static function setUpBeforeClass(): void
    {
        self::$previousDatabaseUrl = $_ENV['DATABASE_URL'] ?? null;
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = self::DATABASE_URL;

        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(\dirname(__DIR__, 2).'/var/login_test.db');
        if (null === self::$previousDatabaseUrl) {
            unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
        } else {
            $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = self::$previousDatabaseUrl;
        }
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // stateless CSRF accepts same-origin posts
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testLoginPageOffersTheEmailLinkFirst(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Se connecter');
        self::assertSelectorTextContains('h2', 'Recevoir un lien de connexion');
        self::assertSame('email', $crawler->filter('#login-email')->attr('autocomplete'));
        self::assertSame('username', $crawler->filter('#login-username')->attr('autocomplete'));
        self::assertSame('current-password', $crawler->filter('#login-password')->attr('autocomplete'));
        // the password form stays folded until the parent asks for it
        self::assertNull($crawler->filter('details.login-alt')->attr('open'));
        self::assertSelectorExists('a[href="/register"]');
        self::assertSelectorExists('a.skip-link[href="#main"]');
        self::assertSame('', $crawler->filter('#login-email')->attr('autofocus'));
        // one primary button visible at a time; the password one only shows inside the open disclosure
        self::assertCount(1, $crawler->filter('.login-section .btn-primary'));
    }

    public function testEmptyAddressIsExplainedNextToTheField(): void
    {
        $this->postEmail('');

        // Post/Redirect/Get: a refresh must not re-post the form
        self::assertResponseRedirects('/login', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#login-email-error', 'Indiquez votre adresse e-mail.');
        self::assertSame('true', $crawler->filter('#login-email')->attr('aria-invalid'));
    }

    public function testMalformedAddressIsKeptAndExplained(): void
    {
        $this->postEmail('jean.dupont');

        self::assertResponseRedirects('/login', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#login-email-error', 'ne semble pas complète');
        self::assertSame('jean.dupont', $crawler->filter('#login-email')->attr('value'));
        self::assertSelectorTextNotContains('body', 'exception');

        // the error is shown once: a reload starts clean
        $this->client->request('GET', '/login');
        self::assertSelectorNotExists('#login-email-error');
    }

    public function testUnknownAddressGetsTheSameConfirmationAsAKnownOne(): void
    {
        $this->postEmail('personne@exemple.invalid');

        self::assertResponseRedirects('/login/lien-envoye');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Vérifiez votre boîte mail');
        self::assertSelectorTextContains('.login-email', 'personne@exemple.invalid');
        self::assertSelectorExists('form[action="/login/with/email"] input[name="email"][value="personne@exemple.invalid"]');
        self::assertSelectorNotExists('.login-success');

        // "Renvoyer le lien" confirms the resend with its time
        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->filter('form[action="/login/with/email"]')->form());
        self::assertResponseRedirects('/login/lien-envoye');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.login-success', 'Lien renvoyé à');

        // "Modifier l'adresse" brings the parent back with the address filled in
        $crawler = $this->client->request('GET', '/login');
        self::assertSame('personne@exemple.invalid', $crawler->filter('#login-email')->attr('value'));
    }

    public function testLinkSentPageWithoutARequestGoesBackToLogin(): void
    {
        $this->client->request('GET', '/login/lien-envoye');

        self::assertResponseRedirects('/login');
    }

    private function postEmail(string $email): Crawler
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form[action="/login/with/email"]')->form(['email' => $email]);

        return $this->client->submit($form);
    }
}
