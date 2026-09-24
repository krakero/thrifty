<?php

namespace App\Agent;

use App\Agent\Exceptions\AnalysisFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal OpenAI Responses API client for on-device agent runs.
 */
class OpenAiResponses
{
    public const Url = 'https://api.openai.com/v1/responses';

    /**
     * Create one model response, retrying transient failures like the official SDK (two retries).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws AnalysisFailed
     */
    public function create(string $apiKey, array $payload): array
    {
        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(15)
                ->timeout(180)
                ->retry(3, fn (int $attempt): int => 500 * 2 ** ($attempt - 1), function (\Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    $status = $exception instanceof RequestException ? $exception->response->status() : 0;

                    return in_array($status, [408, 409, 429], true) || $status >= 500;
                }, throw: false)
                ->post(self::Url, $payload);
        } catch (ConnectionException) {
            throw new AnalysisFailed("Couldn't reach OpenAI. Check your connection and try again.");
        }

        if ($response->failed()) {
            throw new AnalysisFailed(self::errorMessage($response), $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new AnalysisFailed('OpenAI returned an unreadable response.');
        }

        return $body;
    }

    private static function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');
        $detail = is_string($message) && $message !== '' ? $message : "status {$response->status()}";

        return match (true) {
            $response->status() === 401 => 'OpenAI rejected your API key. Check it in Settings.',
            $response->status() === 429 => "OpenAI rate limit or quota reached: {$detail}",
            default => "OpenAI request failed: {$detail}",
        };
    }
}
