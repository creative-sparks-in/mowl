<?php

namespace Tests\Feature;

use App\Enums\OfferingType;
use App\Jobs\GenerateCampaignPack;
use App\Models\CampaignGenerationJob;
use App\Models\CampaignPack;
use App\Models\Product;
use App\Models\Workspace;
use App\Services\CampaignGeneratorManager;
use App\Services\CampaignPackSafetyValidator;
use App\Services\GoogleVertexAIClient;
use App\Services\Pages\Document\Document;
use App\Services\Pages\Document\ProductBrief;
use App\Services\Pages\Document\Templates;
use App\Services\Pages\Writing\GooglePageWriter;
use App\Services\Pages\Writing\MockPageWriter;
use App\Services\ProductPageFetcher;
use App\Services\ProviderCostCalculator;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfferingTypeCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_software_pack_keeps_its_trial_cta_through_the_job(): void
    {
        $product = $this->product(OfferingType::Software, 'https://93.184.216.34/app');
        $source = $product->sourceSnapshots()->firstOrFail();
        $source->update(['status' => 'pending']);
        $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'Pack', 'status' => 'queued', 'current_version' => 0]);
        $job = CampaignGenerationJob::create(['workspace_id' => $product->brand->workspace_id, 'campaign_pack_id' => $pack->id, 'source_snapshot_id' => $source->id]);
        Http::fake(['93.184.216.34/*' => Http::response('<html><head><title>Planner</title></head><body>Plan your week.</body></html>', 200, ['Content-Type' => 'text/html'])]);

        (new GenerateCampaignPack($job->id))->handle(app(ProductPageFetcher::class), app(CampaignGeneratorManager::class), app(ProviderCostCalculator::class));

        $content = $pack->fresh()->versions()->firstOrFail()->content;
        $this->assertSame(['Start Free Trial'], $content['marketing_hub']['channels']['meta_ads']['ctas'], 'The finalizer is told the type, so it does not turn the trial CTA into Shop Now.');
        $this->assertSame('Start Free Trial', $content['creative_routes'][0]['platform_assets']['meta_ads']['cta']);
    }

    public function test_templated_pages_use_the_types_button_label(): void
    {
        foreach ([OfferingType::Physical->value => 'Buy now', OfferingType::Software->value => 'Start free trial', OfferingType::Service->value => 'Book a call'] as $type => $label) {
            $brief = ProductBrief::for($this->product(OfferingType::from($type)));
            $document = Templates::document('studio', $brief);

            $this->assertSame($label, Document::first($document, 'product_hero')['settings']['button_label']);
            $this->assertSame($label, Document::first($document, 'cta')['settings']['button_label']);
            if ($type !== OfferingType::Physical->value) {
                $this->assertStringNotContainsString('Buy now', json_encode($document));
            }

            $ids = array_column($document['sections'], 'id');
            $written = app(MockPageWriter::class)->write($brief, $document, $ids, '')->sections;
            $this->assertSame($label, $written[Document::first($document, 'product_hero')['id']]['button_label'], 'The mock writer fills from the templates.');
        }
    }

    public function test_the_page_writer_prompt_carries_the_guidance_for_software_only(): void
    {
        $prompts = [];
        $this->mock(GoogleVertexAIClient::class, function ($mock) use (&$prompts): void {
            $mock->shouldReceive('generateContent')->andReturnUsing(function (array $payload) use (&$prompts): array {
                $prompts[] = $payload['contents'][0]['parts'][0]['text'];

                return [new Response(new Psr7Response(200, [], json_encode([
                    'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode(['sections' => []])]]]]],
                ]))), 10];
            });
        });

        foreach ([OfferingType::Physical, OfferingType::Software] as $type) {
            $brief = ProductBrief::for($this->product($type));
            $document = Templates::document('studio', $brief);
            app(GooglePageWriter::class)->write($brief, $document, [Document::first($document, 'product_hero')['id']], '');
        }

        $this->assertStringNotContainsString('OFFERING TYPE', $prompts[0]);
        $this->assertStringContainsString(OfferingType::Software->promptGuidance(), $prompts[1]);
    }

    public function test_a_confirmed_price_with_a_period_still_supports_its_amount(): void
    {
        $validator = app(CampaignPackSafetyValidator::class);
        foreach ([
            [OfferingType::Software, '₹999 per month', 'Plans start at ₹999 per month.', 'Plans start at ₹1,299 per month.', '1299'],
            [OfferingType::Service, 'From ₹5,000 per project', 'Projects from ₹5,000.', 'Projects from ₹7,500.', '7500'],
        ] as [$type, $price, $supported, $unsupported, $amount]) {
            $product = $this->product($type);
            $product->facts()->updateOrCreate(['key' => 'price'], ['workspace_id' => $product->brand->workspace_id, 'confirmed_value' => $price, 'status' => 'ok']);
            $prices = $validator->supportedPrices($product->sourceSnapshots()->firstOrFail());

            $this->assertSame([], $validator->unsupportedPrices($supported, $prices));
            $this->assertSame([], $validator->unsupportedPrices($price, $prices, true));
            $this->assertSame([$amount], $validator->unsupportedPrices($unsupported, $prices));
        }
    }

    private function product(OfferingType $type, string $url = 'https://example.com/item'): Product
    {
        $workspace = Workspace::create(['name' => 'Agency']);
        $product = $workspace->brands()->create(['name' => 'Northline', 'offering_type' => $type->value])
            ->products()->create(['name' => 'Week Planner', 'summary' => 'Plan your week in one place.']);
        $product->sourceSnapshots()->create([
            'url' => $url, 'content_hash' => hash('sha256', $url), 'status' => 'ready',
            'extracted_content' => 'Plan your week in one place.', 'extracted_truth' => ['name' => 'Week Planner', 'description' => 'Plan your week in one place.'],
        ]);

        return $product;
    }
}
