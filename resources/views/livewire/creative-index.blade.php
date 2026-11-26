<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to creatives</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page">
            <div class="pagehead">
                <div>
                    <div class="eyebrow">All products</div>
                    <h1 class="display">Creatives</h1>
                    <p class="muted">Every image and reel your team made, for every product, plus banners from launch packs. Upload, find, preview, download and share.</p>
                </div>
            </div>
            @if($available)
                <livewire:creative-browser />
            @else
                <div class="empty"><strong>Creatives turn on after the next database update</strong><span class="small muted">Launch pack banners stay in the Library in the meantime.</span></div>
            @endif
        </main>
    </div>
</div>
