<?php

namespace App\Services\Analytics;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AnalyticsQuery
{
    public function __construct(private readonly AnalyticsCatalog $catalog) {}

    /** @return array{metric:string,from:string,to:string,granularity:string,compare:bool} */
    public function workspace(Request $request, User $user): array
    {
        $timezone = $user->calendarTimezone();
        $today = CarbonImmutable::now($timezone)->toDateString();
        $data = [
            'metric' => $request->query('metric', AnalyticsCatalog::DEFAULT_METRIC),
            'to' => $request->query('to', $today),
            'from' => $request->query('from', CarbonImmutable::parse($today, $timezone)->subDays(29)->toDateString()),
            'granularity' => $request->query('granularity', 'daily'),
            'compare' => $this->normalizeBoolean($request->query('compare', true)),
        ];
        $validator = Validator::make($data, [
            'metric' => ['required', 'string', 'in:'.implode(',', $this->catalog->keys())],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'granularity' => ['required', 'in:daily,weekly,monthly'],
            'compare' => ['required', 'boolean'],
        ]);
        $validator->after(function ($validator) use ($data, $timezone): void {
            $this->validateRange($validator, (string) $data['from'], (string) $data['to'], $timezone,
                $this->catalog->limits()[($data['granularity'] ?? 'daily').'_days'] ?? 0);
        });
        $validated = $validator->validate();

        return [
            'metric' => $validated['metric'], 'from' => $validated['from'], 'to' => $validated['to'],
            'granularity' => $validated['granularity'], 'compare' => filter_var($validated['compare'], FILTER_VALIDATE_BOOL),
        ];
    }

    /** @return array{from:string,to:string} */
    public function correlations(Request $request, User $user): array
    {
        $timezone = $user->calendarTimezone();
        $today = CarbonImmutable::now($timezone)->toDateString();
        $data = [
            'to' => $request->query('to', $today),
            'from' => $request->query('from', CarbonImmutable::parse($today, $timezone)->subDays(29)->toDateString()),
        ];
        $validator = Validator::make($data, [
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d'],
        ]);
        $validator->after(function ($validator) use ($data, $timezone): void {
            $this->validateRange($validator, (string) $data['from'], (string) $data['to'], $timezone,
                $this->catalog->limits()['correlation_days']);
        });

        return $validator->validate();
    }

    private function validateRange($validator, string $from, string $to, string $timezone, int $maximum): void
    {
        try {
            $first = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone);
            $last = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone);
        } catch (\Throwable) {
            return;
        }
        if (! $first || ! $last || $first->format('Y-m-d') !== $from || $last->format('Y-m-d') !== $to) {
            return;
        }
        if ($first->gt($last) || $first->diffInDays($last) + 1 > $maximum) {
            $validator->errors()->add('to', __('messages.analytics_range_invalid', ['days' => $maximum]));
        }
    }

    private function normalizeBoolean(mixed $value): mixed
    {
        return match ($value) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => $value,
        };
    }
}
