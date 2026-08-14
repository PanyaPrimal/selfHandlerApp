<?php

namespace App\Http\Resources\Integrations;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Integration */
class CalendarIntegrationResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $settings = Integration::normalizeSettings($this->settings);

        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'status' => $this->status,
            'account' => $this->mask($this->external_account_label),
            'calendar' => $this->external_calendar_name === null ? null : [
                'name' => $this->external_calendar_name,
                'timezone' => $settings['calendar_timezone'],
                'writable' => $settings['calendar_writable'],
            ],
            'settings' => [
                'import_detail' => $settings['import_detail'],
                'export_categories' => $settings['export_categories'],
            ],
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'last_error_code' => $this->last_error_code,
        ];
    }

    private function mask(?string $label): ?string
    {
        if ($label === null || $label === '') {
            return null;
        }
        if (str_contains($label, '@')) {
            [$local, $domain] = explode('@', $label, 2);

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        return mb_substr($label, 0, min(2, mb_strlen($label))).'***';
    }
}
