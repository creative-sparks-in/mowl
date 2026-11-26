<?php

namespace App\Services;

use App\Contracts\BannerModels;
use App\Exceptions\AiProviderException;
use App\Exceptions\OpenAIResponseException;
use Illuminate\Support\Str;

/** Banner drawing with OpenAI's image models and image checks with its campaign model, on OpenAI or Azure OpenAI. */
class OpenAIBannerModels implements BannerModels
{
    /** The only sizes models before gpt-image-2 draw. */
    private const FIXED_SIZES = [[1024, 1024], [1024, 1536], [1536, 1024]];

    public function __construct(private OpenAIClient $client) {}

    public function provider(): string
    {
        return 'openai';
    }

    public function visionModel(): string
    {
        return (string) config('campaigns.openai.model');
    }

    /**
     * Each configured model in turn: one with no capacity left this minute (429 or 503) hands the call
     * to the next at once, and the last one's refusal goes back to the caller's rate-limit wait.
     */
    public function draw(string $prompt, array $references = [], bool $banner = false, ?int $timeoutSeconds = null): array
    {
        $models = (array) config('campaigns.banners.openai.models');
        foreach ($models as $index => $model) {
            // gpt-image-2 rejects background and input_fidelity changes, so neither is sent to any model.
            $fields = [
                'model' => $model,
                'prompt' => $prompt,
                'size' => $this->size($model, $references, $banner),
                'quality' => (string) config('campaigns.banners.openai.quality'),
                'output_format' => 'png',
                'n' => 1,
            ];
            try {
                [$response, $latency] = $references === []
                    ? $this->client->post('/images/generations', $fields, $timeoutSeconds)
                    : $this->client->postMultipart('/images/edits', $fields, array_map(fn (array $reference, int $n) => [
                        'name' => 'image[]',
                        'contents' => (string) base64_decode($reference['data']),
                        'filename' => 'reference-'.($n + 1).'.'.Str::after($reference['mimeType'], '/'),
                        'mime_type' => $reference['mimeType'],
                    ], $references, array_keys($references)), $timeoutSeconds);
            } catch (OpenAIResponseException $exception) {
                if (! in_array($exception->errorCode, ['openai_http_429', 'openai_http_503'], true) || $index === array_key_last($models)) {
                    throw $exception;
                }

                continue;
            }
            $encoded = $response->json('data.0.b64_json');
            $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
            $details = $response->json('usage.output_tokens_details');

            return [
                'bytes' => is_string($bytes) ? $bytes : null,
                // Azure draws png or jpeg only, and png is asked for.
                'mime_type' => 'image/png',
                'model' => $model,
                'input_tokens' => (int) $response->json('usage.input_tokens', 0),
                'output_text_tokens' => (int) ($details['text_tokens'] ?? 0),
                // Without a breakdown the output is priced as image tokens, so a drawing is never under-recorded.
                'output_image_tokens' => is_array($details) ? (int) ($details['image_tokens'] ?? 0) : (int) $response->json('usage.output_tokens', 0),
                'untagged_output_tokens' => 0,
                'thinking_tokens' => 0,
                'request_id' => $response->header('x-request-id') ?: null,
                'latency_ms' => $latency,
            ];
        }

        throw $this->failure('not_configured', 'banner image models are not configured (OPENAI_BANNER_MODELS).', false);
    }

    public function look(string $prompt, array $images, array $schema): array
    {
        $payload = [
            'model' => $this->visionModel(),
            'store' => false,
            // A short answer is all a check needs, and a cap ends a runaway list instead of letting it hang.
            'max_output_tokens' => (int) config('campaigns.banners.inspection_max_output_tokens'),
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt],
                    ...array_map(fn (array $image) => ['type' => 'input_image', 'image_url' => "data:{$image['mimeType']};base64,{$image['data']}"], $images),
                ],
            ]],
            'text' => ['format' => OpenAIClient::jsonFormat('banner_check', $schema)],
        ];
        // A yes/no look needs little reasoning; empty leaves the model's default.
        $effort = (string) config('campaigns.openai.inspection_reasoning_effort');
        if ($effort !== '') {
            $payload['reasoning'] = ['effort' => $effort];
        }
        $answer = $this->client->respond($payload, (int) config('campaigns.banners.inspection_timeout_seconds'));

        return [
            'answer' => $answer['incomplete'] || $answer['refusal'] !== null || $answer['text'] === null ? null : json_decode($answer['text'], true),
            'cut_short' => $answer['incomplete'],
            'input_tokens' => $answer['input_tokens'],
            'output_tokens' => $answer['output_tokens'],
        ];
    }

    public function failure(string $code, string $message, bool $retryable = true): AiProviderException
    {
        return new OpenAIResponseException('openai_'.$code, 'OpenAI '.$message, $retryable);
    }

    /**
     * A banner in its own shape; otherwise the first reference's shape at about 1024px on its longer side
     * (1024x1024 when it cannot be measured). Models before gpt-image-2 draw the nearest fixed size, and
     * the composer cover-crops a banner to 4:5.
     */
    private function size(string $model, array $references, bool $banner): string
    {
        $measured = $references === [] ? false : @getimagesizefromstring((string) base64_decode($references[0]['data']));
        [$width, $height] = $banner ? [(int) config('campaigns.banners.width'), (int) config('campaigns.banners.height')] : ($measured ? [$measured[0], $measured[1]] : [1024, 1024]);
        $longest = $banner ? max($width, $height) : 1024;
        if ($model !== 'gpt-image-2') {
            $ratio = log($width / $height);
            [$width, $height] = collect(self::FIXED_SIZES)->sortBy(fn (array $size) => abs(log($size[0] / $size[1]) - $ratio))->first();

            return "{$width}x{$height}";
        }
        // gpt-image-2 draws sides in multiples of 16, at most 3:1, and 655,360 to 8,294,400 pixels.
        $ratio = min(3, max(1 / 3, $width / $height));
        [$width, $height] = $ratio >= 1 ? [$longest, $longest / $ratio] : [$longest * $ratio, $longest];
        $grow = max(1, sqrt(655_360 / ($width * $height)));

        return sprintf('%dx%d', ceil($width * $grow / 16) * 16, ceil($height * $grow / 16) * 16);
    }
}
