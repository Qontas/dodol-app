{{-- PWA: manifest, ikon, meta iOS, dan registrasi service worker. --}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#F59E0B">
<link rel="icon" href="/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Cemilan Qontas">
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function () {
                /* registrasi gagal (mis. dev http non-localhost) — abaikan, app tetap jalan */
            });
        });
    }
</script>
