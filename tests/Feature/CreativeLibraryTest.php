<?php

namespace Tests\Feature;

use App\Livewire\ClientPortal;
use App\Livewire\CreativeBrowser;
use App\Models\BannerGenerationBatch;
use App\Models\Brand;
use App\Models\CampaignPack;
use App\Models\ContentItem;
use App\Models\CreativeAsset;
use App\Models\Product;
use App\Models\ProductHubShare;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CreativeLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['creatives.disk' => 'local']);
        Storage::fake('local');
    }

    public function test_upload_request_checks_type_size_and_workspace_quota(): void
    {
        [$owner, , $product] = $this->fixture();
        config(['creatives.workspace_cap_mb' => 100, 'creatives.max_image_mb' => 50]);
        $browser = Livewire::actingAs($owner)->test(CreativeBrowser::class, ['productId' => $product->id]);

        $browser->call('requestUpload', $this->file(['name' => 'notes.pdf', 'type' => 'application/pdf']))
            ->assertReturned(fn ($result) => str_contains($result['error'], 'Upload JPG, PNG'));
        $browser->call('requestUpload', $this->file(['size' => 51 * 1024 * 1024]))
            ->assertReturned(fn ($result) => $result['error'] === 'Images can be up to 50 MB.');
        $this->asset($product, ['size_bytes' => 90 * 1024 * 1024]);
        $browser->call('requestUpload', $this->file(['size' => 20 * 1024 * 1024]))
            ->assertReturned(fn ($result) => str_contains($result['error'], 'would pass your workspace'));
        $this->assertSame(1, CreativeAsset::count());

        $browser->call('requestUpload', $this->file(['name' => 'Summer reel.mov', 'type' => 'video/quicktime', 'size' => 5 * 1024 * 1024, 'width' => 1080, 'height' => 1920, 'duration_ms' => 32000, 'poster' => true]))
            ->assertReturned(fn ($result) => isset($result['id'], $result['upload']['url'], $result['poster']['url']) && $result['upload']['blocks'] === false);
        $asset = CreativeAsset::latest('id')->firstOrFail();
        $this->assertSame(['pending', 'video', 'Summer reel', '9:16', 32000], [$asset->status, $asset->kind, $asset->title, $asset->aspect_ratio, $asset->duration_ms]);
        $this->assertStringEndsWith('/original.mov', $asset->path);
        $this->assertStringEndsWith('/poster.jpg', $asset->poster_path);
    }

    public function test_finalize_marks_ready_only_when_the_whole_file_arrived(): void
    {
        [$owner, , $product] = $this->fixture();
        $browser = Livewire::actingAs($owner)->test(CreativeBrowser::class, ['productId' => $product->id]);

        $browser->call('requestUpload', $this->file(['size' => 10, 'poster' => true]));
        $short = CreativeAsset::latest('id')->firstOrFail();
        Storage::disk('local')->put($short->path, 'too short');
        $browser->call('finalizeUpload', $short->id)->assertReturned(fn ($result) => $result['ok'] === false);
        $this->assertModelMissing($short);
        Storage::disk('local')->assertMissing($short->path);

        $browser->call('requestUpload', $this->file(['size' => 10, 'poster' => true]));
        $asset = CreativeAsset::latest('id')->firstOrFail();
        Storage::disk('local')->put($asset->path, str_repeat('a', 10));
        $browser->call('finalizeUpload', $asset->id)->assertReturned(['ok' => true])->assertSee('Hero');
        $asset->refresh();
        $this->assertSame('ready', $asset->status);
        $this->assertNull($asset->poster_path, 'A poster that never arrived is dropped.');

        $browser->call('requestUpload', $this->file(['size' => 10, 'poster' => true]));
        $oversized = CreativeAsset::latest('id')->firstOrFail();
        Storage::disk('local')->put($oversized->path, str_repeat('a', 10));
        Storage::disk('local')->put($oversized->poster_path, str_repeat('a', 3 * 1024 * 1024));
        $poster = $oversized->poster_path;
        $browser->call('finalizeUpload', $oversized->id)->assertReturned(['ok' => true]);
        $this->assertNull($oversized->refresh()->poster_path, 'A poster too large to be a thumbnail is dropped.');
        Storage::disk('local')->assertMissing($poster);
    }

    public function test_reviewers_view_but_cannot_upload_and_other_workspaces_are_isolated(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $asset = $this->asset($product, ['title' => 'Team reel']);
        Storage::disk('local')->put($asset->path, str_repeat('a', $asset->size_bytes));
        $reviewer = $this->member($workspace, 'reviewer');
        $outsider = User::factory()->create();
        Workspace::create(['name' => 'Elsewhere'])->users()->attach($outsider, ['role' => 'owner']);

        $this->actingAs($reviewer)->get(route('creatives.index'))->assertOk()->assertSee('Team reel');
        $this->actingAs($reviewer)->get(route('creatives.file', [$asset, 'download']))->assertOk()->assertHeader('content-disposition');
        Livewire::actingAs($reviewer)->test(CreativeBrowser::class)->call('requestUpload', $this->file(['product_id' => $product->id]))->assertForbidden();
        Livewire::actingAs($reviewer)->test(CreativeBrowser::class)->call('delete', $asset->id)->assertForbidden();

        $this->actingAs($outsider)->get(route('creatives.index'))->assertOk()->assertDontSee('Team reel');
        $this->actingAs($outsider)->get(route('creatives.file', [$asset, 'file']))->assertNotFound();
        Livewire::actingAs($outsider)->test(CreativeBrowser::class)->call('requestUpload', $this->file(['product_id' => $product->id]))
            ->assertReturned(['error' => 'Choose a product for this upload.']);
        Livewire::actingAs($outsider)->test(CreativeBrowser::class)->call('archive', $asset->id)->assertNotFound();
        $this->assertNull($asset->fresh()->archived_at);
    }

    public function test_brand_scoped_members_and_clients_only_reach_their_brand(): void
    {
        [, $workspace, $product] = $this->fixture();
        $otherProduct = Brand::create(['workspace_id' => $workspace->id, 'name' => 'Kettle Brand'])->products()->create(['name' => 'Kettle', 'summary' => 'Other brand.']);
        $own = $this->asset($product, ['title' => 'North reel']);
        $other = $this->asset($otherProduct, ['title' => 'Kettle reel']);
        $scoped = $this->member($workspace, 'member', $product->brand_id);
        $client = $this->member($workspace, 'client', $product->brand_id);

        $this->actingAs($scoped)->get(route('creatives.index'))->assertOk()->assertSee('North reel')->assertDontSee('Kettle reel');
        $this->actingAs($scoped)->get(route('creatives.file', [$other, 'file']))->assertNotFound();
        Livewire::actingAs($scoped)->test(CreativeBrowser::class)->call('requestUpload', $this->file(['product_id' => $otherProduct->id]))
            ->assertReturned(['error' => 'Choose a product for this upload.']);

        $this->actingAs($client)->get(route('creatives.index'))->assertRedirect(route('portal.index'));
        $this->actingAs($client)->get(route('creatives.file', [$own, 'file']))->assertRedirect(route('portal.index'));
    }

    public function test_filters_quick_views_and_generated_banners(): void
    {
        [$owner, $workspace, $product, $pack] = $this->fixture(withPack: true);
        $colleague = $this->member($workspace, 'member');
        $this->asset($product, ['title' => 'Prospecting reel', 'kind' => 'video', 'mime_type' => 'video/mp4', 'channel' => 'meta_ads', 'stage' => 'prospecting', 'tags' => ['UGC'], 'aspect_ratio' => '9:16']);
        $this->asset($product, ['title' => 'Square static', 'channel' => 'instagram', 'aspect_ratio' => '1:1', 'uploaded_by_user_id' => $colleague->id]);
        $this->asset($product, ['title' => 'Old banner', 'archived_at' => now()]);
        $this->banner($pack, 'Generated headline');

        $browser = Livewire::actingAs($owner)->test(CreativeBrowser::class, ['productId' => $product->id])
            ->assertSee('Prospecting reel')->assertSee('Square static')->assertSee('Generated headline')->assertDontSee('Old banner')->assertSee('3 assets');
        $browser->set('kind', 'video')->assertSee('Prospecting reel')->assertDontSee('Square static')->assertDontSee('Generated headline')->set('kind', '');
        $browser->set('channel', 'instagram')->assertSee('Square static')->assertDontSee('Prospecting reel')->assertDontSee('Generated headline')->set('channel', '');
        $browser->set('stage', 'prospecting')->assertSee('Prospecting reel')->assertDontSee('Square static')->set('stage', '');
        $browser->set('aspect', '1:1')->assertSee('Square static')->assertDontSee('Prospecting reel')->set('aspect', '');
        $browser->set('tag', 'UGC')->assertSee('Prospecting reel')->assertDontSee('Square static')->set('tag', '');
        $browser->set('search', 'SQUARE')->assertSee('Square static')->assertDontSee('Prospecting reel')->set('search', '');
        $browser->set('view', 'untagged')->assertSee('Square static')->assertDontSee('Prospecting reel')->assertDontSee('Generated headline');
        $browser->set('view', 'mine')->assertSee('Prospecting reel')->assertDontSee('Square static');
        $browser->set('view', 'archived')->assertSee('Old banner')->assertDontSee('Square static');
        $browser->set('view', 'all')->set('sort', 'name')->assertSeeInOrder(['Generated headline', 'Prospecting reel', 'Square static']);

        $this->asset($product, ['title' => 'apple pie']);
        $browser->set('limit', 2)->assertSeeInOrder(['apple pie', 'Generated headline'])->assertDontSee('Prospecting reel');
        $browser->set('limit', 3)->assertSeeInOrder(['apple pie', 'Generated headline', 'Prospecting reel'])->assertDontSee('Square static');
    }

    public function test_edit_bulk_actions_and_delete_remove_storage_objects(): void
    {
        [$owner, , $product] = $this->fixture();
        $first = $this->asset($product, ['title' => 'First', 'poster_path' => 'creatives/x/first/poster.jpg']);
        $second = $this->asset($product, ['title' => 'Second', 'tags' => ['keep']]);
        foreach ([$first->path, $first->poster_path, $second->path] as $path) {
            Storage::disk('local')->put($path, 'x');
        }
        $browser = Livewire::actingAs($owner)->test(CreativeBrowser::class, ['productId' => $product->id]);

        $browser->call('select', 'u'.$first->id)->set('edit.title', 'First, renamed')->set('edit.stage', 'testimonial')->set('edit.tags', 'UGC, summer, ugc')->call('save')->assertHasNoErrors();
        $this->assertSame(['First, renamed', 'testimonial', ['UGC', 'summer']], [$first->fresh()->title, $first->fresh()->stage, $first->fresh()->tags]);
        $browser->set('edit.channel', 'nowhere')->call('save')->assertHasErrors('edit.channel');

        $browser->set('picked', [$first->id, $second->id])->set('bulk.channel', 'tiktok')->set('bulk.tags', 'Q4')->call('bulkApply');
        $this->assertSame(['tiktok', ['keep', 'Q4']], [$second->fresh()->channel, $second->fresh()->tags]);
        $this->assertSame('testimonial', $first->fresh()->stage, 'An empty bulk field leaves the value alone.');

        $browser->set('picked', [$second->id])->call('bulkArchive');
        $this->assertNotNull($second->fresh()->archived_at);

        $browser->call('delete', $first->id);
        $this->assertModelMissing($first);
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertMissing($first->poster_path);

        $browser->set('view', 'archived')->set('picked', [$second->id])->call('bulkDelete');
        $this->assertModelMissing($second);
        Storage::disk('local')->assertMissing($second->path);
    }

    public function test_share_link_portal_and_review_link_serve_ready_creatives_of_that_product_only(): void
    {
        [$owner, $workspace, $product] = $this->fixture();
        $otherProduct = Brand::create(['workspace_id' => $workspace->id, 'name' => 'Kettle Brand'])->products()->create(['name' => 'Kettle', 'summary' => 'Other brand.']);
        $asset = $this->asset($product, ['title' => 'Client reel', 'kind' => 'video', 'mime_type' => 'video/mp4', 'original_name' => 'client-reel.mp4', 'poster_path' => 'creatives/client-reel/poster.jpg']);
        $archived = $this->asset($product, ['title' => 'Archived reel', 'archived_at' => now()]);
        $foreign = $this->asset($otherProduct, ['title' => 'Kettle reel']);
        $pending = $this->asset($product, ['title' => 'Pending reel', 'status' => 'pending']);
        foreach ([$asset, $archived, $foreign, $pending] as $item) {
            Storage::disk('local')->put($item->path, str_repeat('a', $item->size_bytes));
        }
        Storage::disk('local')->put($asset->poster_path, 'jpeg');
        $share = ProductHubShare::create(['workspace_id' => $workspace->id, 'product_id' => $product->id, 'created_by_user_id' => $owner->id, 'token' => hash('sha256', 'creatives')]);

        ContentItem::create(['workspace_id' => $workspace->id, 'product_id' => $product->id, 'type' => 'copy', 'title' => 'Email subject lines', 'status' => 'approved']);

        $this->get(route('product-hubs.share', $share->token))->assertOk()->assertSee('Creatives · 1')->assertSee('Client reel')
            ->assertDontSee('Archived reel')->assertDontSee('Pending reel')->assertDontSee('Kettle reel')
            ->assertSee('Approved so far · 1')->assertSee('Email subject lines');
        $this->get(route('product-hubs.shared-library', [$share->token, $asset, 'download']))->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=client-reel.mp4');
        $this->get(route('product-hubs.shared-library', [$share->token, $asset, 'poster']))->assertOk()
            ->assertHeader('content-type', 'image/jpeg')->assertHeader('content-disposition', 'inline; filename=client-reel.jpg');
        $this->get(route('product-hubs.shared-library', [$share->token, $archived, 'file']))->assertNotFound();
        $this->get(route('product-hubs.shared-library', [$share->token, $foreign, 'file']))->assertNotFound();
        $this->get(route('product-hubs.shared-library', [$share->token, $pending, 'file']))->assertNotFound();

        $client = $this->member($workspace, 'client', $product->brand_id);
        $this->actingAs($client)->get(route('portal.index'))->assertOk()->assertSee($product->name);
        Livewire::actingAs($client)->withQueryParams(['product' => $product->id])->test(ClientPortal::class)->assertSee('Client reel')->assertDontSee('Kettle reel');
        $this->actingAs($client)->get(route('portal.hub-library', [$product->id, $asset, 'file']))->assertOk();
        $this->actingAs($client)->get(route('portal.hub-library', [$otherProduct->id, $foreign, 'file']))->assertNotFound();
        Livewire::actingAs($client)->test(CreativeBrowser::class)->call('requestUpload', $this->file(['product_id' => $product->id]))->assertForbidden();

        $share->update(['revoked_at' => now()]);
        $this->get(route('product-hubs.shared-library', [$share->token, $asset, 'file']))->assertNotFound();
    }

    public function test_azure_disk_signs_single_blob_links_and_creatives_redirect_to_them(): void
    {
        config(['filesystems.disks.azure' => [
            'driver' => 'azure', 'account' => 'mowltest', 'key' => base64_encode('not-a-real-key'), 'container' => 'creatives', 'throw' => true,
        ]]);
        $disk = Storage::disk('azure');

        $upload = $disk->temporaryUploadUrl('creatives/1/2/a/original.mov', now()->addHour());
        $this->assertTrue($upload['blocks']);
        $this->assertStringStartsWith('https://mowltest.blob.core.windows.net/creatives/creatives/1/2/a/original.mov?', $upload['url']);
        parse_str(parse_url($upload['url'], PHP_URL_QUERY), $query);
        $this->assertSame(['cw', 'b'], [$query['sp'], $query['sr']]);

        [$owner, , $product] = $this->fixture();
        $asset = $this->asset($product, ['disk' => 'azure', 'original_name' => 'Reel "final".mov', 'mime_type' => 'video/quicktime']);
        $location = $this->actingAs($owner)->get(route('creatives.file', [$asset, 'download']))->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['r', 'b', 'video/quicktime'], [$query['sp'], $query['sr'], $query['rsct']]);
        $this->assertStringStartsWith('attachment;', $query['rscd']);
        $this->assertStringContainsString('creatives/'.$asset->path, rawurldecode(parse_url($location, PHP_URL_PATH)));
    }

    /** @return array{0: User, 1: Workspace, 2: Product, 3: ?CampaignPack} */
    private function fixture(bool $withPack = false): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'North Agency']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $product = $workspace->brands()->create(['name' => 'North Brand'])->products()->create(['name' => 'North Product', 'summary' => 'A supported fact.']);
        $pack = null;
        if ($withPack) {
            $source = $product->sourceSnapshots()->create(['url' => 'https://example.com/north', 'content_hash' => hash('sha256', 'north'), 'status' => 'ready', 'extracted_content' => 'A supported fact.', 'extracted_truth' => []]);
            $pack = CampaignPack::create(['product_id' => $product->id, 'source_snapshot_id' => $source->id, 'name' => 'North Pack', 'status' => 'draft', 'current_version' => 1, 'analysis_mode' => 'standard']);
            $pack->versions()->create(['version' => 1, 'generator' => 'mock', 'content' => [], 'evidence' => [], 'compliance_flags' => []]);
        }

        return [$owner, $workspace, $product, $pack];
    }

    private function file(array $overrides = []): array
    {
        return $overrides + ['name' => 'Hero.png', 'type' => 'image/png', 'size' => 2048, 'width' => 1080, 'height' => 1350, 'poster' => false];
    }

    private function asset(Product $product, array $attributes = []): CreativeAsset
    {
        static $sequence = 0;
        $sequence++;

        return CreativeAsset::create($attributes + [
            'workspace_id' => $product->brand->workspace_id, 'product_id' => $product->id, 'kind' => 'image', 'disk' => 'local',
            'path' => "creatives/{$product->brand->workspace_id}/{$product->id}/{$sequence}/original.png", 'original_name' => "asset-{$sequence}.png",
            'title' => "Asset {$sequence}", 'mime_type' => 'image/png', 'size_bytes' => 1024, 'width' => 1080, 'height' => 1350, 'status' => 'ready',
            'uploaded_by_user_id' => $product->brand->workspace->users()->wherePivot('role', 'owner')->value('users.id'),
        ]);
    }

    private function banner(CampaignPack $pack, string $headline): void
    {
        $version = $pack->versions()->firstOrFail();
        $batch = BannerGenerationBatch::create([
            'workspace_id' => $pack->product->brand->workspace_id, 'campaign_pack_id' => $pack->id, 'campaign_pack_version_id' => $version->id,
            'kind' => 'included', 'requested_count' => 1, 'credit_cost' => 0, 'status' => 'completed',
        ]);
        $batch->creatives()->create([
            'campaign_pack_id' => $pack->id, 'campaign_pack_version_id' => $version->id, 'sequence' => 1, 'direction' => 'Product hero', 'layout' => 'top_left',
            'headline' => $headline, 'supporting_text' => 'Small', 'cta' => 'Shop now', 'prompt' => 'A desk', 'status' => 'completed', 'disk' => 'local',
            'output_path' => 'banners/1.png', 'width' => 1080, 'height' => 1350,
        ]);
    }

    private function member(Workspace $workspace, string $role, ?int $brandId = null): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user, ['role' => $role, 'brand_id' => $brandId]);

        return $user;
    }
}
