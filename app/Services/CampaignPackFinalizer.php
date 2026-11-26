<?php

namespace App\Services;

use App\Enums\OfferingType;
use App\Support\VoiceoverTiming;

/**
 * Deterministic clean-up of generated pack content before it is versioned.
 *
 * Models copy dates from the page ("Month of manufacture: July 2026") into fields that should
 * say when the pack was made, and pick CTAs that do not fit what is sold: service CTAs ("Book Now")
 * for a physical product, retail ones ("Shop Now") for software or a service. Those become the
 * type's default CTA. Voiceover word counts and paces are recounted from the lines, since models
 * miscount them.
 */
class CampaignPackFinalizer
{
    /** CTAs for services, bookings, apps or lead forms; replaced for a physical product. */
    private const NON_RETAIL_CTAS = [
        'book now', 'book a demo', 'sign up', 'subscribe', 'apply now', 'get quote', 'request quote', 'get a quote',
        'download', 'install now', 'use app', 'play game', 'contact us', 'call now', 'get directions', 'listen now',
        'watch more', 'donate now', 'register now', 'get showtimes', 'request time', 'see menu', 'start free trial',
    ];

    /** CTAs for buying goods online; replaced for software and services. */
    private const RETAIL_CTAS = ['shop now', 'buy now', 'order now', 'add to cart'];

    public function finalize(array $content, OfferingType $type = OfferingType::Physical): array
    {
        if (isset($content['marketing_hub']['overview']) && is_array($content['marketing_hub']['overview'])) {
            $content['marketing_hub']['overview']['updated_at'] = now()->toDateString();
        }

        if (is_array($content['marketing_hub']['channels']['meta_ads']['ctas'] ?? null)) {
            $content['marketing_hub']['channels']['meta_ads']['ctas'] = array_values(array_unique(array_map(
                fn ($cta) => $this->cta((string) $cta, $type),
                $content['marketing_hub']['channels']['meta_ads']['ctas'],
            )));
        }

        $content = VoiceoverTiming::refreshContent($content);
        foreach ($content['creative_routes'] ?? [] as $routeIndex => $route) {
            foreach (is_array($route['platform_assets'] ?? null) ? $route['platform_assets'] : [] as $platform => $asset) {
                if (is_array($asset) && isset($asset['cta'])) {
                    $content['creative_routes'][$routeIndex]['platform_assets'][$platform]['cta'] = $this->cta((string) $asset['cta'], $type);
                }
            }
        }

        return $content;
    }

    private function cta(string $cta, OfferingType $type): string
    {
        $key = mb_strtolower(trim($cta, " \t\n.!"));
        if ($type === OfferingType::Physical) {
            return in_array($key, self::NON_RETAIL_CTAS, true) ? 'Shop Now' : $cta;
        }

        return in_array($key, self::RETAIL_CTAS, true) ? $type->adCtas()[0] : $cta;
    }
}
