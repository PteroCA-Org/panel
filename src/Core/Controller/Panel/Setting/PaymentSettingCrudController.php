<?php

namespace App\Core\Controller\Panel\Setting;

use App\Core\Enum\SettingContextEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class PaymentSettingCrudController extends AbstractSettingCrudController
{
    protected function getSettingContext(): SettingContextEnum
    {
        return SettingContextEnum::PAYMENT;
    }
}
