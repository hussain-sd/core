<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        @if ($canEdit)
            <x-filament::button type="submit">
                Save
            </x-filament::button>
        @endif
    </form>
</x-filament-panels::page>
