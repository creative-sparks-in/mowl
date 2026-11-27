<?php

namespace App\Services;

use App\Contracts\BannerGenerator;
use InvalidArgumentException;

class BannerGeneratorManager
{
    public function driver(): BannerGenerator
    {
        return match ($this->providerName()) {
            'mock' => app(MockBannerGenerator::class),
            // One pipeline on either provider; the BannerModels binding picks the models.
            'google', 'openai' => app(ImageModelBannerGenerator::class),
            default => throw new InvalidArgumentException('Unsupported banner generator: '.$this->providerName()),
        };
    }

    public function providerName(): string
    {
        return (string) config('campaigns.banners.generator', 'mock');
    }

    public function model(): ?string
    {
        return match ($this->providerName()) {
            'google' => (string) config('campaigns.banners.model'),
            'openai' => config('campaigns.banners.openai.models')[0] ?? null,
            default => null,
        };
    }
}
