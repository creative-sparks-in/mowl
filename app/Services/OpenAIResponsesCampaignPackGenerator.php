<?php

namespace App\Services;

use App\Contracts\CampaignPackGenerator;
use App\Data\GenerationResult;
use App\Enums\OfferingType;
use App\Exceptions\OpenAIResponseException;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Support\GenerationDeadline;
use App\Support\VoiceoverTiming;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

class OpenAIResponsesCampaignPackGenerator implements CampaignPackGenerator
{
    /** How many times the lines the approval check blocks are sent back for a rewrite. */
    private const REPAIR_ROUNDS = 2;

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
        // A pack that records unsafe claims of its own. Any of them blocks approval, so the pack is asked
        // for once more without them; if that fails, this one is still a whole pack a person can fix.
        $blocked = null;

        foreach ($this->models() as $model) {
            for ($attempt = 1; $attempt <= config('campaigns.openai.retry_attempts'); $attempt++) {
                if (! app(GenerationDeadline::class)->allowsAnotherAttempt()) {
                    if ($blocked) {
                        break 2;
                    }
                    throw new OpenAIResponseException('openai_time_budget', 'Generation stopped to stay inside the request time limit.'.($lastException ? ' Last error: '.$lastException->getMessage() : ''), true);
                }
                try {
                    $result = $this->resultFromResponse($this->client->respond($this->payload($model, $instructions, [
                        ...$userContent,
                        ...($blocked ? [['type' => 'input_text', 'text' => $this->rejection($blocked)]] : []),
                    ])), $source, $page);
                    if ($blocked) {
                        // The first pack was paid for too.
                        $result = $this->withUsage($result, $blocked->inputTokens, $blocked->cachedInputTokens, $blocked->outputTokens, (int) $blocked->providerLatencyMs);
                    } elseif ($this->unsafeClaims($result) !== [] && $attempt < config('campaigns.openai.retry_attempts')) {
                        $blocked = $result;

                        continue;
                    }

                    return $this->repaired($result, $model, $source);
                } catch (OpenAIResponseException $exception) {
                    $lastException = $exception;
                    Log::warning('OpenAI campaign-pack attempt failed.', ['model' => $model, 'attempt' => $attempt, 'code' => $exception->errorCode, 'message' => $exception->getMessage()]);

                    if ($blocked) {
                        break 2;
                    }

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

        if ($blocked) {
            return $this->repaired($blocked, $model, $source);
        }

        throw $lastException ?? new RuntimeException('OpenAI generation failed before a response was received.');
    }

    /** @return list<string> claims the pack itself records as unsafe: an unsafe evidence record or a "blocked" flag */
    private function unsafeClaims(GenerationResult $result): array
    {
        return array_values(array_unique(array_map(fn (array $record) => trim((string) ($record['claim'] ?? '')), [
            ...array_filter($result->evidence, fn (array $record) => in_array($record['status'] ?? null, CampaignPackEvidence::UNSAFE_STATUSES, true)),
            ...array_filter($result->complianceFlags, fn (array $flag) => ($flag['severity'] ?? null) === 'blocked'),
        ])));
    }

    private function rejection(GenerationResult $blocked): string
    {
        return "YOUR PREVIOUS PACK WAS REJECTED\nIt recorded these claims as unsafe, and a pack with an unsafe claim cannot be approved:\n- "
            .implode("\n- ", $this->unsafeClaims($blocked))
            ."\nWrite the pack again. Do not state these claims anywhere in the copy, and give them no evidence record and no compliance flag: list each one under product_truth.prohibited_claims.";
    }

    private function withUsage(GenerationResult $result, int $inputTokens, int $cachedInputTokens, int $outputTokens, int $latencyMs, ?array $content = null): GenerationResult
    {
        return new GenerationResult(
            content: $content ?? $result->content,
            evidence: $result->evidence,
            complianceFlags: $result->complianceFlags,
            provider: $result->provider,
            model: $result->model,
            inputTokens: $result->inputTokens + $inputTokens,
            cachedInputTokens: $result->cachedInputTokens + $cachedInputTokens,
            outputTokens: $result->outputTokens + $outputTokens,
            providerRequestId: $result->providerRequestId,
            providerLatencyMs: $result->providerLatencyMs + $latencyMs,
        );
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

    /**
     * The pack with the lines the approval check would block rewritten. gpt-5.4-mini gives a voiceover
     * line more words than its seconds hold, and leaves unsupported wording in copy, more often than
     * the check allows. Each round is one small request for those lines alone, checked again by the
     * same deterministic rules; a pack that still fails is returned as it is, for a person to fix.
     */
    private function repaired(GenerationResult $result, string $model, SourceSnapshot $source): GenerationResult
    {
        for ($round = 1; $round <= self::REPAIR_ROUNDS; $round++) {
            $lines = $this->blockedLines($result, $source);
            if ($lines === [] || ! app(GenerationDeadline::class)->allowsAnotherAttempt()) {
                break;
            }
            try {
                $answer = $this->client->respond($this->repairPayload($model, $lines));
            } catch (OpenAIResponseException $exception) {
                Log::warning('OpenAI campaign-pack repair failed; the pack is kept as generated.', ['model' => $model, 'round' => $round, 'message' => $exception->getMessage()]);
                break;
            }

            // An answer is paid for whether or not its rewrites can be read.
            $content = $result->content;
            $decoded = json_decode((string) $answer['text'], true);
            foreach (is_array($decoded) && is_array($decoded['rewrites'] ?? null) ? $decoded['rewrites'] : [] as $rewrite) {
                $path = is_array($rewrite) ? (string) ($rewrite['id'] ?? '') : '';
                $text = is_array($rewrite) ? trim((string) ($rewrite['text'] ?? '')) : '';
                if (! isset($lines[$path]) || $text === '') {
                    continue;
                }
                data_set($content, $path, $text);
                // The shot a segment is filmed for repeats its line.
                if (preg_match('/^creative_routes\.(\d+)\.voiceover\.(\d+)\.line$/', $path, $at)) {
                    $shotId = $content['creative_routes'][$at[1]]['voiceover'][$at[2]]['shot_id'] ?? null;
                    foreach ($content['creative_routes'][$at[1]]['shot_plan'] ?? [] as $shotIndex => $shot) {
                        if (($shot['id'] ?? null) === $shotId && ($shot['voiceover_line'] ?? null) === $lines[$path]['text']) {
                            $content['creative_routes'][$at[1]]['shot_plan'][$shotIndex]['voiceover_line'] = $text;
                        }
                    }
                }
            }
            $result = $this->withUsage($result, $answer['input_tokens'], $answer['cached_input_tokens'], $answer['output_tokens'], $answer['latency_ms'], $content);
        }

        return $result;
    }

    /** @return array<string, array{text: string, problem: string}> content path => the line and why the approval check blocks it */
    private function blockedLines(GenerationResult $result, SourceSnapshot $source): array
    {
        $lines = [];
        foreach ($result->content['creative_routes'] ?? [] as $routeIndex => $route) {
            foreach (is_array($route['voiceover'] ?? null) ? $route['voiceover'] : [] as $segmentIndex => $segment) {
                if (is_array($segment) && (VoiceoverTiming::pace($segment) ?? 0) > VoiceoverTiming::MAX_PACE) {
                    $lines["creative_routes.{$routeIndex}.voiceover.{$segmentIndex}.line"] = [
                        'text' => (string) $segment['line'],
                        'problem' => 'Too long to say in its '.((float) $segment['end_seconds'] - (float) $segment['start_seconds']).' seconds. It has '.VoiceoverTiming::words((string) $segment['line']).' words and may have at most '.VoiceoverTiming::maxWords($segment).'.',
                    ];
                }
            }
        }
        foreach (app(CampaignPackSafetyValidator::class)->issues($result->content, $result->evidence, $result->complianceFlags, $source) as $issue) {
            // Only copy is reworded: a verified fact repeats its evidence record word for word, and a
            // price field holds the product's own price.
            if (isset($issue['path'], $issue['text']) && ! preg_match('/\.verified_facts\.|(^|\.)price$/', $issue['path'])) {
                $lines[$issue['path']] ??= [
                    'text' => $issue['text'],
                    'problem' => isset($issue['term'])
                        ? "Says \"{$issue['term']}\", which the product page does not support. Say it without that."
                        : 'States a price the product page does not show. Say it without that price.',
                ];
            }
        }

        return $lines;
    }

    private function repairPayload(string $model, array $lines): array
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
                        'text' => 'You fix lines of a product marketing pack that failed a check. Rewrite each line so its problem is gone. Keep its meaning, tone and language, and keep it a complete, natural line. Use only what the line already says: add no facts, claims, numbers, prices or offers. When a word limit is given, count the words of your line before you return it. Return every line under the id it came with.',
                    ]],
                ],
                [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => CampaignPackEvidence::json(array_map(fn (string $path) => ['id' => $path] + $lines[$path], array_keys($lines)), JSON_PRETTY_PRINT),
                    ]],
                ],
            ],
            'text' => [
                'format' => OpenAIClient::jsonFormat('line_rewrites', [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['rewrites'],
                    'properties' => ['rewrites' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'text'],
                        'properties' => ['id' => ['type' => 'string'], 'text' => ['type' => 'string']],
                    ]]],
                ]),
            ],
        ];
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
        // Rules gpt-5.4-mini needs spelled out; Gemini keeps to the shared instructions without them.
        return CampaignPackBlueprint::instructions($product->offeringType())."\n\n".<<<'PROMPT'
Word budget for voiceover: aim for about 2 words for each second of a segment and never write more than 2.5, so 2 seconds hold at most 5 words, 3 hold 7, 4 hold 10, 5 hold 12 and 6 hold 15. Count the words of every line before you return it. When a line is over, shorten it or give its segment more time.

Evidence records and compliance_flags describe only statements this pack makes. Never add a record or a flag for a claim you decided not to write: list it in product_truth.prohibited_claims instead. A finished pack makes no unsafe statement, so it has no evidence record with an unsafe status and no "blocked" flag: reword a doubtful statement until the source supports it, or leave it out. If one does remain, its evidence record needs a compliance flag whose claim repeats that record's claim word for word.

An evidence excerpt is one unbroken passage copied exactly from the source: no "..." and no joining of separate passages. Its source is the SOURCE URL, or "team_confirmed" only when the excerpt is copied from a TEAM-CONFIRMED FACTS section in the request.
PROMPT;
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
