<?php

namespace AcMarche\Mercredi\Registration\Form;

use AcMarche\Mercredi\Spam\TimeTrap;
use AcMarche\Mercredi\User\Form\UserType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Constraints\Blank;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class RegistrationFormType extends AbstractType
{
    public function __construct(
        private RouterInterface $router,
        private TimeTrap $timeTrap,
    ) {
    }

    public function buildForm(FormBuilderInterface $formBuilder, array $options): void
    {
        $url = $this->router->generate('mercredi_front_modalite');

        $formBuilder
            ->remove('roles')
            ->add(
                'nom',
                TextType::class,
                [
                    'required' => true,
                    'label' => 'Nom du parent',
                    'constraints' => [$this->notGibberish()],
                ]
            )
            ->add(
                'prenom',
                TextType::class,
                [
                    'required' => true,
                    'label' => 'Prénom du parent',
                    'constraints' => [$this->notGibberish()],
                ]
            )
            ->add(
                'telephone',
                TextType::class,
                [
                    'label' => 'Téléphone',
                    'required' => true,
                ]
            )
            ->add(
                'agreeTerms',
                CheckboxType::class,
                [
                    'label' => 'J\'accepte les conditions d\'utilisation',
                    'help_html' => true,
                    'help' => '<a href="'.$url.'" target="_blank">Lire les conditions d\'utilisation</a>',
                    'mapped' => false,
                    'constraints' => [
                        new IsTrue(message: 'You should agree to our terms.'),
                    ],
                ]
            )
            // Honeypot: hidden from humans, robots fill in every field they find
            ->add(
                'code_parrainage',
                TextType::class,
                [
                    'label' => 'Code de parrainage',
                    'mapped' => false,
                    'required' => false,
                    'attr' => ['autocomplete' => 'off', 'tabindex' => -1],
                    'constraints' => [new Blank(message: 'Filtre anti-spam invalide.')],
                ]
            )
            ->add(
                'ts',
                HiddenType::class,
                [
                    'mapped' => false,
                    'constraints' => [
                        new Callback(function (?string $token, ExecutionContextInterface $context): void {
                            if (!$this->timeTrap->isValid($token)) {
                                $context->buildViolation('Le formulaire a été envoyé trop vite ou a expiré, veuillez réessayer.')
                                    ->addViolation();
                            }
                        }),
                    ],
                ]
            );
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        // Always a fresh token, also when the form is displayed again with errors
        $view['ts']->vars['value'] = $this->timeTrap->generate();
    }

    /**
     * Robots register with random letters such as "kMfrvKUeBQpYNcikagMOFDXL":
     * no real name switches from lowercase to uppercase three times.
     */
    private function notGibberish(): Regex
    {
        return new Regex(
            pattern: '/(\p{Ll}\p{Lu}.*){3}/u',
            message: 'Ce nom ne semble pas valide.',
            match: false,
        );
    }

    public function getParent(): ?string
    {
        return UserType::class;
    }
}
