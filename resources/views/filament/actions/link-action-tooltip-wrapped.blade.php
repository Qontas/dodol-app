{{--
    Aksi tabel bergaya link, TAPI tooltipnya dipasang di elemen PEMBUNGKUS.

    KENAPA ADA FILE INI
    Tombol aksi yang ->disabled() (mis. Delete pada baris owner/operator BERDATA)
    memakai ->tooltip() untuk menjelaskan KENAPA tak bisa dihapus. Tooltip itu tak
    pernah muncul, jadi owner cuma melihat tombol mati tanpa penjelasan.

    Akarnya ADA DI TIPPY, bukan sekadar CSS: Filament menempelkan `x-tooltip` pada
    tombol itu sendiri, dan `show()` milik Tippy punya penjaga eksplisit —

        if (!(isVisible || isDestroyed || !isEnabled || touchDisabled)
            && !getCurrentTarget().hasAttribute("disabled")) { …tampilkan… }

    (vendor/filament/support/dist/index.js). Selama atribut `disabled` menempel,
    Tippy MENOLAK tampil — bahkan `instance.show()` manual pun tak berefek
    (diukur: isVisible tetap false). Karena itu melonggarkan `pointer-events`
    lewat CSS TIDAK cukup; komentar Tippy sendiri menyarankan elemen pembungkus.

    CARA KERJA
    Pembungkus <div> yang memegang tooltip TIDAK punya atribut `disabled`, jadi
    Tippy mau menampilkannya. Tombol di dalamnya tetap `disabled` +
    `pointer-events-none`, sehingga pointer menembus ke pembungkus dan
    mouseenter-nya mengenai pembungkus. Keamanan tak berubah sedikit pun:
    tombolnya tetap tombol disabled yang sama, klik tetap mustahil, dan guard
    ->before() di server tetap jalan.

    Dibungkus HANYA saat aksi benar-benar disabled — kalau tidak, tombol yang
    aktif akan punya dua tooltip (miliknya sendiri + milik pembungkus).

    ⚠️ Blok <x-filament-actions::action> di bawah adalah SALINAN PERSIS
    vendor/filament/actions/resources/views/link-action.blade.php. Kalau upgrade
    Filament mengubah file itu, samakan lagi di sini.
--}}
@php
    $tooltipAlasan = $getTooltip();
    $butuhPembungkus = filled($tooltipAlasan) && $action->isDisabled();
@endphp

@if ($butuhPembungkus)
    <div
        x-data="{}"
        x-tooltip="{
            content: @js($tooltipAlasan),
            theme: $store.theme,
        }"
        class="fi-dodol-disabled-action-wrapper inline-flex cursor-not-allowed"
    >
@endif

<x-filament-actions::action
    :action="$action"
    :badge="$getBadge()"
    :badge-color="$getBadgeColor()"
    dynamic-component="filament::link"
    :icon-position="$getIconPosition()"
    :size="$getSize()"
    class="fi-ac-link-action"
>
    {{ $getLabel() }}
</x-filament-actions::action>

@if ($butuhPembungkus)
    </div>
@endif
