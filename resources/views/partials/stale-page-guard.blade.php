{{--
    GUARD HALAMAN BASI SAAT BACK/FORWARD.

    Masalah yang ditutup (bug "pilih Kota 1 → kebuka Pancing", 29 Juli 2026):
    `wire:navigate` menyimpan HTML tiap halaman di `snapshotCache` JavaScript
    (vendor/livewire/livewire/dist/livewire.js:8127) dan MEMULIHKANNYA pada `popstate`
    tanpa satu pun request ke server:

        if (snapshotCache.has(alpine.snapshotIdx)) {
            let snapshot = snapshotCache.retrieve(alpine.snapshotIdx);
            handleHtml(snapshot.html, ...)     // ← langsung ditempel ke DOM
        }

    Konsekuensinya mount() komponen TIDAK jalan, dan header `Cache-Control: no-store`
    dari middleware `no-store` TIDAK berpengaruh sama sekali (tak ada HTTP). Layar
    "Mulai Trip" bisa muncul lagi dengan formnya utuh padahal di server trip sudah
    berjalan.

    Guard ini memicu satu `$refresh` ke server BEGITU halaman tiba lewat back/forward,
    jadi apa pun yang tampil dikoreksi oleh render() yang segar. Hanya untuk komponen
    yang menandai dirinya `data-stale-page-guard` — halaman lain tak kena biaya apa pun.

    Dua jalur pemulihan ditangani:
      a. SPA Livewire  → event `livewire:navigate` dengan detail.history === true.
      b. bfcache browser (back dari halaman non-SPA) → `pageshow` dengan e.persisted.

    Listener dipasang di `document`/`window` yang BERTAHAN melintasi swap wire:navigate,
    dan dijaga idempoten supaya tak menumpuk saat layout dirender ulang.
--}}
<script>
    (function () {
        if (window.__stalePageGuardInstalled) {
            return;
        }
        window.__stalePageGuardInstalled = true;

        var cameFromHistory = false;

        function refreshGuardedComponents() {
            if (!window.Livewire) {
                return;
            }

            document.querySelectorAll('[data-stale-page-guard]').forEach(function (el) {
                // Elemen root komponen Livewire memegang wire:id; kalau penanda dipasang
                // di elemen dalam, naik ke root terdekat.
                var root = el.hasAttribute('wire:id') ? el : el.closest('[wire\\:id]');
                if (!root) {
                    return;
                }

                var component = window.Livewire.find(root.getAttribute('wire:id'));
                if (component) {
                    component.$refresh();
                }
            });
        }

        // (a) Navigasi SPA: tandai kalau ini back/forward, lalu segarkan setelah swap.
        document.addEventListener('livewire:navigate', function (e) {
            cameFromHistory = !!(e.detail && e.detail.history);
        });

        document.addEventListener('livewire:navigated', function () {
            if (!cameFromHistory) {
                return; // navigasi maju biasa sudah dirender server — jangan bayar roundtrip.
            }
            cameFromHistory = false;
            refreshGuardedComponents();
        });

        // (b) bfcache browser: halaman dihidupkan lagi dari memori, bukan dirender ulang.
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) {
                refreshGuardedComponents();
            }
        });
    })();
</script>
