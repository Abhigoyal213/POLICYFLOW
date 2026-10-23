<?php

namespace App\Services;

use App\Enums\Destination;
use App\Enums\PlanCode;
use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculator
{
    /**
     * Supported coverage amounts and their multipliers.
     */
    private const COVERAGE_MULTIPLIERS = [
        100000 => '1.00',
        300000 => '1.25',
        500000 => '1.50',
    ];

    /**
     * Calculate the premium using decimal-safe arithmetic.
     *
     * Formula: base_daily_rate × travel_days × age_multiplier × destination_multiplier × coverage_multiplier
     *
     * @throws InvalidArgumentException if sum_insured is not a supported value
     */
    public function calculate(
        PlanCode $plan,
        Carbon $tripStart,
        Carbon $tripEnd,
        Carbon $dateOfBirth,
        Destination $destination,
        int $sumInsured,
    ): string {
        // Validate coverage amount
        if (!isset(self::COVERAGE_MULTIPLIERS[$sumInsured])) {
            $supported = implode(', ', array_keys(self::COVERAGE_MULTIPLIERS));
            throw new InvalidArgumentException(
                "Unsupported sum_insured value: {$sumInsured}. Supported values: {$supported}."
            );
        }

        $baseDailyRate = (string) $plan->baseDailyRate();
        $travelDays = (string) max(1, $tripStart->diffInDays($tripEnd) + 1);
        $ageMultiplier = $this->getAgeMultiplier($dateOfBirth, $tripStart);
        $destinationMultiplier = $destination->multiplier();
        $coverageMultiplier = self::COVERAGE_MULTIPLIERS[$sumInsured];

        // Decimal-safe multiplication chain using bcmul (scale 10 for intermediates)
        $result = bcmul($baseDailyRate, $travelDays, 10);
        $result = bcmul($result, $ageMultiplier, 10);
        $result = bcmul($result, $destinationMultiplier, 10);
        $result = bcmul($result, $coverageMultiplier, 10);

        // Round to 2 decimal places
        return $this->bcround($result, 2);
    }

    /**
     * Determine the age multiplier based on age at trip start.
     */
    private function getAgeMultiplier(Carbon $dateOfBirth, Carbon $tripStart): string
    {
        $age = $dateOfBirth->diffInYears($tripStart);

        return match (true) {
            $age <= 17 => '1.10',
            $age <= 60 => '1.00',
            $age <= 70 => '1.50',
            default    => '2.00',
        };
    }

    /**
     * Round a bcmath string to the given number of decimal places.
     */
    private function bcround(string $value, int $precision): string
    {
        if ($precision < 0) {
            $precision = 0;
        }

        // Add 0.5 * 10^(-precision) and truncate
        $half = bcdiv('1', bcpow('10', (string) $precision, 0), $precision + 1);
        $half = bcdiv($half, '2', $precision + 1);

        // Determine sign
        if (bccomp($value, '0', 10) >= 0) {
            $rounded = bcadd($value, $half, $precision);
        } else {
            $rounded = bcsub($value, $half, $precision);
        }

        return $rounded;
    }
}
