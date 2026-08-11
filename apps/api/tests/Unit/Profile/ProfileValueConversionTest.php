<?php

namespace Tests\Unit\Profile;

use App\Models\UserProfile;
use PHPUnit\Framework\TestCase;

class ProfileValueConversionTest extends TestCase
{
    public function test_metric_imperial_display_round_trip_keeps_canonical_precision(): void
    {
        $meters = 1.725;
        $grams = 68400;
        $inches = $meters * 39.37007874;
        $pounds = ($grams / 1000) * 2.2046226218;

        $this->assertEqualsWithDelta($meters, $inches / 39.37007874, 0.0005);
        $this->assertEqualsWithDelta($grams, ($pounds / 2.2046226218) * 1000, 0.5);
    }

    public function test_formula_readiness_is_derived_without_a_persisted_cache(): void
    {
        $profile = new UserProfile([
            'bmr_formula' => 'mifflin_st_jeor',
            'date_of_birth' => '1990-01-01',
            'sex' => 'female',
            'height_meters' => 1.725,
            'weight_grams' => 68400,
            'baseline_activity' => 'moderate',
        ]);

        $this->assertTrue($profile->calculationReady());
        $this->assertSame([], $profile->missingCalculationFields());

        $profile->bmr_formula = 'katch_mcardle';
        $this->assertFalse($profile->calculationReady());
        $this->assertSame(['body_fat_percentage'], $profile->missingCalculationFields());
    }
}
