<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

class LeafletMapPicker extends Field
{
    protected string $view = 'filament.forms.leaflet-map-picker';

    protected array $mapConfig = [
        'draggable' => true,
        'clickable' => false,
        'markerColor' => '#3b82f6',
        'showZoomControl' => true,
        'showFullscreenControl' => false,
        'showMyLocationButton' => false,
        'tilesUrl' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution' => '© OpenStreetMap contributors',
        'zoom' => 15,
        'maxZoom' => 19,
        'default' => ['lat' => 0, 'lng' => 0],
    ];

    public function getMapConfig(): string
    {
        return json_encode(array_merge($this->mapConfig, [
            'statePath' => $this->getStatePath(),
        ]));
    }

    public function defaultLocation(int|float $latitude, int|float $longitude): static
    {
        $this->mapConfig['default'] = ['lat' => $latitude, 'lng' => $longitude];

        return $this;
    }

    public function draggable(bool $draggable = true): static
    {
        $this->mapConfig['draggable'] = $draggable;

        return $this;
    }

    public function clickable(bool $clickable = true): static
    {
        $this->mapConfig['clickable'] = $clickable;

        return $this;
    }

    public function markerColor(string $color): static
    {
        $this->mapConfig['markerColor'] = $color;

        return $this;
    }

    public function showZoomControl(bool $show = true): static
    {
        $this->mapConfig['showZoomControl'] = $show;

        return $this;
    }

    public function showFullscreenControl(bool $show = true): static
    {
        $this->mapConfig['showFullscreenControl'] = $show;

        return $this;
    }

    public function showMyLocationButton(bool $show = true): static
    {
        $this->mapConfig['showMyLocationButton'] = $show;

        return $this;
    }

    public function tilesUrl(string $url): static
    {
        $this->mapConfig['tilesUrl'] = $url;

        return $this;
    }

    public function attribution(string $attribution): static
    {
        $this->mapConfig['attribution'] = $attribution;

        return $this;
    }

    public function zoom(int $zoom): static
    {
        $this->mapConfig['zoom'] = $zoom;

        return $this;
    }

    public function maxZoom(int $maxZoom): static
    {
        $this->mapConfig['maxZoom'] = $maxZoom;

        return $this;
    }
}
