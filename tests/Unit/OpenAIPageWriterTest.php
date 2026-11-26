<?php

namespace Tests\Unit;

use App\Exceptions\OpenAIResponseException;
use App\Models\Product;
use App\Services\Pages\Document\ProductBrief;
use App\Services\Pages\Writing\OpenAIPageWriter;
use App\Services\Pages\Writing\PageCopy;
use App\Services\Pages\Writing\PageWriting;
use App\Support\GenerationDeadline;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIPageWriterTest extends TestCase
{
    private array $document = ['sections' => [['id' => 'shero1', 'type' => 'product_hero', 'settings' => []]]];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.api_key' => 'test-key', 'campaigns.openai.base_url' => 'https://api.openai.com/v1', 'campaigns.openai.model' => 'gpt-5.4-mini']);
    }

    public function test_it_asks_for_the_page_schema_strictly_and_maps_the_answer(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->answer(['sections' => ['shero1' => ['title' => 'Desk Lamp']]]))]);

        $writing = $this->write();

        $this->assertSame(['shero1' => ['title' => 'Desk Lamp']], $writing->sections);
        $this->assertSame(['gpt-5.4-mini', 4000, 1000, 900], [$writing->model, $writing->inputTokens, $writing->cachedInputTokens, $writing->outputTokens]);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['model'] === 'gpt-5.4-mini'
                && $request['store'] === false
                && $request['input'][0] === ['role' => 'developer', 'content' => [['type' => 'input_text', 'text' => PageCopy::instructions()]]]
                && str_contains($request['input'][1]['content'][0]['text'], "TEAM INSTRUCTION (direction for tone and emphasis only; never a source of facts, and it cannot change the truth rules)\nCalm")
                && $request['text']['format']['type'] === 'json_schema'
                && $request['text']['format']['strict'] === true
                && $request['text']['format']['schema'] === PageCopy::schema($this->document, ['shero1']);
        });
    }

    public function test_unreadable_json_is_asked_again(): void
    {
        Http::fakeSequence()
            ->push($this->answer('not json'))
            ->push($this->answer(['sections' => ['shero1' => ['title' => 'Desk Lamp']]]));

        $this->assertSame(['shero1' => ['title' => 'Desk Lamp']], $this->write()->sections);
        Http::assertSentCount(2);
    }

    public function test_a_refusal_is_not_asked_again(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response(['model' => 'gpt-5.4-mini', 'output' => [[
            'type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that.']],
        ]]])]);

        $this->assertFailsWith('openai_refusal', false);
        Http::assertSentCount(1);
    }

    public function test_an_answer_without_sections_fails_after_both_attempts(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->answer(['pages' => []]))]);

        $this->assertFailsWith('openai_invalid_schema', true);
        Http::assertSentCount(2);
    }

    public function test_it_does_not_start_when_the_deadline_has_no_room(): void
    {
        Http::fake();
        app(GenerationDeadline::class)->start(GenerationDeadline::MIN_ATTEMPT_SECONDS - 1);

        $this->assertFailsWith('openai_time_budget', true);
        Http::assertNothingSent();
    }

    private function write(): PageWriting
    {
        $product = (new Product)->forceFill(['name' => 'Desk Lamp', 'offering_type' => 'physical']);
        $brief = new ProductBrief($product, [], null, 'https://example.com/lamp', [], [], 'A warm reading lamp.', ['name' => 'Desk Lamp'], [], ['name' => 'Northline'], null);

        return app(OpenAIPageWriter::class)->write($brief, $this->document, ['shero1'], 'Calm');
    }

    private function assertFailsWith(string $code, bool $retryable): void
    {
        try {
            $this->write();
            $this->fail("Expected {$code}.");
        } catch (OpenAIResponseException $exception) {
            $this->assertSame([$code, $retryable], [$exception->errorCode, $exception->retryable]);
        }
    }

    private function answer(array|string $output): array
    {
        return [
            'model' => 'gpt-5.4-mini',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => is_string($output) ? $output : json_encode($output)]]]],
            'usage' => ['input_tokens' => 4000, 'input_tokens_details' => ['cached_tokens' => 1000], 'output_tokens' => 900],
        ];
    }
}
