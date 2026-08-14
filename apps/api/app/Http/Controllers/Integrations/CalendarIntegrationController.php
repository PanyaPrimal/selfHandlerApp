<?php

namespace App\Http\Controllers\Integrations;

use App\Exceptions\CalendarIntegrationException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Integrations\CalendarIntegrationResource;
use App\Models\Integration;
use App\Services\Integrations\CalendarProviderRegistry;
use App\Services\Integrations\CalendarSyncService;
use App\Services\Integrations\Google\GoogleCalendarProvider;
use App\Services\Integrations\GoogleOAuthState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CalendarIntegrationController extends Controller
{
    public function __construct(
        private readonly CalendarProviderRegistry $providers,
        private readonly GoogleOAuthState $oauthState,
        private readonly CalendarSyncService $sync,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $integrations = Integration::query()->ownedBy($request->user())->orderBy('provider')->get();

        return response()->json([
            'data' => CalendarIntegrationResource::collection($integrations)->resolve($request),
            'providers' => collect($this->providers->all())->map(fn ($provider): array => [
                'provider' => $provider->provider(),
                'available' => $provider->configured(),
                'connection_mode' => $provider->provider() === Integration::PROVIDER_GOOGLE
                    ? 'oauth_browser' : 'app_specific_password',
                'android_connect_supported' => $provider->provider() === Integration::PROVIDER_APPLE,
                'unavailable_code' => $provider->configured() ? null : 'calendar_provider_unavailable',
            ])->values()->all(),
        ]);
    }

    public function googleAuthorize(Request $request): JsonResponse
    {
        $google = $this->providers->for(Integration::PROVIDER_GOOGLE);
        abort_unless($google instanceof GoogleCalendarProvider, 500);
        try {
            $issued = $this->oauthState->issue($request->user());

            return response()->json([
                'authorization_url' => $google->authorizationUrl($issued['state']),
                'expires_at' => $issued['expires_at']->toIso8601String(),
            ]);
        } catch (CalendarIntegrationException $exception) {
            return $this->problem($exception);
        }
    }

    public function appleConnect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account' => ['required', 'string', 'email:rfc', 'max:254'],
            'app_specific_password' => ['required', 'string', 'min:4', 'max:128'],
        ]);
        if (Integration::query()->ownedBy($request->user())
            ->where('provider', Integration::PROVIDER_APPLE)->exists()) {
            throw ValidationException::withMessages(['account' => __('messages.calendar_connection_exists')]);
        }

        try {
            $integration = DB::transaction(fn (): Integration => Integration::query()->create([
                'user_id' => $request->user()->id,
                'provider' => Integration::PROVIDER_APPLE,
                'kind' => Integration::KIND_CALENDAR,
                'status' => Integration::STATUS_PENDING,
                'access_token' => strtolower(trim($data['account'])),
                'external_account_label' => strtolower(trim($data['account'])),
                'external_account_id' => hash('sha256', strtolower(trim($data['account']))),
                'secret' => $data['app_specific_password'],
            ]));
            $calendars = $this->providers->for($integration->provider)->calendars($integration);
            if ($calendars === []) {
                throw new CalendarIntegrationException('calendar_discovery_failed');
            }

            return response()->json([
                'data' => CalendarIntegrationResource::make($integration)->resolve($request),
                'calendars' => array_map(fn ($calendar): array => $calendar->toArray(), $calendars),
            ], 201);
        } catch (Throwable $exception) {
            if (isset($integration)) {
                $integration->delete();
            }
            if ($exception instanceof CalendarIntegrationException) {
                return $this->problem($exception);
            }
            throw $exception;
        }
    }

    public function calendars(Request $request, int $integration): JsonResponse
    {
        $model = $this->owned($request, $integration);
        try {
            return response()->json(['data' => array_map(
                fn ($calendar): array => $calendar->toArray(),
                $this->providers->for($model->provider)->calendars($model),
            )]);
        } catch (CalendarIntegrationException $exception) {
            $this->recordFailure($model, $exception);

            return $this->problem($exception);
        }
    }

    public function select(Request $request, int $integration): JsonResponse
    {
        $model = $this->owned($request, $integration);
        $data = $request->validate(['calendar_id' => ['required', 'string', 'max:4096']]);
        try {
            $calendar = collect($this->providers->for($model->provider)->calendars($model))
                ->first(fn ($candidate): bool => hash_equals($candidate->id, $data['calendar_id']));
            if (! $calendar) {
                return $this->problem(new CalendarIntegrationException('calendar_not_found', 422));
            }
            if (! $calendar->writable) {
                return $this->problem(new CalendarIntegrationException('calendar_read_only', 422));
            }
            $settings = Integration::normalizeSettings([
                ...$model->settings,
                'calendar_writable' => true,
                'calendar_timezone' => $calendar->timezone,
            ]);
            $model->forceFill([
                'external_calendar_id' => $calendar->id,
                'external_calendar_name' => $calendar->name,
                'status' => Integration::STATUS_ACTIVE,
                'settings' => $settings,
                'sync_cursor' => null,
                'last_error_code' => null,
            ])->save();

            return response()->json(['data' => CalendarIntegrationResource::make($model->fresh())->resolve($request)]);
        } catch (CalendarIntegrationException $exception) {
            $this->recordFailure($model, $exception);

            return $this->problem($exception);
        }
    }

    public function update(Request $request, int $integration): JsonResponse
    {
        $model = $this->owned($request, $integration);
        $allowed = ['import_detail', 'export_categories'];
        if ($request->collect()->keys()->diff($allowed)->isNotEmpty()) {
            throw ValidationException::withMessages(['settings' => __('messages.calendar_settings_unknown')]);
        }
        $data = $request->validate([
            'import_detail' => ['sometimes', Rule::in(Integration::IMPORT_DETAILS)],
            'export_categories' => ['sometimes', 'array', 'max:7'],
            'export_categories.*' => ['string', 'distinct', Rule::in(Integration::EXPORT_CATEGORIES)],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['settings' => __('messages.calendar_settings_empty')]);
        }
        $model->forceFill(['settings' => Integration::normalizeSettings([...$model->settings, ...$data])])->save();

        return response()->json(['data' => CalendarIntegrationResource::make($model->fresh())->resolve($request)]);
    }

    public function sync(Request $request, int $integration): JsonResponse
    {
        $model = $this->owned($request, $integration);
        try {
            return response()->json(['data' => $this->sync->sync($model)]);
        } catch (CalendarIntegrationException $exception) {
            return $this->problem($exception);
        }
    }

    public function destroy(Request $request, int $integration): Response
    {
        $model = $this->owned($request, $integration);
        $request->validate(['confirmation' => ['required', Rule::in(['DISCONNECT'])]]);
        $model->delete();

        return response()->noContent();
    }

    private function owned(Request $request, int $id): Integration
    {
        return Integration::query()->ownedBy($request->user())->findOrFail($id);
    }

    private function recordFailure(Integration $integration, CalendarIntegrationException $exception): void
    {
        $integration->forceFill([
            'status' => $exception->authenticationFailure ? Integration::STATUS_EXPIRED : $integration->status,
            'last_error_code' => $exception->errorCode,
        ])->save();
    }

    private function problem(CalendarIntegrationException $exception): JsonResponse
    {
        return response()->json([
            'message' => __("messages.{$exception->errorCode}"),
            'code' => $exception->errorCode,
        ], $exception->httpStatus);
    }
}
