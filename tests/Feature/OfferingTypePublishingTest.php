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
use App\Services\Pages\PagePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OfferingTypePublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_software_and_services_see_only_sandbox_stores_and_physical_products_see_woocommerce_too(): void
    {
        [, $workspace, $software] = $this->fixture('software');
        $physical = $software->brand->products()->create(['name' => 'Desk Stand', 'offering_type' => 'physical']);
        $service = $software->brand->products()->create(['name' => 'Setup Call', 'offering_type' => 'service']);
        [$woo, $sandbox] = $this->stores($workspace);
        $builder = app(PageBuilder::class);

        $this->assertSame([$sandbox->id], $builder->stores($software)->modelKeys());
        $this->assertSame([$sandbox->id], $builder->stores($service)->modelKeys());
        $this->assertSame($sandbox->id, $builder->defaultStore($software)?->id);
        $this->assertEqualsCanonicalizing([$woo->id, $sandbox->id], $builder->stores($physical)->modelKeys());
        $this->assertSame($woo->id, $builder->defaultStore($physical)?->id);
    }

    public function test_a_software_page_is_never_sent_to_a_woocommerce_store_chosen_before_its_type_changed(): void
    {
        Http::fake();
        [$owner, $workspace, $product] = $this->fixture('software');
        [$woo] = $this->stores($workspace);
        $page = app(PageBuilder::class)->start($product, $owner);
        $page->update(['store_connection_id' => $woo->id]);

        try {
            app(PagePublisher::class)->publish($page->fresh(), $owner);
            $this->fail('A software page was published to a WooCommerce store.');
        } catch (ValidationException $exception) {
            $this->assertSame(OfferingType::PUBLISHING_NOTE, $exception->errors()['publish'][0]);
        }

        Http::assertNothingSent();
        $this->assertSame(0, $page->attempts()->count());
        $this->assertSame('idle', $page->fresh()->publish_state);
        Livewire::actingAs($owner)->test(PageEditor::class, ['product' => $product, 'page' => $page->fresh()])
            ->call('chooseStore', (string) $woo->id)
            ->assertNotFound();
    }

    public function test_the_publishing_note_shows_in_the_drawer_and_on_the_pages_tab_for_software_only(): void
    {
        [$owner, $workspace, $software] = $this->fixture('software');
        $this->stores($workspace);
        $page = app(PageBuilder::class)->start($software, $owner);

        Livewire::actingAs($owner)->test(PageEditor::class, ['product' => $software, 'page' => $page])
            ->call('openDrawer')
            ->assertSee(OfferingType::PUBLISHING_NOTE)
            ->assertSee('Sandbox')
            ->assertDontSee('Corner Shop');
        Livewire::actingAs($owner)->test(ProductPages::class, ['productId' => $software->id])
            ->assertSee('then publish it to your store.')
            ->assertSee(OfferingType::PUBLISHING_NOTE);

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
