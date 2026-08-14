<?php

namespace App\Http\Controllers\Integrations;

use App\Exceptions\CalendarIntegrationException;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\Integrations\Google\GoogleCalendarProvider;
use App\Services\Integrations\GoogleOAuthState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class GoogleCalendarCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        GoogleOAuthState $state,
        GoogleCalendarProvider $google,
    ): RedirectResponse {
        $settings = rtrim((string) config('integrations.web_settings_url'), '/');
        try {
            $user = $state->consume((string) $request->query('state', ''));
            if ($request->query('error') !== null || ! is_string($request->query('code'))
                || $request->query('code') === '') {
                return redirect()->away($settings.'?calendar=oauth_denied');
            }
            $tokens = $google->exchangeCode($request->query('code'));
            $integration = DB::transaction(function () use ($user, $tokens): Integration {
                $existing = Integration::query()->ownedBy($user)
                    ->where('provider', Integration::PROVIDER_GOOGLE)->first();
                if ($existing) {
                    $existing->externalEvents()->delete();
                    $existing->syncedItems()->delete();
                }
                $integration = $existing ?? new Integration;
                $integration->forceFill([
                    'user_id' => $user->id,
                    'provider' => Integration::PROVIDER_GOOGLE,
                    'kind' => Integration::KIND_CALENDAR,
                    'status' => Integration::STATUS_PENDING,
                    'external_account_id' => null,
                    'external_account_label' => null,
                    'external_calendar_id' => null,
                    'external_calendar_name' => null,
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? $existing?->refresh_token,
                    'token_expires_at' => $tokens['expires_at'],
                    'sync_cursor' => null,
                    'settings' => Integration::defaultSettings(),
                    'last_sync_at' => null,
                    'last_success_at' => null,
                    'last_error_code' => null,
                ])->save();

                return $integration;
            });
            $primary = collect($google->calendars($integration))->firstWhere('default', true);
            if ($primary) {
                $integration->forceFill([
                    'external_account_id' => hash('sha256', $primary->id),
                    'external_account_label' => $primary->id,
                ])->save();
            }

            return redirect()->away($settings.'?calendar=oauth_connected');
        } catch (ValidationException) {
            return redirect()->away($settings.'?calendar=oauth_invalid_state');
        } catch (CalendarIntegrationException $exception) {
            return redirect()->away($settings.'?calendar='.rawurlencode($exception->errorCode));
        } catch (Throwable) {
            return redirect()->away($settings.'?calendar=calendar_sync_failed');
        }
    }
}
