<?php

namespace Tests\Feature;

use App\Livewire\BrandEditor;
use App\Livewire\ProductCreate;
use App\Livewire\ProductHub;
use App\Livewire\ProductIndex;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\TestCase;

class OfferingTypeScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(ProductKnowledge::class, 'available'))->setValue(null, null);
        (new ReflectionProperty(Brand::class, 'profileAvailable'))->setValue(null, null);
        (new ReflectionProperty(Brand::class, 'offeringTypeAvailable'))->setValue(null, null);
    }

    public function test_the_brand_editor_saves_and_reloads_what_the_brand_sells(): void
    {
        [$user, $workspace] = $this->workspace();

        Livewire::actingAs($user)->test(BrandEditor::class)
            ->assertSee('What does this brand sell?')
            ->assertSee('Apps, SaaS, subscriptions')
            ->assertSet('offeringType', 'physical')
            ->set('name', 'Ledger')
            ->set('offeringType', 'spaceship')
            ->call('save')
            ->assertHasErrors('offeringType')
            ->set('offeringType', 'software')
            ->call('save')
            ->assertHasNoErrors();

        $brand = $workspace->brands()->firstOrFail();
        $this->assertSame('software', $brand->offering_type);
        Livewire::actingAs($user)->test(BrandEditor::class, ['brand' => $brand])->assertSet('offeringType', 'software');
    }

    public function test_the_brand_editor_hides_the_question_until_the_column_exists(): void
    {
        [$user, $workspace] = $this->workspace();
        (new ReflectionProperty(Brand::class, 'offeringTypeAvailable'))->setValue(null, false);

        Livewire::actingAs($user)->test(BrandEditor::class)
            ->assertDontSee('What does this brand sell?')
            ->set('name', 'Ledger')
            ->set('offeringType', 'software')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotSame('software', $workspace->brands()->firstOrFail()->offering_type);
    }

    public function test_the_brand_card_names_what_to_add(): void
    {
        [$user, $workspace] = $this->workspace();
        $workspace->brands()->create(['name' => 'Ledger', 'offering_type' => 'software']);

        $this->actingAs($user)->get(route('brands.index'))->assertOk()->assertSee('Add app, plan or feature');
    }

    public function test_the_add_screen_speaks_for_the_brand_type(): void
    {
        [$user, $workspace] = $this->workspace();
        $software = $workspace->brands()->create(['name' => 'Ledger', 'offering_type' => 'software']);
        $physical = $workspace->brands()->create(['name' => 'Northline']);

        Livewire::actingAs($user)->test(ProductCreate::class, [])
            ->set('brandId', (string) $software->id)
            ->assertSee('Add app, plan or feature')
            ->assertSee('Paste the home, pricing or feature page link')
            ->assertSee('Same as brand (Software)')
            ->call('setMode', 'manual')
            ->assertSeeHtml('placeholder="e.g. ₹999 per month, or Free"')
            ->assertSee('Home, pricing or feature page address')
            ->assertSee('Per month')
            ->set('brandId', (string) $physical->id)
            ->assertSee('Add product')
            ->assertDontSee('Add app, plan or feature')
            ->assertSeeHtml('placeholder="e.g. ₹1,499"')
            ->assertSee('Product page address')
            ->assertDontSee('Per month')
            ->set('offeringType', 'service')
            ->assertSee('Add service')
            ->assertSee('Per month');

        $this->actingAs($user)->get(route('products.create', ['brand' => $physical->id]))
            ->assertOk()->assertSee('Paste the product page link')->assertSee('<title>Add product', false);
    }

    public function test_the_override_is_stored_only_when_it_differs_from_following_the_brand(): void
    {
        [$user, $workspace] = $this->workspace();
        $brand = $workspace->brands()->create(['name' => 'Northline']);

        $this->addManually($user, $brand, 'Desk Lamp');
        $this->addManually($user, $brand, 'Lamp Repair', 'service');

        $this->assertNull(Product::where('name', 'Desk Lamp')->value('offering_type'));
        $this->assertSame('service', Product::where('name', 'Lamp Repair')->value('offering_type'));
        $this->assertSame('Service page', Product::where('name', 'Lamp Repair')->firstOrFail()->resourceLinks()->value('label'));
        $this->assertSame('Product page', Product::where('name', 'Desk Lamp')->firstOrFail()->resourceLinks()->value('label'));
    }

    public function test_the_override_control_is_hidden_and_not_saved_until_the_column_exists(): void
    {
        [$user, $workspace] = $this->workspace();
        $brand = $workspace->brands()->create(['name' => 'Northline']);
        (new ReflectionProperty(Brand::class, 'offeringTypeAvailable'))->setValue(null, false);

        Livewire::actingAs($user)->test(ProductCreate::class)->assertDontSee('Same as brand');
        $this->addManually($user, $brand, 'Desk Lamp', 'service');

        $this->assertNull(Product::where('name', 'Desk Lamp')->value('offering_type'));
    }

    public function test_a_new_brand_is_created_with_what_it_sells(): void
    {
        [$user, $workspace] = $this->workspace();

        Livewire::actingAs($user)->test(ProductCreate::class)
            ->assertSet('brandId', 'new')
            ->assertSee('What does this brand sell?')
            ->set('newBrandName', 'Fix-it')
            ->set('newBrandType', 'service')
            ->assertSee('Add service')
            ->assertSee('Same as brand (Service)')
            ->call('setMode', 'manual')
            ->set('name', 'Boiler check')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::with('brand')->firstOrFail();
        $this->assertSame('service', $product->brand->offering_type);
        $this->assertNull($product->offering_type);
    }

    public function test_an_imported_page_preselects_the_type_of_a_new_brand_only(): void
    {
        [$user, $workspace] = $this->workspace();
        Http::fake(['93.184.216.34/*' => Http::response('<html><head><title>Ledger</title><script type="application/ld+json">{"@type":"SoftwareApplication","name":"Ledger"}</script></head><body>Books</body></html>', 200, ['Content-Type' => 'text/html'])]);

        Livewire::actingAs($user)->test(ProductCreate::class)
            ->assertSet('newBrandType', 'physical')
            ->set('productUrl', '93.184.216.34/app')
            ->call('import')
            ->assertSet('newBrandType', 'software');

        $brand = $workspace->brands()->create(['name' => 'Northline']);
        Livewire::actingAs($user)->test(ProductCreate::class)
            ->set('brandId', (string) $brand->id)
            ->set('productUrl', '93.184.216.34/app')
            ->call('import')
            ->assertSet('newBrandType', 'physical')
            ->assertSet('offeringType', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('physical', $brand->fresh()->offeringType()->value);
        $this->assertNull(Product::firstOrFail()->offering_type);
    }

    public function test_a_price_period_is_written_into_the_price_and_the_price_stays_optional(): void
    {
        [$user, $workspace] = $this->workspace();
        $brand = $workspace->brands()->create(['name' => 'Ledger', 'offering_type' => 'software']);

        $this->addManually($user, $brand, 'Ledger Pro', price: '₹999', period: 'month');
        $this->addManually($user, $brand, 'Ledger Free');

        $pro = Product::where('name', 'Ledger Pro')->firstOrFail();
        $this->assertSame('₹999 per month', $pro->price);
        $this->assertSame('₹999 per month', $pro->facts()->where('key', 'price')->value('confirmed_value'));
        $this->assertNull(Product::where('name', 'Ledger Free')->value('price'));

        Livewire::actingAs($user)->test(ProductCreate::class)
            ->set('brandId', (string) $brand->id)
            ->call('setMode', 'manual')
            ->set('name', 'Ledger Max')
            ->set('price', str_repeat('9', 35))
            ->set('pricePeriod', 'project')
            ->call('save')
            ->assertHasErrors('price');
    }

    public function test_a_physical_price_ignores_a_period(): void
    {
        [$user, $workspace] = $this->workspace();
        $brand = $workspace->brands()->create(['name' => 'Northline']);

        $this->addManually($user, $brand, 'Desk Lamp', price: '₹1,499', period: 'month');

        $this->assertSame('₹1,499', Product::firstOrFail()->price);
    }

    public function test_the_products_list_is_worded_for_one_software_brand(): void
    {
        [$user, $workspace] = $this->workspace();
        $workspace->brands()->create(['name' => 'Ledger', 'offering_type' => 'software']);

        Livewire::actingAs($user)->test(ProductIndex::class)
            ->assertSee('Apps, plans and features')
            ->assertSee('Add app, plan or feature');
        $this->actingAs($user)->get(route('products.index'))->assertSee('<title>Apps, plans and features', false);
    }

    public function test_the_products_list_is_neutral_for_mixed_brands_and_names_the_filtered_brand(): void
    {
        [$user, $workspace] = $this->workspace();
        $workspace->brands()->create(['name' => 'Ledger', 'offering_type' => 'software']);
        $service = $workspace->brands()->create(['name' => 'Fix-it', 'offering_type' => 'service']);

        Livewire::actingAs($user)->test(ProductIndex::class)
            ->assertSee('Add product or service')
            ->assertSee('Products')
            ->set('brand', $service->id)
            ->assertSee('Add service')
            ->assertSee('No services yet')
            ->assertSee('Paste a service page link.');
    }

    public function test_the_products_list_keeps_its_physical_wording(): void
    {
        [$user, $workspace] = $this->workspace();
        $brand = $workspace->brands()->create(['name' => 'Northline']);

        Livewire::actingAs($user)->test(ProductIndex::class)
            ->assertSee('Products')
            ->assertSee('Add product')
            ->assertDontSee('Add product or service')
            ->set('brand', $brand->id)
            ->assertSee('No products yet')
            ->assertSee('Paste a product page link.');

        [$storeOwner] = $this->workspace('store');
        Livewire::actingAs($storeOwner)->test(ProductIndex::class)->assertSee('Add product')->assertDontSee('Add product or service');
        [$agencyOwner] = $this->workspace();
        Livewire::actingAs($agencyOwner)->test(ProductIndex::class)->assertSee('Add product or service');
    }

    public function test_a_manager_sets_the_type_on_the_hub_and_others_cannot(): void
    {
        [$owner, $workspace] = $this->workspace();
        $product = $workspace->brands()->create(['name' => 'Northline'])->products()->create(['name' => 'Lamp Repair']);
        $product->resourceLinks()->create(['workspace_id' => $workspace->id, 'kind' => 'product_page', 'label' => 'Product page', 'url' => 'https://shop.example/repair']);

        Livewire::actingAs($owner)->test(ProductHub::class, ['product' => $product])
            ->assertSee('Same as brand (Physical product)')
            ->set('offeringType', 'service')
            ->call('saveOfferingType')
            ->assertHasNoErrors()
            ->call('saveResources');

        $this->assertSame('service', $product->fresh()->offering_type);
        $this->assertSame('Service page', $product->resourceLinks()->value('label'));
        $this->assertSame('product_page', $product->resourceLinks()->value('kind'));
        $this->assertSame('product_offering_type_changed', $workspace->auditEvents()->latest('id')->value('event'));

        $reviewer = User::factory()->create();
        $workspace->users()->attach($reviewer, ['role' => 'reviewer']);
        Livewire::actingAs($reviewer)->test(ProductHub::class, ['product' => $product])
            ->assertDontSee('Same as brand')
            ->set('offeringType', '')
            ->call('saveOfferingType')
            ->assertForbidden();
        $this->assertSame('service', $product->fresh()->offering_type);

        Livewire::actingAs($owner)->test(ProductHub::class, ['product' => $product])
            ->set('offeringType', '')
            ->call('saveOfferingType');
        $this->assertNull($product->fresh()->offering_type);
    }

    private function addManually(User $user, Brand $brand, string $name, string $type = '', string $price = '', string $period = ''): void
    {
        Livewire::actingAs($user)->test(ProductCreate::class)
            ->set('brandId', (string) $brand->id)
            ->call('setMode', 'manual')
            ->set('name', $name)
            ->set('offeringType', $type)
            ->set('price', $price)
            ->set('pricePeriod', $period)
            ->set('productUrl', 'https://shop.example/'.str($name)->slug())
            ->call('save')
            ->assertHasNoErrors();
    }

    private function workspace(string $kind = 'agency'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Workspace '.$user->id, 'kind' => $kind]);
        $workspace->users()->attach($user, ['role' => 'owner']);

        return [$user, $workspace];
    }
}
