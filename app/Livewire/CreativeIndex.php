<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Services\CreativeLibrary;
use Livewire\Component;

class CreativeIndex extends Component
{
    use InteractsWithWorkspace;

    public function render()
    {
        return view('livewire.creative-index', [
            'workspace' => $this->currentWorkspace(),
            'available' => CreativeLibrary::available(),
        ])->layout('components.layouts.app')->title('Creatives');
    }
}
