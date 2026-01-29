<?php

namespace App\Core\Service\Voucher;

use App\Core\Contract\UserInterface;
use App\Core\Entity\Voucher;
use App\Core\Enum\VoucherTypeEnum;
use Exception;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class VoucherPaymentService
{
    public function __construct(
        private VoucherService      $voucherService,
        private TranslatorInterface $translator,
    )
    {
    }

    public function getVoucher(string $voucherCode): ?Voucher
    {
        try {
            return $this->voucherService->getValidVoucher($voucherCode);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @throws Exception
     */
    public function validateVoucherCode(string $voucherCode, UserInterface $user, VoucherTypeEnum $voucherType): void
    {
        $voucher = $this->voucherService->validateVoucherForRedemption($voucherCode, null, $user);

        if ($voucher->getType() !== $voucherType) {
            throw new Exception($this->translator->trans('pteroca.voucher.invalid_voucher_type'));
        }
    }

    /**
     * @throws Exception
     */
    public function redeemPaymentVoucher(float $amount, string $voucherCode, UserInterface $user): float
    {
        $voucher = $this->voucherService->validateVoucherForRedemption($voucherCode, $amount, $user);
        $this->voucherService->redeemVoucherForUser($voucher, $user);

        return $amount * (1 - $voucher->getValue() / 100);
    }
}
