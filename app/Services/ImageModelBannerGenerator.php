<?php

namespace App\Services;

use App\Contracts\BannerGenerator;
use App\Contracts\BannerModels;
use App\Data\BannerGenerationResult;
use App\Exceptions\AiProviderException;
use App\Exceptions\BannerAttemptFailed;
use App\Exceptions\VertexAIResponseException;
use App\Models\BannerCreative;
use App\Models\MediaAsset;
use App\Support\GenerationDeadline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

class ImageModelBannerGenerator implements BannerGenerator
{
    public function __construct(private BannerModels $models, private BannerImageInspector $inspector, private ProviderCostCalculator $costs, private BannerReferenceImage $references) {}

    /**
     * Generates a scene without the product and returns it with the product cutout for the composer
     * (or, with compositing off, a background drawn from a prop-free copy of the product photo), then
     * rejects and regenerates any image with readable third-party text or marks (a real book cover on
     * the e-reader, a magazine spine). Every round's image tokens are kept; the cutout and inspections
     * are priced at their own models' rates.
     */
    public function generate(BannerCreative $creative, Collection $productImages): BannerGenerationResult
    {
        $attempts = max(1, (int) config('campaigns.banners.inspection_attempts'));
        $product = (string) ($creative->campaignPack?->product?->name ?? '');
        $brand = (string) ($creative->campaignPack?->product?->brand?->name ?? '');
        $usage = [0, 0, 0];
        $extraCost = 0.0;
        $marks = [];
        // Generation plus inspection, possibly twice, must finish inside the queue worker timeout
        // (180s) and the request function's limit, so the whole loop runs under one deadline.
        $deadline = app(GenerationDeadline::class);
        $ownsDeadline = $deadline->remaining() === null;
        if ($ownsDeadline) {
            $deadline->start((int) config('campaigns.banners.time_budget_seconds'));
        }
        try {
            return $this->generateWithin($deadline, $attempts, $creative, $productImages, $product, $brand, $usage, $extraCost, $marks);
        } finally {
            if ($ownsDeadline) {
                $deadline->clear();
            }
        }
    }

    private function generateWithin(GenerationDeadline $deadline, int $attempts, BannerCreative $creative, Collection $productImages, string $product, string $brand, array $usage, float $extraCost, array $marks): BannerGenerationResult
    {
        $asset = $productImages->first();
        $cutout = null;
        // With compositing, the image model draws only the scene and never sees the product; the cutout
        // (checked against the store photo when it was made) is placed on the scene by the composer. The
        // mode follows the stored prompt, not the current setting, so a banner queued before a switch is
        // never drawn with a product and then given a second one. Only the prompt's fixed opening counts:
        // product copy further in could contain any phrase.
        $composite = $asset && str_starts_with((string) $creative->prompt, BannerCopySelector::SCENE_OPENING);
        $checkProduct = $asset && ! $composite && config('campaigns.banners.inspect_images') && config('campaigns.banners.check_product');
        try {
            $photo = $asset && ! $composite ? $this->imagePart($asset)['inlineData'] : null;
            if ($composite) {
                $round = (int) config('campaigns.banners.min_round_seconds');
                $timeout = (int) config('campaigns.banners.reference_timeout_seconds');
                $made = $this->rateLimited($deadline, $round, fn () => $this->references->for(
                    $asset,
                    $product,
                    $deadline->timeout($timeout),
                    // Waiters reuse the cutout another banner is making, including its two checks.
                    (int) min($timeout + 2 * (int) config('campaigns.banners.inspection_timeout_seconds') + 15, max(1, ($deadline->remaining() ?? INF) - $round)),
                    $brand,
                    $creative->campaignPack?->sourceSnapshot,
                ));
                $extraCost += $made['cost'];
                $cutout = base64_decode($made['inlineData']['data']);
                // The cutout is stored, so a retry skips straight to the scene with the full budget.
                if (($deadline->remaining() ?? INF) < $round) {
                    throw new VertexAIResponseException('banner_deadline_after_reference', 'Not enough time was left for a banner after cutting out the product photo.', true);
                }
            }
        } catch (AiProviderException $exception) {
            throw $this->withSpend($exception, $usage, $extraCost);
        }
        $failure = null;
        $split = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($attempt > 1 && ($deadline->remaining() ?? INF) < (int) config('campaigns.banners.min_round_seconds')) {
                break;
            }
            try {
                // A retried image still needs time for its inspection afterwards.
                $result = $this->rateLimited($deadline, (int) config('campaigns.banners.min_round_seconds'), fn () => $this->generateOnce($creative, $photo));
                $usage = [$usage[0] + $result->inputTokens, $usage[1] + $result->outputTextTokens, $usage[2] + $result->outputImageTokens];
                if (! config('campaigns.banners.inspect_images')) {
                    return $this->result($result, $usage, $extraCost, $cutout);
                }
                $inspection = $this->rateLimited($deadline, GenerationDeadline::MIN_ATTEMPT_SECONDS + 10, fn () => $this->inspector->inspect($result->imageBytes, $result->mimeType, $product, $brand, $composite), retryHang: true);
            } catch (AiProviderException $exception) {
                throw $this->withSpend($exception, $usage, $extraCost, $result->model ?? null);
            }
            $extraCost += $this->costs->calculate($this->models->visionModel(), $inspection['input_tokens'], 0, $inspection['output_tokens']);
            $marks = $inspection['marks'];
            if ($marks !== []) {
                $failure = ['banner_third_party_text', 'Every generated background showed readable third-party text or marks: '.mb_substr(implode(', ', $marks), 0, 300)];

                continue;
            }
            if ($inspection['product_shown']) {
                // The real product is composited on top, so a drawn one would show the product twice.
                $failure = ['banner_scene_has_product', 'Every generated scene drew the product itself, which the real product photo would be placed on top of.'];

                continue;
            }
            if (! $checkProduct) {
                return $this->result($result, $usage, $extraCost, $cutout, $composite ? $inspection['spot'] : null);
            }
            try {
                $match = $this->rateLimited($deadline, GenerationDeadline::MIN_ATTEMPT_SECONDS, fn () => $this->inspector->matchesProduct($result->imageBytes, $result->mimeType, $product, $photo), retryHang: true);
            } catch (AiProviderException $exception) {
                throw $this->withSpend($exception, $usage, $extraCost, $result->model);
            }
            $extraCost += $this->costs->calculate($this->models->visionModel(), $match['input_tokens'], 0, $match['output_tokens']);
            if ($match['matches'] && ! $match['split']) {
                return $this->result($result, $usage, $extraCost, $cutout);
            }
            if ($match['matches']) {
                // The overlay already fades softly; a panel edge drawn into the photo shows through it, so redraw.
                $split = $result;
                $failure = null;

                continue;
            }
            // A banner must show the real product; a stand drawn as a paper book is a false picture of it.
            $failure = ['banner_product_mismatch', 'The generated product did not match the store photo: '.mb_substr($match['differences'], 0, 300)];
        }

