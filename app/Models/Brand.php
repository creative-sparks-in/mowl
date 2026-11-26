<?php

namespace App\Models;

use App\Enums\OfferingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

class Brand extends Model
{
    protected $fillable = [
        'workspace_id', 'name', 'website', 'status', 'banner_logo_disk', 'banner_logo_path',
        'banner_logo_mime_type', 'primary_color', 'voice', 'default_audience', 'palette', 'offering_type',
    ];

    private static ?bool $profileAvailable = null;

    private static ?bool $offeringTypeAvailable = null;

    protected function casts(): array
    {
        return ['palette' => 'array'];
    }

    /** Production runs migrations as a separate step; voice, audience and palette wait for their columns. */
    public static function profileAvailable(): bool
    {
        return self::$profileAvailable ??= Schema::hasColumn('brands', 'voice');
    }

    /** As above: the type columns on brands and products arrive together, in one migration. */
    public static function offeringTypeAvailable(): bool
    {
        return self::$offeringTypeAvailable ??= Schema::hasColumn('brands', 'offering_type');
    }

    /** What the brand sells. Its products are this type unless one says otherwise. */
    public function offeringType(): OfferingType
    {
        return OfferingType::of($this->offering_type ?? null);
    }

    /**
     * The brand palette, primary colour first. Brands saved before palettes existed fall back to their primary colour.
     *
     * @return list<string>
     */
    public function colors(): array
    {
        $palette = array_values(array_filter((array) ($this->palette ?? []), fn ($color) => is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color)));

        return $palette ?: array_values(array_filter([$this->primary_color]));
    }

    /** Voice and default audience every product of the brand inherits, without empty values. */
    public function profile(): array
    {
        return array_filter([
            'voice' => filled($this->voice ?? null) ? trim($this->voice) : null,
            'default_audience' => filled($this->default_audience ?? null) ? trim($this->default_audience) : null,
            'palette' => $this->colors() ?: null,
        ]);
    }

    /** Copies an uploaded logo onto the banners disk, points the brand at it and removes the logo it replaced. */
    public function storeBannerLogo(TemporaryUploadedFile $logo): void
    {
        $disk = config('campaigns.banners.disk');
        $path = "brand-assets/{$this->workspace_id}/{$this->id}/banner-logo.".strtolower($logo->extension() ?: 'png');
        $mimeType = $logo->getMimeType();
        $previous = [$this->banner_logo_disk, $this->banner_logo_path];
        // Streamed, not storeAs(): when both disks match, storeAs() moves the upload and later reads of it fail.
        throw_unless(Storage::disk($disk)->writeStream($path, $logo->readStream()), RuntimeException::class, "The logo could not be written to the {$disk} disk.");

        try {
            $this->update(['banner_logo_disk' => $disk, 'banner_logo_path' => $path, 'banner_logo_mime_type' => $mimeType]);
        } catch (Throwable $exception) {
            if ($previous !== [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }

        if ($previous[1] && $previous !== [$disk, $path]) {
            Storage::disk($previous[0])->delete($previous[1]);
        }
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
