<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceDebt;
use App\Services\Finance\FinanceCounterpartyService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\FinanceTestCase;

class FinanceCounterpartyServiceTest extends FinanceTestCase
{
    public function test_directory_is_trimmed_ordered_owner_scoped_and_case_insensitively_unique(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $service = app(FinanceCounterpartyService::class);
        $service->create($owner, ['name' => '  Zebra Bank  ', 'kind' => 'bank', 'note' => '  Main  ']);
        $service->create($owner, ['name' => 'Alpha Store', 'kind' => 'store', 'note' => null]);
        $service->create($other, ['name' => 'ZEBRA BANK', 'kind' => 'bank', 'note' => null]);

        $this->assertSame(['Alpha Store', 'Zebra Bank'], $service->list($owner)->pluck('name')->all());
        $this->assertSame('Main', $service->list($owner)->last()->note);

        $this->expectException(ValidationException::class);
        $service->create($owner, ['name' => ' zebra bank ', 'kind' => 'person', 'note' => null]);
    }

    public function test_active_debt_blocks_archive_and_foreign_update_is_hidden(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $service = app(FinanceCounterpartyService::class);
        $counterparty = $service->create($owner, ['name' => 'Bank', 'kind' => 'bank', 'note' => null]);
        $debt = FinanceDebt::factory()->create([
            'user_id' => $owner->id, 'finance_counterparty_id' => $counterparty->id,
        ]);

        try {
            $service->update($owner, $counterparty, ['archived' => true]);
            $this->fail('An active debt must block counterparty archive.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('archived', $exception->errors());
        }

        $debt->forceFill(['is_archived' => true, 'archived_at' => now()])->save();
        $archived = $service->update($owner, $counterparty, ['archived' => true]);
        $this->assertTrue($archived->is_archived);
        $this->assertNotNull($archived->archived_at);

        $this->expectException(NotFoundHttpException::class);
        $service->update($other, $counterparty, ['name' => 'Leak']);
    }
}
