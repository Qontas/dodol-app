<?php

namespace App\Livewire\Operator;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.operator')]
class Profile extends Component
{
    public function render()
    {
        return view('livewire.operator.profile');
    }
}
