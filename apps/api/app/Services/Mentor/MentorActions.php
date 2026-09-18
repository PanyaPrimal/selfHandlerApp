<?php

namespace App\Services\Mentor;

use App\Http\Controllers\BodyMeasurementController;
use App\Http\Controllers\Finance\FinanceTransactionController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\TimeBlockController;
use App\Http\Requests\Finance\StoreFinanceTransactionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MentorActions
{
    private const FIELDS = [
        'capture_item' => ['title', 'description', 'type', 'due_on', 'project_id'],
        'record_measurement' => ['metric', 'measured_on', 'value', 'note'],
        'plan_block' => ['title', 'block_date', 'starts_at', 'ends_at', 'note'],
        'record_transaction' => ['kind', 'account_id', 'category_id', 'amount', 'occurred_on', 'note'],
    ];

    public function normalize(array $actions): array
    {
        Validator::make(['actions' => $actions], ['actions' => ['array', 'max:5'],
            'actions.*.kind' => ['required', Rule::in(array_keys(self::FIELDS))],
            'actions.*.label' => ['required', 'string', 'max:200'],
            'actions.*.payload_json' => ['required', 'string', 'max:4000'],
        ])->validate();

        return array_map(function ($action): array {
            $payload = json_decode($action['payload_json'], true);
            if (! is_array($payload) || array_diff(array_keys($payload), self::FIELDS[$action['kind']]) !== []) {
                throw ValidationException::withMessages(['actions' => 'Invalid action fields.']);
            }

            return ['kind' => $action['kind'], 'label' => $action['label'], 'payload' => $payload, 'status' => 'pending'];
        }, $actions);
    }

    public function execute(User $user, array $action, string $operation): array
    {
        $request = Request::create('/api/mentor/confirmed-action', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode($action['payload'], JSON_THROW_ON_ERROR));
        $request->setUserResolver(fn () => $user);
        $response = match ($action['kind']) {
            'capture_item' => app(ItemController::class)->store($request),
            'record_measurement' => app(BodyMeasurementController::class)->upsert($request),
            'plan_block' => app(TimeBlockController::class)->store($request),
            'record_transaction' => $this->transaction($request, $operation),
            default => throw ValidationException::withMessages(['action' => 'Unsupported action.']),
        };

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function transaction(Request $request, string $operation): JsonResponse
    {
        $request->merge(['idempotency_key' => $operation]);
        $form = StoreFinanceTransactionRequest::createFrom($request);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();

        return app(FinanceTransactionController::class)->store($form);
    }
}
