<?php

namespace App\Core\Enum;

enum SettingTypeEnum: string
{
    case TEXT = 'text';
    case TEXTAREA = 'textarea';
    case CODE = 'code';
    case SECRET = 'secret';
    case COLOR = 'color';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case LOCALE = 'locale';
    case TWIG = 'twig';
    case URL = 'url';
    case EMAIL = 'email';
    case IMAGE = 'image';
    case SELECT = 'select';

    public static function getValues(): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            $values[ucfirst(strtolower($case->name))] = $case->value;
        }
        return $values;
    }
}
