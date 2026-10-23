<?php

namespace Tests\Unit;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Services\PremiumCalculator;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    /**
     * Test the exact example from the specification.
     * PLUS, 5 days, age 28, INTERNATIONAL, ₹300,000
     * Expected: 20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00
     */
    public function test_spec_example_plus_international_300k(): void
    {
        $premium = $this->calculator->calculate(
            PlanCode::PLUS,
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-14'),
            Carbon::parse('1998-04-10'),
            Destination::INTERNATIONAL,
            300000,
        );

        $this->assertEquals('225.00', $premium);
    }

    /**
     * BASIC, 1 day, age 10, DOMESTIC, ₹100,000
     * Expected: 12 × 1 × 1.10 × 1.00 × 1.00 = ₹13.20
     */
    public function test_basic_domestic_child(): void
    {
        $premium = $this->calculator->calculate(
            PlanCode::BASIC,
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2016-05-01'),
            Destination::DOMESTIC,
            100000,
        );

        $this->assertEquals('13.20', $premium);
    }

    /**
     * PREMIUM, 3 days, age 65, INTERNATIONAL, ₹500,000
     * Expected: 30 × 3 × 1.50 × 1.80 × 1.50 = ₹364.50
     */
    public function test_premium_international_senior(): void
    {
        $premium = $this->calculator->calculate(
            PlanCode::PREMIUM,
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-12'),
            Carbon::parse('1961-03-15'),
            Destination::INTERNATIONAL,
            500000,
        );

        $this->assertEquals('364.50', $premium);
    }

    /**
     * BASIC, 7 days, age 75, DOMESTIC, ₹300,000
     * Expected: 12 × 7 × 2.00 × 1.00 × 1.25 = ₹210.00
     */
    public function test_basic_domestic_elderly(): void
    {
        $premium = $this->calculator->calculate(
            PlanCode::BASIC,
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-16'),
            Carbon::parse('1951-01-01'),
            Destination::DOMESTIC,
            300000,
        );

        $this->assertEquals('210.00', $premium);
    }

    /**
     * Same-day trip should count as 1 day.
     */
    public function test_same_day_trip_counts_as_one_day(): void
    {
        $premium = $this->calculator->calculate(
            PlanCode::BASIC,
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('1990-01-01'),
            Destination::DOMESTIC,
            100000,
        );

        // 12 × 1 × 1.00 × 1.00 × 1.00 = 12.00
        $this->assertEquals('12.00', $premium);
    }

    /**
     * Unsupported sum_insured should throw an exception.
     */
    public function test_unsupported_sum_insured_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sum_insured value: 200000');

        $this->calculator->calculate(
            PlanCode::BASIC,
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-14'),
            Carbon::parse('1990-01-01'),
            Destination::DOMESTIC,
            200000,
        );
    }

    /**
     * Age exactly 18 should use 1.00 multiplier.
     */
    public function test_age_boundary_18(): void
    {
        $premium = $this->calculator->calculate(
            PlanCode::BASIC,
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('2008-11-10'), // exactly 18 on trip start
            Destination::DOMESTIC,
            100000,
        );

        // 12 × 1 × 1.00 × 1.00 × 1.00 = 12.00
        $this->assertEquals('12.00', $premium);
    }

    /**
     * Age exactly 61 should use 1.50 multiplier.
     */
    public function test_age_boundary_61(): void
    {
        $premium = $this->calculator->calculate(
            PlanCode::BASIC,
            Carbon::parse('2026-11-10'),
            Carbon::parse('2026-11-10'),
            Carbon::parse('1965-11-10'), // exactly 61 on trip start
            Destination::DOMESTIC,
            100000,
        );

        // 12 × 1 × 1.50 × 1.00 × 1.00 = 18.00
        $this->assertEquals('18.00', $premium);
    }
}
