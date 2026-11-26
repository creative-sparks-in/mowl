<?php

namespace App\Contracts;

use App\Exceptions\AiProviderException;

/** The model calls banner making needs, on whichever provider BANNER_GENERATOR names. */
interface BannerModels
{
    /** "google" or "openai": recorded as the banner's provider. */
    public function provider(): string;

    /** The model that reads images; ProviderCostCalculator::calculate() prices its tokens. */
    public function visionModel(): string;

    /**
     * Draws one image from a prompt and, when given, reference photos (the real product).
     * $banner asks for the banner's own shape; otherwise the picture keeps the reference's shape as closely as the model allows.
     *
     * Output the provider did not split into text and image is in untagged_output_tokens, and thinking in
     * thinking_tokens, so each caller prices them as it always has.
     *
     * @param  list<array{mimeType: string, data: string}>  $references  base64 data
     * @return array{bytes: ?string, mime_type: string, model: string, input_tokens: int, output_text_tokens: int, output_image_tokens: int, untagged_output_tokens: int, thinking_tokens: int, request_id: ?string, latency_ms: int}
     */
    public function draw(string $prompt, array $references = [], bool $banner = false, ?int $timeoutSeconds = null): array;

    /**
     * Answers a question about images as JSON that follows $schema (plain JSON Schema: lowercase types, every property required, additionalProperties false).
     * An answer cut off at the output cap is cut_short; a missing or refused one is null.
     *
     * @param  list<array{mimeType: string, data: string}>  $images  base64 data
     * @return array{answer: mixed, cut_short: bool, input_tokens: int, output_tokens: int}
     */
    public function look(string $prompt, array $images, array $schema): array;

    /** A failure in this provider's own error codes, e.g. failure('missing_image', 'returned no generated banner image.') gives vertex_missing_image / "Vertex AI returned ..." or openai_missing_image / "OpenAI returned ...". */
    public function failure(string $code, string $message, bool $retryable = true): AiProviderException;
}
