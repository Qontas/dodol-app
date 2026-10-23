@php
    use Filament\Support\Facades\FilamentView;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $filters = $this->getFilters();
    $isEmpty = $this->isEmpty();
@endphp

{{--
    TUGAS 3 (BRIEF 23 Okt 2026) — view kustom, BUKAN filament-widgets::chart-widget
    bawaan, karena 2 kebutuhan yang tak bisa dicapai lewat getMaxHeight()/getOptions()
    saja:

    1. Tinggi grafik RESPONSIF (≈260px di HP, ≈320px di laptop) — getMaxHeight()
       Filament hanya menerima SATU nilai statis. <style> inline di bawah (class
       lokal, bukan utility Tailwind) dipakai karena panel admin memakai CSS
       Filament yang SUDAH DIKOMPILASI DI PACKAGE (tak men-scan view app ini) —
       kelas semacam h-[260px] di sini TAK AKAN ada di stylesheet-nya.
    2. State KOSONG ("Belum ada omset 30 hari terakhir") kalau 30 hari nihil —
       Filament tak punya slot bawaan untuk ini, jadi diperiksa di sini
       ($this->isEmpty(), dihitung dari data yang SAMA, 0 query tambahan).
--}}
<x-filament-widgets::widget class="fi-wi-chart">
    {{--
        <style> HARUS di DALAM root element ini (bukan sebelum <x-filament-widgets::widget>
        seperti versi awal) — Livewire butuh SATU root element per komponen dan menempel
        wire:id ke elemen pertama yang ditemui. Waktu <style> ada di luar/sebelum root,
        Livewire salah mengira <style> itu sendiri rootnya, lalu seluruh widget di
        bawahnya (section, canvas, dst.) tak ikut ter-render sama sekali — bug nyata
        yang sempat bikin chart admin hilang total dari halaman /admin.
    --}}
    <style>
        .dodol-omset-chart-box {
            position: relative;
            width: 100%;
            height: 260px;
        }

        @media (min-width: 1024px) {
            .dodol-omset-chart-box {
                height: 320px;
            }
        }
    </style>

    <x-filament::section :description="$description" :heading="$heading">
        @if ($filters)
            <x-slot name="headerEnd">
                <x-filament::input.wrapper
                    inline-prefix
                    wire:target="filter"
                    class="w-max sm:-my-2"
                >
                    <x-filament::input.select
                        inline-prefix
                        wire:model.live="filter"
                    >
                        @foreach ($filters as $value => $label)
                            <option value="{{ $value }}">
                                {{ $label }}
                            </option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </x-slot>
        @endif

        @if ($isEmpty)
            <div class="dodol-omset-chart-box flex items-center justify-center text-sm text-gray-500 dark:text-gray-400">
                Belum ada omset 30 hari terakhir.
            </div>
        @else
            <div
                @if ($pollingInterval = $this->getPollingInterval())
                    wire:poll.{{ $pollingInterval }}="updateChartData"
                @endif
            >
                <div
                    @if (FilamentView::hasSpaMode())
                        x-load="visible"
                    @else
                        x-load
                    @endif
                    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                    wire:ignore
                    x-data="chart({
                                cachedData: @js($this->getCachedData()),
                                options: @js($this->getOptions()),
                                type: @js($this->getType()),
                            })"
                    @class([
                        'dodol-omset-chart-box',
                        match ($color) {
                            'gray' => null,
                            default => 'fi-color-custom',
                        },
                        is_string($color) ? "fi-color-{$color}" : null,
                    ])
                >
                    <canvas x-ref="canvas"></canvas>

                    <span
                        x-ref="backgroundColorElement"
                        @class([
                            match ($color) {
                                'gray' => 'text-gray-100 dark:text-gray-800',
                                default => 'text-custom-50 dark:text-custom-400/10',
                            },
                        ])
                        @style([
                            \Filament\Support\get_color_css_variables(
                                $color,
                                shades: [50, 400],
                                alias: 'widgets::chart-widget.background',
                            ) => $color !== 'gray',
                        ])
                    ></span>

                    <span
                        x-ref="borderColorElement"
                        @class([
                            match ($color) {
                                'gray' => 'text-gray-400',
                                default => 'text-custom-500 dark:text-custom-400',
                            },
                        ])
                        @style([
                            \Filament\Support\get_color_css_variables(
                                $color,
                                shades: [400, 500],
                                alias: 'widgets::chart-widget.border',
                            ) => $color !== 'gray',
                        ])
                    ></span>

                    <span
                        x-ref="gridColorElement"
                        class="text-gray-200 dark:text-gray-800"
                    ></span>

                    <span
                        x-ref="textColorElement"
                        class="text-gray-500 dark:text-gray-400"
                    ></span>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
