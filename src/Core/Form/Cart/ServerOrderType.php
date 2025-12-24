<?php

namespace App\Core\Form\Cart;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form type for server ordering/configuration.
 *
 * Used in cart_configure and cart_buy routes.
 */
class ServerOrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', HiddenType::class, [
                'data' => $options['product_id'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                ],
            ])
            ->add('egg', ChoiceType::class, [
                'choices' => $options['eggs'],
                'label' => false,
                'required' => true,
                'mapped' => false,
                'constraints' => [
                    new Assert\NotBlank(message: 'pteroca.store.please_select_game'),
                ],
                'attr' => [
                    'style' => 'display:none',
                ],
                'label_attr' => [
                    'style' => 'display:none',
                ],
            ])
            ->add('duration', ChoiceType::class, [
                'choices' => $options['prices'],
                'label' => false,
                'required' => true,
                'mapped' => false,
                'constraints' => [
                    new Assert\NotBlank(message: 'pteroca.store.please_select_duration'),
                ],
                'attr' => [
                    'style' => 'display:none',
                ],
                'label_attr' => [
                    'style' => 'display:none',
                ],
            ])
            ->add('server-name', TextType::class, [
                'label' => 'pteroca.store.server_name',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(message: 'pteroca.store.server_name_required'),
                    new Assert\Length(
                        min: 3,
                        max: 50,
                        minMessage: 'pteroca.store.server_name_too_short',
                        maxMessage: 'pteroca.store.server_name_too_long'
                    ),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'pteroca.store.enter_server_name',
                ],
            ])
            ->add('auto-renewal', ChoiceType::class, [
                'label' => 'pteroca.cart_configuration.auto_renewal',
                'choices' => [
                    'pteroca.cart_configuration.enable' => '1',
                    'pteroca.cart_configuration.disable' => '0',
                ],
                'data' => '0',
                'required' => true,
                'attr' => [
                    'class' => 'form-select form-select-lg',
                ],
            ])
            ->add('voucher', HiddenType::class, [
                'required' => false,
                'data' => '',
            ]);

        // Add slots field only if product has slot pricing
        if ($options['has_slot_prices']) {
            $builder->add('slots', IntegerType::class, [
                'label' => 'pteroca.store.slots',
                'required' => true,
                'data' => $options['initial_slots'],
                'constraints' => [
                    new Assert\NotBlank(message: 'pteroca.store.slots_required'),
                    new Assert\Positive(message: 'pteroca.store.slots_must_be_positive'),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'style' => 'display:none',
                ],
                'label_attr' => [
                    'style' => 'display:none',
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'product_id' => null,
            'eggs' => [],
            'prices' => [],
            'has_slot_prices' => false,
            'initial_slots' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'server_order',
        ]);

        $resolver->setRequired(['product_id', 'eggs', 'prices']);
        $resolver->setAllowedTypes('product_id', 'int');
        $resolver->setAllowedTypes('eggs', 'array');
        $resolver->setAllowedTypes('prices', 'array');
        $resolver->setAllowedTypes('has_slot_prices', 'bool');
        $resolver->setAllowedTypes('initial_slots', ['int', 'null']);
    }
}
