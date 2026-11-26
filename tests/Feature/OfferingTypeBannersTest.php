<?php

namespace Tests\Feature;

use App\Livewire\CampaignWorkspace;
use App\Models\BannerCreative;
use App\Models\CampaignPack;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BannerBatchCreator;
use App\Services\BannerComposer;
use App\Services\BannerCopySelector;
use App\Services\BannerJobRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OfferingTypeBannersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('campaigns.banners.enabled', true);
        config()->set('campaigns.banners.generator', 'mock');
        config()->set('campaigns.banners.disk', 'local');
        config()->set('campaigns.banners.composite_product', true);
        config()->set('campaigns.processing_mode', 'request');
        Storage::fake('local');
    }

    public function test_a_software_pack_without_a_product_photo_gets_trial_banners_drawn_from_its_own_scene(): void
    {
        [$user, $workspace, $pack] = $this->fixture('software', productPhoto: false);

        $batch = app(BannerBatchCreator::class)->createIncluded($workspace, $pack, $user);

        $this->assertCount(3, $batch->creatives);
        foreach ($batch->creatives as $creative) {
            $this->assertSame('Start free trial', $creative->cta);
            $this->assertStringStartsNotWith(BannerCopySelector::SCENE_OPENING, $creative->prompt);
            $this->assertStringContainsString('no physical product', $creative->prompt);
            $this->assertStringContainsString('bottom-right corner', $creative->prompt);
            $this->assertStringContainsString('Do not include any text', $creative->prompt);
            $this->assertStringContainsString('4:5', $creative->prompt);

            $done = app(BannerJobRunner::class)->run($creative);
            $this->assertSame('completed', $done->status);
            $this->assertSame([1080, 1350], array_slice(getimagesizefromstring(Storage::disk('local')->get($done->output_path)), 0, 2));
            $this->assertSame('image/png', getimagesizefromstring(Storage::disk('local')->get($done->output_path))['mime']);
        }
    }

    public function test_a_software_upload_is_framed_as_an_app_screenshot_and_a_service_ignores_it(): void
    {
        [$user, $workspace, $pack] = $this->fixture('software', productPhoto: false);
        $creative = app(BannerBatchCreator::class)->createIncluded($workspace, $pack, $user)->creatives->firstWhere('layout', 'hero_top');
        $plain = $this->render($creative);
        $this->upload($pack, $workspace, [20, 200, 90]);

        $framed = $this->render($creative);

        $this->assertNotSame(hash('sha256', $plain), hash('sha256', $framed));
        $banner = imagecreatefromstring($framed);
        $slot = BannerComposer::productSlot('hero_top');
        $this->assertSame([20, 200, 90], $this->rgb($banner, $slot['x'], $slot['base'] - 150));

        [$serviceUser, $serviceWorkspace, $service] = $this->fixture('service', productPhoto: false);
        $serviceCreative = app(BannerBatchCreator::class)->createIncluded($serviceWorkspace, $service, $serviceUser)->creatives->firstWhere('layout', 'hero_top');
        $this->assertSame('Book a call', $serviceCreative->cta);
        $this->assertStringStartsNotWith(BannerCopySelector::SCENE_OPENING, $serviceCreative->prompt);
        $this->assertStringContainsString('people or lifestyle scene', $serviceCreative->prompt);
        $before = $this->render($serviceCreative);
        $this->upload($service, $serviceWorkspace, [20, 200, 90]);
        $this->assertSame(hash('sha256', $before), hash('sha256', $this->render($serviceCreative)));
    }

    public function test_physical_banners_still_need_a_product_page_photo_and_keep_their_prompt_and_cta(): void
    {
        [$user, $workspace, $pack] = $this->fixture('physical', productPhoto: true);

        $batch = app(BannerBatchCreator::class)->createIncluded($workspace, $pack, $user);

        foreach ($batch->creatives as $creative) {
            $this->assertSame('Shop now', $creative->cta);
            $this->assertStringStartsWith(BannerCopySelector::SCENE_OPENING.'Source Product.', $creative->prompt);
            $this->assertStringContainsString('do NOT show Source Product', $creative->prompt);
        }

        $pack->product->mediaAssets()->delete();
        $this->upload($pack, $workspace, [20, 200, 90]);
        try {
            app(BannerJobRunner::class)->run($batch->creatives->first());
            $this->fail('A physical banner was generated without a product-page photo.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('No product image is available for banner generation.', $exception->getMessage());
        }

        // An uploaded image is no product-page photo, so a physical product without one stays blocked.
        [$user, $workspace, $pack] = $this->fixture('physical', productPhoto: false);
        $this->upload($pack, $workspace, [20, 200, 90]);
        try {
            app(BannerBatchCreator::class)->createIncluded($workspace, $pack, $user);
            $this->fail('A physical banner batch was created without a product-page photo.');
        } catch (ValidationException $exception) {
            $this->assertSame('No usable product image was found on the product page.', $exception->errors()['banner'][0]);
        }
    }

    public function test_the_banner_step_does_not_ask_software_or_services_for_a_product_photo(): void
    {
        [$user, , $pack] = $this->fixture('software', productPhoto: false);

        Livewire::actingAs($user)->test(CampaignWorkspace::class, ['pack' => $pack])
            ->assertSee('Three 4:5 images built from your approved copy.')
            ->assertDontSee('PRODUCT REFERENCE')
            ->assertDontSee('No usable source image found')
            ->assertDontSee('A usable product-page image is required before generation.')
            ->assertDontSee('Banners need a product photo');

        [$user, , $physical] = $this->fixture('physical', productPhoto: false);
        Livewire::actingAs($user)->test(CampaignWorkspace::class, ['pack' => $physical])
            ->assertSee('No usable source image found')
            ->assertSee('A usable product-page image is required before generation.');
    }

    private function render(BannerCreative $creative): string
    {
        BannerCreative::whereKey($creative->id)->update(['status' => 'queued', 'attempts' => 0]);
        $done = app(BannerJobRunner::class)->run($creative->fresh());
        $this->assertSame('completed', $done->status);

        return Storage::disk('local')->get($done->output_path);
    }

    private function rgb(\GdImage $image, int $x, int $y): array
    {
        $colour = imagecolorat($image, $x, $y);

        return [($colour >> 16) & 255, ($colour >> 8) & 255, $colour & 255];
    }

    private function upload(CampaignPack $pack, Workspace $workspace, array $colour): void
    {
        $image = imagecreatetruecolor(1280, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$colour));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        $path = "page-media/{$workspace->id}/{$pack->product_id}/screen.png";
        Storage::disk('local')->put($path, $bytes);
        $pack->product->mediaAssets()->create([
            'workspace_id' => $workspace->id,
            'type' => 'image',
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'screen.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
            'content_hash' => hash('sha256', $bytes),
            'status' => 'ready',
            'metadata' => ['origin' => 'page_upload', 'width' => 1280, 'height' => 800],
        ]);
    }

    private function fixture(string $type, bool $productPhoto): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Banner Agency']);
        $workspace->users()->attach($user, ['role' => 'owner']);
        $brand = $workspace->brands()->create(['name' => 'Exact Brand']);
        $product = $brand->products()->create(['name' => 'Source Product', 'offering_type' => $type]);
        $source = $product->sourceSnapshots()->create([
            'url' => 'https://example.com/product',
            'content_hash' => hash('sha256', 'source'),
            'status' => 'ready',
        ]);
        $pack = CampaignPack::create([
            'product_id' => $product->id,
            'source_snapshot_id' => $source->id,
            'name' => 'Banner Pack',
            'status' => 'approved',
            'current_version' => 1,
            'analysis_mode' => 'standard',
        ]);
        $pack->versions()->create([
            'version' => 1,
            'generator' => 'mock',
            'review_status' => 'approved',
            'content' => [
                'direction' => ['title' => 'Approved direction', 'summary' => 'Approved summary'],
                'product_truth' => ['name' => 'Source Product', 'price' => '', 'source' => 'https://example.com/product', 'verified_facts' => []],
                'audiences' => ['Approved audience'],
                'benefits' => ['Approved benefit'],
                'meta' => ['primary_text' => 'Approved primary', 'headlines' => ['Approved hero headline', 'Approved problem headline', 'Approved lifestyle headline'], 'descriptions' => ['Approved description']],
                'hooks' => ['Approved hook'],
            ],
            'evidence' => [],
            'compliance_flags' => [],
        ]);
        if ($productPhoto) {
            $image = imagecreatetruecolor(600, 800);
            imagefill($image, 0, 0, imagecolorallocate($image, 218, 173, 115));
            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();
            $path = "campaign-media/{$workspace->id}/{$product->id}/product.png";
            Storage::disk('local')->put($path, $bytes);
            $product->mediaAssets()->create([
                'workspace_id' => $workspace->id,
                'source_snapshot_id' => $source->id,
                'type' => 'image',
                'disk' => 'local',
                'path' => $path,
                'original_name' => 'product.png',
                'mime_type' => 'image/png',
                'size_bytes' => strlen($bytes),
                'content_hash' => hash('sha256', $bytes),
                'status' => 'ready',
                'metadata' => ['origin' => 'product_page', 'source_url' => 'https://example.com/images/product.png', 'width' => 600, 'height' => 800],
            ]);
        }

        return [$user, $workspace, $pack->fresh(['product.brand', 'versions'])];
    }
}
