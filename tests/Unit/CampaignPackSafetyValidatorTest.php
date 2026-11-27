<?php

namespace Tests\Unit;

use App\Models\SourceSnapshot;
use App\Services\CampaignPackEvidence;
use App\Services\CampaignPackFinalizer;
use App\Services\CampaignPackSafetyValidator;
use Tests\TestCase;

class CampaignPackSafetyValidatorTest extends TestCase
{
    public function test_it_blocks_a_claim_that_is_more_specific_than_the_source(): void
    {
        $content = $this->safeContent();
        $evidence = [[
            'id' => 'claim-1',
            'claim' => 'Handmade in India',
            'source' => 'https://example.com/product',
            'excerpt' => 'Made, sourced, or packed in India.',
            'status' => 'supported_paraphrased',
        ]];

        $issues = app(CampaignPackSafetyValidator::class)->issues($content, $evidence, [], $this->source());

        $this->assertTrue(collect($issues)->contains(fn (array $issue) => str_contains($issue['message'], 'more specific than its evidence')));
    }

    public function test_it_blocks_voiceover_and_caption_timing_that_cannot_fit(): void
    {
        $content = $this->safeContent();
        $content['creative_routes'][0]['voiceover'][0] = [
            'start_seconds' => 18,
            'end_seconds' => 21,
            'line' => 'This line starts too late and contains far too many words to fit naturally in the claimed duration.',
            'word_count' => 3,
            'pace_wpm' => 60,
            'delivery_notes' => 'Natural',
            'shot_id' => 'shot-1',
        ];
        $content['creative_routes'][0]['captions'][0] = [
            'start_seconds' => 0,
            'end_seconds' => 1,
            'text' => 'This caption is much too long to read within only one second.',
            'shot_id' => 'shot-1',
        ];

        $issues = app(CampaignPackSafetyValidator::class)->issues($content, [], [], $this->source());

        $this->assertTrue(collect($issues)->contains(fn (array $issue) => $issue['type'] === 'timing'));
        $this->assertTrue(collect($issues)->contains(fn (array $issue) => $issue['type'] === 'caption'));
    }

    public function test_a_models_miscounted_words_and_pace_do_not_block_and_are_recounted(): void
    {
        $content = $this->safeContent();
        $content['creative_routes'][0]['voiceover'][0] = [
            'start_seconds' => 0,
            'end_seconds' => 5,
            'line' => 'This Diwali, gift a beautiful, dedicated home for their favorite e-reader.',
            'word_count' => 12,
            'pace_wpm' => 144,
            'delivery_notes' => 'Warm',
            'shot_id' => 'shot-1',
        ];

        $issues = app(CampaignPackSafetyValidator::class)->issues($content, [], [], $this->source());
        $this->assertFalse(collect($issues)->contains(fn (array $issue) => $issue['type'] === 'timing'), 'The line itself fits: 11 words at 132 wpm');

        $segment = app(CampaignPackFinalizer::class)->finalize($content)['creative_routes'][0]['voiceover'][0];
        $this->assertSame([11, 132], [$segment['word_count'], $segment['pace_wpm']]);
    }

    public function test_it_blocks_specific_route_copy_when_only_broad_origin_evidence_exists(): void
    {
        $content = $this->safeContent();
        $content['creative_routes'][0]['core_promise'] = 'Handmade in India';
        $evidence = [[
            'id' => 'claim-1',
            'claim' => 'Made, sourced, or packed in India',
            'source' => 'https://example.com/product',
            'excerpt' => 'Made, sourced, or packed in India.',
            'status' => 'directly_supported',
        ]];

        $issues = app(CampaignPackSafetyValidator::class)->issues($content, $evidence, [], $this->source());

        $this->assertTrue(collect($issues)->contains(fn (array $issue) => str_contains($issue['message'], 'more specific than the approved evidence')));
    }

    public function test_a_source_linked_claim_with_valid_timing_passes(): void
    {
        $evidence = [[
            'id' => 'claim-1',
            'claim' => 'Made, sourced, or packed in India',
            'source' => 'https://example.com/product',
            'excerpt' => 'Made, sourced, or packed in India.',
            'status' => 'directly_supported',
        ]];

        $issues = app(CampaignPackSafetyValidator::class)->issues($this->safeContent(), $evidence, [], $this->source());

        $this->assertSame([], $issues);
    }

    public function test_a_quote_that_runs_over_a_line_break_in_the_page_description_is_on_the_page(): void
    {
        $source = $this->source();
        $source->extracted_truth = ['description' => "The kit includes:\n\n30 Today cards,\n2 Next Day cards"];
        $evidence = [[
            'id' => 'claim-1',
            'claim' => 'The kit includes 30 Today cards and 2 Next Day cards',
            'source' => 'https://example.com/product',
            'excerpt' => '30 Today cards, 2 Next Day cards',
            'status' => 'directly_supported',
        ]];

        $this->assertSame([], app(CampaignPackSafetyValidator::class)->issues($this->safeContent(), $evidence, [], $source));
        // Generation accepts the same quote from the same text, so the two checks cannot disagree on it.
        $this->assertStringContainsString('30 today cards, 2 next day cards', CampaignPackEvidence::sourceText($source->extracted_content, $source->extracted_truth));
    }

    public function test_a_php_version_is_not_read_as_a_price_unless_the_page_is_priced_in_pesos(): void
    {
        $evidence = [[
            'id' => 'claim-1',
            'claim' => 'Made, sourced, or packed in India',
            'source' => 'https://example.com/product',
            'excerpt' => 'Made, sourced, or packed in India.',
            'status' => 'directly_supported',
        ]];
        $content = $this->safeContent() + ['overview' => ['summary' => 'Compatible with PHP 7.4 to 8.5.']];
        $source = $this->source();
        $source->extracted_truth = ['description' => 'Made, sourced, or packed in India.', 'price' => '49.00', 'currency' => 'USD'];

        $this->assertSame([], app(CampaignPackSafetyValidator::class)->issues($content, $evidence, [], $source));

        $source->extracted_truth = ['description' => 'Made, sourced, or packed in India.', 'price' => '499.00', 'currency' => 'PHP'];
        $content['overview']['summary'] = 'Yours for PHP 899.';

        $this->assertStringContainsString('states a price the product page does not show (899)', app(CampaignPackSafetyValidator::class)->issues($content, $evidence, [], $source)[0]['message']);
    }

    private function source(): SourceSnapshot
    {
        return new SourceSnapshot([
            'status' => 'ready',
            'url' => 'https://example.com/product',
            'extracted_content' => 'Made, sourced, or packed in India.',
            'extracted_truth' => ['description' => 'Made, sourced, or packed in India.'],
            'approved_at' => now(),
        ]);
    }

    private function safeContent(): array
    {
        $route = [
            'name' => 'Everyday route',
            'total_duration_seconds' => 20,
            'voiceover' => [[
                'start_seconds' => 0,
                'end_seconds' => 5,
                'line' => 'Meet the product for everyday routines.',
                'word_count' => 6,
                'pace_wpm' => 72,
                'delivery_notes' => 'Natural',
                'shot_id' => 'shot-1',
            ]],
            'captions' => [[
                'start_seconds' => 0,
                'end_seconds' => 5,
                'text' => 'Made for everyday routines',
                'shot_id' => 'shot-1',
            ]],
            'platform_assets' => array_fill_keys(['instagram_reels', 'youtube_shorts', 'whatsapp_status', 'meta_ads'], []),
        ];

        return [
            'ranked_angles' => array_fill(0, 5, ['title' => 'Safe angle']),
            'creative_routes' => array_fill(0, 3, $route),
        ];
    }
}
