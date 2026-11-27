<?php

namespace App\Services;

use App\Exceptions\OpenAIResponseException;
use App\Support\GenerationDeadline;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Calls an OpenAI-compatible API: OpenAI itself, Azure OpenAI's v1 endpoint or Vercel AI Gateway,
 * whichever OPENAI_BASE_URL names. Failures become {@see OpenAIResponseException}.
 */
class OpenAIClient
{
    /** @return array{0: Response, 1: int} the response and its latency in milliseconds */
    public function post(string $path, array $payload, ?int $timeoutSeconds = null): array
    {
        return $this->send(fn (PendingRequest $http) => $http->post($this->url($path), $payload), $timeoutSeconds);
    }

    /**
     * @param  list<array{name: string, contents: string|resource, filename: string, mime_type?: string}>  $files
     * @return array{0: Response, 1: int}
     */
    public function postMultipart(string $url, array $fields, array $files, ?int $timeoutSeconds = null): array
    {
        return $this->send(function (PendingRequest $http) use ($url, $fields, $files) {
            foreach ($files as $file) {
                $http->attach($file['name'], $file['contents'], $file['filename'], isset($file['mime_type']) ? ['Content-Type' => $file['mime_type']] : []);
            }

            return $http->post(str_starts_with($url, 'https://') || str_starts_with($url, 'http://') ? $url : $this->url($url), $fields);
        }, $timeoutSeconds);
    }

    /**
     * One Responses API call, reduced to the parts every caller reads.
     *
     * @return array{incomplete: bool, refusal: ?string, text: ?string, model: string, input_tokens: int, cached_input_tokens: int, output_tokens: int, request_id: ?string, latency_ms: int}
     */
    public function respond(array $payload, ?int $timeoutSeconds = null): array
    {
        [$response, $latency] = $this->post('/responses', $payload, $timeoutSeconds);
        $json = $response->json() ?? [];
        $content = collect($json['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $item) => $item['content'] ?? []);

        return [
            'incomplete' => ($json['status'] ?? null) === 'incomplete',
            'refusal' => $content->first(fn (array $item) => isset($item['refusal']))['refusal'] ?? null,
            'text' => $content->firstWhere('type', 'output_text')['text'] ?? null,
            'model' => (string) ($json['model'] ?? $payload['model'] ?? ''),
            'input_tokens' => (int) data_get($json, 'usage.input_tokens', 0),
            'cached_input_tokens' => (int) data_get($json, 'usage.input_tokens_details.cached_tokens', 0),
            // Reasoning tokens are part of the output count, and billed as output.
            'output_tokens' => (int) data_get($json, 'usage.output_tokens', 0),
            'request_id' => $response->header('x-request-id') ?: ($json['id'] ?? null),
            'latency_ms' => $latency,
        ];
    }

    /** The "text.format" block that holds an answer to a strict JSON schema. */
    public static function jsonFormat(string $name, array $schema, ?string $description = null): array
    {
        return array_filter([
            'type' => 'json_schema',
            'name' => $name,
            'description' => $description,
            'strict' => true,
            'schema' => $schema,
        ], fn ($value) => $value !== null);
    }

    /** The transcript of an audio file, or of a video file's sound track (mp3, mp4, m4a, wav, webm; 25 MB at most). */
    public function transcribe(string $path, ?string $filename = null): string
    {
        $model = (string) config('campaigns.media.transcription_model');
        $handle = fopen($path, 'r');
        try {
            [$response] = $this->postMultipart($this->transcriptionUrl($model), ['model' => $model, 'response_format' => 'json'], [
                ['name' => 'file', 'contents' => $handle, 'filename' => $filename ?? basename($path)],
            ]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        $text = $response->json('text');
        if (! is_string($text) || trim($text) === '') {
            throw new OpenAIResponseException('openai_missing_transcript', 'OpenAI returned no audio transcript.', true);
        }

        return trim($text);
    }

    public function apiKey(): ?string
    {
        if ($apiKey = config('services.openai.api_key')) {
            return $apiKey;
        }

        if (! str_starts_with($this->url(''), 'https://ai-gateway.vercel.sh')) {
            return null;
        }

        return config('services.ai_gateway.api_key')
            ?: config('services.ai_gateway.oidc_token')
            ?: request()->header('x-vercel-oidc-token');
    }

    private function url(string $path): string
    {
        return rtrim((string) config('campaigns.openai.base_url'), '/').$path;
    }

    /**
     * Azure serves audio only on the deployment's own path: its OpenAI v1 path answered
     * DeploymentNotFound for a deployed gpt-4o-mini-transcribe. The deployment is named after the model.
     */
    private function transcriptionUrl(string $model): string
    {
        if (preg_match('#^(https://[^/]+\.azure\.com/openai)/v1$#', $this->url(''), $azure)) {
            return "{$azure[1]}/deployments/{$model}/audio/transcriptions?api-version=2024-10-21";
        }

        return $this->url('/audio/transcriptions');
    }

    /** @return array{0: Response, 1: int} */
    private function send(callable $request, ?int $timeoutSeconds): array
    {
        $apiKey = $this->apiKey();
        if (! $apiKey) {
            throw new OpenAIResponseException('openai_not_configured', 'OPENAI_API_KEY is not configured. Set an OpenAI or Azure OpenAI key, or use Vercel AI Gateway with deployment OIDC.');
        }
        $startedAt = hrtime(true);

        try {
            $response = $request(Http::withToken($apiKey)
                ->acceptJson()
                // Bodies over 1MB (a product photo, a banner sent for inspection) make curl send
                // "Expect: 100-continue", which some gateways answer with HTTP 417.
                ->withOptions(['expect' => false])
                ->timeout(app(GenerationDeadline::class)->timeout($timeoutSeconds ?? (int) config('campaigns.openai.timeout_seconds'))));
        } catch (ConnectionException $exception) {
            throw new OpenAIResponseException('openai_connection_error', 'OpenAI connection failed: '.$exception->getMessage(), true);
        }

        if ($response->failed()) {
            $status = $response->status();

            throw new OpenAIResponseException(
                "openai_http_{$status}",
                "OpenAI request failed ({$status}): ".data_get($response->json(), 'error.message', "OpenAI returned HTTP {$status}."),
                $status === 408 || $status === 409 || $status === 429 || $status >= 500,
            );
        }

        return [$response, (int) round((hrtime(true) - $startedAt) / 1_000_000)];
    }
}
