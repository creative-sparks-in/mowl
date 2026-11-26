<?php

namespace App\Services;

use App\Contracts\BannerModels;
use App\Exceptions\AiProviderException;
use App\Exceptions\VertexAIResponseException;

/** Banner drawing and image checks on Gemini, through Vertex AI. */
class GoogleBannerModels implements BannerModels
{
    private static bool $thinkingRejected = false;

    public function __construct(private GoogleVertexAIClient $client) {}

    public function provider(): string
    {
        return 'google';
    }

    public function visionModel(): string
    {
        return (string) config('campaigns.google.model');
    }

    public function draw(string $prompt, array $references = [], bool $banner = false, ?int $timeoutSeconds = null): array
    {
        $parts = [['text' => $prompt]];
        foreach ($references as $reference) {
            $parts[] = ['inlineData' => $reference];
        }
        $config = ['responseModalities' => ['TEXT', 'IMAGE']];
        if ($banner) {
            $config['imageConfig'] = [
                'aspectRatio' => config('campaigns.banners.aspect_ratio'),
                'imageSize' => config('campaigns.banners.image_size'),
            ];
        }

        [$response, $latency] = $this->client->generateContent([
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => $config,
        ], config('campaigns.banners.model'), $timeoutSeconds);

        $json = $response->json();
        $image = collect(data_get($json, 'candidates.0.content.parts', []))
            ->first(fn (array $part) => is_string(data_get($part, 'inlineData.data')));
        $encoded = data_get($image, 'inlineData.data');
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        $details = collect(data_get($json, 'usageMetadata.candidatesTokensDetails', []));

        return [
            'bytes' => is_string($bytes) ? $bytes : null,
            'mime_type' => (string) data_get($image, 'inlineData.mimeType', 'image/png'),
            'model' => (string) config('campaigns.banners.model'),
            'input_tokens' => (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
            'output_text_tokens' => (int) $details->filter(fn (array $detail) => strtoupper((string) ($detail['modality'] ?? '')) === 'TEXT')->sum('tokenCount'),
            'output_image_tokens' => (int) $details->filter(fn (array $detail) => strtoupper((string) ($detail['modality'] ?? '')) === 'IMAGE')->sum('tokenCount'),
            'untagged_output_tokens' => $details->isEmpty() ? (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0) : 0,
            // Thinking is reported apart from the candidates.
            'thinking_tokens' => (int) data_get($json, 'usageMetadata.thoughtsTokenCount', 0),
            'request_id' => $response->header('x-request-id') ?: data_get($json, 'responseId'),
            'latency_ms' => $latency,
        ];
    }

    /**
     * A yes/no look at an image needs little reasoning, and long default thinking can run past the
     * inspection timeout with nothing sent back. A model that rejects the setting is asked without it.
     */
    public function look(string $prompt, array $images, array $schema): array
    {
        $parts = [['text' => $prompt]];
        foreach ($images as $image) {
            $parts[] = ['inlineData' => $image];
        }
        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => self::vertexSchema($schema),
                // A short answer is all a check needs. Without a cap, a model listing marks can loop on and on
                // and hold the connection silently until the timeout, losing an image that was already paid for.
                'maxOutputTokens' => (int) config('campaigns.banners.inspection_max_output_tokens', 2048),
            ],
        ];
        $level = (string) config('campaigns.banners.inspection_thinking_level');
        if ($level !== '' && ! self::$thinkingRejected) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $level];
        }
        try {
            [$response] = $this->client->generateContent($payload, config('campaigns.google.model'), (int) config('campaigns.banners.inspection_timeout_seconds', 30));
        } catch (VertexAIResponseException $exception) {
            if (! isset($payload['generationConfig']['thinkingConfig']) || $exception->errorCode !== 'vertex_http_400' || ! str_contains(strtolower($exception->getMessage()), 'thinking')) {
                throw $exception;
            }
            self::$thinkingRejected = true;
            unset($payload['generationConfig']['thinkingConfig']);
            [$response] = $this->client->generateContent($payload, config('campaigns.google.model'), (int) config('campaigns.banners.inspection_timeout_seconds', 30));
        }

        $json = $response->json();

        return [
            'answer' => json_decode((string) data_get($json, 'candidates.0.content.parts.0.text'), true),
            'cut_short' => strtoupper((string) data_get($json, 'candidates.0.finishReason')) === 'MAX_TOKENS',
            'input_tokens' => (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
            // Thinking tokens are billed as output too.
            'output_tokens' => (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0) + (int) data_get($json, 'usageMetadata.thoughtsTokenCount', 0),
        ];
    }

    public function failure(string $code, string $message, bool $retryable = true): AiProviderException
    {
        return new VertexAIResponseException('vertex_'.$code, 'Vertex AI '.$message, $retryable);
    }

    /** Vertex's schema dialect: uppercase types and no additionalProperties. */
    private static function vertexSchema(array $schema): array
    {
        unset($schema['additionalProperties']);
        foreach ($schema as $key => $value) {
            $schema[$key] = match ($key) {
                'type' => strtoupper($value),
                'properties' => array_map(self::vertexSchema(...), $value),
                'items' => self::vertexSchema($value),
                default => $value,
            };
        }

        return $schema;
    }
}
