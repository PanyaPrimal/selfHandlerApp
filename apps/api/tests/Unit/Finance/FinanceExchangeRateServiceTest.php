<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceExchangeRate;
use App\Services\Finance\FinanceExchangeRateService;
use Tests\Support\FinanceTestCase;

class FinanceExchangeRateServiceTest extends FinanceTestCase
{
    public function test_latest_historical_direct_inverse_identity_and_missing_lookup_are_exact(): void
    {
        $owner = $this->owner();
        FinanceExchangeRate::factory()->create([
            'user_id' => $owner->id, 'from_currency' => 'USD', 'to_currency' => 'UAH',
            'rate_date' => '2026-08-01', 'rate' => '40.000000000000',
        ]);
        FinanceExchangeRate::factory()->create([
            'user_id' => $owner->id, 'from_currency' => 'USD', 'to_currency' => 'UAH',
            'rate_date' => '2026-08-10', 'rate' => '41.000000000000',
        ]);
        $service = app(FinanceExchangeRateService::class);

        $this->assertSame('40.000000000000', $service->lookup($owner, 'USD', 'UAH', '2026-08-05')['rate']);
        $inverse = $service->lookup($owner, 'UAH', 'USD', '2026-08-13');
        $this->assertSame('inverse', $inverse['direction']);
        $this->assertSame('0.024390243902', $inverse['rate']);
        $this->assertSame('1.000000000000', $service->lookup($owner, 'EUR', 'EUR', '2026-08-13')['rate']);
        $this->assertNull($service->lookup($owner, 'EUR', 'UAH', '2026-08-13'));
        $this->assertSame('12.3457', $service->convert('10.0000', 'USD', 'EUR', [
            'rate' => '1.234567000000', 'date' => '2026-08-13', 'direction' => 'direct',
        ]));
    }
}
