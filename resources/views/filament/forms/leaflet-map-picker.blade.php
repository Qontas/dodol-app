<x-filament-forms::field-wrapper
    :id="$getId()"
    :label="$getLabel()"
    :label-sr-only="$isLabelHidden()"
    :helper-text="$getHelperText()"
    :hint="$getHint()"
    :required="$isRequired()"
    :state-path="$getStatePath()"
>
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset('js/leaflet-map-picker.js') }}"></script>

    <div
        wire:ignore
        x-data="leafletMapPicker($wire, {{ $getMapConfig() }})"
        x-init="init()"
    >
        <div x-ref="map" class="w-full rounded-xl" style="min-height: 30vh;"></div>
    </div>
</x-filament-forms::field-wrapper>