        if ($split) {
            // A visible seam is cosmetic; a correct product with clean text still beats no banner.
            return $this->result($split, $usage, $extraCost, $cutout);
        }

        throw new BannerAttemptFailed($failure[0] ?? 'banner_third_party_text', $failure[1] ?? 'No generated background passed its checks.', $this->spent($usage, $extraCost, $result->model ?? null), $result->imageBytes ?? null, $usage);
    }

    /** @param  array{x: float, y: float, width: float}|null  $spot */
    private function result(BannerGenerationResult $result, array $usage, float $extraCost, ?string $cutout, ?array $spot = null): BannerGenerationResult
    {
        return new BannerGenerationResult(
            imageBytes: $result->imageBytes,
            mimeType: $result->mimeType,
            provider: $result->provider,
            model: $result->model,
            inputTokens: $usage[0],
            outputTextTokens: $usage[1],
            outputImageTokens: $usage[2],
            providerRequestId: $result->providerRequestId,
            providerLatencyMs: $result->providerLatencyMs,
            additionalCost: round($extraCost, 6),
            productCutout: $cutout,
            productSpot: $cutout === null ? null : $spot,
        );
    }

    /** Images and calls already made in this attempt were paid for, even when a later call fails. */
    private function withSpend(AiProviderException $exception, array $usage, float $extraCost, ?string $model = null): \Throwable
    {
        $spent = $this->spent($usage, $extraCost, $model);

        return $spent > 0 ? new BannerAttemptFailed($exception->errorCode, $exception->getMessage(), $spent, tokens: $usage) : $exception;
    }

    /**
     * The provider answers 429 when the per-minute quota is used up. Retrying at once only burns
     * the job's attempts, so the call waits and tries again while the deadline still leaves $reserveSeconds after the wait.
     */
    private function rateLimited(GenerationDeadline $deadline, int $reserveSeconds, callable $call, bool $retryHang = false): mixed
    {
        $hangRetries = $retryHang ? max(0, (int) config('campaigns.banners.inspection_hang_retries', 2)) : 0;
        $waits = config('campaigns.banners.rate_limit_backoff_seconds');
        while (true) {
            try {
                return $call();
            } catch (AiProviderException $exception) {
                $remaining = $deadline->remaining() ?? INF;
                // A text call that hung with no answer (cURL 28, 0 bytes) was not billed, and failing here
                // throws away an image that was, so it is asked again at once while the deadline allows.
                if ($hangRetries > 0 && $exception->connectionFailed() && $remaining >= $reserveSeconds) {
                    $hangRetries--;

                    continue;
                }
                $wait = array_shift($waits);
                if (! $exception->rateLimited() || $wait === null || $remaining - $wait < $reserveSeconds) {
                    throw $exception;
                }
                Sleep::for($wait + random_int(0, 3))->seconds();
            }
        }
    }

    // shortcut: rounds drawn by a fallback model are priced at the final image's model, price each round on its own once fallbacks are common.
    private function spent(array $usage, float $extraCost, ?string $model): float
    {
        return round($this->costs->calculateBanner($usage[0], $usage[1], $usage[2], $model) + $extraCost, 6);
    }

    /** @param  array{mimeType: string, data: string}|null  $reference */
    private function generateOnce(BannerCreative $creative, ?array $reference): BannerGenerationResult
    {
        $drawn = $this->models->draw((string) $creative->prompt, $reference ? [$reference] : [], banner: true);
        if (! is_string($drawn['bytes']) || $drawn['bytes'] === '') {
            throw $this->models->failure('missing_image', 'returned no generated banner image.');
        }

        return new BannerGenerationResult(
            imageBytes: $drawn['bytes'],
            mimeType: $drawn['mime_type'],
            provider: $this->models->provider(),
            model: $drawn['model'],
            inputTokens: $drawn['input_tokens'],
            // Output with no text/image breakdown is counted as text.
            outputTextTokens: $drawn['output_text_tokens'] + $drawn['untagged_output_tokens'],
            outputImageTokens: $drawn['output_image_tokens'],
            providerRequestId: $drawn['request_id'],
            providerLatencyMs: $drawn['latency_ms'],
        );
    }

    private function imagePart(MediaAsset $asset): array
    {
        $bytes = Storage::disk($asset->disk)->get($asset->path);

        return ['inlineData' => [
            'mimeType' => $asset->mime_type,
            'data' => base64_encode($bytes),
        ]];
    }
}
