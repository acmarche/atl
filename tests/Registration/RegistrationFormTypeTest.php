<?php

namespace App\Tests\Registration;

use AcMarche\Mercredi\Entity\Security\User;
use AcMarche\Mercredi\Registration\Form\RegistrationFormType;
use AcMarche\Mercredi\Spam\TimeTrap;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Submits the registration form the way a visitor (or a robot) would,
 * with the real validator; only the database lookup of UniqueEntity is stubbed.
 */
final class RegistrationFormTypeTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testHumanSubmissionIsValid(): void
    {
        self::assertSame('', (string) $this->submit()->getErrors(true));
    }

    public function testRealNamesAreNotMistakenForRandomLetters(): void
    {
        $form = $this->submit(['nom' => 'Van Den Berghe-McDonald', 'prenom' => 'Jean-François']);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    public function testRandomLettersNameIsRefused(): void
    {
        $form = $this->submit(['nom' => 'kMfrvKUeBQpYNcikagMOFDXL']);

        self::assertStringContainsString('Ce nom ne semble pas valide.', (string) $form->get('nom')->getErrors());

        $form = $this->submit(['prenom' => 'MFNLaAuTErwIxmbBtJaFkgEd']);

        self::assertStringContainsString('Ce nom ne semble pas valide.', (string) $form->get('prenom')->getErrors());
    }

    public function testFilledHoneypotIsRefused(): void
    {
        $form = $this->submit(['code_parrainage' => 'hello']);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('code_parrainage')->getErrors());
    }

    public function testFormPostedTooFastIsRefused(): void
    {
        $form = $this->submit(wait: 1);

        self::assertFalse($form->isValid());
        self::assertStringContainsString('trop vite', (string) $form->getErrors());
    }

    public function testMissingTimestampIsRefused(): void
    {
        $form = $this->submit(['ts' => '']);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->getErrors());
    }

    private function submit(array $values = [], int $wait = TimeTrap::MIN_SECONDS + 1): FormInterface
    {
        $clock = self::mockTime();
        self::bootKernel();
        // no database in the test environment
        self::getContainer()->set('doctrine.orm.validator.unique', new class extends ConstraintValidator {
            public function validate(mixed $value, Constraint $constraint): void
            {
            }
        });

        $form = self::getContainer()->get(FormFactoryInterface::class)
            ->create(RegistrationFormType::class, new User(), ['csrf_protection' => false]);
        // the token the visitor received when the page was displayed
        $token = $form->createView()['ts']->vars['value'];
        $clock->sleep($wait);

        return $form->submit($values + [
            'nom' => 'Dupont',
            'prenom' => 'Marie',
            'email' => 'marie.dupont@example.com',
            'plainPassword' => 'Correct-Horse-42',
            'telephone' => '084000000',
            'agreeTerms' => '1',
            'code_parrainage' => '',
            'ts' => $token,
        ]);
    }
}
