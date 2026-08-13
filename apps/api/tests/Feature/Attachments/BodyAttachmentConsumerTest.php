<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Services\Attachments\AttachmentService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Support\AttachmentTestCase;

class BodyAttachmentConsumerTest extends AttachmentTestCase
{
    public function test_body_reads_add_ordered_attachments_without_changing_body_fact_or_trend(): void
    {
        $owner = $this->user();
        $owner->ensureProfile()->update(['timezone' => 'UTC']);
        $body = $this->measurement($owner);
        $service = app(AttachmentService::class);
        $service->upload($owner, 'body_measurement', $body->id, 'first', $this->image());
        $service->upload($owner, 'body_measurement', $body->id, 'second', $this->image('second.png'));
        $before = Arr::only(
            $body->attributesToArray(), ['metric', 'measured_on', 'value', 'note'],
        );
        $this->actingAs($owner);

        $response = $this->getJson('/api/body/measurements?from=2026-08-13&to=2026-08-13')
            ->assertOk()->assertJsonCount(2, 'data.0.attachments');
        $this->assertSame('first', AttachmentKey::fromContentUrl($response->json('data.0.attachments.0.content_url'), $owner));
        $this->assertSame($before, Arr::only(
            $body->fresh()->attributesToArray(), ['metric', 'measured_on', 'value', 'note'],
        ));
        $this->getJson('/api/body/trend?metric=body_mass&from=2026-08-13&to=2026-08-13')
            ->assertOk()->assertJsonPath('state', 'insufficient');
    }

    public function test_attachment_projection_has_a_fixed_query_budget_for_twenty_measurements(): void
    {
        $owner = $this->user();
        $owner->ensureProfile()->update(['timezone' => 'UTC']);
        foreach (range(1, 20) as $index) {
            $measurement = $this->measurement($owner, [
                'metric' => 'waist', 'measured_on' => sprintf('2026-07-%02d', $index),
            ]);
            app(AttachmentService::class)->upload(
                $owner, 'body_measurement', $measurement->id, "body-{$index}", $this->image(),
            );
        }
        $this->actingAs($owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/body/measurements?metric=waist&from=2026-07-01&to=2026-07-20')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, $count);
    }
}

final class AttachmentKey
{
    public static function fromContentUrl(string $url, $owner): string
    {
        preg_match('#/attachments/(\d+)/content$#', $url, $matches);

        return Attachment::query()->where('user_id', $owner->id)
            ->findOrFail((int) $matches[1])->upload_key;
    }
}
