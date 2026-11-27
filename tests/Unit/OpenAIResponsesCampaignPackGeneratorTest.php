<?php

namespace Tests\Unit;

use App\Exceptions\OpenAIResponseException;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Services\MockCampaignPackGenerator;
use App\Services\OpenAIResponsesCampaignPackGenerator;
use App\Support\VoiceoverTiming;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIResponsesCampaignPackGeneratorTest extends TestCase
{
    private const LONG_LINE = 'This opening line has far too many words in it to be spoken clearly inside five short seconds';

    public function test_it_requests_strict_structured_output_and_maps_provider_usage(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('campaigns.openai.model', 'gpt-5.4-mini');
        config()->set('campaigns.openai.retry_backoff_ms', [0, 0]);
        $output = $this->campaignOutput();
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_test_123',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => json_encode($output)]],
                ]],
                'usage' => [
                    'input_tokens' => 1200,
                    'input_tokens_details' => ['cached_tokens' => 200],
                    'output_tokens' => 500,
                ],
            ], 200, ['x-request-id' => 'req_test_123']),
        ]);

        $result = app(OpenAIResponsesCampaignPackGenerator::class)->generate(
            new Product(['name' => 'Canvas Tote', 'price' => '$89']),
            new SourceSnapshot(['url' => 'https://example.com/tote']),
            ['description' => 'Everyday tote', 'content' => 'A roomy canvas tote.', 'product_truth' => ['name' => 'Canvas Tote']],
        );

        $this->assertSame('openai', $result->provider);
        $this->assertSame('gpt-5.4-mini', $result->model);
        $this->assertSame(1200, $result->inputTokens);
        $this->assertSame(200, $result->cachedInputTokens);
        $this->assertStringContainsString('Canvas Tote', $result->content['overview']['summary']);
        $this->assertCount(1, $result->evidence);
        $this->assertSame('req_test_123', $result->providerRequestId);
        $this->assertNotNull($result->providerLatencyMs);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request['text']['format']['type'] === 'json_schema'
                && $request['text']['format']['strict'] === true
                && $request['text']['format']['schema']['additionalProperties'] === false
                && $request['store'] === false
                && $request['model'] === 'gpt-5.4-mini';
        });
    }

    public function test_it_retries_a_rate_limited_response_with_the_same_structured_schema(): void
    {
        $this->configureGenerator();
        Http::fakeSequence()
            ->push(['error' => ['message' => 'Slow down']], 429)
            ->push($this->responsePayload($this->campaignOutput()));

        $result = $this->generate();

        $this->assertSame('openai', $result->provider);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['text']['format']['strict'] === true);
    }

    public function test_it_uses_vercel_oidc_only_for_the_ai_gateway(): void
    {
        config()->set('services.openai.api_key', null);
        config()->set('services.ai_gateway.api_key', null);
        config()->set('services.ai_gateway.oidc_token', 'gateway-oidc-token');
        config()->set('campaigns.openai.base_url', 'https://ai-gateway.vercel.sh/v1');
        config()->set('campaigns.openai.retry_attempts', 1);
        Http::fake(['ai-gateway.vercel.sh/v1/responses' => Http::response($this->responsePayload($this->campaignOutput()))]);

        $this->assertSame('openai', $this->generate()->provider);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer gateway-oidc-token') && $request->url() === 'https://ai-gateway.vercel.sh/v1/responses');
    }

    public function test_it_uses_the_vercel_oidc_request_header_for_the_ai_gateway(): void
    {
        config()->set('services.openai.api_key', null);
        config()->set('services.ai_gateway.api_key', null);
        config()->set('services.ai_gateway.oidc_token', null);
        config()->set('campaigns.openai.base_url', 'https://ai-gateway.vercel.sh/v1');
        config()->set('campaigns.openai.retry_attempts', 1);
        request()->headers->set('x-vercel-oidc-token', 'gateway-request-oidc-token');
        Http::fake(['ai-gateway.vercel.sh/v1/responses' => Http::response($this->responsePayload($this->campaignOutput()))]);

        $this->assertSame('openai', $this->generate()->provider);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer gateway-request-oidc-token'));
    }

    public function test_it_uses_a_configured_model_fallback_after_a_provider_error(): void
    {
        $this->configureGenerator();
        config()->set('campaigns.openai.retry_attempts', 1);
        config()->set('campaigns.openai.fallback_models', ['gpt-5.4-mini-fallback']);
        Http::fakeSequence()
            ->push(['error' => ['message' => 'Temporary failure']], 500)
            ->push($this->responsePayload($this->campaignOutput()));

        $result = $this->generate();

        $this->assertSame('gpt-5.4-mini', $result->model);
        $this->assertSame(['gpt-5.4-mini', 'gpt-5.4-mini-fallback'], collect(Http::recorded())->pluck(0)->map(fn (Request $request) => $request['model'])->all());
    }

    public function test_it_retries_malformed_structured_output(): void
    {
        $this->configureGenerator();
        $invalid = $this->campaignOutput();
        unset($invalid['overview']);
        Http::fakeSequence()
            ->push($this->responsePayload($invalid))
            ->push($this->responsePayload($this->campaignOutput()));

        $result = $this->generate();

        $this->assertStringContainsString('Canvas Tote', $result->content['overview']['summary']);
        Http::assertSentCount(2);
    }

    public function test_a_voiceover_line_too_long_for_its_seconds_is_rewritten_within_its_word_budget(): void
    {
        $this->configureGenerator();
        Http::fakeSequence()
            ->push($this->responsePayload($this->outputWithLongLine()))
            ->push($this->responsePayload(['rewrites' => [
                ['id' => 'creative_routes.0.voiceover.0.line', 'text' => 'This opening line now fits its five seconds.'],
                ['id' => 'overview.summary', 'text' => 'A line nobody asked to change.'],
            ]]));

        $result = $this->generate();

        $segment = $result->content['creative_routes'][0]['voiceover'][0];
        $this->assertSame('This opening line now fits its five seconds.', $segment['line']);
        $this->assertLessThanOrEqual(VoiceoverTiming::MAX_PACE, VoiceoverTiming::pace($segment));
        $this->assertSame('This opening line now fits its five seconds.', $result->content['creative_routes'][0]['shot_plan'][0]['voiceover_line']);
        $this->assertStringContainsString('Canvas Tote', $result->content['overview']['summary']);
        // The rewrite is paid for too.
        $this->assertSame([2400, 400, 1000], [$result->inputTokens, $result->cachedInputTokens, $result->outputTokens]);
        Http::assertSentCount(2);
        $repair = Http::recorded()[1][0];
        $this->assertSame('line_rewrites', $repair['text']['format']['name']);
        $this->assertSame('gpt-5.4-mini', $repair['model']);
        $asked = json_decode($repair['input'][1]['content'][0]['text'], true);
        $this->assertSame(['creative_routes.0.voiceover.0.line'], array_column($asked, 'id'));
        $this->assertSame('Too long to say in its 5 seconds. It has 18 words and may have at most 14.', $asked[0]['problem']);
    }

    public function test_a_line_still_too_long_after_two_rewrites_is_kept_for_a_person(): void
    {
        $this->configureGenerator();
        $unchanged = $this->responsePayload(['rewrites' => [['id' => 'creative_routes.0.voiceover.0.line', 'text' => self::LONG_LINE]]]);
        Http::fakeSequence()->push($this->responsePayload($this->outputWithLongLine()))->push($unchanged)->push($unchanged);

        $this->assertSame(self::LONG_LINE, $this->generate()->content['creative_routes'][0]['voiceover'][0]['line']);
        Http::assertSentCount(3);
    }

    public function test_a_failed_rewrite_keeps_the_generated_pack(): void
    {
        $this->configureGenerator();
        Http::fakeSequence()->push($this->responsePayload($this->outputWithLongLine()))->push(['error' => ['message' => 'Unavailable']], 503);

        $this->assertSame(self::LONG_LINE, $this->generate()->content['creative_routes'][0]['voiceover'][0]['line']);
        Http::assertSentCount(2);
    }

    public function test_unsupported_wording_in_copy_is_rewritten_but_price_fields_are_left_alone(): void
    {
        $this->configureGenerator();
        $output = $this->campaignOutput();
        $output['creative_routes'][0]['hooks'][0] = 'Guaranteed to fit your day';
        Http::fakeSequence()
            ->push($this->responsePayload($output))
            ->push($this->responsePayload(['rewrites' => [['id' => 'creative_routes.0.hooks.0', 'text' => 'Made to fit your day']]]));

        $result = $this->generate();

        $this->assertSame('Made to fit your day', $result->content['creative_routes'][0]['hooks'][0]);
        Http::assertSentCount(2);
        // The snapshot shows no price, so the price fields are blocked too; they are never sent for rewording.
        $asked = json_decode(Http::recorded()[1][0]['input'][1]['content'][0]['text'], true);
        $this->assertSame(['creative_routes.0.hooks.0'], array_column($asked, 'id'));
        $this->assertStringContainsString('"guaranteed"', $asked[0]['problem']);
    }

    public function test_a_pack_that_records_an_unsafe_claim_is_asked_for_once_more_without_it(): void
    {
        $this->configureGenerator();
        Http::fakeSequence()
            ->push($this->responsePayload($this->outputWithUnsafeClaim()))
            ->push($this->responsePayload($this->campaignOutput()));

        $result = $this->generate();

        $this->assertNotContains('unsupported', array_column($result->evidence, 'status'));
        // Both packs were paid for.
        $this->assertSame([2400, 400, 1000], [$result->inputTokens, $result->cachedInputTokens, $result->outputTokens]);
        Http::assertSentCount(2);
        $this->assertCount(1, Http::recorded()[0][0]['input'][1]['content']);
        $reason = Http::recorded()[1][0]['input'][1]['content'][1]['text'];
        $this->assertStringContainsString("- The tote is made in the USA\n", $reason);
        $this->assertStringContainsString('product_truth.prohibited_claims', $reason);
    }

    public function test_the_pack_with_an_unsafe_claim_is_kept_when_asking_again_fails(): void
    {
        $this->configureGenerator();
        Http::fakeSequence()
            ->push($this->responsePayload($this->outputWithUnsafeClaim()))
            ->push(['error' => ['message' => 'Unavailable']], 503);

        $this->assertContains('unsupported', array_column($this->generate()->evidence, 'status'));
        Http::assertSentCount(2);
    }

    public function test_it_rejects_a_refusal_without_retrying(): void
    {
        $this->configureGenerator();
        config()->set('campaigns.openai.fallback_models', ['gpt-5.4-mini-fallback']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that request.']],
                ]],
            ]),
        ]);

        try {
            $this->generate();
            $this->fail('Expected the provider refusal to be surfaced.');
        } catch (OpenAIResponseException $exception) {
            $this->assertSame('openai_refusal', $exception->errorCode);
            $this->assertStringContainsString('declined', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_it_retries_a_connection_timeout(): void
    {
        $this->configureGenerator();
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            if (++$attempt === 1) {
                throw new ConnectionException('Timed out');
            }

            return Http::response($this->responsePayload($this->campaignOutput()));
        });

        $this->assertSame('openai', $this->generate()->provider);
        $this->assertSame(2, $attempt);
    }

    public function test_it_rejects_an_unsupported_claim_that_is_not_flagged(): void
    {
        $this->configureGenerator();
        $output = $this->campaignOutput();
        $output['evidence'][] = ['id' => 'claim-unsafe', 'claim' => 'Waterproof', 'source' => '', 'excerpt' => '', 'status' => 'unsupported'];
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->responsePayload($output))]);

        $this->expectException(OpenAIResponseException::class);
        $this->expectExceptionMessage('unsafe claim without a compliance flag');
        $this->generate();
    }

    public function test_team_confirmed_evidence_is_accepted_only_when_it_quotes_team_knowledge(): void
    {
        $this->configureGenerator();
        config()->set('campaigns.openai.retry_attempts', 1);
        $output = $this->campaignOutput();
        $output['evidence'][0]['source'] = 'team_confirmed';
        $output['evidence'][0]['excerpt'] = 'Home office workers';
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->responsePayload($output))]);
        $page = ['description' => 'Everyday tote', 'content' => 'A roomy canvas tote.', 'product_truth' => ['name' => 'Canvas Tote']];

        $result = app(OpenAIResponsesCampaignPackGenerator::class)->generate(
            new Product(['name' => 'Canvas Tote', 'price' => '$89']),
            new SourceSnapshot(['url' => 'https://example.com/tote']),
            $page + ['team_knowledge' => ['audience' => 'Home office workers']],
        );
        $this->assertSame('team_confirmed', $result->evidence[0]['source']);
        Http::assertSent(fn (Request $request) => str_contains(json_encode($request->data()), 'TEAM-CONFIRMED FACTS'));

        $this->expectException(OpenAIResponseException::class);
        app(OpenAIResponsesCampaignPackGenerator::class)->generate(
            new Product(['name' => 'Canvas Tote', 'price' => '$89']),
            new SourceSnapshot(['url' => 'https://example.com/tote']),
            $page,
        );
    }

    private function configureGenerator(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('campaigns.openai.model', 'gpt-5.4-mini');
        config()->set('campaigns.openai.fallback_models', []);
        config()->set('campaigns.openai.retry_attempts', 2);
        config()->set('campaigns.openai.retry_backoff_ms', [0, 0]);
    }

    private function generate()
    {
        return app(OpenAIResponsesCampaignPackGenerator::class)->generate(
            new Product(['name' => 'Canvas Tote', 'price' => '$89']),
            new SourceSnapshot(['url' => 'https://example.com/tote']),
            ['description' => 'Everyday tote', 'content' => 'A roomy canvas tote.', 'product_truth' => ['name' => 'Canvas Tote']],
        );
    }

    private function responsePayload(array $output): array
    {
        return [
            'id' => 'resp_test_123',
            'model' => 'gpt-5.4-mini',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode($output)]],
            ]],
            'usage' => [
                'input_tokens' => 1200,
                'input_tokens_details' => ['cached_tokens' => 200],
                'output_tokens' => 500,
            ],
        ];
    }

    /** A pack whose first voiceover line has 18 words for a 5-second segment: 216 words per minute. */
    private function outputWithLongLine(): array
    {
        $output = $this->campaignOutput();
        $output['creative_routes'][0]['voiceover'][0]['line'] = self::LONG_LINE;
        $output['creative_routes'][0]['shot_plan'][0]['voiceover_line'] = self::LONG_LINE;

        return $output;
    }

    private function outputWithUnsafeClaim(): array
    {
        $output = $this->campaignOutput();
        $output['evidence'][] = ['id' => 'claim-usa', 'claim' => 'The tote is made in the USA', 'source' => 'https://example.com/tote', 'excerpt' => '', 'status' => 'unsupported'];
        $output['compliance_flags'][] = ['severity' => 'warning', 'claim' => 'The tote is made in the USA', 'reason' => 'The page names no origin.'];

        return $output;
    }

    private function campaignOutput(): array
    {
        $result = app(MockCampaignPackGenerator::class)->generate(
            new Product(['name' => 'Canvas Tote', 'price' => '$89']),
            new SourceSnapshot(['url' => 'https://example.com/tote']),
            ['description' => 'Everyday tote', 'content' => 'A roomy canvas tote.', 'product_truth' => ['name' => 'Canvas Tote']],
        );

        return $result->content + ['evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags];
    }
}
