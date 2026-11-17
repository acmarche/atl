<?php

namespace AcMarche\Mercredi\Facture\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;

final class FactureSendAllType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->remove('to')
            ->add('force', CheckboxType::class, [
                'required' => false,
                'label' => 'Forcer l\'envoi',
                'help' => 'Si cette case est cochée, les factures déjà envoyées, mais non payées, seront envoyées à nouveau.',
            ]);
    }

    public function getParent(): ?string
    {
        return FactureSendType::class;
    }
}
