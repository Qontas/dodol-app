{{--
    Tombol "Ambil Foto" / "Dari Galeri" untuk widget Foto Kios (FileUpload/FilePond) di ATASNYA.

    KENAPA TOMBOL, BUKAN capture DI INPUT-NYA: atribut `capture` MEMAKSA kamera dan
    MENGHILANGKAN pilihan galeri di HP. Widget foto cuma punya SATU input, jadi kalau
    capture dipasang permanen di sana, owner tak bisa lagi pilih foto lama dari galeri.
    Maka: capture dipasang HANYA sesaat sebelum picker dibuka, lalu dilepas lagi.

    RISIKO DIJAGA: kita TIDAK menyentuh pipeline upload FilePond sama sekali — cuma
    meng-klik input milik FilePond sendiri, jadi kompres/preview/upload/simpan ke R2
    tetap lewat jalur bawaan yang sudah teruji. `.filepond--browser` = kelas internal
    FilePond; kalau suatu saat berubah (upgrade Filament), tombol ini diam saja dan
    owner tetap bisa pakai kotak unggah FilePond di atas seperti biasa (tak ada yang rusak).

    KENAPA INLINE STYLE, BUKAN KELAS TAILWIND: view ini dirender di dalam panel Filament,
    yang memakai CSS build-nya SENDIRI — utility app (grid-cols-2, bg-amber-600, dst) tak
    dijamin ada di sana. Versi pertama pakai kelas Tailwind dan layout-nya rusak (tombol
    kamera gepeng jadi sepotong kecil, ketahuan dari screenshot verifikasi). Inline style
    tak bisa ke-purge → tampilannya pasti.
--}}
<div
    x-data="{
        pilih(pakaiKamera) {
            const input = document.querySelector('[data-kiosk-photo] input.filepond--browser');
            if (! input) return; // FilePond belum siap → kotak unggah di atas tetap jalan.

            if (pakaiKamera) {
                input.setAttribute('capture', 'environment'); // kamera belakang
            } else {
                input.removeAttribute('capture');
            }

            input.click();

            // Kembalikan ke netral setelah picker dibuka, supaya klik pada kotak unggah
            // FilePond sendiri (dan klik tombol Galeri berikutnya) tak ikut terkunci
            // ke kamera. 'focus' menangkap kasus picker DIBATALKAN (tak ada 'change').
            const bersihkan = () => input.removeAttribute('capture');
            input.addEventListener('change', bersihkan, { once: true });
            window.addEventListener('focus', bersihkan, { once: true });
        },
    }"
    style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-top: -0.25rem;"
>
    <button
        type="button"
        x-on:click="pilih(true)"
        style="display: inline-flex; align-items: center; justify-content: center; gap: 0.375rem;
               padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid #d97706;
               background-color: #d97706; color: #ffffff; font-size: 0.875rem; font-weight: 600;
               cursor: pointer; line-height: 1.25rem;"
    >
        📷 Ambil Foto (Kamera)
    </button>
    <button
        type="button"
        x-on:click="pilih(false)"
        style="display: inline-flex; align-items: center; justify-content: center; gap: 0.375rem;
               padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid #fcd34d;
               background-color: #fffbeb; color: #b45309; font-size: 0.875rem; font-weight: 600;
               cursor: pointer; line-height: 1.25rem;"
    >
        🖼️ Dari Galeri
    </button>
</div>
