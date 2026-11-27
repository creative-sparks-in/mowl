<?php

namespace App\Services\Pages\Writing;

use App\Exceptions\OpenAIResponseException;
use App\Services\OpenAIClient;
use App\Services\Pages\Document\ProductBrief;
use App\Support\GenerationDeadline;
use Illuminate\Support\Facades\Log;
use JsonException;

/** Page copy from OpenAI or Azure OpenAI, held to a strict JSON schema built from the page itself. */
class OpenAIPageWriter implements PageWriter
{
    private const ATTEMPTS = 2;

    public function __construct(private OpenAIClient $client) {}

    public function write(ProductBrief $brief, array $document, array $targets, string $instruction): PageWriting
    {
        $payload = [
            'model' => config('campaigns.openai.model'),
            'store' => false,
            'reasoning' => ['effort' => config('campaigns.openai.reasoning_effort')],
            'input' => [
                ['role' => 'developer', 'content' => [['type' => 'input_text', 'text' => PageCopy::instructions()]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => PageCopy::prompt($brief, $document, $targets, $instruction)]]],
            ],
            'text' => ['format' => OpenAIClient::jsonFormat('page_copy', PageCopy::schema($document, $targets))],
        ];
        $last = null;
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            if (! app(GenerationDeadline::class)->allowsAnotherAttempt()) {
                break;
            }
            try {
                $answer = $this->client->respond($payload);
                if ($answer['incomplete']) {
                    throw new OpenAIResponseException('openai_incomplete_response', 'The AI stopped before finishing.', true);
                }
                if ($answer['refusal'] !== null) {
                    throw new OpenAIResponseException('openai_refusal', 'The AI declined to write this page: '.$answer['refusal']);
                }
                try {
                    $decoded = json_decode((string) $answer['text'], true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new OpenAIResponseException('openai_invalid_json', 'The AI returned text the app could not read.', true);
                }
                if (! is_array($decoded['sections'] ?? null)) {
                    throw new OpenAIResponseException('openai_invalid_schema', 'The AI returned no sections.', true);
                }

                return new PageWriting(
                    sections: $decoded['sections'],
                    model: $answer['model'],
                    inputTokens: $answer['input_tokens'],
                    cachedInputTokens: $answer['cached_input_tokens'],
                    outputTokens: $answer['output_tokens'],
                );
            } catch (OpenAIResponseException $exception) {
                $last = $exception;
                Log::warning('OpenAI page writing attempt failed.', ['attempt' => $attempt, 'code' => $exception->errorCode, 'message' => $exception->getMessage()]);
                if (! $exception->retryable) {
                    break;
                }
            }
        }

        throw $last ?? new OpenAIResponseException('openai_time_budget', 'The AI ran out of time.', true);
    }
}
