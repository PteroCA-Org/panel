<?php

namespace App\Core\Form\Cart;

use App\Core\Enum\SettingEnum;
use App\Core\Service\SettingService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form type for balance top-up (recharge).
 *
 * Used in cart_topup route.
 */
class TopUpBalanceType extends AbstractType
{
    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $minAmount = (float) ($this->settingService
            ->getSetting(SettingEnum::MINIMUM_TOPUP_AMOUNT->value) ?? '1.00');

        $builder
            ->add('amount', NumberType::class, [
                'label' => 'pteroca.recharge.amount',
                'scale' => 2,
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(message: 'pteroca.recharge.amount_required'),
                    new Assert\Positive(message: 'pteroca.recharge.amount_must_be_positive'),
                    new Assert\Range(
                        minMessage: 'pteroca.recharge.amount_minimum_not_reached',
                        min: $minAmount
                    ),
                ],
                'attr' => [
                    'class' => 'form-control form-control-lg',
                    'placeholder' => 'pteroca.recharge.enter_amount',
                    'min' => (string) $minAmount,
                    'step' => '0.01',
                ],
            ])
            ->add('currency', HiddenType::class, [
                'data' => $options['currency'],
            ])
            ->add('voucher', HiddenType::class, [
                'required' => false,
                'data' => '',
            ])
            ->add('gateway', ChoiceType::class, [
                'label' => 'pteroca.recharge.payment_method',
                'choices' => $options['payment_gateways'],
                'required' => true,
                'expanded' => true,
                'multiple' => false,
                'constraints' => [
                    new Assert\NotBlank(message: 'pteroca.recharge.payment_method_required'),
                ],
                'choice_attr' => function() {
                    return ['class' => 'card-input-element d-none'];
                },
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'currency' => 'PLN',
            'payment_gateways' => [],
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'topup_balance',
        ]);

        $resolver->setRequired(['currency', 'payment_gateways']);
        $resolver->setAllowedTypes('currency', 'string');
        $resolver->setAllowedTypes('payment_gateways', 'array');
    }
}
