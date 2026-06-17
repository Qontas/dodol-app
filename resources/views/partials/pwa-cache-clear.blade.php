{{--
    Pembersih cache klien di halaman tamu/login.
    Tujuan: device yang SUDAH terlanjur menyimpan halaman ter-auth di SW v1
    langsung bersih saat membuka halaman login — tanpa perlu uninstall PWA.
    Aman: hanya menghapus cache lama (≠ dodol-v2); cache shell v2 tetap utuh.
--}}
<script>
    if ('caches' in window) {
        caches.keys().then(function (keys) {
            keys.filter(function (key) { return key !== 'dodol-v2'; })
                .forEach(function (key) { caches.delete(key); });
        }).catch(function () { /* abaikan, login tetap jalan */ });
    }
</script>
