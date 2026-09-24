<?php

namespace App\Agent;

use App\Agent\Exceptions\AnalysisFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal OpenAI Responses API client for on-device agent runs.
 */
class OpenAiResponses
{
    public const Url = 'https://api.openai.com/v1/responses';

    /** Longest a single request may take when the frame's budget allows it. */
    public const RequestTimeoutSeconds = 120;

    /** Retries after the first attempt, like the official SDK. */
    public const MaxRetries = 2;

    public const TimedOutMessage = 'Luna took too long to analyze this frame.';

    /**
     * Create one model response, retrying transient failures while the frame's budget allows.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws AnalysisFailed
     */
    public function create(#[\SensitiveParameter] string $apiKey, array $payload, Deadline $deadline): array
    {
        for ($attempt = 0; ; $attempt++) {
            if ($deadline->expired()) {
                throw new AnalysisFailed(self::TimedOutMessage);
            }

            $response = null;

            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout($deadline->cap(15))
                    ->timeout($deadline->cap(self::RequestTimeoutSeconds))
                    ->post(self::Url, $payload);
            } catch (ConnectionException) {
                // Retried below; reported as a connection failure if retries run out.
            }

            $retryable = $response === null || in_array($response->status(), [408, 409, 429], true) || $response->serverError();
            $backoffSeconds = 0.5 * 2 ** $attempt;

            if ($retryable && $attempt < self::MaxRetries && $deadline->remainingSeconds() > $backoffSeconds + 1) {
                usleep((int) ($backoffSeconds * 1_000_000));

                continue;
            }

            break;
        }

        if ($response === null) {
            throw new AnalysisFailed($deadline->expired() ? self::TimedOutMessage : "Couldn't reach OpenAI. Check your connection and try again.");
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
