{{--
    Dua tugas:
    1) AKAR fix 419 — token @csrf bisa beku di DOM. remember-me merotasi sesi+token
       diam-diam saat app dibuka ulang. Saat resume kita ambil token sesi TERKINI dan
       sinkronkan ke <meta csrf-token> + semua input[name=_token] → POST pertama tak 419.

    2) Multi-tenant guard — snapshot wire:navigate Livewire disimpan di memori SPA /
       history.state dan KEBAL terhadap Cache-Control: no-store maupun service worker.
       Halaman tenant lain yang sempat ter-render bisa muncul lagi via klik wire:navigate
       / back-forward TANPA request ke server (server tak sempat memfilter).

       Dua lapis deteksi:
       a. SINKRON (utama, tanpa kedip) — saat navigasi SPA/popstate: bandingkan uid
          sesi-live dari cookie `auth_uid` (ditanam StampAuthUidCookie tiap respons)
          vs uid yang TAMPIL (meta[auth-uid] snapshot). Beda → reload SEGERA sebelum
          snapshot basi sempat terlihat. Halaman no-store → reload = render segar utk
          user benar; role-middleware server menjaga akses.
       b. ASINKRON (resume) — saat pageshow/visibilitychange: fetch /csrf-token untuk
          sinkron token + (kalau identitas beda) hard-nav ke homePath user benar.

    Akun normal: cookie uid == meta uid selalu → tak pernah reload (no loop).
    Hanya dimuat di layout TER-AUTH.
--}}
<script>
    (function () {
        if (!('fetch' in window)) {
            return;
        }

        var endpoint = @json(route('csrf-token'));
        var loginUrl = @json(route('login'));
        var busy = false;

        // uid halaman/snapshot yang sedang TAMPIL (dibaca segar tiap cek).
        function shownUid() {
            var m = document.querySelector('meta[name="auth-uid"]');
            return m ? m.getAttribute('content') : null;
        }

        // uid sesi LIVE dari cookie readable (sinkron, tanpa jaringan).
        function liveUidCookie() {
            var m = document.cookie.match('(?:^|; )auth_uid=([^;]*)');
            return m ? decodeURIComponent(m[1]) : null;
        }

        function syncTokens(token) {
            var meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) {
                meta.setAttribute('content', token);
            }
            document.querySelectorAll('input[name="_token"]').forEach(function (el) {
                el.value = token;
            });
        }

        // Lapis (a): cek identitas SINKRON. Return true kalau sudah memicu reload
        // (pemanggil harus berhenti). Tanpa fetch → tak ada kedip snapshot basi.
        function syncIdentityGuard() {
            var live = liveUidCookie();
            var shown = shownUid();
            if (live && shown && live !== shown) {
                // Halaman no-store → reload = render segar utk user live; kalau URL ini
                // di luar hak user live, role-middleware server mengalihkan dgn benar.
                window.location.reload();
                return true;
            }
            return false;
        }

        // Lapis (b): resume — sinkron-guard dulu, lalu fetch utk token + identitas.
        function resumeCheck() {
            if (syncIdentityGuard() || busy) {
                return;
            }
            busy = true;

            fetch(endpoint, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            }).then(function (res) {
                if (res.status === 401 || res.status === 419 || res.redirected) {
                    window.location.href = loginUrl;
                    return null;
                }
                return res.ok ? res.json() : null;
            }).then(function (data) {
                if (!data || !data.token) {
                    return;
                }
                var live = (data.uid !== null && data.uid !== undefined) ? String(data.uid) : null;
                var shown = shownUid();
                // Identitas live (server) beda dari yang tampil → lempar ke homePath benar.
                if (live && shown && live !== shown) {
                    window.location.replace(data.home || '/');
                    return;
                }
                syncTokens(data.token);
            }).catch(function () {
                /* offline / gagal jaringan — diamkan, jangan ganggu operator */
            }).finally(function () {
                busy = false;
            });
        }

        // Navigasi SPA (klik wire:navigate) & back/forward (popstate) → guard SINKRON.
        document.addEventListener('livewire:navigated', syncIdentityGuard);
        window.addEventListener('popstate', syncIdentityGuard);

        // Resume app (PWA mobile) & restore halaman → guard + sinkron token.
        window.addEventListener('pageshow', resumeCheck);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                resumeCheck();
            }
        });
    })();
</script>
