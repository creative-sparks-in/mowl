<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Workspace;
use App\Services\MediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MediaProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_an_uploaded_image_to_a_512px_derivative(): void
    {
        Storage::fake('local');

        $processed = $this->processedImage();

        $this->assertSame('processed', $processed->status);
        $this->assertCount(1, $processed->derivatives['images']);
        Storage::disk('local')->assertExists($processed->derivatives['images'][0]['path']);
    }

    public function test_it_extracts_audio_and_a_bounded_deduplicated_frame_set_from_video(): void
    {
        Storage::fake('local');
        config()->set('services.openai.api_key', null);
        $asset = $this->videoAsset();

        app(MediaProcessor::class)->process($asset);

        $processed = $asset->fresh();
        $frames = $processed->derivatives['frames'];
        $this->assertSame('processed', $processed->status);
        $this->assertGreaterThan(0, count($frames));
        $this->assertLessThanOrEqual(config('campaigns.media.max_frames'), count($frames));
        $this->assertSame('pending_credentials', $processed->metadata['transcription_status']);
        Storage::disk('local')->assertExists($processed->derivatives['audio']);
        foreach ($frames as $frame) {
            $this->assertSame(64, strlen($frame['perceptual_hash']));
            Storage::disk('local')->assertExists($frame['path']);
        }
    }

    public function test_it_transcribes_the_extracted_audio_with_openai(): void
    {
        Storage::fake('local');
        config()->set('services.openai.api_key', 'test-key');
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => ' A short product demo. '])]);
        $asset = $this->videoAsset();

        app(MediaProcessor::class)->process($asset);

        $processed = $asset->fresh();
        $this->assertSame('completed', $processed->metadata['transcription_status']);
        $this->assertSame('A short product demo.', $processed->metadata['transcript']);
        Http::assertSent(fn (Request $request) => $request->hasFile('model', 'gpt-4o-mini-transcribe') && $request->hasFile('file', null, 'audio.wav'));
    }

    public function test_azure_transcription_goes_to_the_deployment_url(): void
    {
        Storage::fake('local');
        config()->set('services.openai.api_key', 'test-key');
        config()->set('campaigns.openai.base_url', 'https://x.cognitiveservices.azure.com/openai/v1');
        Http::preventStrayRequests();
        Http::fake(['x.cognitiveservices.azure.com/*' => Http::response(['text' => 'Hello from Azure.'])]);
        $asset = $this->videoAsset();

        app(MediaProcessor::class)->process($asset);

        $this->assertSame('Hello from Azure.', $asset->fresh()->metadata['transcript']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://x.cognitiveservices.azure.com/openai/deployments/gpt-4o-mini-transcribe/audio/transcriptions?api-version=2024-10-21');
    }

    public function test_a_failed_transcription_keeps_the_frames(): void
    {
        Storage::fake('local');
        config()->set('services.openai.api_key', 'test-key');
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['error' => ['message' => 'Server error']], 500)]);
        $asset = $this->videoAsset();

        app(MediaProcessor::class)->process($asset);

        $processed = $asset->fresh();
        $this->assertSame('processed', $processed->status);
        $this->assertGreaterThan(0, count($processed->derivatives['frames']));
        $this->assertNull($processed->metadata['transcript']);
        $this->assertSame('failed', $processed->metadata['transcription_status']);
    }

    public function test_without_ffmpeg_an_image_is_resized_with_gd(): void
    {
        Storage::fake('local');
        $this->withoutFfmpeg();

        $processed = $this->processedImage(1200, 800);

        $this->assertSame('processed', $processed->status);
        $derivative = Storage::disk('local')->path($processed->derivatives['images'][0]['path']);
        $this->assertSame([512, 341, IMAGETYPE_JPEG], array_slice(getimagesize($derivative), 0, 3));
        $this->assertSame(512, $processed->derivatives['images'][0]['width']);
    }

    public function test_without_ffmpeg_a_narrow_image_is_never_enlarged(): void
    {
        Storage::fake('local');
        $this->withoutFfmpeg();

        // Enlarged to 512 wide this strip would be 512 x 102400, more than PHP's memory holds.
        $processed = $this->processedImage(10, 2000);

        $this->assertSame('processed', $processed->status);
        $derivative = Storage::disk('local')->path($processed->derivatives['images'][0]['path']);
        $this->assertSame([10, 2000, IMAGETYPE_JPEG], array_slice(getimagesize($derivative), 0, 3));
        $this->assertSame(10, $processed->derivatives['images'][0]['width']);
    }

    public function test_ffmpeg_named_as_a_command_on_the_path_is_found(): void
    {
        Storage::fake('local');
        config()->set('services.openai.api_key', null);
        $asset = $this->videoAsset();
        // The folder of the configured full path goes on PATH, so the bare names resolve wherever ffmpeg lives.
        $path = (string) getenv('PATH');
        putenv('PATH='.dirname((string) config('campaigns.media.ffmpeg')).PATH_SEPARATOR.$path);
        config()->set('campaigns.media.ffmpeg', 'ffmpeg');
        config()->set('campaigns.media.ffprobe', 'ffprobe');

        try {
            app(MediaProcessor::class)->process($asset);
        } finally {
            putenv('PATH='.$path);
        }

        $processed = $asset->fresh();
        $this->assertSame('processed', $processed->status);
        $this->assertArrayNotHasKey('analysis_mode', $processed->metadata);
        $this->assertGreaterThan(0, count($processed->derivatives['frames']));
    }

    public function test_without_ffmpeg_a_camera_jpeg_stored_on_its_side_is_turned_upright(): void
    {
        Storage::fake('local');
        $this->withoutFfmpeg();

        // Stored 1200 x 800 but shown turned, so it is an 800 x 1200 portrait: 512 x 768, not a sideways 512 x 341.
        $processed = $this->processedImage(1200, 800, sideways: true);

        $this->assertSame('processed', $processed->status);
        $derivative = Storage::disk('local')->path($processed->derivatives['images'][0]['path']);
        $this->assertSame([512, 768, IMAGETYPE_JPEG], array_slice(getimagesize($derivative), 0, 3));
        $this->assertSame(512, $processed->derivatives['images'][0]['width']);
    }

    public function test_without_ffmpeg_an_image_too_large_to_decode_is_refused_before_decoding(): void
    {
        Storage::fake('local');
        $this->withoutFfmpeg();
        [$workspace, $product] = $this->productFixture();
        ob_start();
        imagepng(imagecreatetruecolor(8, 8));
        // The header claims 5000 x 5000 pixels: more than GD may decode, whatever the file's size.
        $bomb = substr_replace((string) ob_get_clean(), pack('NN', 5000, 5000), 16, 8);
        Storage::disk('local')->put('originals/bomb.png', $bomb);
        $asset = MediaAsset::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'type' => 'image',
            'disk' => 'local',
            'path' => 'originals/bomb.png',
            'original_name' => 'bomb.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bomb),
            'content_hash' => hash('sha256', $bomb),
        ]);

        try {
            app(MediaProcessor::class)->process($asset);
            $this->fail('An image over the pixel limit was decoded.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The image could not be decoded or is too large.', $exception->getMessage());
        }

        $this->assertSame('failed', $asset->fresh()->status);
    }

    public function test_without_ffmpeg_an_mp4_video_is_transcribed_from_the_original_file(): void
    {
        Storage::fake('local');
        $this->withoutFfmpeg();
        config()->set('services.openai.api_key', 'test-key');
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Spoken words.'])]);
        $asset = $this->videoAsset('video.mp4', 'video bytes');

        app(MediaProcessor::class)->process($asset);

        $processed = $asset->fresh();
        $this->assertSame('processed', $processed->status);
        $this->assertSame(['frames' => [], 'audio' => null], $processed->derivatives);
        $this->assertSame('no_ffmpeg', $processed->metadata['analysis_mode']);
        $this->assertSame(0, $processed->metadata['frames_selected']);
        $this->assertSame('Spoken words.', $processed->metadata['transcript']);
        $this->assertSame('completed', $processed->metadata['transcription_status']);
        Http::assertSent(fn (Request $request) => $request->hasFile('file', null, 'video.mp4'));
    }

    public function test_without_ffmpeg_a_mov_video_skips_transcription(): void
    {
        Storage::fake('local');
        $this->withoutFfmpeg();
        config()->set('services.openai.api_key', 'test-key');
        Http::preventStrayRequests();
        $asset = $this->videoAsset('video.mov', 'video bytes');

        app(MediaProcessor::class)->process($asset);

        $processed = $asset->fresh();
        $this->assertSame('processed', $processed->status);
        $this->assertSame('no_ffmpeg', $processed->metadata['analysis_mode']);
        $this->assertSame('skipped_no_ffmpeg', $processed->metadata['transcription_status']);
        Http::assertNothingSent();
    }

    public function test_google_processing_keeps_private_gcs_media_for_direct_vertex_analysis(): void
    {
        config()->set('campaigns.generator', 'google');
        Storage::fake('gcs');
        [$workspace, $product] = $this->productFixture();
        Storage::disk('gcs')->put('originals/video.mp4', 'video bytes');
        $asset = MediaAsset::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'type' => 'video',
            'disk' => 'gcs',
            'path' => 'originals/video.mp4',
            'original_name' => 'video.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 11,
            'content_hash' => hash('sha256', 'video bytes'),
        ]);

        $summary = app(MediaProcessor::class)->processForProduct($product);

        $this->assertSame('processed', $asset->fresh()->status);
        $this->assertSame('vertex_direct', $asset->fresh()->metadata['analysis_mode']);
        $this->assertSame('originals/video.mp4', $summary['videos'][0]['path']);
        $this->assertSame('video/mp4', $summary['videos'][0]['mime_type']);
        Storage::disk('gcs')->assertExists('originals/video.mp4');
    }

    private function processedImage(int $width = 120, int $height = 80, bool $sideways = false): MediaAsset
    {
        [$workspace, $product] = $this->productFixture();
        $image = imagecreatetruecolor($width, $height);
        $temporary = tempnam(sys_get_temp_dir(), 'mowl-image').'.png';
        if ($sideways) {
            imagejpeg($image, $temporary);
            $jpeg = file_get_contents($temporary);
            // An Exif block saying the camera was turned: the photo is shown rotated 90 degrees clockwise.
            $exif = "Exif\0\0MM\0\x2a\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01\0\x06\0\0\0\0\0\0";
            file_put_contents($temporary, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
        } else {
            imagepng($image, $temporary);
        }
        imagedestroy($image);
        Storage::disk('local')->put('originals/image.png', file_get_contents($temporary));
        $asset = MediaAsset::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'type' => 'image',
            'disk' => 'local',
            'path' => 'originals/image.png',
            'original_name' => 'image.png',
            'mime_type' => 'image/png',
            'size_bytes' => filesize($temporary),
            'content_hash' => hash_file('sha256', $temporary),
        ]);
        @unlink($temporary);

        app(MediaProcessor::class)->process($asset);

        return $asset->fresh();
    }

    /** A two-second test-pattern video with a sine tone, unless other contents are given. */
    private function videoAsset(string $name = 'video.mp4', ?string $contents = null): MediaAsset
    {
        if ($contents === null) {
            $temporary = tempnam(sys_get_temp_dir(), 'mowl-video').'.mp4';
            (new Process([
                config('campaigns.media.ffmpeg'), '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=12',
                '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=16000',
                '-t', '2', '-c:v', 'mpeg4', '-c:a', 'aac', '-pix_fmt', 'yuv420p', '-shortest', $temporary,
            ]))->mustRun();
            $contents = file_get_contents($temporary);
            @unlink($temporary);
        }
        [$workspace, $product] = $this->productFixture();
        Storage::disk('local')->put("originals/{$name}", $contents);

        return MediaAsset::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'type' => 'video',
            'disk' => 'local',
            'path' => "originals/{$name}",
            'original_name' => $name,
            'mime_type' => 'video/mp4',
            'size_bytes' => strlen($contents),
            'content_hash' => hash('sha256', $contents),
        ]);
    }

    private function withoutFfmpeg(): void
    {
        config()->set('campaigns.media.ffmpeg', '/missing/ffmpeg');
        config()->set('campaigns.media.ffprobe', '/missing/ffprobe');
    }

    private function productFixture(): array
    {
        $workspace = Workspace::create(['name' => 'Media Agency']);
        $brand = $workspace->brands()->create(['name' => 'Media Brand']);

        return [$workspace, $brand->products()->create(['name' => 'Media Product'])];
    }
}
