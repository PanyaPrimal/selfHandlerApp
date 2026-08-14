<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AnalyticsCatalog;
use App\Services\Analytics\AnalyticsQuery;
use App\Services\Analytics\AnalyticsWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsCatalog $catalog,
        private readonly AnalyticsQuery $query,
        private readonly AnalyticsWorkspaceService $workspaces,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $request->user()->ensureProfile();

        return response()->json(['data' => [
            'metrics' => $this->catalog->metrics(),
            'correlations' => $this->catalog->correlations(),
            'limits' => $this->catalog->limits(),
        ]]);
    }

    public function workspace(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->ensureProfile();
        $query = $this->query->workspace($request, $user);

        return response()->json(['data' => $this->workspaces->workspace($user, ...$query)]);
    }

    public function correlations(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->ensureProfile();
        $query = $this->query->correlations($request, $user);

        return response()->json(['data' => $this->workspaces->correlations($user, ...$query)]);
    }
}
