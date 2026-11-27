<?php

namespace Tests\Unit;

use App\Enums\OfferingType;
use App\Models\Product;
use App\Models\SourceSnapshot;
use App\Services\CampaignPackBlueprint;
use App\Services\CampaignPackFinalizer;
use App\Services\GoogleVertexAICampaignPackGenerator;
use App\Services\MockCampaignPackGenerator;
use App\Services\OpenAIResponsesCampaignPackGenerator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfferingTypeCopyTest extends TestCase
{
    private const PHYSICAL_CTA_RULE = 'Every CTA must suit buying a physical product online';

    public function test_physical_instructions_are_unchanged(): void
    {
        $instructions = CampaignPackBlueprint::instructions();

        $this->assertSame($instructions, CampaignPackBlueprint::instructions(OfferingType::Physical));
        $this->assertSame('bcaef96eb70f8c037d9c90417a631be9', md5($instructions), 'The physical prompt is the one used before offering types existed, byte for byte.');
        $this->assertStringContainsString(self::PHYSICAL_CTA_RULE.' (Shop Now, Buy Now, Order Now, Learn More, or Get Offer only when the source states an offer); never use service or booking CTAs such as Book Now or Sign Up.', $instructions);
        $this->assertStringNotContainsString('OFFERING TYPE', $instructions);
    }

    public function test_software_and_service_instructions_use_their_own_ctas_and_guidance(): void
    {
        foreach ([OfferingType::Software, OfferingType::Service] as $type) {
            $instructions = CampaignPackBlueprint::instructions($type);

            $this->assertStringNotContainsString(self::PHYSICAL_CTA_RULE, $instructions);
            $this->assertStringContainsString('('.implode(', ', $type->adCtas()).', or Get Offer only when the source states an offer)', $instructions);
            $this->assertStringEndsWith("\n\n".$type->promptGuidance(), $instructions);
        }
    }

    public function test_vertex_sends_the_products_type_with_the_instructions_and_the_facts(): void
    {
        $this->configureGoogle();
        request()->headers->set('x-vercel-oidc-token', 'vercel-production-jwt');

        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            return match (true) {
                str_contains($request->url(), 'oidc.vercel.com') => Http::response(['token' => 'vercel-google-audience-jwt']),
                str_contains($request->url(), 'sts.googleapis.com') => Http::response(['access_token' => 'federated-token', 'expires_in' => 3600]),
                str_contains($request->url(), 'iamcredentials.googleapis.com') => Http::response(['accessToken' => 'google-access-token', 'expireTime' => now()->addHour()->toIso8601String()]),
                default => tap(Http::response([
                    'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($this->campaignOutput(OfferingType::Physical))]]]]],
                ]), function () use (&$sent, $request) {
                    $sent[] = $request;
                }),
            };
        });

        foreach (OfferingType::cases() as $index => $type) {
            app(GoogleVertexAICampaignPackGenerator::class)->generate($this->product($type), $this->source(), $this->page());

            $this->assertCount($index + 1, $sent);
            $this->assertPrompt($type, $sent[$index]['systemInstruction']['parts'][0]['text'], $sent[$index]['contents'][0]['parts'][0]['text']);
        }
    }

    public function test_openai_sends_the_products_type_with_the_instructions_and_the_facts(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        config()->set('campaigns.openai.model', 'gpt-5.4-mini');
        config()->set('campaigns.openai.fallback_models', []);
        config()->set('campaigns.openai.retry_attempts', 1);
        config()->set('campaigns.openai.retry_backoff_ms', [0]);

        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $sent[] = $request;

            return Http::response([
                'model' => 'gpt-5.4-mini',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($this->campaignOutput(OfferingType::Physical))]]]],
            ]);
        });

        foreach (OfferingType::cases() as $index => $type) {
            app(OpenAIResponsesCampaignPackGenerator::class)->generate($this->product($type), $this->source(), $this->page());

            $this->assertCount($index + 1, $sent);
            // The shared instructions come first, then the rules only gpt-5.4-mini needs.
            [$shared, $own] = explode("\n\nWord budget for voiceover:", $sent[$index]['input'][0]['content'][0]['text'], 2);
            $this->assertStringContainsString('never write more than 2.5', $own);
            $this->assertPrompt($type, $shared, $sent[$index]['input'][1]['content'][0]['text']);
        }
    }

    public function test_the_mock_generator_writes_ctas_for_the_type(): void
    {
        $expected = [OfferingType::Physical->value => 'Learn more', OfferingType::Software->value => 'Start Free Trial', OfferingType::Service->value => 'Book a Call'];

        foreach (OfferingType::cases() as $type) {
            $content = $this->campaignOutput($type);

            $this->assertSame([$expected[$type->value]], $content['marketing_hub']['channels']['meta_ads']['ctas']);
            $this->assertSame([$expected[$type->value]], array_values(array_unique(array_column($content['creative_routes'][0]['platform_assets'], 'cta'))));
        }
    }

    public function test_the_finalizer_turns_retail_ctas_into_the_types_default(): void
    {
        $finalizer = app(CampaignPackFinalizer::class);
        $content = fn (array $ctas, string $routeCta) => [
            'marketing_hub' => ['channels' => ['meta_ads' => ['ctas' => $ctas]]],
            'creative_routes' => [['platform_assets' => ['meta_ads' => ['cta' => $routeCta]]]],
        ];

        $software = $finalizer->finalize($content(['Start Free Trial', 'Shop Now', 'Book a Demo', 'Add to cart'], 'Buy Now!'), OfferingType::Software);
        $this->assertSame(['Start Free Trial', 'Book a Demo'], $software['marketing_hub']['channels']['meta_ads']['ctas']);
        $this->assertSame('Start Free Trial', $software['creative_routes'][0]['platform_assets']['meta_ads']['cta']);

        $service = $finalizer->finalize($content(['Book a Call', 'Get a Quote', 'Order Now'], 'Shop now.'), OfferingType::Service);
        $this->assertSame(['Book a Call', 'Get a Quote'], $service['marketing_hub']['channels']['meta_ads']['ctas']);
        $this->assertSame('Book a Call', $service['creative_routes'][0]['platform_assets']['meta_ads']['cta']);

        $physical = $finalizer->finalize($content(['Book Now', 'Learn More'], 'Start Free Trial'));
        $this->assertSame(['Shop Now', 'Learn More'], $physical['marketing_hub']['channels']['meta_ads']['ctas']);
        $this->assertSame('Shop Now', $physical['creative_routes'][0]['platform_assets']['meta_ads']['cta']);
    }

    private function assertPrompt(OfferingType $type, string $instructions, string $prompt): void
    {
        $this->assertSame(CampaignPackBlueprint::instructions($type), $instructions);
        if ($type === OfferingType::Physical) {
            $this->assertStringContainsString(self::PHYSICAL_CTA_RULE, $instructions);
            $this->assertStringStartsWith("PRODUCT NAME\nCanvas Tote\n\nSUPPLIED PRICE", $prompt);

            return;
        }
        $this->assertStringNotContainsString(self::PHYSICAL_CTA_RULE, $instructions);
        $this->assertStringContainsString($type->promptGuidance(), $instructions);
        $this->assertStringContainsString(implode(', ', $type->adCtas()), $instructions);
        $this->assertStringStartsWith("PRODUCT NAME\nCanvas Tote\n\nOFFERING TYPE\n{$type->productOption()}\n\nSUPPLIED PRICE", $prompt);
    }

    private function product(OfferingType $type): Product
    {
        return new Product(['name' => 'Canvas Tote', 'price' => '$89', 'offering_type' => $type->value]);
    }

    private function source(): SourceSnapshot
    {
        return new SourceSnapshot(['url' => 'https://example.com/tote']);
    }

    private function page(): array
    {
        return ['description' => 'Everyday tote', 'content' => 'A roomy canvas tote.', 'product_truth' => ['name' => 'Canvas Tote']];
    }

    private function campaignOutput(OfferingType $type): array
    {
        $result = app(MockCampaignPackGenerator::class)->generate($this->product($type), $this->source(), $this->page());

        return $result->content + ['evidence' => $result->evidence, 'compliance_flags' => $result->complianceFlags];
    }

    private function configureGoogle(): void
    {
        config()->set('campaigns.google.project', 'callingdesk-marketingowl');
        config()->set('campaigns.google.location', 'global');
        config()->set('campaigns.google.model', 'gemini-3.5-flash');
        config()->set('campaigns.google.base_url', 'https://aiplatform.googleapis.com/v1');
        config()->set('campaigns.google.sts_audience', '//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/pool/providers/provider');
        config()->set('campaigns.google.vercel_audience', 'https://iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/pool/providers/provider');
        config()->set('campaigns.google.vercel_token_exchange_url', 'https://oidc.vercel.com/~token');
        config()->set('campaigns.google.sts_token_url', 'https://sts.googleapis.com/v1/token');
        config()->set('campaigns.google.service_account_impersonation_url', 'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/test@example.iam.gserviceaccount.com:generateAccessToken');
        config()->set('campaigns.google.retry_attempts', 1);
        config()->set('campaigns.google.retry_backoff_ms', [0]);
    }
}
