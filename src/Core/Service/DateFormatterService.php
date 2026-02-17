<?php

namespace App\Core\Service;

use DateTimeInterface;
use DateTimeZone;
use DateTime;

class DateFormatterService
{
    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    /**
     * Format DateTime according to global settings
     */
    public function formatDateTime(DateTimeInterface $date): string
    {
        // Get settings with fallbacks
        $format = $this->settingService->getSetting('date_format') ?? 'Y-m-d H:i';
        $timezone = $this->settingService->getSetting('date_timezone') ?? 'UTC';
        $showTimezone = $this->settingService->getSetting('date_show_timezone') === '1';

        // Clone date to avoid mutating original
        $dateTime = DateTime::createFromInterface($date);

        // Apply timezone conversion
        $dateTime->setTimezone(new DateTimeZone($timezone));

        // Format date
        $formatted = $dateTime->format($format);

        // Optionally append timezone abbreviation
        if ($showTimezone) {
            $formatted .= ' (' . $dateTime->format('T') . ')';
        }

        return $formatted;
    }

    /**
     * Get current date format setting
     */
    public function getDateFormat(): string
    {
        return $this->settingService->getSetting('date_format') ?? 'Y-m-d H:i';
    }

    /**
     * Get current timezone setting
     */
    public function getTimezone(): string
    {
        return $this->settingService->getSetting('date_timezone') ?? 'UTC';
    }
}
