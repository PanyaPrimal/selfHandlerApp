<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceDebt;
use App\Services\Finance\FinanceDebtScheduleService;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceTestCase;

class FinanceDebtScheduleServiceTest extends FinanceTestCase
{
    public function test_valid_dates_skip_absent_monthdays_and_preserve_exact_count(): void
    {
        $dates = app(FinanceDebtScheduleService::class)->dates([
            'first_due_on' => '2024-01-31', 'installment_count' => 5,
            'interval_months' => 1, 'monthday' => 31,
        ]);

        $this->assertSame(
            ['2024-01-31', '2024-03-31', '2024-05-31', '2024-07-31', '2024-08-31'],
            $dates,
        );
    }

    public function test_schedule_rejects_wrong_total_day_bounds_and_ten_year_overflow(): void
    {
        $debt = FinanceDebt::factory()->make([
            'original_amount' => '300.0000', 'originated_on' => '2026-01-01',
        ]);
        $service = app(FinanceDebtScheduleService::class);
        $invalid = [
            ['installment_amount' => '99.0000', 'installment_count' => 3, 'interval_months' => 1,
                'monthday' => 31, 'first_due_on' => '2026-01-31', 'reminder_time' => null],
            ['installment_amount' => '100.0000', 'installment_count' => 3, 'interval_months' => 1,
                'monthday' => 31, 'first_due_on' => '2026-01-30', 'reminder_time' => null],
            ['installment_amount' => '2.5000', 'installment_count' => 120, 'interval_months' => 12,
                'monthday' => 31, 'first_due_on' => '2026-01-31', 'reminder_time' => null],
        ];

        foreach ($invalid as $schedule) {
            try {
                $service->validate($debt, $schedule);
                $this->fail('Invalid fixed schedule was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
}
