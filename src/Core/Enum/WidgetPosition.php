<?php

namespace App\Core\Enum;

enum WidgetPosition: string
{
    case TOP = 'top';
    case LEFT = 'left';
    case RIGHT = 'right';
    case BOTTOM = 'bottom';
    case FULL_WIDTH = 'full_width';
    case NAVBAR = 'navbar';
    case HERO_TOP = 'hero_top';
    case HERO_BOTTOM = 'hero_bottom';
    case BEFORE_SECTION = 'before_section';
    case AFTER_SECTION = 'after_section';
}
