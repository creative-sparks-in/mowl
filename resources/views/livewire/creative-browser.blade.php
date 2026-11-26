@php
    $views = \App\Livewire\CreativeBrowser::VIEWS;
    $channels = \App\Models\CreativeAsset::CHANNELS;
    $stages = \App\Models\CreativeAsset::STAGES;
    $usedPercent = min(100, $used / max(1, $cap) * 100);
@endphp
<div class="creatives col">
    <div class="row cr-head">
        <strong class="num" aria-live="polite">{{ $total }} {{ \Illuminate\Support\Str::plural('asset', $total) }} · {{ $totalSize }}</strong>
        <span class="sp"></span>
        <div class="col cr-usage">
            <span class="xs muted num">{{ \Illuminate\Support\Number::fileSize($used, maxPrecision: 1) }} of {{ \Illuminate\Support\Number::fileSize($cap) }} storage used</span>
            <div @class(['meter', 'is-full' => $usedPercent >= 90]) role="meter" aria-label="Creative storage used" aria-valuemin="0" aria-valuemax="{{ $cap }}" aria-valuenow="{{ $used }}"><i style="width: {{ $usedPercent }}%"></i></div>
        </div>
    </div>

    @if($canCreate)
        <section class="cr-drop" wire:ignore aria-label="Upload creatives"
                 x-data="creativeUploader($wire, {{ $productId ? 'null' : 'true' }})"
                 :class="{ 'is-over': over }"
                 @dragover.prevent="over = true" @dragleave.self="over = false" @drop.prevent="over = false; add($event.dataTransfer.files)">
            <div class="row cr-drop-row">
                <i class="ti ti-cloud-upload cr-drop-icon" aria-hidden="true"></i>
                <div class="grow col cr-drop-text">
                    <strong class="medium">Drop images and reels here</strong>
                    <span class="muted xs">JPG, PNG, WebP or GIF up to {{ config('creatives.max_image_mb') }} MB · MP4, MOV or WebM up to {{ \Illuminate\Support\Number::fileSize(config('creatives.max_video_mb') * 1024 * 1024) }}</span>
                </div>
                @unless($productId)
                    <select class="input cr-upload-product" x-model="productId" aria-label="Product for new uploads">
                        <option value="">Choose a product</option>
                        @foreach($products as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                    </select>
                @endunless
                <label class="pill pri sm cr-pick">
                    <input type="file" class="cr-file" multiple accept="{{ $accept }},.mov" @change="add($event.target.files); $event.target.value = ''">
                    <i class="ti ti-upload" aria-hidden="true"></i>Choose files
                </label>
            </div>
            <p class="err" x-show="notice" x-text="notice" role="alert" x-cloak></p>
            <ul class="cr-uploads" x-show="uploads.length" x-cloak>
                <template x-for="u in uploads" :key="u.key">
                    <li class="cr-upload" :class="'is-' + u.state">
                        <div class="row">
                            <span class="grow small cr-upload-name" x-text="u.file.name"></span>
                            <span class="xs muted num" x-text="label(u)"></span>
                            <button type="button" class="linkbtn" x-show="u.state === 'failed' || u.state === 'cancelled'" @click="retry(u)">Retry</button>
                            <button type="button" class="linkbtn" x-show="['queued', 'preparing', 'uploading'].includes(u.state)" @click="cancel(u)">Cancel</button>
                            <button type="button" class="viewer-btn cr-dismiss" x-show="['done', 'failed', 'cancelled'].includes(u.state)" @click="dismiss(u)" :aria-label="'Dismiss ' + u.file.name"><i class="ti ti-x" aria-hidden="true"></i></button>
                        </div>
                        <div class="meter" role="progressbar" :aria-label="'Upload progress for ' + u.file.name" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round(u.progress * 100)"><i :style="'width:' + (u.progress * 100) + '%'"></i></div>
                        <span class="err xs" x-show="u.error" x-text="u.error"></span>
                    </li>
                </template>
            </ul>
        </section>
    @endif

    <div class="chips" role="group" aria-label="Quick views">
        @foreach($views as $key => $label)
            <button type="button" class="chip" aria-pressed="{{ $view === $key ? 'true' : 'false' }}" wire:click="$set('view', '{{ $key }}')">{{ $label }}</button>
        @endforeach
    </div>

    <div class="row cr-filters">
        <input type="search" class="input cr-search" wire:model.live.debounce.300ms="search" placeholder="Search titles and file names" aria-label="Search creatives">
        @unless($productId)
            <select class="input" wire:model.live="filterProduct" aria-label="Product">
                <option value="">All products</option>
                @foreach($products as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </select>
        @endunless
        <select class="input" wire:model.live="kind" aria-label="Type">
            <option value="">Images and videos</option><option value="image">Images</option><option value="video">Videos</option>
        </select>
        <select class="input" wire:model.live="channel" aria-label="Channel">
            <option value="">Any channel</option>
            @foreach($channels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select class="input" wire:model.live="stage" aria-label="Stage">
            <option value="">Any stage</option>
            @foreach($stages as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select class="input" wire:model.live="aspect" aria-label="Shape">
            <option value="">Any shape</option>
            @foreach(array_keys(\App\Models\CreativeAsset::ASPECTS) as $ratio)<option value="{{ $ratio }}">{{ $ratio }}</option>@endforeach
            <option value="other">Other</option>
        </select>
        @if($tags)
            <select class="input" wire:model.live="tag" aria-label="Tag">
                <option value="">Any tag</option>
                @foreach($tags as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
            </select>
        @endif
        <select class="input" wire:model.live="sort" aria-label="Sort">
            @foreach(\App\Livewire\CreativeBrowser::SORTS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        @if($filtersOn)<button type="button" class="linkbtn" wire:click="clearFilters">Clear filters</button>@endif
    </div>

    @if($flash && ! $selected)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif

    @if($canCreate && $picked)
        @php($pickedDownloads = $list->where('generated', false)->whereIn('id', array_map('intval', $picked))->pluck('download')->values())
        <div class="banner neu cr-bulk" role="region" aria-label="Selected creatives">
            <span class="num medium">{{ count($picked) }} selected</span>
            <select class="input" wire:model="bulk.channel" aria-label="Set channel"><option value="">Channel…</option>@foreach($channels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            <select class="input" wire:model="bulk.stage" aria-label="Set stage"><option value="">Stage…</option>@foreach($stages as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            <input class="input cr-bulk-tags" wire:model="bulk.tags" placeholder="Add tags, comma separated" aria-label="Add tags">
            <button type="button" class="pill ghost sm" wire:click="bulkApply">Apply</button>
            <button type="button" class="pill ghost sm" x-data @click="downloadCreatives(@js($pickedDownloads))"><i class="ti ti-download" aria-hidden="true"></i>Download</button>
            <button type="button" class="pill ghost sm" wire:click="bulkArchive">{{ $view === 'archived' ? 'Restore' : 'Archive' }}</button>
            <button type="button" class="pill ghost sm cr-danger" wire:click="bulkDelete" wire:confirm="Delete {{ count($picked) }} {{ \Illuminate\Support\Str::plural('creative', count($picked)) }}? The files are removed from storage and cannot be recovered.">Delete</button>
            <button type="button" class="linkbtn" wire:click="$set('picked', [])">Clear</button>
        </div>
    @endif

    @if($list->isEmpty())
        @if($filtersOn)
            <div class="empty"><strong>Nothing matches these filters</strong><button type="button" class="pill ghost sm" wire:click="clearFilters">Clear filters</button></div>
        @else
            <div class="empty">
                <strong>No creatives yet</strong>
                <span class="small muted">Upload the images and reels your team makes for {{ $productId ? 'this product' : 'each product' }}. Banners from approved launch packs appear here too.</span>
            </div>
        @endif
    @else
        <div class="cr-grid">
            @foreach($list as $item)
                <div class="cr-card-wrap" wire:key="creative-{{ $item['key'] }}">
                    <button type="button" class="cr-card" aria-haspopup="dialog" wire:click="select('{{ $item['key'] }}')">
                        <span class="cr-thumb" @if($item['kind'] === 'video') x-data="{ playing: false }" :class="{ 'is-playing': playing }" @mouseenter="const v = $el.querySelector('video'); if (!v.src) v.src = v.dataset.src; v.play().then(() => playing = true).catch(() => {})" @mouseleave="$el.querySelector('video').pause(); playing = false" @endif>
                            @if($item['thumb'])
                                <img src="{{ $item['thumb'] }}" alt="" loading="lazy">
                            @else
                                <span class="cr-placeholder"><i class="ti ti-{{ $item['kind'] === 'video' ? 'movie' : 'photo' }}" aria-hidden="true"></i></span>
                            @endif
                            @if($item['kind'] === 'video')<video muted loop playsinline preload="none" data-src="{{ $item['file'] }}" aria-hidden="true"></video>@endif
                            @if($item['generated'])<span class="cr-badge is-start">Generated</span>@else<span class="cr-badge is-start">{{ $item['format'] }}</span>@endif
                            @if($item['duration'])<span class="cr-badge is-end num">{{ $item['duration'] }}</span>@endif
                        </span>
                        <strong class="cr-title">{{ $item['title'] }}</strong>
                        <span class="xs muted cr-meta num">{{ $item['meta'] }}</span>
                        @unless($productId)<span class="xs muted cr-meta">{{ $item['product'] }}</span>@endunless
                    </button>
                    @if($canCreate && ! $item['generated'])<input class="sel" type="checkbox" value="{{ $item['id'] }}" wire:model.live="picked" aria-label="Select {{ $item['title'] }}">@endif
                </div>
            @endforeach
        </div>
        @if($more)<button type="button" class="pill ghost sm self-start" wire:click="loadMore">Show more</button>@endif
    @endif

    @if($selected)
        @include('creatives.viewer')
    @endif
</div>
