<?php

namespace Tests\Feature;

use App\Enums\OfferingType;
use App\Livewire\PageEditor;
use App\Livewire\ProductPages;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pages\PageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OfferingTypePublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_type_sees_wordpress_and_sandbox_stores_and_the_wordpress_store_comes_first(): void
    {
        [$owner, $workspace, $software] = $this->fixture('software');
        $physical = $software->brand->products()->create(['name' => 'Desk Stand', 'offering_type' => 'physical']);
        $service = $software->brand->products()->create(['name' => 'Setup Call', 'offering_type' => 'service']);
        [$woo, $sandbox] = $this->stores($workspace);
        $builder = app(PageBuilder::class);

        foreach ([$software, $service, $physical] as $product) {
            $this->assertEqualsCanonicalizing([$woo->id, $sandbox->id], $builder->stores($product)->modelKeys());
            $this->assertSame($woo->id, $builder->defaultStore($product)?->id);
        }
        $page = $builder->start($software, $owner);
        Livewire::actingAs($owner)->test(PageEditor::class, ['product' => $software, 'page' => $page])
            ->call('chooseStore', (string) $sandbox->id)->assertHasNoErrors()
            ->call('chooseStore', (string) $woo->id)->assertHasNoErrors();
        $this->assertSame($woo->id, $page->fresh()->store_connection_id);
    }

    public function test_the_publishing_note_shows_in_the_publish_drawer_for_software_only(): void
    {
        [$owner, $workspace, $software] = $this->fixture('software');
        $this->stores($workspace);
        $page = app(PageBuilder::class)->start($software, $owner);

        Livewire::actingAs($owner)->test(PageEditor::class, ['product' => $software, 'page' => $page])
            ->call('openDrawer')
            ->assertSee(OfferingType::PUBLISHING_NOTE)
            ->assertSee('Sandbox')
            ->assertSee('Corner Shop');
        Livewire::actingAs($owner)->test(ProductPages::class, ['productId' => $software->id])
            ->assertSee('then publish it to your store.')
            ->assertDontSee(OfferingType::PUBLISHING_NOTE);

        $physical = $software->brand->products()->create(['name' => 'Desk Stand', 'offering_type' => 'physical']);
        $physicalPage = app(PageBuilder::class)->start($physical, $owner);
        Livewire::actingAs($owner)->test(PageEditor::class, ['product' => $physical, 'page' => $physicalPage])
            ->call('openDrawer')
            ->assertDontSee(OfferingType::PUBLISHING_NOTE)
            ->assertSee('Corner Shop');
        Livewire::actingAs($owner)->test(ProductPages::class, ['productId' => $physical->id])
            ->assertDontSee(OfferingType::PUBLISHING_NOTE);
    }

    /** @return array{StoreConnection, StoreConnection} a WooCommerce store, then the sandbox store */
    private function stores(Workspace $workspace): array
    {
        return [
            StoreConnection::create(['workspace_id' => $workspace->id, 'name' => 'Corner Shop', 'driver' => 'woocommerce', 'site_url' => 'https://shop.test', 'api_base' => 'https://shop.test/wp-json', 'username' => 'editor', 'secret' => 'abcd abcd abcd abcd', 'status' => 'connected']),
            StoreConnection::create(['workspace_id' => $workspace->id, 'name' => 'Try-out store', 'driver' => 'sandbox', 'site_url' => 'https://sandbox.test', 'status' => 'connected']),
        ];
    }

    /** The brand carries the type and the product keeps it, so the effective type is what is tested. */
    private function fixture(string $type): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Publishing Agency']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $brand = $workspace->brands()->create(['name' => 'Publishing Brand', 'offering_type' => $type]);
        $product = $brand->products()->create(['name' => 'Planner Pro', 'summary' => 'Plans your week.']);

        return [$owner, $workspace, Product::with('brand')->findOrFail($product->id)];
    }
}
