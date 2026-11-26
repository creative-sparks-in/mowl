<?php

namespace Tests\Unit;

use App\Contracts\BannerModels;
use App\Models\BannerCreative;
use App\Models\MediaAsset;
use App\Services\BannerCopySelector;
use App\Services\BannerGeneratorManager;
use App\Services\GoogleBannerModels;
use App\Services\ImageModelBannerGenerator;
use App\Services\OpenAIBannerModels;
use App\Services\ProviderCostCalculator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** The banner pipeline with BANNER_GENERATOR=openai: images from gpt-image models, checks from the campaign model. */
class OpenAIBannerGeneratorTest extends TestCase
{
    private const SCENE = BannerCopySelector::SCENE_OPENING.'Kindle Stand. An empty set. No text or logos.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->put('product.png', 'product-bytes');
        config()->set('campaigns.banners.generator', 'openai');
        config()->set('services.openai.api_key', 'test-key');
        config()->set('campaigns.openai.base_url', 'https://api.openai.com/v1');
        config()->set('campaigns.openai.model', 'gpt-5.4-mini');
        config()->set('campaigns.banners.openai.models', ['gpt-image-2', 'gpt-image-1.5']);
        config()->set('campaigns.banners.inspect_images', true);
        config()->set('campaigns.banners.inspection_attempts', 2);
        config()->set('campaigns.banners.rate_limit_backoff_seconds', [10, 25, 45]);
        config()->set('campaigns.banners.composite_product', false);
        config()->set('campaigns.banners.check_product', false);
    }

    public function test_a_composite_banner_is_cut_out_drawn_and_checked_on_openai(): void
    {
        config()->set('campaigns.banners.composite_product', true);
        config()->set('campaigns.banners.check_product', true);
        $this->fakeOpenAI([self::keyed()], ['banner-background']);

        $result = app(ImageModelBannerGenerator::class)->generate(new BannerCreative(['prompt' => self::SCENE]), collect([$this->asset()]));

        $this->assertSame(['banner-background', 'openai', 'gpt-image-2', 500, 44, 1056], [$result->imageBytes, $result->provider, $result->model, $result->inputTokens, $result->outputTextTokens, $result->outputImageTokens]);
        $this->assertSame([20, 16], array_slice(getimagesizefromstring((string) $result->productCutout), 0, 2));
        // The cutout at gpt-image-2's rates, and its two checks and the scene's check at gpt-5.4-mini's.
        $this->assertEqualsWithDelta((500 * 8.00 + 1056 * 30.00) / 1_000_000 + 3 * (300 * 0.75 + 12 * 4.50) / 1_000_000, $result->additionalCost, 0.000001);
        $sent = collect(Http::recorded())->map(fn (array $pair) => $pair[0]);
        $this->assertSame(['images/edits', 'responses', 'responses', 'images/generations', 'responses'], $sent->map(fn (Request $request) => str_replace('https://api.openai.com/v1/', '', $request->url()))->all());
        // The cutout redraw keeps the photo's shape (unmeasurable here: square); the scene is the banner's shape and never sees the product.
        $edit = collect($sent[0]->data())->keyBy('name');
        $this->assertSame(['product-bytes', '1024x1024'], [$edit['image[]']['contents'], $edit['size']['contents']]);
        $this->assertStringContainsString('pure green (#00FF00)', $edit['prompt']['contents']);
        $this->assertSame('1088x1360', $sent[3]['size']);
        $this->assertSame('product_shown', array_keys($sent[4]['text']['format']['schema']['properties'])[1]);
    }

    public function test_a_background_with_third_party_text_is_redrawn_on_openai(): void
    {
        $this->fakeOpenAI(['cover-on-screen', 'blank-screen'], [], [['The Hobbit'], []]);

        $result = app(ImageModelBannerGenerator::class)->generate(new BannerCreative(['prompt' => 'No text or logos.']), collect([$this->asset()]));

        $this->assertSame('blank-screen', $result->imageBytes);
        $this->assertSame([1000, 2 * 1056], [$result->inputTokens, $result->outputImageTokens]);
        $this->assertEqualsWithDelta(2 * (300 * 0.75 + 12 * 4.50) / 1_000_000, $result->additionalCost, 0.000001);
        // With compositing off, the product photo is the reference, and the picture is still the banner's shape.
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/images/edits')
            && collect($request->data())->firstWhere('name', 'size')['contents'] === '1088x1360'
            && collect($request->data())->firstWhere('name', 'image[]')['contents'] === 'product-bytes');
    }

    public function test_a_drawing_every_image_model_refuses_with_429_waits_and_is_retried(): void
    {
        Sleep::fake();
        $refusals = 2;
        Http::fake([
            'api.openai.com/v1/images/generations' => function () use (&$refusals) {
                return $refusals-- > 0 ? Http::response(['error' => ['message' => 'Rate limit reached.']], 429) : Http::response(OpenAIBannerModelsTest::image('scene'));
            },
            'api.openai.com/v1/responses' => Http::response(OpenAIBannerModelsTest::answer(self::inspection([]))),
        ]);

        $result = app(ImageModelBannerGenerator::class)->generate(new BannerCreative(['prompt' => 'A planner app on a desk.']), collect());

        $this->assertSame(['scene', 'gpt-image-2'], [$result->imageBytes, $result->model]);
        Sleep::assertSleptTimes(1);
        $this->assertSame(['gpt-image-2', 'gpt-image-1.5', 'gpt-image-2'], collect(Http::recorded())->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), '/images/generations'))->map(fn (Request $request) => $request['model'])->values()->all());
    }

    public function test_openai_image_models_are_priced_from_their_own_table(): void
    {
        $costs = app(ProviderCostCalculator::class);

        $this->assertSame(0.196, $costs->calculateBanner(10_000, 2_000, 3_000, 'gpt-image-1.5'));
        $this->assertSame(0.17, $costs->calculateBanner(10_000, 2_000, 3_000, 'gpt-image-2'));
        // Any other model, or none, keeps the Gemini image rates.
        $this->assertSame(0.191, $costs->calculateBanner(10_000, 2_000, 3_000, 'gemini-3.1-flash-image'));
    }

    public function test_the_manager_runs_the_same_pipeline_on_either_provider(): void
    {
        $manager = app(BannerGeneratorManager::class);

        $this->assertInstanceOf(ImageModelBannerGenerator::class, $manager->driver());
        $this->assertInstanceOf(OpenAIBannerModels::class, app(BannerModels::class));
        $this->assertSame('gpt-image-2', $manager->model());

        config()->set('campaigns.banners.generator', 'google');
        config()->set('campaigns.banners.model', 'gemini-3.1-flash-image');
        $this->assertInstanceOf(ImageModelBannerGenerator::class, $manager->driver());
        $this->assertInstanceOf(GoogleBannerModels::class, app(BannerModels::class));
        $this->assertSame('gemini-3.1-flash-image', $manager->model());

        config()->set('campaigns.banners.generator', 'mock');
        $this->assertNull($manager->model());
    }

    /**
     * @param  list<string>  $edits  images drawn from a reference
     * @param  list<string>  $generations  images drawn from the prompt alone
     * @param  list<list<string>>  $marks  each check's marks, in order (none once they run out)
     */
    private function fakeOpenAI(array $edits, array $generations, array $marks = []): void
    {
        Http::fake([
            'api.openai.com/v1/images/edits' => function () use (&$edits) {
                return Http::response(OpenAIBannerModelsTest::image(array_shift($edits)));
            },
            'api.openai.com/v1/images/generations' => function () use (&$generations) {
                return Http::response(OpenAIBannerModelsTest::image(array_shift($generations)));
            },
            'api.openai.com/v1/responses' => function (Request $request) use (&$marks) {
                return Http::response(OpenAIBannerModelsTest::answer(isset($request['text']['format']['schema']['properties']['matches'])
                    ? ['matches' => true, 'differences' => '', 'split' => false]
                    : self::inspection(array_shift($marks) ?? [])));
            },
        ]);
    }

    private static function inspection(array $marks): array
    {
        return ['marks' => $marks, 'product_shown' => false, 'spot_found' => false, 'spot_x' => 0, 'spot_y' => 0, 'spot_width' => 0, 'other_objects' => []];
    }

    private function asset(): MediaAsset
    {
        return new MediaAsset(['disk' => 'local', 'path' => 'product.png', 'mime_type' => 'image/png']);
    }

    /** A product on a pure green key, as the cutout step asks the image model for. */
    private static function keyed(): string
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 255, 0));
        imagefilledrectangle($image, 10, 12, 29, 27, imagecolorallocate($image, 240, 240, 240));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
