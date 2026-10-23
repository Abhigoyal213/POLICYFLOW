<?php

namespace App\Enums;

enum PlanCode: string
{
    case BASIC = 'BASIC';
    case PLUS = 'PLUS';
    case PREMIUM = 'PREMIUM';

    /**
     * Get the base daily premium rate for this plan.
     */
    public function baseDailyRate(): int
    {
        return match ($this) {
            self::BASIC => 12,
            self::PLUS => 20,
            self::PREMIUM => 30,
        };
    }
}
