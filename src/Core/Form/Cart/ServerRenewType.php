<?php

namespace App\Core\Form\Cart;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form type for server renewal.
 *
 * Used in cart_renew and cart_renew_buy routes.
 */
class ServerRenewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', HiddenType::class, [
                'data' => $options['server_id'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                ],
            ]);

        if ($options['allow_auto_renewal']) {
            $builder->add('auto-renewal', ChoiceType::class, [
                'label' => 'pteroca.cart_configuration.auto_renewal',
                'choices' => [
                    'pteroca.cart_configuration.enable' => '1',
                    'pteroca.cart_configuration.disable' => '0',
                ],
                'data' => $options['current_auto_renewal'] ? '1' : '0',
                'required' => $options['is_owner'],
                'disabled' => !$options['is_owner'],
                'attr' => [
                    'class' => 'form-select form-select-lg',
                ],
            ]);
        }

        $builder->add('voucher', HiddenType::class, [
                'required' => false,
                'data' => '',
            ]);

        // Add server-slots field only if server has slot pricing
        if ($options['has_slot_pricing']) {
            $builder->add('server-slots', IntegerType::class, [
                'label' => 'pteroca.product.slots',
                'required' => true,
                'data' => $options['server_slots'],
                'attr' => [
                    'class' => 'form-control form-control-lg',
                    'readonly' => true,
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'server_id' => null,
            'current_auto_renewal' => false,
            'is_owner' => false,
            'has_slot_pricing' => false,
            'server_slots' => null,
            'allow_auto_renewal' => true,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'server_renew',
        ]);

        $resolver->setRequired(['server_id', 'current_auto_renewal', 'is_owner']);
        $resolver->setAllowedTypes('server_id', 'int');
        $resolver->setAllowedTypes('current_auto_renewal', 'bool');
        $resolver->setAllowedTypes('is_owner', 'bool');
        $resolver->setAllowedTypes('has_slot_pricing', 'bool');
        $resolver->setAllowedTypes('server_slots', ['int', 'null']);
        $resolver->setAllowedTypes('allow_auto_renewal', 'bool');
    }
}
