<?php

namespace App\Core\Enum;

enum ProductHealthStatusEnum: string
{
    case HEALTHY = 'healthy';
    case SOME_EGGS_INVALID = 'some_eggs_invalid';
    case ALL_EGGS_INVALID = 'all_eggs_invalid';
    case NO_EGGS = 'no_eggs';
    case NO_PRICES = 'no_prices';
    case NEST_UNAVAILABLE = 'nest_unavailable';
    case UNKNOWN = 'unknown';
}
