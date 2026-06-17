{{--
    Pengaman bfcache untuk halaman TER-AUTENTIKASI (PWA mobile).
    Bila halaman dipulihkan dari back/forward cache (e.persisted = true), paksa
    reload agar render selalu dari server sesi terkini — cegah dashboard akun
    sebelumnya muncul lintas login/logout (multi-tenant).

    Anti reload-loop:
    - Hanya reload saat e.persisted === true (load normal e.persisted=false → diam).
    - Partial ini HANYA di-include di layout ter-auth (operator/owner/app), TIDAK
      di login/landing.
    - Guard pathname tambahan: hanya reload di area ter-auth.
--}}
<script>
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) {
            return;
        }
        var p = window.location.pathname;
        if (p.startsWith('/owner') || p.startsWith('/operator')
            || p.startsWith('/dashboard') || p.startsWith('/admin')) {
            window.location.reload();
        }
    });
</script>
