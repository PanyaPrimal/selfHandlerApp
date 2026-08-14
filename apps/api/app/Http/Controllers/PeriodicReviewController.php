<?php

namespace App\Http\Controllers;

use App\Services\Review\PeriodicReviewWriter;
use App\Services\Review\ReviewPeriodFactory;
use App\Services\Review\ReviewPresenter;
use App\Services\Review\ReviewWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PeriodicReviewController extends Controller
{
    public function __construct(
        private readonly ReviewWorkspaceService $workspaces,
        private readonly ReviewPeriodFactory $periods,
        private readonly ReviewPresenter $presenter,
        private readonly PeriodicReviewWriter $writer,
    ) {}

    public function show(Request $request, string $period, string $anchor): JsonResponse
    {
        return response()->json(['data' => $this->workspaces->periodic($request->user(), $period, $anchor)]);
    }

    public function upsert(Request $request, string $period, string $anchor): JsonResponse
    {
        $user = $request->user();
        $canonical = $this->periods->make($period, $anchor, $user->calendarTimezone());
        $data = $request->validate([
            'period_rating' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'worked_well' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'did_not_work' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'learned' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'next_focus' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);
        foreach (['worked_well', 'did_not_work', 'learned', 'next_focus', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $trimmed = trim((string) ($data[$field] ?? ''));
                $data[$field] = $trimmed === '' ? null : $trimmed;
            }
        }
        if ($data === [] || collect($data)->every(fn (mixed $value): bool => $value === null)) {
            throw ValidationException::withMessages(['request' => __('messages.periodic_review_field_required')]);
        }

        $review = $this->writer->upsert($user, $canonical, $data);

        return response()->json(['data' => $this->presenter->periodic($review)]);
    }
}
