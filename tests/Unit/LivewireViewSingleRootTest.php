<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Regresi (26 Okt 2026): active-trip.blade.php SEBELUMNYA mulai dengan
 * <script src="...gps-accuracy.js"></script> SEBELUM <div> root komponen (sibling,
 * bukan child). Livewire menganggap elemen pertama di output view sebagai akar
 * komponen dan memasang wire:id di sana — jadi wire:id nempel di <script> itu, BUKAN
 * di <div> yang berisi semua logic (termasuk Alpine x-data & sortByDistance()).
 * Akibatnya closest('[wire\:id]') dari DALAM <div> tak pernah ketemu komponennya
 * (closest() cuma menyusuri ANCESTOR, tak bisa ke sibling sebelumnya) -> setiap
 * $wire.*() yang dipanggil lewat Alpine balik fungsi no-op (bukan Promise) -> .then()
 * meledak -> tombol "Urutkan Jarak" macet selamanya di "Mencari...". Terbukti lewat
 * Playwright (lihat .claude/skills/verify-browser/scratch/debug-sort.cjs sesi ini):
 * closestWireFound berubah dari false -> true setelah <script> dipindah jadi child
 * pertama <div>, bukan sibling sebelumnya.
 *
 * Livewire::test()->html() TIDAK BISA mendeteksi bug ini — test harness Livewire
 * selalu membungkus output dalam <div wire:snapshot="..."> buatannya sendiri,
 * terlepas dari struktur root view yang sesungguhnya (dibuktikan sesi ini: test yang
 * sama PASS baik di versi buggy maupun versi fixed). Satu-satunya cara memverifikasi
 * lewat PHPUnit adalah membaca source .blade.php mentah dan memastikan elemen
 * non-whitespace PERTAMA adalah <div> root, bukan <script>/elemen lain — persis yang
 * dicek test ini.
 */
class LivewireViewSingleRootTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function viewsWithSingleDivRoot(): array
    {
        return [
            'operator.active-trip' => ['resources/views/livewire/operator/active-trip.blade.php'],
            'operator.create-kiosk' => ['resources/views/livewire/operator/create-kiosk.blade.php'],
        ];
    }

    /**
     * @dataProvider viewsWithSingleDivRoot
     */
    public function test_view_first_element_is_the_single_div_root(string $relativePath)
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path);

        $source = file_get_contents($path);

        // Buang komentar Blade {{-- ... --}} (boleh multi-baris) sebelum mencari elemen
        // nyata pertama — komentar bukan bagian dari output HTML yang dilihat browser.
        $withoutComments = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $firstRealContent = ltrim($withoutComments);

        $this->assertStringStartsWith('<div', $firstRealContent,
            "$relativePath: elemen pertama (setelah komentar Blade dibuang) harus <div> "
            . 'root tunggal. Kalau ada <script>, <link>, atau elemen lain SEBELUM <div> '
            . 'ini (sebagai sibling, bukan child), Livewire salah memasang wire:id ke '
            . 'elemen itu, bukan ke <div> — dan setiap $wire.*() yang dipanggil dari '
            . 'Alpine x-data di dalam <div> akan selalu gagal (lihat komentar kelas ini).');
    }
}
