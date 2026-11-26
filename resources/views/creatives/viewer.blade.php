{{-- One creative, large, with its details beside it. Arrow keys step through the grid, Esc closes. --}}
<div class="viewer" role="dialog" aria-modal="true" aria-labelledby="cr-viewer-title" wire:key="cr-viewer"
     x-data x-trap.noscroll="true"
     @keydown.escape.window.prevent="$wire.close()"
     @keydown.window="if (['INPUT', 'TEXTAREA', 'SELECT', 'VIDEO'].includes($event.target.tagName) || $event.target.isContentEditable || $event.metaKey || $event.ctrlKey || $event.altKey) return; if ($event.key === 'ArrowLeft') { $event.preventDefault(); $wire.step(-1) } else if ($event.key === 'ArrowRight') { $event.preventDefault(); $wire.step(1) }">
    <div class="viewer-backdrop" wire:click="close" aria-hidden="true"></div>
    <div class="viewer-frame">
        <header class="viewer-bar">
            <div class="viewer-crumbs grow">
                <span class="muted xs viewer-crumb">{{ $selected['product'] }}@if($selected['generated']) · Generated @endif</span>
                <h2 id="cr-viewer-title" class="viewer-title">{{ $selected['title'] }}</h2>
            </div>
            <a class="pill pri sm" href="{{ $selected['download'] }}"><i class="ti ti-download" aria-hidden="true"></i>Download</a>
            <div class="row viewer-nav">
                <button type="button" class="viewer-btn" wire:click="step(-1)" @disabled(! $previousKey) aria-label="Previous creative" title="Previous (←)"><i class="ti ti-chevron-left" aria-hidden="true"></i></button>
                <button type="button" class="viewer-btn" wire:click="step(1)" @disabled(! $nextKey) aria-label="Next creative" title="Next (→)"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>
                <button type="button" class="viewer-btn" wire:click="close" aria-label="Close" title="Close (Esc)"><i class="ti ti-x" aria-hidden="true"></i></button>
            </div>
        </header>

        <div class="viewer-body">
            <div class="viewer-stage is-image">
                @if($selected['kind'] === 'video')
                    <video class="viewer-img cr-player" src="{{ $selected['file'] }}" @if($selected['thumb']) poster="{{ $selected['thumb'] }}" @endif controls playsinline preload="metadata" wire:key="cr-player-{{ $selected['key'] }}"></video>
                @else
                    <img class="viewer-img" src="{{ $selected['file'] }}" alt="{{ $selected['title'] }}" wire:key="cr-img-{{ $selected['key'] }}">
                @endif
                @if($previousKey)<button type="button" class="viewer-arrow is-prev" wire:click="step(-1)" aria-label="Previous creative" tabindex="-1"><i class="ti ti-chevron-left" aria-hidden="true"></i></button>@endif
                @if($nextKey)<button type="button" class="viewer-arrow is-next" wire:click="step(1)" aria-label="Next creative" tabindex="-1"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>@endif
            </div>

            <aside class="viewer-panel" aria-label="Details of {{ $selected['title'] }}">
                @if($flash)<div class="banner ok xs" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif

                <div class="col">
                    <span class="eyebrow">Details</span>
                    <div class="kv"><span>Format</span><span>{{ $selected['format'] }}</span></div>
                    @if($selected['size'])<div class="kv"><span>Size</span><span class="num">{{ $selected['size'] }}</span></div>@endif
                    @if($selected['dimensions'])<div class="kv"><span>Dimensions</span><span class="num">{{ $selected['dimensions'] }}</span></div>@endif
                    @if($selected['duration'])<div class="kv"><span>Duration</span><span class="num">{{ $selected['duration'] }}</span></div>@endif
                    <div class="kv"><span>Product</span><span>{{ $selected['product'] }}</span></div>
                    @unless($selected['generated'])
                        <div class="kv"><span>Channel</span><span>{{ $selected['channel'] ?? '—' }}</span></div>
                        <div class="kv"><span>Stage</span><span>{{ $selected['stage'] ?? '—' }}</span></div>
                        <div class="kv"><span>Tags</span><span>{{ $selected['tags'] ? implode(', ', $selected['tags']) : '—' }}</span></div>
                        <div class="kv"><span>Uploaded by</span><span>{{ $selected['uploader'] ?? '—' }}</span></div>
                        <div class="kv"><span>File</span><span class="break">{{ $selected['source'] }}</span></div>
                    @else
                        <div class="kv"><span>Launch pack</span><span>{{ $selected['source'] ?? '—' }}</span></div>
                    @endunless
                    <div class="kv"><span>Added</span><span>{{ $selected['created']?->format('j M Y') }}</span></div>
                </div>

                @if($selected['generated'])
                    <p class="muted small">Made by a launch pack. Review and approve it in Outputs; it is read-only here.</p>
                @elseif($canCreate)
                    <form class="col cr-edit" wire:submit="save">
                        <span class="eyebrow">Edit</span>
                        <div class="field">
                            <label for="cr-title">Title</label>
                            <input id="cr-title" class="input" wire:model="edit.title" maxlength="200" @error('edit.title') aria-invalid="true" @enderror>
                            @error('edit.title')<span class="err" role="alert">{{ $message }}</span>@enderror
                        </div>
                        <div class="field">
                            <label for="cr-channel">Channel</label>
                            <select id="cr-channel" class="input" wire:model="edit.channel"><option value="">None</option>@foreach(\App\Models\CreativeAsset::CHANNELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                        </div>
                        <div class="field">
                            <label for="cr-stage">Stage</label>
                            <select id="cr-stage" class="input" wire:model="edit.stage"><option value="">None</option>@foreach(\App\Models\CreativeAsset::STAGES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                        </div>
                        <div class="field">
                            <label for="cr-tags">Tags</label>
                            <input id="cr-tags" class="input" wire:model="edit.tags" placeholder="Comma separated, e.g. UGC, summer">
                            @error('edit.tags')<span class="err" role="alert">{{ $message }}</span>@enderror
                        </div>
                        <div class="row">
                            <button type="submit" class="pill pri sm" wire:loading.attr="disabled" wire:target="save">Save</button>
                            <span class="sp"></span>
                            <button type="button" class="pill ghost sm" wire:click="archive({{ $selected['id'] }}, {{ $selected['archived'] ? 'false' : 'true' }})">{{ $selected['archived'] ? 'Restore' : 'Archive' }}</button>
                            <button type="button" class="pill ghost sm cr-danger" wire:click="delete({{ $selected['id'] }})" wire:confirm="Delete “{{ $selected['title'] }}”? The file is removed from storage and cannot be recovered.">Delete</button>
                        </div>
                    </form>
                @endif
            </aside>
        </div>
    </div>
</div>
