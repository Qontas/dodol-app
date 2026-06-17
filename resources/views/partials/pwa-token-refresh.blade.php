{{--
    Auto-refresh CSRF token saat app resume (PWA mobile) + deteksi pergantian
    identitas. Menggantikan pwa-bfcache-guard yang impoten (reload-on-persisted
    tak pernah menyala karena no-store mematikan bfcache).

    AKAR fix 419: token @csrf beku di DOM. remember-me bisa merotasi sesi+token
    diam-diam saat app dibuka ulang. Saat resume kita ambil token sesi TERKINI dan
    sinkronkan ke <meta csrf-token> + semua input[name=_token] (termasuk logout) →
    POST pertama setelah app dibuka lama TIDAK 419.

    Multi-tenant (#4): kalau uid sesi terkini BEDA dari uid render halaman ini
    (mis. akun berganti di tab lain) → reload penuh agar halaman milik user yang
    benar. BUKAN reload buta tiap resume → tak ada reload-loop.

    Hanya dimuat di layout TER-AUTH. Endpoint butuh auth; kalau sesi habis →
    arahkan ke login (bukan error).
--}}
<script>
    (function () {
        if (!('fetch' in window)) {
            return;
        }

        var expectedUid = @json(auth()->id());
        var endpoint = @json(route('csrf-token'));
        var loginUrl = @json(route('login'));
        var busy = false;

        function syncToken() {
            if (busy) {
                return;
            }
            busy = true;

            fetch(endpoint, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            }).then(function (res) {
                // Sesi habis / tak terautentikasi → endpoint auth redirect ke login.
                if (res.status === 401 || res.status === 419 || res.redirected) {
                    window.location.href = loginUrl;
                    return null;
                }
                return res.ok ? res.json() : null;
            }).then(function (data) {
                if (!data || !data.token) {
                    return;
                }

                // Identitas berubah → muat ulang halaman milik user terkini (multi-tenant).
                if (data.uid && expectedUid && data.uid !== expectedUid) {
                    window.location.reload();
                    return;
                }

                // Sinkronkan token ke meta + semua form (logout & lainnya).
                var meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) {
                    meta.setAttribute('content', data.token);
                }
                document.querySelectorAll('input[name="_token"]').forEach(function (el) {
                    el.value = data.token;
                });
            }).catch(function () {
                /* offline / gagal jaringan — diamkan, jangan ganggu operator */
            }).finally(function () {
                busy = false;
            });
        }

        // pageshow: SEMUA (bukan hanya e.persisted) — tangkap resume PWA & restore.
        window.addEventListener('pageshow', syncToken);
        // app kembali ke foreground.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                syncToken();
            }
        });
    })();
</script>
