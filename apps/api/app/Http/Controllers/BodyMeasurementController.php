<?php

namespace App\Http\Controllers;

use App\Models\BodyMeasurement;
use App\Services\BodyTrendService;
use App\ValueObjects\BodyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class BodyMeasurementController extends Controller
{
    private const DEFAULT_WINDOW_DAYS = 365;

    public function __construct(private readonly BodyTrendService $trends) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $this->validatedWindow($request);
        [$from, $to] = $this->window($user->calendarTimezone(), $filters);

        $measurements = BodyMeasurement::query()
            ->ownedBy($user)
            ->when($filters['metric'] ?? null, fn ($query, $metric) => $query->where('metric', $metric))
            ->whereBetween('measured_on', [$from, $to])
            ->orderBy('measured_on')
            ->orderBy('metric')
            // Always bounded: history grows without limit otherwise.
            ->limit($filters['limit'] ?? 1000)
            ->get(['id', 'metric', 'measured_on', 'value', 'note']);

        return response()->json([
            'data' => $measurements,
            'metrics' => BodyMetric::catalogue(),
            'today' => CarbonImmutable::now($user->calendarTimezone())->toDateString(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Record an observation, or correct the one already stored for that day.
     *
     * There is one value per user, metric and calendar date, so saving the same
     * combination again replaces it rather than creating a second observation.
     */
    public function upsert(Request $request): JsonResponse
    {
        $user = $request->user();

        $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();

        $data = $request->validate([
            'metric' => ['required', Rule::in(BodyMetric::values())],
            // An observation is something that already happened, so a date after
            // the user's own today is rejected rather than stored where the
            // default history window would never show it.
            'measured_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'value' => ['required', 'numeric'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], [
            'measured_on.before_or_equal' => 'A measurement cannot be dated in the future.',
        ]);

        $metric = BodyMetric::from($data['metric']);

        $request->validate([
            'value' => [
                'numeric',
                'min:'.$metric->minimum(),
                'max:'.$metric->maximum(),
            ],
        ], [
            'value.min' => "That {$metric->label()} value looks too low. Check the units and try again.",
            'value.max' => "That {$metric->label()} value looks too high. Check the units and try again.",
        ]);

        $measurement = BodyMeasurement::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'metric' => $metric->value,
                'measured_on' => $data['measured_on'],
            ],
            [
                'value' => $data['value'],
                'note' => $data['note'] ?? null,
            ],
        );

        return response()->json(['data' => $measurement->fresh()]);
    }

    public function destroy(Request $request, BodyMeasurement $measurement): Response
    {
        abort_unless($measurement->isOwnedBy($request->user()), 404);

        $measurement->delete();

        return response()->noContent();
    }

    public function trend(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $this->validatedWindow($request, metricRequired: true);
        [$from, $to] = $this->window($user->calendarTimezone(), $filters);

        return response()->json(
            $this->trends->for($user, BodyMetric::from($filters['metric']), $from, $to),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedWindow(Request $request, bool $metricRequired = false): array
    {
        return $request->validate([
            'metric' => [$metricRequired ? 'required' : 'sometimes', Rule::in(BodyMetric::values())],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: string}
     */
    private function window(string $timezone, array $filters): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $to = $filters['to'] ?? $today->toDateString();
        $from = $filters['from'] ?? CarbonImmutable::parse($to, $timezone)
            ->subDays(self::DEFAULT_WINDOW_DAYS)
            ->toDateString();

        return $from <= $to ? [$from, $to] : [$to, $from];
    }
}
