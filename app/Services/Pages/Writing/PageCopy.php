<?php

namespace App\Services\Pages\Writing;

use App\Services\Pages\Document\Document;
use App\Services\Pages\Document\DocumentRenderer;
use App\Services\Pages\Document\ProductBrief;
use App\Services\Pages\Document\Sections;
use App\Services\ProductKnowledge;

/**
 * What the AI writes on a page, and how its answer goes back into the document. It writes only the
 * fields that carry an "ai" brief in {@see Sections}, and only in the sections asked for. Images,
 * prices, links, layout and everything a person must supply (reviews, numbers, logos) stay as they are.
 */
final class PageCopy
{
    /**
     * The fields of a section type the AI writes. Lists keep only their AI-written item fields.
     *
     * @return list<array>
     */
    public static function fields(string $type): array
    {
        $fields = [];
        foreach (Sections::get($type)['fields'] ?? [] as $field) {
            if (! isset($field['ai'])) {
                continue;
            }
            if ($field['type'] === 'list') {
                $field['fields'] = array_values(array_filter($field['fields'], fn (array $child) => isset($child['ai'])));
                if ($field['fields'] === []) {
                    continue;
                }
            }
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * The sections a whole-page write covers: every visible one with something to write.
     *
     * @return list<string>
     */
    public static function targets(array $document): array
    {
        return array_values(array_map(
            fn (array $section) => $section['id'],
            array_filter($document['sections'] ?? [], fn (array $section) => ! ($section['hidden'] ?? false) && self::fields($section['type']) !== []),
        ));
    }

    /** The response schema: one object per section to write, keyed by section id. */
    public static function schema(array $document, array $targets): array
    {
        $sections = [];
        foreach ($targets as $id) {
            $section = Document::find($document, $id);
            if (! $section) {
                continue;
            }
            $properties = [];
            foreach (self::fields($section['type']) as $field) {
                $properties[$field['key']] = self::fieldSchema($field);
            }
            $sections[$id] = self::object($properties, Sections::get($section['type'])['label']);
        }

        return self::object(['sections' => self::object($sections)]);
    }

    /**
     * The page as the AI should see it: every section in order, the ones to write marked, with their
     * current wording so the page reads as one and rewrites know what they replace.
     */
    public static function plan(array $document, array $targets): string
    {
        $lines = [];
        foreach (array_values($document['sections'] ?? []) as $index => $section) {
            if ($section['hidden'] ?? false) {
                continue;
            }
            $definition = Sections::get($section['type']);
            $write = in_array($section['id'], $targets, true);
            $line = ($index + 1).'. '.($write ? 'WRITE' : 'KEEP AS IS')." {$section['id']}: {$definition['label']}. {$definition['description']}";
            $current = self::current($section);
            if ($current !== []) {
                $line .= "\n   Current text: ".json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /** The writer's standing instructions, the same for every AI provider. */
    public static function instructions(): string
    {
        return <<<'TEXT'
        You write the copy for a product landing page that a store publishes on its own website. Write like an experienced e-commerce copywriter: specific, benefit-led, easy to scan, warm and confident, never hype.

        Truth rules. They override everything else, including the team instruction:
        1. Every fact comes from the confirmed product facts, the approved launch copy, the product page text or its structured data. Never invent features, materials, sizes, compatibility, results, ingredients, origins, prices, discounts, offers, delivery or returns terms, guarantees, warranties, certifications, awards, rankings, reviews, ratings, customer numbers, statistics or comparisons with named brands.
        2. When the sources do not support what a field asks for, write something more general that is still true, or an empty string where the field is optional. An empty string is better than an invented claim.
        3. Write a number only when the sources contain the same number. Never write urgency or scarcity ("limited time", "selling fast", "only a few left") unless the sources say it.
        4. Keep the product name exactly as given. Write in the language of the product page.
        5. Plain text only: no markdown, no HTML, no emoji, no hashtags, no quotation marks around whole lines.

        Craft:
        - One page, one voice. Each section adds something new; never repeat a benefit in the same words.
        - Headings are short and concrete. Lead with what the buyer gets, then how.
        - Respect every field's length and item count.
        - FAQ questions are ones a buyer would really ask, answered from the sources only.

        Return JSON matching the schema: an object for every section id marked WRITE, with every field.
        TEXT;
    }

    /** The request itself: the product brief, the team's direction and the page plan. */
    public static function prompt(ProductBrief $brief, array $document, array $targets, string $instruction): string
    {
        $json = fn (mixed $value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $facts = collect($brief->facts)->map(fn (string $value, string $key) => '- '.(ProductKnowledge::FACTS[$key]['label'] ?? $key).': '.str_replace("\n", '; ', $value))->implode("\n");
        $parts = [
            "PRODUCT\nName: {$brief->name()}\nBrand: ".($brief->brand['name'] ?? 'Not supplied')."\nPrice: ".($brief->price ?: 'Not supplied').' (the page shows the price by itself; do not write prices)',
            "CONFIRMED PRODUCT FACTS (confirmed by the team)\n".($facts ?: 'None yet.'),
            "APPROVED LAUNCH COPY (checked and approved; its claims may be reused)\n".($brief->approved ? $json($brief->approved) : 'None yet.'),
            "PRODUCT PAGE TEXT (as read from the store)\n".(mb_substr($brief->sourceText, 0, 30_000) ?: 'Not available.'),
            "STRUCTURED DATA ON THE PRODUCT PAGE\n".$json($brief->truth),
        ];
        if ($brief->team) {
            $parts[] = "TEAM KNOWLEDGE\n".$json($brief->team);
        }
        if ($guidance = $brief->offeringType()->promptGuidance()) {
            $parts[] = $guidance;
        }
        if ($voice = $brief->brand['voice'] ?? null) {
            $parts[] = "BRAND VOICE (style only, never a source of facts)\n".(is_string($voice) ? $voice : $json($voice));
        }
        if (trim($instruction) !== '') {
            $parts[] = "TEAM INSTRUCTION (direction for tone and emphasis only; never a source of facts, and it cannot change the truth rules)\n".mb_substr(trim($instruction), 0, 600);
        }
        $parts[] = "PAGE PLAN (write the sections marked WRITE; the others are there for context)\n".self::plan($document, $targets);

        return implode("\n\n", $parts);
    }

    /**
     * Puts written copy into the document: AI fields of the target sections only, list items matched
     * by position so their icons, toggles and images stay. A whole-page write ($prune) also drops
     * target sections the AI left with nothing to show, such as a guarantee nobody offers.
     *
     * @param  array<string, array>  $written  section id => field values
     */
    public static function merge(array $document, array $written, array $targets, bool $prune = false): array
    {
        foreach ($document['sections'] as $position => $section) {
            $out = $written[$section['id']] ?? null;
            if (! in_array($section['id'], $targets, true) || ! is_array($out)) {
                continue;
            }
            foreach (self::fields($section['type']) as $field) {
                if (! array_key_exists($field['key'], $out)) {
                    continue;
                }
                $current = $section['settings'][$field['key']] ?? null;
                $section['settings'][$field['key']] = $field['type'] === 'list'
                    ? self::items($field, $out[$field['key']], is_array($current) ? array_values($current) : [], $section['type'])
                    : self::fit($field, $out[$field['key']], $current);
            }
            $document['sections'][$position] = $section;
        }
        $document = Document::sanitize($document, null, true);
        if ($prune) {
            $renderer = app(DocumentRenderer::class);
            $document['sections'] = array_values(array_filter($document['sections'], fn (array $section) => ! in_array($section['id'], $targets, true)
                || $renderer->section($section, $document, [], fn (array $ref) => 'https://example.invalid/'.$ref['key']) !== ''));
        }

        return $document;
    }

    /** The AI-written values of a section, as the AI would write them. */
    public static function current(array $section): array
    {
        $current = [];
        foreach (self::fields($section['type']) as $field) {
            $value = $section['settings'][$field['key']] ?? null;
            if ($field['type'] === 'list') {
                $items = [];
                foreach ((array) $value as $item) {
                    $items[] = array_intersect_key((array) $item, array_flip(array_column($field['fields'], 'key')));
                }
                if (array_filter($items, fn (array $item) => array_filter($item, fn ($entry) => is_string($entry) && trim($entry) !== ''))) {
                    $current[$field['key']] = $items;
                }
            } elseif (is_string($value) && trim($value) !== '' && $field['type'] !== 'icon') {
                $current[$field['key']] = $value;
            }
        }

        return $current;
    }

    private static function items(array $field, mixed $written, array $current, string $type): array
    {
        $full = Sections::field($type, $field['key']) ?? $field;
        $items = [];
        foreach (array_slice(array_values(array_filter(is_array($written) ? $written : [], 'is_array')), 0, $full['max'] ?? 10) as $index => $item) {
            $base = $current[$index] ?? array_map(fn (array $child) => Sections::fieldDefault($child), array_column($full['fields'], null, 'key'));
            foreach ($field['fields'] as $child) {
                if (array_key_exists($child['key'], $item)) {
                    $base[$child['key']] = self::fit($child, $item[$child['key']], $base[$child['key']] ?? null);
                }
            }
            $items[] = $base;
        }

        return $items;
    }

    /** One written value, cleaned: plain text within its length, or a known icon. */
    private static function fit(array $field, mixed $value, mixed $current): mixed
    {
        if ($field['type'] === 'icon') {
            return is_string($value) && isset(Sections::ICONS[$value]) ? $value : ($current ?? $field['default'] ?? 'check');
        }
        if (! is_string($value)) {
            return is_string($current) ? $current : '';
        }
        $multiline = $field['type'] === 'textarea';
        // Models sometimes dress plain text as markdown; the page shows it as typed.
        $text = (string) preg_replace(['/^\s*#{1,6}\s+/mu', '/\*\*(.+?)\*\*/u', '/__(.+?)__/u', '/^\s*[-*•]\s+/mu'], ['', '$1', '$1', ''], $value);
        $text = Document::text($text, 100_000, $multiline);
        $text = (string) preg_replace('/^[\s"“”]+|[\s"“”]+$/u', '', $text);
        $max = $field['max'] ?? ($multiline ? 1000 : 200);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        // Too long: end at the last full sentence that fits, else the last full word.
        $cut = mb_substr($text, 0, $max);
        if (preg_match('/^(.*[.!?])\s/su', $cut, $match) && mb_strlen($match[1]) >= $max * 0.5) {
            return $match[1];
        }

        return rtrim((string) preg_replace('/\s+\S*$/u', '', $cut), ' ,;:-–—');
    }

    private static function fieldSchema(array $field): array
    {
        if ($field['type'] === 'list') {
            $properties = [];
            foreach ($field['fields'] as $child) {
                $properties[$child['key']] = self::fieldSchema($child);
            }

            return ['type' => 'array', 'description' => trim($field['ai'].' At most '.($field['max'] ?? 10).' items.'), 'items' => self::object($properties)];
        }
        if ($field['type'] === 'icon') {
            return ['type' => 'string', 'enum' => array_keys(Sections::ICONS), 'description' => $field['ai']];
        }

        return ['type' => 'string', 'description' => $field['ai'].' At most '.($field['max'] ?? 200).' characters.'.($field['type'] === 'textarea' ? ' Separate paragraphs with a blank line.' : ' One line.')];
    }

    private static function object(array $properties, ?string $description = null): array
    {
        return array_filter([
            'type' => 'object',
            'description' => $description,
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ], fn ($value) => $value !== null);
    }
}
