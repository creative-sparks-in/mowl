<?php

namespace App\Enums;

/**
 * What a brand sells, and what one product is when it differs from its brand.
 * Every label, hint and prompt line that changes with the type lives here.
 */
enum OfferingType: string
{
    case Physical = 'physical';
    case Software = 'software';
    case Service = 'service';

    public const BRAND_QUESTION = 'What does this brand sell?';

    public const BRAND_HELPER = 'Pick the main one. You can change it for any single item.';

    public const PRODUCT_LABEL = 'Type';

    public const PUBLISHING_NOTE = 'Publishing to Shopify or WooCommerce is available for physical products only.';

    /** Price periods offered for software and services. The key is stored nowhere: it is written into the price text. */
    public const PRICE_PERIODS = ['' => 'One-off', 'month' => 'Per month', 'year' => 'Per year', 'project' => 'From, per project'];

    /** Unknown or missing values count as physical, which is what every brand was before types existed. */
    public static function of(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::Physical;
    }

    /**
     * The one type every given brand or product has; null when they are mixed or there are none.
     *
     * @param  iterable<self>  $types
     */
    public static function shared(iterable $types): ?self
    {
        $unique = [];
        foreach ($types as $type) {
            $unique[$type->value] = $type;
        }

        return count($unique) === 1 ? reset($unique) : null;
    }

    public function brandOption(): string
    {
        return match ($this) {
            self::Physical => 'Physical products',
            self::Software => 'Software',
            self::Service => 'Services',
        };
    }

    public function brandHint(): string
    {
        return match ($this) {
            self::Physical => 'Goods you ship or hand over',
            self::Software => 'Apps, SaaS, subscriptions',
            self::Service => 'Work you do for clients',
        };
    }

    public function productOption(): string
    {
        return match ($this) {
            self::Physical => 'Physical product',
            self::Software => 'Software',
            self::Service => 'Service',
        };
    }

    /** The product option that keeps the brand's type. */
    public function sameAsBrand(): string
    {
        return "Same as brand ({$this->productOption()})";
    }

    /** Null is a view that mixes types, such as All brands. */
    public static function addLabel(?self $type): string
    {
        return match ($type) {
            self::Physical => 'Add product',
            self::Software => 'Add app, plan or feature',
            self::Service => 'Add service',
            null => 'Add product or service',
        };
    }

    /** Page heading for a list of one type. A mixed list keeps the wording it always had. */
    public static function plural(?self $type): string
    {
        return match ($type) {
            self::Software => 'Apps, plans and features',
            self::Service => 'Services',
            default => 'Products',
        };
    }

    public static function emptyHeading(?self $type): string
    {
        return match ($type) {
            self::Physical => 'No products yet',
            self::Software => 'No apps, plans or features yet',
            self::Service => 'No services yet',
            null => 'No products or services yet',
        };
    }

    public static function emptyLine(?self $type): string
    {
        $tail = " We'll read the name, price and details, and you confirm them.";

        return match ($type) {
            self::Physical => 'Paste a product page link.',
            self::Software => 'Paste your home, pricing or feature page link.',
            self::Service => 'Paste a service page link.',
            null => 'Pick a brand, then paste a page link.',
        }.$tail;
    }

    public function importHint(): string
    {
        return match ($this) {
            self::Physical => 'Paste the product page link',
            self::Software => 'Paste the home, pricing or feature page link',
            self::Service => 'Paste the service page link',
        };
    }

    /** What the page a product was read from is called. */
    public function pageLabel(): string
    {
        return match ($this) {
            self::Physical => 'Product page',
            self::Software => 'Home, pricing or feature page',
            self::Service => 'Service page',
        };
    }

    public function priceHint(): string
    {
        return match ($this) {
            self::Physical => 'e.g. ₹1,499',
            self::Software => 'e.g. ₹999 per month, or Free',
            self::Service => 'e.g. From ₹5,000 per project',
        };
    }

    /** Physical prices are one-off, so they get no period choice. */
    public function hasPricePeriods(): bool
    {
        return $this !== self::Physical;
    }

    /** "₹999" with "month" becomes "₹999 per month". A price with no amount, or one that already names its period, is kept. */
    public static function priceWithPeriod(string $price, string $period): string
    {
        $price = trim($price);
        if ($period === '' || ! isset(self::PRICE_PERIODS[$period]) || ! preg_match('/\d/', $price) || preg_match('#\bper\b|/#i', $price)) {
            return $price;
        }

        return $period === 'project' ? 'From '.preg_replace('/^from\s+/i', '', $price).' per project' : "{$price} per {$period}";
    }

    /** The default button on banners and pages. */
    public function cta(): string
    {
        return match ($this) {
            self::Physical => 'Shop now',
            self::Software => 'Start free trial',
            self::Service => 'Book a call',
        };
    }

    /**
     * Calls to action ad platforms offer for this type, the default first.
     *
     * @return list<string>
     */
    public function adCtas(): array
    {
        return match ($this) {
            self::Physical => ['Shop Now', 'Buy Now', 'Order Now', 'Learn More'],
            self::Software => ['Start Free Trial', 'Book a Demo', 'Sign Up', 'Learn More'],
            self::Service => ['Book a Call', 'Get a Quote', 'Contact Us', 'Learn More'],
        };
    }

    /** Extra direction for copy prompts. Empty for physical products, whose prompts stay as they were. */
    public function promptGuidance(): string
    {
        return match ($this) {
            self::Physical => '',
            self::Software => 'OFFERING TYPE: software (an app, a SaaS plan or a feature), not a physical product. Frame the copy around trying, signing up for or subscribing to the software. Default call to action: Start Free Trial or Book a Demo. Never use shop, buy-now, add-to-cart, shipping, delivery, stock, packaging or returns language unless the source states it.',
            self::Service => 'OFFERING TYPE: a service delivered to clients, not a physical product. Frame the copy around enquiring about, booking or hiring the service. Default call to action: Book a Call or Get a Quote. Never use shop, buy-now, add-to-cart, shipping, delivery, stock or packaging language unless the source states it.',
        };
    }
}
