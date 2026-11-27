<?php

namespace Tests\Unit;

use App\Exceptions\OpenAIResponseException;
use App\Services\OpenAIClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.api_key' => 'test-key', 'campaigns.openai.base_url' => 'https://api.openai.com/v1', 'campaigns.media.transcription_model' => 'gpt-4o-mini-transcribe']);
    }

    public function test_respond_reads_the_text_usage_and_request_id(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_1', 'model' => 'gpt-5.4-mini', 'status' => 'completed',
            'output' => [['type' => 'reasoning'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"ok":true}']]]],
            'usage' => ['input_tokens' => 100, 'input_tokens_details' => ['cached_tokens' => 40], 'output_tokens' => 60],
        ], 200, ['x-request-id' => 'req_1'])]);

        $answer = app(OpenAIClient::class)->respond(['model' => 'gpt-5.4-mini']);

        $this->assertSame([false, null, '{"ok":true}', 'gpt-5.4-mini', 100, 40, 60, 'req_1'], [
            $answer['incomplete'], $answer['refusal'], $answer['text'], $answer['model'], $answer['input_tokens'], $answer['cached_input_tokens'], $answer['output_tokens'], $answer['request_id'],
        ]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/responses' && $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function test_respond_reports_a_refusal_and_an_incomplete_answer(): void
    {
        Http::fakeSequence()
            ->push(['id' => 'resp_2', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No.']]]]])
            ->push(['id' => 'resp_3', 'status' => 'incomplete', 'output' => []]);
        $client = app(OpenAIClient::class);

        $refused = $client->respond(['model' => 'gpt-5.4-mini']);
        $this->assertSame(['No.', null, 'gpt-5.4-mini', 'resp_2'], [$refused['refusal'], $refused['text'], $refused['model'], $refused['request_id']]);
        $this->assertTrue($client->respond(['model' => 'gpt-5.4-mini'])['incomplete']);
    }

    public function test_failures_carry_a_code_and_whether_to_retry(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'Slow down']], 429)
            ->push(['error' => ['message' => 'Bad schema']], 400);
        $client = app(OpenAIClient::class);

        $limited = $this->failure(fn () => $client->respond([]));
        $this->assertSame(['openai_http_429', true, true], [$limited->errorCode, $limited->retryable, $limited->rateLimited()]);
        $this->assertStringContainsString('Slow down', $limited->getMessage());
        $bad = $this->failure(fn () => $client->respond([]));
        $this->assertSame(['openai_http_400', false, false], [$bad->errorCode, $bad->retryable, $bad->rateLimited()]);
    }

    public function test_a_lost_connection_can_be_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('Timed out'));

        $lost = $this->failure(fn () => app(OpenAIClient::class)->respond([]));
        $this->assertSame(['openai_connection_error', true, true], [$lost->errorCode, $lost->retryable, $lost->connectionFailed()]);
    }

    public function test_a_missing_key_is_reported_without_a_request(): void
    {
        config(['services.openai.api_key' => null]);
        Http::fake();

        $this->assertSame('openai_not_configured', $this->failure(fn () => app(OpenAIClient::class)->respond([]))->errorCode);
        Http::assertNothingSent();
    }

    public function test_transcription_uses_the_azure_deployment_path_or_the_openai_one(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'audio');
        file_put_contents($path, 'sound');
        Http::fake(['*' => Http::response(['text' => ' Hello there. '])]);

        config(['campaigns.openai.base_url' => 'https://x.cognitiveservices.azure.com/openai/v1']);
        $this->assertSame('Hello there.', app(OpenAIClient::class)->transcribe($path, 'clip.mp3'));
        config(['campaigns.openai.base_url' => 'https://api.openai.com/v1']);
        app(OpenAIClient::class)->transcribe($path, 'clip.mp3');

        $urls = Http::recorded()->map(fn (array $pair) => $pair[0]->url())->all();
        $this->assertSame([
            'https://x.cognitiveservices.azure.com/openai/deployments/gpt-4o-mini-transcribe/audio/transcriptions?api-version=2024-10-21',
            'https://api.openai.com/v1/audio/transcriptions',
        ], $urls);
        // Azure gets its key in the documented "api-key" header as well; no other host does.
        $this->assertSame([['test-key'], []], Http::recorded()->map(fn (array $pair) => $pair[0]->header('api-key'))->all());
        $this->assertTrue(Http::recorded()->every(fn (array $pair) => $pair[0]->hasHeader('Authorization', 'Bearer test-key')));
        unlink($path);
    }

    public function test_an_empty_transcript_is_a_failure(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'audio');
        file_put_contents($path, 'sound');
        Http::fake(['*' => Http::response(['text' => '  '])]);

        $this->assertSame('openai_missing_transcript', $this->failure(fn () => app(OpenAIClient::class)->transcribe($path))->errorCode);
        unlink($path);
    }

    private function failure(callable $call): OpenAIResponseException
    {
        try {
            $call();
        } catch (OpenAIResponseException $exception) {
            return $exception;
        }
        $this->fail('Expected an OpenAI failure.');
    }
}
