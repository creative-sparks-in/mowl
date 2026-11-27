<?php

namespace App\Services;

use App\Contracts\CampaignPackGenerator;
use App\Data\GenerationResult;
use App\Enums\OfferingType;
use App\Exceptions\OpenAIResponseException;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Support\GenerationDeadline;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

class OpenAIResponsesCampaignPackGenerator implements CampaignPackGenerator
{
    public function __construct(private OpenAIClient $client) {}

    public function generate(Product $product, SourceSnapshot $source, array $page): GenerationResult
    {
        if (! $this->client->apiKey()) {
            throw new RuntimeException('OPENAI_API_KEY is not configured. Set an OpenAI key or use Vercel AI Gateway with deployment OIDC.');
        }

        $instructions = $this->instructions($product);
        $userContent = [[
            'type' => 'input_text',
            'text' => $this->sourcePrompt($product, $source, $page),
        ]];
        $lastException = null;

        foreach ($this->models() as $model) {
            for ($attempt = 1; $attempt <= config('campaigns.openai.retry_attempts'); $attempt++) {
                if (! app(GenerationDeadline::class)->allowsAnotherAttempt()) {
                    throw new OpenAIResponseException('openai_time_budget', 'Generation stopped to stay inside the request time limit.'.($lastException ? ' Last error: '.$lastException->getMessage() : ''), true);
                }
                try {
                    return $this->resultFromResponse($this->client->respond($this->payload($model, $instructions, $userContent)), $source, $page);
                } catch (OpenAIResponseException $exception) {
                    $lastException = $exception;
                    Log::warning('OpenAI campaign-pack attempt failed.', ['model' => $model, 'attempt' => $attempt, 'code' => $exception->errorCode, 'message' => $exception->getMessage()]);

                    if (! $exception->retryable) {
                        throw $exception;
                    }

                    if ($attempt === config('campaigns.openai.retry_attempts')) {
                        break;
                    }

                    usleep($this->retryDelay($attempt) * 1000);
                }
            }
        }

        throw $lastException ?? new RuntimeException('OpenAI generation failed before a response was received.');
    }

    private function payload(string $model, string $instructions, array $userContent): array
    {
        return [
            'model' => $model,
            'store' => false,
            'reasoning' => ['effort' => config('campaigns.openai.reasoning_effort')],
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => $instructions,
                    ]],
                ],
                [
                    'role' => 'user',
                    'content' => $userContent,
                ],
            ],
            'text' => [
                'format' => OpenAIClient::jsonFormat('campaign_pack_content', $this->schema(), 'Evidence-linked ecommerce campaign pack content.'),
            ],
        ];
    }

    /** @param  array  $answer  from {@see OpenAIClient::respond()} */
    private function resultFromResponse(array $answer, SourceSnapshot $source, array $page): GenerationResult
    {
        if ($answer['incomplete']) {
            throw new OpenAIResponseException('openai_incomplete_response', 'OpenAI returned an incomplete campaign-pack response.', true);
        }
        if ($answer['refusal'] !== null) {
            throw new OpenAIResponseException('openai_refusal', 'OpenAI declined the campaign-pack request: '.$answer['refusal'], false);
        }

        $outputText = $answer['text'];
        if (! $outputText) {
            throw new OpenAIResponseException('openai_missing_structured_output', 'OpenAI returned no structured campaign-pack output.', true);
        }

        try {
            $generated = json_decode($outputText, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OpenAIResponseException('openai_invalid_json', 'OpenAI returned invalid structured JSON.', true);
        }

        $this->validateShape($generated);
        $evidence = $generated['evidence'] ?? [];
        $flags = $generated['compliance_flags'] ?? [];
        unset($generated['evidence'], $generated['compliance_flags']);
        $checked = app(CampaignPackEvidence::class)->check($generated, $evidence, $flags, $source, $page);
        if (is_string($checked)) {
            throw new OpenAIResponseException('openai_'.$checked, 'OpenAI '.match ($checked) {
                'empty_claim' => 'returned evidence without a claim.',
                'unflagged_claim' => 'returned an unsafe claim without a compliance flag.',
                default => 'returned no evidence that is traceable to the supplied source.',
            }, true);
        }
        [$generated, $evidence] = $checked;

        return new GenerationResult(
            content: $generated,
            evidence: $evidence,
            complianceFlags: $flags,
            provider: 'openai',
            model: $answer['model'],
            inputTokens: $answer['input_tokens'],
            cachedInputTokens: $answer['cached_input_tokens'],
            outputTokens: $answer['output_tokens'],
            providerRequestId: $answer['request_id'],
            providerLatencyMs: $answer['latency_ms'],
        );
    }

    private function models(): array
    {
        return array_values(array_unique(array_filter([
            config('campaigns.openai.model'),
            ...config('campaigns.openai.fallback_models'),
        ])));
    }

    private function retryDelay(int $attempt): int
    {
        return config('campaigns.openai.retry_backoff_ms')[$attempt - 1] ?? 0;
    }

    private function validateShape(array $generated): void
    {
        if ($error = CampaignPackBlueprint::shapeError($generated)) {
            throw new OpenAIResponseException('openai_invalid_schema', 'OpenAI '.$error, true);
        }
    }

    private function instructions(Product $product): string
    {
        return CampaignPackBlueprint::instructions($product->offeringType());
    }

    private function sourcePrompt(Product $product, SourceSnapshot $source, array $page): string
    {
        $type = $product->offeringType();
        $parts = [
            "PRODUCT NAME\n{$product->name}",
            ...($type === OfferingType::Physical ? [] : ["OFFERING TYPE\n{$type->productOption()}"]),
            "SUPPLIED PRICE\n".($product->price ?: 'Not supplied'),
            "PRODUCT CONTEXT\n".($product->summary ?: 'Not supplied'),
            "SOURCE URL\n{$source->url}",
            'EXTRACTED STRUCTURED TRUTH'."\n".CampaignPackEvidence::json($page['product_truth'] ?? [], JSON_PRETTY_PRINT),
            "SOURCE DESCRIPTION\n".($page['description'] ?? ''),
            "NORMALIZED SOURCE CONTENT\n".mb_substr($page['content'] ?? '', 0, 50_000),
        ];

        if ($team = $page['team_knowledge'] ?? null) {
            $parts[] = 'TEAM-CONFIRMED FACTS'."\n".CampaignPackEvidence::json($team, JSON_PRETTY_PRINT);
        }

        if ($brand = $page['brand_profile'] ?? null) {
            $parts[] = 'BRAND VOICE AND PALETTE (style guidance for tone and colour, not product facts)'."\n".CampaignPackEvidence::json($brand, JSON_PRETTY_PRINT);
        }

        if ($brief = $page['campaign_brief'] ?? null) {
            $parts[] = 'CAMPAIGN BRIEF (the team\'s direction for angle and emphasis; never a source of product facts, prices, offers or claims)'."\n".CampaignPackEvidence::json($brief, JSON_PRETTY_PRINT);
        }

        if ($section = $page['regeneration_section'] ?? null) {
            $parts[] = "REGENERATION REQUEST\nCreate a materially different {$section} section while preserving every other section's meaning. Avoid unsupported claims and repetition.";
            $parts[] = 'CURRENT CAMPAIGN PACK'."\n".CampaignPackEvidence::json($page['current_content'] ?? [], JSON_PRETTY_PRINT);
        }

        return implode("\n\n", $parts);
    }

    private function schema(): array
    {
        return CampaignPackBlueprint::schema();
    }
}
