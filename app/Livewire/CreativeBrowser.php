<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\BannerCreative;
use App\Models\CreativeAsset;
use App\Models\Product;
use App\Services\CreativeLibrary;
use App\Support\Membership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Creatives in one place: files the team uploads per product (images and reels made elsewhere), plus
 * finished launch pack banners shown read-only as "Generated". Shown on a product's Creatives tab
 * (one product) and on the Creatives page (every product in the workspace).
 */
class CreativeBrowser extends Component
{
    use InteractsWithWorkspace;

    public const VIEWS = ['all' => 'All', 'recent' => 'Recently added', 'mine' => 'My uploads', 'untagged' => 'Untagged', 'archived' => 'Archived'];

    public const SORTS = ['newest' => 'Newest', 'oldest' => 'Oldest', 'name' => 'Name'];

    #[Locked]
    public ?int $productId = null;

    #[Url(as: 'view')]
    public string $view = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'kind')]
    public string $kind = '';

    #[Url(as: 'channel')]
    public string $channel = '';

    #[Url(as: 'stage')]
    public string $stage = '';

    #[Url(as: 'aspect')]
    public string $aspect = '';

    #[Url(as: 'tag')]
    public string $tag = '';

    #[Url(as: 'product')]
    public ?int $filterProduct = null;

    #[Url(as: 'sort')]
    public string $sort = 'newest';

    /** Open preview: "u12" for an upload, "g5" for a generated banner. */
    #[Url(as: 'open')]
    public ?string $open = null;

    public int $limit = 60;

    /** @var array<int, int> picked upload ids */
    public array $picked = [];

    public array $edit = ['title' => '', 'channel' => '', 'stage' => '', 'tags' => ''];

    public array $bulk = ['channel' => '', 'stage' => '', 'tags' => ''];

    public ?string $flash = null;

    public function mount(?int $productId = null): void
    {
        $this->productId = $productId;
        if ($productId) {
            abort_unless($this->product($productId), 404);
        }
        if ($this->open) {
            $this->select($this->open);
        }
    }

    /** @return array{id?: int, upload?: array, poster?: ?array, error?: string} */
    #[Renderless]
    public function requestUpload(array $file, CreativeLibrary $library): array
    {
        $this->authorizeCreate();
        $product = $this->product($this->productId ?? (int) ($file['product_id'] ?? 0));
        if (! $product || $product->isArchived()) {
            return ['error' => 'Choose a product for this upload.'];
        }
        try {
            $begun = $library->begin($this->currentWorkspace(), $product, auth()->user(), $file);
        } catch (ValidationException $exception) {
            return ['error' => collect($exception->errors())->flatten()->first()];
        }

        return ['id' => $begun['asset']->id, 'upload' => $begun['upload'], 'poster' => $begun['poster']];
    }

    /** @return array{ok: bool, error?: string} */
    public function finalizeUpload(int $id, CreativeLibrary $library): array
    {
        $this->authorizeCreate();
        $asset = $this->assets()->where('status', 'pending')->where('uploaded_by_user_id', auth()->id())->find($id);
        abort_unless($asset, 404);
        if ($library->finalize($asset)) {
            return ['ok' => true];
        }
        $library->delete($asset);

        return ['ok' => false, 'error' => 'The file did not arrive in full. Try again.'];
    }

    #[Renderless]
    public function cancelUpload(int $id, CreativeLibrary $library): void
    {
        $this->authorizeCreate();
        $asset = $this->assets()->where('status', 'pending')->where('uploaded_by_user_id', auth()->id())->find($id);
        if ($asset) {
            $library->delete($asset);
        }
    }

    public function select(string $key): void
    {
        $item = $this->find($key);
        $this->open = $item ? $key : null;
        if ($item instanceof CreativeAsset) {
            $this->edit = ['title' => $item->title, 'channel' => (string) $item->channel, 'stage' => (string) $item->stage, 'tags' => implode(', ', $item->tags ?? [])];
        }
        $this->resetValidation();
    }

    public function close(): void
    {
        $this->open = null;
        $this->resetValidation();
    }

    /** Moves the preview to the previous (-1) or next (1) creative in grid order. */
    public function step(int $direction): void
    {
        $keys = $this->list()->pluck('key')->all();
        $index = array_search($this->open, $keys, true);
        $next = $index === false ? null : ($keys[$index + ($direction < 0 ? -1 : 1)] ?? null);
        if ($next) {
            $this->select($next);
        }
    }

    public function save(): void
    {
        $this->authorizeCreate();
        $asset = $this->find((string) $this->open);
        abort_unless($asset instanceof CreativeAsset, 404);
        $this->validate([
            'edit.title' => ['required', 'string', 'max:200'],
            'edit.channel' => ['nullable', Rule::in(array_keys(CreativeAsset::CHANNELS))],
            'edit.stage' => ['nullable', Rule::in(array_keys(CreativeAsset::STAGES))],
            'edit.tags' => ['nullable', 'string', 'max:500'],
        ], [], ['edit.title' => 'title']);
        $asset->update([
            'title' => trim($this->edit['title']),
            'channel' => $this->edit['channel'] ?: null,
            'stage' => $this->edit['stage'] ?: null,
            'tags' => self::tags($this->edit['tags']) ?: null,
        ]);
        $this->flash = 'Saved.';
    }

    public function archive(int $id, bool $archived = true): void
    {
        $this->authorizeCreate();
        $this->readyAsset($id)->update(['archived_at' => $archived ? now() : null]);
        $this->open = null;
        $this->flash = $archived ? 'Archived. Find it under Archived.' : 'Restored.';
    }

    public function delete(int $id, CreativeLibrary $library): void
    {
        $this->authorizeCreate();
        $library->delete($this->readyAsset($id));
        $this->open = null;
        $this->picked = array_values(array_diff($this->picked, [$id]));
        $this->flash = 'Deleted.';
    }

    public function bulkApply(): void
    {
        $this->authorizeCreate();
        $this->validate([
            'bulk.channel' => ['nullable', Rule::in(array_keys(CreativeAsset::CHANNELS))],
            'bulk.stage' => ['nullable', Rule::in(array_keys(CreativeAsset::STAGES))],
            'bulk.tags' => ['nullable', 'string', 'max:500'],
        ]);
        $tags = self::tags($this->bulk['tags']);
        foreach ($this->pickedAssets() as $asset) {
            $asset->update(array_filter([
                'channel' => $this->bulk['channel'] ?: null,
                'stage' => $this->bulk['stage'] ?: null,
                'tags' => $tags ? array_values(array_unique([...($asset->tags ?? []), ...$tags])) : null,
            ]));
        }
        $this->bulk = ['channel' => '', 'stage' => '', 'tags' => ''];
        $this->flash = trans_choice(':count creative updated.|:count creatives updated.', count($this->picked));
    }

    public function bulkArchive(): void
    {
        $this->authorizeCreate();
        $archived = $this->view !== 'archived';
        $count = $this->pickedAssets()->each(fn (CreativeAsset $asset) => $asset->update(['archived_at' => $archived ? now() : null]))->count();
        $this->picked = [];
        $this->flash = trans_choice($archived ? ':count creative archived.|:count creatives archived.' : ':count creative restored.|:count creatives restored.', $count);
    }

    public function bulkDelete(CreativeLibrary $library): void
    {
        $this->authorizeCreate();
        $count = $this->pickedAssets()->each(fn (CreativeAsset $asset) => $library->delete($asset))->count();
        $this->picked = [];
        $this->flash = trans_choice(':count creative deleted.|:count creatives deleted.', $count);
    }

    public function loadMore(): void
    {
        $this->limit += 60;
    }

    public function clearFilters(): void
    {
        $this->reset('view', 'search', 'kind', 'channel', 'stage', 'aspect', 'tag', 'filterProduct');
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['picked', 'edit', 'bulk', 'limit', 'open'], true) && ! str_starts_with($property, 'edit.') && ! str_starts_with($property, 'bulk.')) {
            $this->limit = 60;
            $this->picked = [];
        }
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();
        $uploads = $this->filteredUploads();
        $generated = $this->generated();
        $total = (clone $uploads)->reorder()->count() + ($generated ? (clone $generated)->reorder()->count() : 0);
        $bytes = (int) (clone $uploads)->reorder()->sum('size_bytes') + (int) ($generated ? (clone $generated)->reorder()->sum('size_bytes') : 0);
        $list = $this->list($uploads, $generated);
        $selected = $this->open ? $list->firstWhere('key', $this->open) ?? $this->present($this->find($this->open)) : null;
        $position = $selected ? $list->search(fn ($item) => $item['key'] === $selected['key']) : false;
        $canCreate = (bool) $this->membership()?->canCreate();

        return view('livewire.creative-browser', [
            'list' => $list,
            'total' => $total,
            'totalSize' => Number::fileSize($bytes, maxPrecision: 1),
            'more' => $total > $list->count(),
            'used' => CreativeLibrary::usedBytes($workspace),
            'cap' => CreativeLibrary::capBytes(),
            'selected' => $selected,
            'previousKey' => $position !== false ? ($list[$position - 1]['key'] ?? null) : null,
            'nextKey' => $position !== false ? ($list[$position + 1]['key'] ?? null) : null,
            'products' => $this->productId ? collect() : $this->products(),
            'tags' => $this->tagOptions(),
            'canCreate' => $canCreate,
            'filtersOn' => $this->view !== 'all' || trim($this->search) !== '' || $this->kind || $this->channel || $this->stage || $this->aspect || $this->tag || $this->filterProduct,
            'accept' => implode(',', array_keys(config('creatives.types'))),
        ]);
    }

    /** Uploads matching the filters, in grid order. */
    private function filteredUploads(): Builder
    {
        $search = mb_strtolower(trim($this->search));
        $query = $this->assets()->where('status', 'ready')
            ->with(['product:id,name', 'uploadedBy:id,name'])
            ->when($this->view === 'archived', fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when($this->view === 'recent', fn ($query) => $query->where('created_at', '>=', now()->subDays(7)))
            ->when($this->view === 'mine', fn ($query) => $query->where('uploaded_by_user_id', auth()->id()))
            ->when($this->view === 'untagged', fn ($query) => $query->whereNull('tags'))
            ->when($this->filterProduct && ! $this->productId, fn ($query) => $query->where('product_id', $this->filterProduct))
            ->when(in_array($this->kind, ['image', 'video'], true), fn ($query) => $query->where('kind', $this->kind))
            ->when($this->channel, fn ($query) => $query->where('channel', $this->channel))
            ->when($this->stage, fn ($query) => $query->where('stage', $this->stage))
            ->when($this->aspect, fn ($query) => $query->where('aspect_ratio', $this->aspect))
            ->when($this->tag, fn ($query) => $query->whereJsonContains('tags', $this->tag))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match->whereRaw('lower(title) like ?', ["%{$search}%"])->orWhereRaw('lower(original_name) like ?', ["%{$search}%"])));

        return match ($this->sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'name' => $query->orderBy('title')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /** Finished launch pack banners, read-only. They have no channel, stage, tags or shape, so those filters hide them. */
    private function generated(): ?Builder
    {
        if (! in_array($this->view, ['all', 'recent'], true) || $this->channel || $this->stage || $this->aspect || $this->tag || $this->kind === 'video') {
            return null;
        }
        $search = mb_strtolower(trim($this->search));
        $query = $this->generatedQuery()
            ?->when($this->filterProduct && ! $this->productId, fn ($query) => $query->whereHas('campaignPack', fn ($pack) => $pack->where('product_id', $this->filterProduct)))
            ->when($this->view === 'recent', fn ($query) => $query->where('created_at', '>=', now()->subDays(7)))
            ->when($search !== '', fn ($query) => $query->whereRaw('lower(headline) like ?', ["%{$search}%"]));
        if (! $query) {
            return null;
        }

        return match ($this->sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'name' => $query->orderBy('headline')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /** Both kinds merged in grid order, up to the current limit. */
    private function list(?Builder $uploads = null, ?Builder $generated = null): Collection
    {
        $uploads ??= $this->filteredUploads();
        $generated ??= $this->generated();
        $items = $uploads->limit($this->limit)->get()->map(fn ($asset) => $this->present($asset));
        if ($generated) {
            $items = $items->concat($generated->limit($this->limit)->get()->map(fn ($banner) => $this->present($banner)));
        }
        $items = match ($this->sort) {
            'oldest' => $items->sortBy('sort_time'),
            'name' => $items->sortBy(fn ($item) => mb_strtolower($item['title'])),
            default => $items->sortByDesc('sort_time'),
        };

        return $items->take($this->limit)->values();
    }

    private function present(CreativeAsset|BannerCreative|null $item): ?array
    {
        if ($item instanceof BannerCreative) {
            $pack = $item->campaignPack;

            return [
                'key' => 'g'.$item->id, 'id' => $item->id, 'generated' => true, 'kind' => 'image',
                'title' => $item->headline ?: 'Banner '.$item->id, 'format' => 'PNG',
                'meta' => implode(' · ', array_filter(['PNG', $item->size_bytes ? Number::fileSize($item->size_bytes, 1) : null, $item->width ? "{$item->width} x {$item->height}" : null])),
                'size' => $item->size_bytes ? Number::fileSize($item->size_bytes, 1) : null,
                'dimensions' => $item->width ? "{$item->width} x {$item->height}" : null,
                'duration' => null, 'product' => $pack?->product?->name, 'productId' => $pack?->product_id,
                'channel' => null, 'stage' => null, 'tags' => [], 'uploader' => null, 'archived' => false,
                'created' => $item->created_at, 'sort_time' => $item->created_at?->getTimestamp(),
                'thumb' => route('campaign-banners.image', [$item->campaign_pack_id, $item]),
                'file' => route('campaign-banners.image', [$item->campaign_pack_id, $item]),
                'download' => route('campaign-banners.download', [$item->campaign_pack_id, $item]),
                'source' => $pack?->name,
            ];
        }
        if (! $item) {
            return null;
        }

        return [
            'key' => 'u'.$item->id, 'id' => $item->id, 'generated' => false, 'kind' => $item->kind,
            'title' => $item->title, 'format' => $item->format(), 'meta' => $item->metaLine(),
            'size' => Number::fileSize($item->size_bytes, 1),
            'dimensions' => $item->width && $item->height ? "{$item->width} x {$item->height}" : null,
            'duration' => $item->durationLabel(), 'product' => $item->product?->name, 'productId' => $item->product_id,
            'channel' => CreativeAsset::CHANNELS[$item->channel] ?? null, 'stage' => CreativeAsset::STAGES[$item->stage] ?? null,
            'tags' => $item->tags ?? [], 'uploader' => $item->uploadedBy?->name, 'archived' => $item->archived_at !== null,
            'created' => $item->created_at, 'sort_time' => $item->created_at?->getTimestamp(),
            'thumb' => $item->poster_path ? route('creatives.file', [$item, 'poster']) : ($item->kind === 'image' ? route('creatives.file', [$item, 'file']) : null),
            'file' => route('creatives.file', [$item, 'file']),
            'download' => route('creatives.file', [$item, 'download']),
            'source' => $item->original_name,
        ];
    }

    private function find(string $key): CreativeAsset|BannerCreative|null
    {
        $id = (int) substr($key, 1);

        return match ($key[0] ?? '') {
            'u' => $this->assets()->where('status', 'ready')->with(['product:id,name', 'uploadedBy:id,name'])->find($id),
            'g' => $this->generatedQuery()?->find($id),
            default => null,
        };
    }

    private function generatedQuery(): ?Builder
    {
        $query = BannerCreative::query()->where('status', 'completed')->whereNotNull('output_path')
            ->whereHas('campaignPack.product.brand', fn ($brand) => $brand->where('workspace_id', $this->currentWorkspace()->id))
            ->when($this->productId, fn ($query) => $query->whereHas('campaignPack', fn ($pack) => $pack->where('product_id', $this->productId)))
            ->with('campaignPack.product:id,name');

        return $this->membership()?->scopeThroughProduct($query, 'campaignPack.product');
    }

    /** Uploads this person may see: the workspace, the product when on a product tab, and their brand scope. */
    private function assets(): Builder
    {
        $query = CreativeAsset::query()->where('workspace_id', $this->currentWorkspace()->id)
            ->when($this->productId, fn ($query) => $query->where('product_id', $this->productId));

        return $this->membership()?->scopeThroughProduct($query) ?? $query->whereRaw('1 = 0');
    }

    private function readyAsset(int $id): CreativeAsset
    {
        $asset = $this->assets()->where('status', 'ready')->find($id);
        abort_unless($asset, 404);

        return $asset;
    }

    private function pickedAssets(): Collection
    {
        return $this->assets()->where('status', 'ready')->whereIn('id', array_map('intval', $this->picked))->get();
    }

    private function product(int $id): ?Product
    {
        $query = Product::query()->whereHas('brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id));

        return $this->membership()?->scopeBrands($query)->find($id);
    }

    private function products(): Collection
    {
        $query = Product::query()->whereHas('brand', fn ($query) => $query->where('workspace_id', $this->currentWorkspace()->id));
        $query = $this->membership()?->scopeBrands($query) ?? $query->whereRaw('1 = 0');

        return $query->when(Product::archiveAvailable(), fn ($query) => $query->whereNull('archived_at'))->orderBy('name')->get(['id', 'name']);
    }

    private function tagOptions(): array
    {
        return $this->assets()->where('status', 'ready')->whereNotNull('tags')->pluck('tags')->flatten()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** "summer, UGC ,summer" => ["summer", "UGC"] */
    private static function tags(string $value): array
    {
        return collect(explode(',', $value))->map(fn ($tag) => Str::limit(trim($tag), 40, ''))->filter()->unique(fn ($tag) => mb_strtolower($tag))->take(20)->values()->all();
    }

    private function membership(): ?Membership
    {
        return Membership::of(auth()->user(), $this->currentWorkspace());
    }

    private function authorizeCreate(): void
    {
        abort_unless($this->membership()?->canCreate(), 403);
    }
}
