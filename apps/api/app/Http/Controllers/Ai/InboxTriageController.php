<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\InboxTriageProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InboxTriageController extends Controller
{
    public function __construct(private readonly InboxTriageProposalService $proposals) {}

    public function draft(Request $request): JsonResponse
    {
        $this->assertOnly($request, ['item_id']);
        $data = $request->validate(['item_id' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->proposals->draft($request->user(), $data['item_id'])]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->assertOnly($request, ['confirmation_token']);
        $data = $request->validate([
            'confirmation_token' => ['required', 'string', 'min:40', 'max:20000'],
        ]);

        return response()->json(['data' => $this->proposals->confirm(
            $request->user(),
            $data['confirmation_token'],
        )]);
    }

    /** @param list<string> $allowed */
    private function assertOnly(Request $request, array $allowed): void
    {
        if ($request->collect()->keys()->diff($allowed)->isNotEmpty()) {
            throw ValidationException::withMessages(['request' => __('messages.ai_settings_unknown')]);
        }
    }
}
