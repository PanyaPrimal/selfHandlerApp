<?php

namespace App\Http\Controllers;

use App\Services\Review\ReviewWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewWorkspaceController extends Controller
{
    public function __construct(private readonly ReviewWorkspaceService $workspaces) {}

    public function daily(Request $request, string $date): JsonResponse
    {
        return response()->json(['data' => $this->workspaces->daily($request->user(), $date)]);
    }
}
