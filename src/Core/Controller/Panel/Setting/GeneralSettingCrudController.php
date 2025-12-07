<?php

namespace App\Core\Controller\Panel\Setting;

use App\Core\Enum\SettingContextEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class GeneralSettingCrudController extends AbstractSettingCrudController
{
    protected function getSettingContext(): SettingContextEnum
    {
        return SettingContextEnum::GENERAL;
    }
}
