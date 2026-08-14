<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\LlmConsent;
use App\Services\Ai\LlmConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LlmConsentController extends Controller
{
    public function __construct(private readonly LlmConsentService $consents) {}

    public function replaceStorageInbox(Request $request): JsonResponse
    {
        if ($request->collect()->keys()->diff(['granted'])->isNotEmpty()) {
            throw ValidationException::withMessages(['request' => __('messages.ai_settings_unknown')]);
        }
        $data = $request->validate(['granted' => ['required', 'boolean']]);
        $consent = $this->consents->replace(
            $request->user(),
            LlmConsent::SCOPE_STORAGE_INBOX,
            $data['granted'],
        );

        return response()->json(['data' => [
            'scope' => $consent->scope,
            'granted' => $consent->isGranted(),
            'granted_at' => $consent->granted_at?->toIso8601String(),
            'revoked_at' => $consent->revoked_at?->toIso8601String(),
        ]]);
    }
}
