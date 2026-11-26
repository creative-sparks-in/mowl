<?php

namespace App\Services;

use App\Models\CreativeAsset;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creatives a team uploads per product. Browsers send files straight to storage with a signed link
 * (Vercel caps request and response bodies at 4.5 MB), so PHP only checks the request, signs links
 * and confirms the stored object afterwards. Viewing and downloading redirect to signed links too.
 */
class CreativeLibrary
{
    /** Production runs migrations as a separate step; the library stays hidden until its table exists. */
    public static function available(): bool
    {
        $container = app();
        if (! $container->bound('creative-library.available')) {
            $container->instance('creative-library.available', Schema::hasTable('creative_assets'));
        }

        return $container->make('creative-library.available');
    }

    public static function capBytes(): int
    {
        return config('creatives.workspace_cap_mb') * 1024 * 1024;
    }

    /** Stored bytes, counting uploads still in flight from the last day so parallel uploads cannot pass the cap. */
    public static function usedBytes(Workspace $workspace): int
    {
        return (int) CreativeAsset::query()->where('workspace_id', $workspace->id)
            ->where(fn ($query) => $query->where('status', 'ready')
                ->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('created_at', '>', now()->subDay())))
            ->sum('size_bytes');
    }

    /**
     * Checks an upload request, records a pending creative and signs where the browser sends the file
     * (and its poster frame, when it captured one).
     *
     * @return array{asset: CreativeAsset, upload: array, poster: ?array}
     */
    public function begin(Workspace $workspace, Product $product, User $user, array $file): array
    {
        $types = config('creatives.types');
        $data = Validator::make($file, [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(array_keys($types))],
            'size' => ['required', 'integer', 'min:1'],
            'width' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'height' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'poster' => ['boolean'],
        ], ['type.in' => 'Upload JPG, PNG, WebP or GIF images, or MP4, MOV or WebM videos.'])->validate();
        $data['name'] = basename(str_replace('\\', '/', $data['name']));

        [$kind, $extension] = $types[$data['type']];
        $max = config("creatives.max_{$kind}_mb") * 1024 * 1024;
        if ($data['size'] > $max) {
            throw ValidationException::withMessages(['size' => ucfirst($kind).'s can be up to '.Number::fileSize($max).'.']);
        }
        if (self::usedBytes($workspace) + $data['size'] > self::capBytes()) {
            throw ValidationException::withMessages(['size' => 'This upload would pass your workspace\'s '.Number::fileSize(self::capBytes()).' creative storage. Delete creatives you no longer need to make room.']);
        }

        $folder = "creatives/{$workspace->id}/{$product->id}/".Str::uuid();
        $width = $data['width'] ?? null;
        $height = $data['height'] ?? null;
        $asset = CreativeAsset::create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'uploaded_by_user_id' => $user->id,
            'kind' => $kind,
            'disk' => config('creatives.disk'),
            'path' => "{$folder}/original.{$extension}",
            'poster_path' => ($data['poster'] ?? false) ? "{$folder}/poster.jpg" : null,
            'original_name' => $data['name'],
            'title' => Str::limit(pathinfo($data['name'], PATHINFO_FILENAME) ?: $data['name'], 200, ''),
            'mime_type' => $data['type'],
            'size_bytes' => $data['size'],
            'width' => $width,
            'height' => $height,
            'duration_ms' => $kind === 'video' ? ($data['duration_ms'] ?? null) : null,
            'aspect_ratio' => CreativeAsset::aspectFor($width, $height),
            'status' => 'pending',
        ]);

        return [
            'asset' => $asset,
            'upload' => $this->uploadTarget($asset->disk, $asset->path),
            'poster' => $asset->poster_path ? $this->uploadTarget($asset->disk, $asset->poster_path) : null,
        ];
    }

    /** Marks an upload ready once storage holds the whole file. A poster that never arrived is dropped. */
    public function finalize(CreativeAsset $asset): bool
    {
        if ($asset->status !== 'pending') {
            return $asset->status === 'ready';
        }
        $disk = Storage::disk($asset->disk);
        if (! $disk->exists($asset->path) || $disk->size($asset->path) !== $asset->size_bytes) {
            return false;
        }
        if ($asset->poster_path && ! $disk->exists($asset->poster_path)) {
            $asset->poster_path = null;
        }
        $asset->status = 'ready';
        $asset->save();

        return true;
    }

    /** Removes the stored files and the record. */
    public function delete(CreativeAsset $asset): void
    {
        Storage::disk($asset->disk)->delete(array_filter([$asset->path, $asset->poster_path]));
        $asset->delete();
    }

    /**
     * A file, poster or download for the browser: a short-lived signed redirect on cloud storage,
     * streamed from the local disk in development.
     */
    public function deliver(CreativeAsset $asset, string $variant): Response
    {
        abort_unless($asset->status === 'ready', 404);
        $path = $variant === 'poster' ? $asset->poster_path : $asset->path;
        abort_unless($path, 404);
        $disk = Storage::disk($asset->disk);
        $type = $variant === 'poster' ? 'image/jpeg' : $asset->mime_type;
        $name = $variant === 'poster' ? pathinfo($asset->original_name, PATHINFO_FILENAME).'.jpg' : $asset->original_name;
        $disposition = HeaderUtils::makeDisposition($variant === 'download' ? 'attachment' : 'inline', $name, str_replace(['%', '"'], '', Str::ascii($name)) ?: 'creative');

        if ($disk instanceof LocalFilesystemAdapter) {
            abort_unless($disk->exists($path), 404);

            return $disk->response($path, null, [
                'Content-Type' => $type,
                'Content-Disposition' => $disposition,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $url = $disk->temporaryUrl($path, now()->addMinutes(config('creatives.view_url_minutes')), [
            'httpHeaders' => ['contentDisposition' => $disposition, 'contentType' => $type],
        ]);

        // The browser may reuse the redirect while the signed link still works.
        return redirect()->away($url)->header('Cache-Control', 'private, max-age=300');
    }

    private function uploadTarget(string $disk, string $path): array
    {
        $target = Storage::disk($disk)->temporaryUploadUrl($path, now()->addMinutes(config('creatives.upload_url_minutes')));

        return ['url' => $target['url'], 'headers' => (object) ($target['headers'] ?? []), 'blocks' => (bool) ($target['blocks'] ?? false)];
    }
}
