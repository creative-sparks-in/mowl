<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

class CreativeAsset extends Model
{
    public const CHANNELS = [
        'instagram' => 'Instagram', 'facebook' => 'Facebook', 'meta_ads' => 'Meta Ads', 'google_ads' => 'Google Ads',
        'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'website' => 'Website', 'print' => 'Print', 'other' => 'Other',
    ];

    public const STAGES = [
        'prospecting' => 'Prospecting', 'retargeting' => 'Retargeting', 're_engagement' => 'Re-engagement', 'email' => 'Email',
        'testimonial' => 'Testimonial', 'thumbnail' => 'Thumbnail', 'raw' => 'Raw', 'other' => 'Other',
    ];

    /** Common ad shapes, width over height. Anything else is "other". */
    public const ASPECTS = ['9:16' => 9 / 16, '4:5' => 4 / 5, '1:1' => 1.0, '16:9' => 16 / 9];

    protected $fillable = [
        'workspace_id', 'product_id', 'uploaded_by_user_id', 'kind', 'disk', 'path', 'poster_path', 'original_name', 'title',
        'mime_type', 'size_bytes', 'width', 'height', 'duration_ms', 'aspect_ratio', 'channel', 'stage', 'tags', 'status', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['tags' => 'array', 'size_bytes' => 'integer', 'archived_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public static function aspectFor(?int $width, ?int $height): ?string
    {
        if (! $width || ! $height) {
            return null;
        }
        foreach (self::ASPECTS as $label => $ratio) {
            if (abs($width / $height - $ratio) / $ratio < 0.02) {
                return $label;
            }
        }

        return 'other';
    }

    /** "MOV", "PNG": the format people know the file by. */
    public function format(): string
    {
        return strtoupper(config("creatives.types.{$this->mime_type}.1") ?? pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    /** "MOV · 179 MB · 1080 x 1920" */
    public function metaLine(): string
    {
        return implode(' · ', array_filter([
            $this->format(),
            Number::fileSize($this->size_bytes, 1),
            $this->width && $this->height ? "{$this->width} x {$this->height}" : null,
        ]));
    }

    /** "0:32", "1:04:05" */
    public function durationLabel(): ?string
    {
        if (! $this->duration_ms) {
            return null;
        }
        $seconds = (int) round($this->duration_ms / 1000);

        return $seconds >= 3600 ? gmdate('G:i:s', $seconds) : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
