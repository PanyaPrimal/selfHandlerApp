<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkoutSessionMutationRequest;
use App\Http\Resources\ExerciseResource;
use App\Http\Resources\WorkoutSessionResource;
use App\Models\WorkoutProgram;
use App\Models\WorkoutSession;
use App\Services\WorkoutSessionService;
use App\Services\WorkoutStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WorkoutSessionController extends Controller
{
    public function __construct(
        private readonly WorkoutSessionService $sessions,
        private readonly WorkoutStatisticsService $statistics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'program_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            if (array_diff(array_keys($request->query()), ['from', 'to', 'program_id']) !== []) {
                $validator->errors()->add('request', __('messages.unknown_fields'));
            }
        });
        $data = $validator->validate();
        $today = CarbonImmutable::now($request->user()->calendarTimezone())->toDateString();
        $from = $data['from'] ?? CarbonImmutable::parse($today)->subDays(29)->toDateString();
        $to = $data['to'] ?? $today;
        $result = $this->statistics->forRange($request->user(), $from, $to, $data['program_id'] ?? null);
        $records = $result['records'];
        $records['exercises'] = array_map(function (array $record) use ($request): array {
            $record['exercise'] = ExerciseResource::make($record['exercise'])->resolve($request);

            return $record;
        }, $records['exercises']);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'data' => WorkoutSessionResource::collection(collect($result['sessions']))->resolve($request),
            'summary' => $result['summary'],
            'records' => $records,
        ]);
    }

    public function store(WorkoutSessionMutationRequest $request): JsonResponse
    {
        $session = $this->sessions->createManual($request->user(), $request->validated());

        return $this->one($session, $request, 201);
    }

    public function upsertPlanned(
        WorkoutSessionMutationRequest $request,
        WorkoutProgram $program,
        string $date,
    ): JsonResponse {
        $session = $this->sessions->upsertPlanned($program, $request->user(), $date, $request->validated());

        return $this->one($session, $request);
    }

    public function update(WorkoutSessionMutationRequest $request, WorkoutSession $workout): JsonResponse
    {
        $session = $this->sessions->update($workout, $request->user(), $request->validated());

        return $this->one($session, $request);
    }

    public function destroy(Request $request, WorkoutSession $workout): JsonResponse
    {
        $this->sessions->delete($workout, $request->user());

        return response()->json(null, 204);
    }

    private function one(WorkoutSession $session, Request $request, int $status = 200): JsonResponse
    {
        return response()->json(['data' => WorkoutSessionResource::make($session)->resolve($request)], $status);
    }
}
