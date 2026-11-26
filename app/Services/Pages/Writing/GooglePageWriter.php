<?php

namespace App\Services\Pages\Writing;

use App\Exceptions\VertexAIResponseException;
use App\Services\GoogleVertexAIClient;
use App\Services\Pages\Document\ProductBrief;
use App\Support\GenerationDeadline;
use Illuminate\Support\Facades\Log;
use JsonException;

/** Page copy from Gemini on Vertex AI, constrained to a response schema built from the page itself. */
class GooglePageWriter implements PageWriter
{
    private const ATTEMPTS = 2;

    public function __construct(private GoogleVertexAIClient $vertex) {}

    public function write(ProductBrief $brief, array $document, array $targets, string $instruction): PageWriting
    {
        $payload = [
            'systemInstruction' => ['parts' => [['text' => PageCopy::instructions()]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => PageCopy::prompt($brief, $document, $targets, $instruction)]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => PageCopy::schema($document, $targets)],
        ];
        $last = null;
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            if (! app(GenerationDeadline::class)->allowsAnotherAttempt()) {
                break;
            }
            try {
                [$response] = $this->vertex->generateContent($payload, null, (int) config('campaigns.google.timeout_seconds'));
                $json = $response->json();
                $finish = data_get($json, 'candidates.0.finishReason');
                if ($finish && $finish !== 'STOP') {
                    throw new VertexAIResponseException('vertex_incomplete_response', "The AI stopped before finishing ({$finish}).", in_array($finish, ['MAX_TOKENS', 'OTHER'], true));
                }
                $text = collect(data_get($json, 'candidates.0.content.parts', []))->pluck('text')->filter()->implode('');
                try {
                    $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new VertexAIResponseException('vertex_invalid_json', 'The AI returned text the app could not read.', true);
                }
                if (! is_array($decoded['sections'] ?? null)) {
                    throw new VertexAIResponseException('vertex_invalid_schema', 'The AI returned no sections.', true);
                }

                return new PageWriting(
                    sections: $decoded['sections'],
                    model: $json['modelVersion'] ?? config('campaigns.google.model'),
                    inputTokens: (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
                    cachedInputTokens: (int) data_get($json, 'usageMetadata.cachedContentTokenCount', 0),
                    outputTokens: (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0),
                );
            } catch (VertexAIResponseException $exception) {
                $last = $exception;
                Log::warning('Vertex AI page writing attempt failed.', ['attempt' => $attempt, 'code' => $exception->errorCode, 'message' => $exception->getMessage()]);
                if (! $exception->retryable) {
                    break;
                }
            }
        }

        throw $last ?? new VertexAIResponseException('vertex_time_budget', 'The AI ran out of time.', true);
    }
}
