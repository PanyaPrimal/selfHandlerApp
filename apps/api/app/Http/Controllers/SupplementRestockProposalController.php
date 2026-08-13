<?php

namespace App\Http\Controllers;

use App\Http\Requests\DismissSupplementRestockProposalRequest;
use App\Http\Resources\SupplementRestockProposalResource;
use App\Models\SupplementRestockProposal;
use App\Services\SupplementRestockProposalService;
use Illuminate\Http\JsonResponse;

class SupplementRestockProposalController extends Controller
{
    public function __construct(private readonly SupplementRestockProposalService $proposals) {}

    public function update(
        DismissSupplementRestockProposalRequest $request,
        SupplementRestockProposal $proposal,
    ): JsonResponse {
        abort_unless($proposal->isOwnedBy($request->user()), 404);
        $proposal = $this->proposals->dismiss($proposal);

        return response()->json([
            'data' => SupplementRestockProposalResource::make($proposal)->resolve($request),
        ]);
    }
}
