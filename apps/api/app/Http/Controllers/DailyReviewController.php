<?php

namespace App\Http\Controllers;

use App\Models\DailyReview;
use App\Support\CurrentUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyReviewController extends Controller
{
    public function show(Request $request, string $date, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);
        $reviewDate = CarbonImmutable::parse($date)->toDateString();

        $review = DailyReview::query()
            ->where('user_id', $user->id)
            ->whereDate('review_date', $reviewDate)
            ->first();

        return response()->json(['data' => $review]);
    }

    public function upsert(Request $request, string $date, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);
        $reviewDate = CarbonImmutable::parse($date)->toDateString();

        $data = $request->validate([
            'mood' => ['nullable', 'integer', 'between:1,10'],
            'energy' => ['nullable', 'integer', 'between:1,10'],
            'stress' => ['nullable', 'integer', 'between:1,10'],
            'day_rating' => ['nullable', 'integer', 'between:1,10'],
            'went_well' => ['nullable', 'string'],
            'improve_tomorrow' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $review = DailyReview::query()
            ->where('user_id', $user->id)
            ->whereDate('review_date', $reviewDate)
            ->first();

        if ($review) {
            $review->update([...$data, 'completed_at' => now()]);
        } else {
            $review = DailyReview::create([
                'user_id' => $user->id,
                'review_date' => $reviewDate,
                ...$data,
                'completed_at' => now(),
            ]);
        }

        return response()->json(['data' => $review]);
    }
}
