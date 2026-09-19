<?php

namespace App\Services\Mentor;

use App\Exceptions\AiAssistantException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ChatGptBridge
{
    public function available(): bool
    {
        return filled(config('chatgpt.url')) && strlen((string) config('chatgpt.token')) >= 32;
    }

    public function request(int $userId, string $action, array $data = []): array
    {
        if (! $this->available()) {
            throw new AiAssistantException('chatgpt_unavailable', 503);
        }
        // Stable opaque account directories, separated by this application installation.
        $owner = hash_hmac('sha256', 'selfhandler-user:'.$userId, (string) config('app.key'));
        try {
            $response = Http::acceptJson()->asJson()->withoutRedirecting()
                ->withToken((string) config('chatgpt.token'))->connectTimeout(3)->timeout($action === 'call' ? 48 : 25)
                ->post(rtrim((string) config('chatgpt.url'), '/').'/accounts/'.$owner.'/'.$action, $data);
        } catch (ConnectionException) {
            throw new AiAssistantException('chatgpt_unavailable', 503);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new AiAssistantException(match ($response->status()) {
                409 => 'chatgpt_login_required',
                429 => $response->json('error') === 'chatgpt_limit_reached' ? 'chatgpt_limit_reached' : 'chatgpt_busy',
                default => 'chatgpt_unavailable',
            }, in_array($response->status(), [409, 429], true) ? $response->status() : 503);
        }

        return $response->json();
    }
}
