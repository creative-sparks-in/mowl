<?php

namespace App\Services\Pages\Writing;

/** The configured page writer: OpenAI (or Azure OpenAI) or Gemini on Vertex AI in production, the mock writer elsewhere. */
class PageWriters
{
    public function current(): PageWriter
    {
        return match (config('pages.writer')) {
            'openai' => app(OpenAIPageWriter::class),
            'google' => app(GooglePageWriter::class),
            default => app(MockPageWriter::class),
        };
    }
}
