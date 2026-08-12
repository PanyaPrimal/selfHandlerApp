<?php

namespace App\Http\Controllers;

use App\Models\TimeBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

/** The only fact Planner owns. */
class TimeBlockController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request);

        $block = TimeBlock::create(['user_id' => $user->id, ...$data]);

        return response()->json(['data' => $block], 201);
    }

    public function update(Request $request, TimeBlock $block): JsonResponse
    {
        abort_unless($block->isOwnedBy($request->user()), 404);

        $block->update($this->validated($request, partial: true, block: $block));

        return response()->json(['data' => $block->fresh()]);
    }

    public function destroy(Request $request, TimeBlock $block): Response
    {
        abort_unless($block->isOwnedBy($request->user()), 404);

        $block->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false, ?TimeBlock $block = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validator = validator($request->all(), [
            'title' => [$required, 'string', 'max:200'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'block_date' => [$required, 'date_format:Y-m-d'],
            'starts_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'nullable', 'date_format:H:i'],
        ]);

        $validator->after(function (LaravelValidator $validator) use ($block, $request): void {
            if ($request->has('title') && trim((string) $request->input('title')) === '') {
                $validator->errors()->add('title', __('messages.block_name'));
            }

            $startsAt = $request->exists('starts_at')
                ? $request->input('starts_at')
                : ($block?->starts_at ? substr((string) $block->starts_at, 0, 5) : null);
            $endsAt = $request->exists('ends_at')
                ? $request->input('ends_at')
                : ($block?->ends_at ? substr((string) $block->ends_at, 0, 5) : null);

            // Overlap between blocks is allowed on purpose; a block that ends
            // before it starts is simply not a span.
            if (is_string($startsAt) && is_string($endsAt) && $endsAt <= $startsAt) {
                $validator->errors()->add('ends_at', __('messages.block_end_after_start'));
            }
        });

        $data = $validator->validate();

        if ($partial && $data === []) {
            throw ValidationException::withMessages([
                'request' => __('messages.field_required_update'),
            ]);
        }

        if (array_key_exists('title', $data)) {
            $data['title'] = trim($data['title']);
        }

        return $data;
    }
}
