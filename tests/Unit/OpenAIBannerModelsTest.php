<?php

namespace Tests\Unit;

use App\Exceptions\OpenAIResponseException;
use App\Services\OpenAIBannerModels;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIBannerModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openai.api_key', 'test-key');
        config()->set('campaigns.openai.base_url', 'https://api.openai.com/v1');
        config()->set('campaigns.openai.model', 'gpt-5.4-mini');
        config()->set('campaigns.openai.inspection_reasoning_effort', 'low');
        config()->set('campaigns.banners.openai.models', ['gpt-image-2', 'gpt-image-1.5']);
        config()->set('campaigns.banners.openai.quality', 'medium');
    }

    public function test_a_banner_is_drawn_at_its_own_shape_rounded_to_sixteen_and_its_usage_is_mapped(): void
    {
        Http::fake(['api.openai.com/v1/images/generations' => Http::response(self::image('drawn'), 200, ['x-request-id' => 'req-1'])]);

        $drawn = app(OpenAIBannerModels::class)->draw('A quiet desk at dawn.', banner: true);

        $this->assertSame(['drawn', 'image/png', 'gpt-image-2', 500, 44, 1056, 'req-1'], [$drawn['bytes'], $drawn['mime_type'], $drawn['model'], $drawn['input_tokens'], $drawn['output_text_tokens'], $drawn['output_image_tokens'], $drawn['request_id']]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/images/generations'
            && $request->data() === ['model' => 'gpt-image-2', 'prompt' => 'A quiet desk at dawn.', 'size' => '1088x1360', 'quality' => 'medium', 'output_format' => 'png', 'n' => 1]);
    }

    public function test_a_reference_is_sent_as_a_multipart_edit_that_keeps_its_shape(): void
    {
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(self::image('cutout'))]);
        $photo = self::png(1500, 1000);

        app(OpenAIBannerModels::class)->draw('Remove the props.', [['mimeType' => 'image/png', 'data' => base64_encode($photo)]]);
        // A reference that cannot be measured is drawn square.
        app(OpenAIBannerModels::class)->draw('Remove the props.', [['mimeType' => 'image/jpeg', 'data' => base64_encode('not-an-image')]]);

        $sent = collect(Http::recorded())->map(fn (array $pair) => $pair[0]);
        $this->assertTrue($sent[0]->isMultipart());
        $parts = collect($sent[0]->data())->keyBy('name');
        $this->assertSame($photo, $parts['image[]']['contents']);
        $this->assertSame('reference-1.png', $parts['image[]']['filename']);
        // 3:2 at 1024px on the longer side, in multiples of 16.
        $this->assertSame(['gpt-image-2', '1024x688', 'medium', 'png'], [$parts['model']['contents'], $parts['size']['contents'], $parts['quality']['contents'], $parts['output_format']['contents']]);
        $this->assertFalse($parts->has('background') || $parts->has('input_fidelity'));
        $this->assertSame('1024x1024', collect($sent[1]->data())->keyBy('name')['size']['contents']);
    }

    public function test_output_without_a_breakdown_is_counted_as_image_tokens(): void
    {
        Http::fake(['api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode('drawn')]], 'usage' => ['input_tokens' => 40, 'output_tokens' => 1200]])]);

        $drawn = app(OpenAIBannerModels::class)->draw('A scene.', banner: true);

        $this->assertSame([40, 0, 1200], [$drawn['input_tokens'], $drawn['output_text_tokens'], $drawn['output_image_tokens']]);
    }

    public function test_a_rate_limited_model_hands_the_drawing_to_the_next_at_its_fixed_size(): void
    {
        Http::fake(['api.openai.com/v1/images/generations' => fn (Request $request) => $request['model'] === 'gpt-image-2'
            ? Http::response(['error' => ['message' => 'Rate limit reached.']], 429)
            : Http::response(self::image('drawn'))]);

        $drawn = app(OpenAIBannerModels::class)->draw('A scene.', banner: true);

        $this->assertSame('gpt-image-1.5', $drawn['model']);
        // 4:5 is nearest 2:3 of the three sizes gpt-image-1.5 draws.
        Http::assertSent(fn (Request $request): bool => $request['model'] === 'gpt-image-1.5' && $request['size'] === '1024x1536');
    }

    public function test_when_every_model_is_rate_limited_the_last_refusal_is_thrown(): void
    {
        Http::fake(['api.openai.com/v1/images/generations' => Http::response(['error' => ['message' => 'Rate limit reached.']], 429)]);

        try {
            app(OpenAIBannerModels::class)->draw('A scene.', banner: true);
            $this->fail('A drawing no model could make must fail.');
        } catch (OpenAIResponseException $exception) {
            $this->assertTrue($exception->rateLimited());
        }
        Http::assertSentCount(2);
    }

    public function test_any_other_error_is_thrown_without_trying_the_next_model(): void
    {
        Http::fake(['api.openai.com/v1/images/generations' => Http::response(['error' => ['message' => 'Invalid size.']], 400)]);

        try {
            app(OpenAIBannerModels::class)->draw('A scene.', banner: true);
            $this->fail('A rejected request must fail.');
        } catch (OpenAIResponseException $exception) {
            $this->assertSame('openai_http_400', $exception->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_a_look_asks_for_strict_json_about_the_images_with_low_reasoning_and_a_cap(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response(self::answer(['marks' => ['KINFOLK']]))]);
        $schema = ['type' => 'object', 'properties' => ['marks' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['marks'], 'additionalProperties' => false];

        $look = app(OpenAIBannerModels::class)->look('List the marks.', [['mimeType' => 'image/jpeg', 'data' => 'AAAA']], $schema);

        $this->assertSame(['answer' => ['marks' => ['KINFOLK']], 'cut_short' => false, 'input_tokens' => 300, 'output_tokens' => 12], $look);
        Http::assertSent(fn (Request $request): bool => $request['model'] === 'gpt-5.4-mini'
            && $request['store'] === false
            && $request['reasoning'] === ['effort' => 'low']
            && $request['max_output_tokens'] === 2048
            && $request['input'][0]['content'] === [['type' => 'input_text', 'text' => 'List the marks.'], ['type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,AAAA']]
            && $request['text']['format'] === ['type' => 'json_schema', 'name' => 'banner_check', 'strict' => true, 'schema' => $schema]);
    }

    public function test_a_look_cut_off_at_its_cap_is_cut_short_with_no_answer_and_no_reasoning_key_when_effort_is_empty(): void
    {
        config()->set('campaigns.openai.inspection_reasoning_effort', '');
        $incomplete = self::answer(['marks' => []]);
        $incomplete['status'] = 'incomplete';
        $incomplete['usage'] = ['input_tokens' => 300, 'output_tokens' => 2048];
        Http::fake(['api.openai.com/v1/responses' => Http::response($incomplete)]);

        $look = app(OpenAIBannerModels::class)->look('List the marks.', [], ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false]);

        $this->assertSame(['answer' => null, 'cut_short' => true, 'input_tokens' => 300, 'output_tokens' => 2048], $look);
        Http::assertSent(fn (Request $request): bool => ! isset($request['reasoning']));
    }

    public static function image(string $bytes): array
    {
        return [
            'data' => [['b64_json' => base64_encode($bytes)]],
            'usage' => ['input_tokens' => 500, 'output_tokens' => 1100, 'output_tokens_details' => ['image_tokens' => 1056, 'text_tokens' => 44]],
        ];
    }

    public static function answer(array $json): array
    {
        return [
            'id' => 'resp_1',
            'status' => 'completed',
            'model' => 'gpt-5.4-mini',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json)]]]],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 12],
        ];
    }

    private static function png(int $width, int $height): string
    {
        ob_start();
        imagepng(imagecreatetruecolor($width, $height));

        return (string) ob_get_clean();
    }
}
