<?php

namespace Tests\Unit;

use App\Enums\OfferingType;
use App\Models\Brand;
use App\Models\Product;
use PHPUnit\Framework\TestCase;

class OfferingTypeTest extends TestCase
{
    public function test_a_product_follows_its_brand_unless_it_has_its_own_type(): void
    {
        $brand = new Brand(['offering_type' => 'software']);
        $product = (new Product)->setRelation('brand', $brand);

        $this->assertSame(OfferingType::Software, $product->offeringType());
        $this->assertSame(OfferingType::Service, $product->fill(['offering_type' => 'service'])->offeringType());
        $this->assertSame(OfferingType::Software, $product->fill(['offering_type' => 'nonsense'])->offeringType());
    }

    public function test_brands_and_products_saved_before_types_existed_are_physical(): void
    {
        $this->assertSame(OfferingType::Physical, (new Brand)->offeringType());
        $this->assertSame(OfferingType::Physical, (new Product)->setRelation('brand', null)->offeringType());
    }

    public function test_labels_follow_the_type_and_mixed_views_stay_neutral(): void
    {
        $this->assertSame('Add product', OfferingType::addLabel(OfferingType::Physical));
        $this->assertSame('Add app, plan or feature', OfferingType::addLabel(OfferingType::Software));
        $this->assertSame('Add service', OfferingType::addLabel(OfferingType::Service));
        $this->assertSame('Add product or service', OfferingType::addLabel(null));
        $this->assertSame('Same as brand (Software)', OfferingType::Software->sameAsBrand());
        $this->assertSame(OfferingType::Service, OfferingType::shared([OfferingType::Service, OfferingType::Service]));
        $this->assertNull(OfferingType::shared([OfferingType::Service, OfferingType::Physical]));
        $this->assertNull(OfferingType::shared([]));
    }

    public function test_a_period_is_written_into_the_price_once(): void
    {
        $this->assertSame('₹999 per month', OfferingType::priceWithPeriod(' ₹999 ', 'month'));
        $this->assertSame('₹9,990 per year', OfferingType::priceWithPeriod('₹9,990', 'year'));
        $this->assertSame('From ₹5,000 per project', OfferingType::priceWithPeriod('from ₹5,000', 'project'));
        $this->assertSame('₹999', OfferingType::priceWithPeriod('₹999', ''));
        $this->assertSame('Free', OfferingType::priceWithPeriod('Free', 'month'));
        $this->assertSame('₹999 per month', OfferingType::priceWithPeriod('₹999 per month', 'year'));
        $this->assertSame('$10/mo', OfferingType::priceWithPeriod('$10/mo', 'month'));
    }

    public function test_only_software_and_services_change_the_copy_prompts(): void
    {
        $this->assertSame('', OfferingType::Physical->promptGuidance());
        $this->assertStringContainsString('Start Free Trial', OfferingType::Software->promptGuidance());
        $this->assertStringContainsString('Book a Call', OfferingType::Service->promptGuidance());
        $this->assertSame('Shop now', OfferingType::Physical->cta());
    }
}
