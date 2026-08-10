<?php

namespace App\Http\Controllers;

use App\Models\DailyReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DailyReviewController extends Controller
{
    public function show(Request $request, string $date): JsonResponse
    {
        $user = $request->user();
        $reviewDate = $this->validatedDate($date);

        $review = DailyReview::query()
            ->ownedBy($user)
            ->where('review_date', $reviewDate)
            ->first();

        return response()->json(['data' => $review]);
    }

    public function upsert(Request $request, string $date): JsonResponse
    {
        $user = $request->user();
        $reviewDate = $this->validatedDate($date);

        $data = $request->validate([
            'mood' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'energy' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'stress' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'day_rating' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'went_well' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'improve_tomorrow' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);

        if ($data === []) {
            throw ValidationException::withMessages([
                'request' => 'Provide at least one review field to save.',
            ]);
        }

        $review = DailyReview::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'review_date' => $reviewDate,
            ],
            [
                ...$data,
                'completed_at' => now(),
            ],
        );

        if (! $review->wasRecentlyCreated) {
            $review->update($data);
        }

        return response()->json(['data' => $review->fresh()]);
    }

    private function validatedDate(string $date): string
    {
        return Validator::make(
            ['date' => $date],
            ['date' => ['required', 'date_format:Y-m-d']],
        )->validate()['date'];
    }
}
