<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\StoreLlmConnectionRequest;
use App\Http\Requests\Ai\UpdateLlmConnectionRequest;
use App\Http\Resources\Ai\LlmConnectionResource;
use App\Models\LlmConnection;
use App\Models\LlmConsent;
use App\Models\LlmSetting;
use App\Services\Ai\LlmConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class LlmConnectionController extends Controller
{
    public function __construct(private readonly LlmConnectionService $connections) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->settings($request));
    }

    public function store(StoreLlmConnectionRequest $request): JsonResponse
    {
        $this->assertOnly($request, ['name', 'provider', 'model', 'api_key', 'parameters']);
        $connection = $this->connections->create($request->user(), $request->validated());

        return response()->json(['data' => LlmConnectionResource::make($connection)->resolve($request)], 201);
    }

    public function update(UpdateLlmConnectionRequest $request, int $connection): JsonResponse
    {
        $this->assertOnly($request, ['name', 'provider', 'model', 'api_key', 'parameters']);
        $data = $request->validated();
        if ($data === []) {
            throw ValidationException::withMessages(['request' => __('messages.field_required_update')]);
        }
        $model = $this->owned($request, $connection);

        return response()->json(['data' => LlmConnectionResource::make(
            $this->connections->update($request->user(), $model, $data),
        )->resolve($request)]);
    }

    public function test(Request $request, int $connection): JsonResponse
    {
        $model = $this->owned($request, $connection);

        return response()->json(['data' => LlmConnectionResource::make(
            $this->connections->test($request->user(), $model),
        )->resolve($request)]);
    }

    public function activate(Request $request, int $connection): JsonResponse
    {
        $model = $this->owned($request, $connection);
        $this->connections->activate($request->user(), $model);

        return response()->json($this->settings($request));
    }

    public function destroy(Request $request, int $connection): Response
    {
        $this->connections->delete($request->user(), $this->owned($request, $connection));

        return response()->noContent();
    }

    private function owned(Request $request, int $id): LlmConnection
    {
        return LlmConnection::query()->ownedBy($request->user())->findOrFail($id);
    }

    /** @return array<string,mixed> */
    private function settings(Request $request): array
    {
        $connections = LlmConnection::query()->ownedBy($request->user())->orderBy('name')->get();
        $active = LlmSetting::query()->ownedBy($request->user())->value('active_connection_id');
        $consent = LlmConsent::query()->ownedBy($request->user())
            ->where('scope', LlmConsent::SCOPE_STORAGE_INBOX)->first();

        return [
            'data' => LlmConnectionResource::collection($connections)->resolve($request),
            'active_connection_id' => $active,
            'consents' => [
                'storage_inbox' => [
                    'scope' => LlmConsent::SCOPE_STORAGE_INBOX,
                    'granted' => $consent?->isGranted() ?? false,
                    'granted_at' => $consent?->granted_at?->toIso8601String(),
                    'revoked_at' => $consent?->revoked_at?->toIso8601String(),
                ],
            ],
            'providers' => LlmConnection::PROVIDERS,
        ];
    }

    /** @param list<string> $allowed */
    private function assertOnly(Request $request, array $allowed): void
    {
        if ($request->collect()->keys()->diff($allowed)->isNotEmpty()) {
            throw ValidationException::withMessages(['request' => __('messages.ai_settings_unknown')]);
        }
    }
}
