// BRIEF 26 Okt 2026 item 1 — keluhan owner (iPhone): klik "Export PDF" di halaman
// laporan membuka PDF di tab browser, bukan terunduh. Server SUDAH kirim
// `Content-Disposition: attachment` (Pdf::download() / Excel::download()), tapi Safari
// iOS tetap menampilkan PDF inline (Quick Look) untuk navigasi langsung ke URL — dia
// mengabaikan header itu untuk tipe yang bisa di-preview. Fix yang konsisten di semua
// browser (termasuk Safari iOS/Android Chrome): JANGAN navigasi langsung, tapi fetch()
// respons sebagai blob lalu klik <a download> SINTETIK ke blob: URL — ini SELALU
// memicu dialog/aksi "simpan file", tak pernah ditampilkan inline.
//
// Dipasang sebagai satu listener delegated di document (bukan per-elemen) — tetap aman
// dipakai berulang lintas navigasi Livewire wire:navigate tanpa perlu re-init.
document.addEventListener('click', async (e) => {
    const link = e.target.closest('a[data-force-download]');
    if (!link) {
        return;
    }

    e.preventDefault();

    if (link.dataset.downloading === '1') {
        return; // cegah klik dobel saat masih proses
    }
    link.dataset.downloading = '1';
    const originalText = link.textContent;
    link.textContent = 'Mengunduh…';

    try {
        const response = await fetch(link.href, { credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const blob = await response.blob();
        const filename = extractFilename(response.headers.get('Content-Disposition'), link.href);

        const blobUrl = URL.createObjectURL(blob);
        const tempLink = document.createElement('a');
        tempLink.href = blobUrl;
        tempLink.download = filename;
        document.body.appendChild(tempLink);
        tempLink.click();
        tempLink.remove();
        setTimeout(() => URL.revokeObjectURL(blobUrl), 10000);
    } catch (err) {
        console.error('Gagal mengunduh file:', err);
        // Fallback: navigasi biasa — lebih baik terbuka di tab daripada gagal total.
        window.location.href = link.href;
    } finally {
        link.textContent = originalText;
        delete link.dataset.downloading;
    }
});

function extractFilename(contentDisposition, fallbackUrl) {
    if (contentDisposition) {
        const starMatch = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i);
        if (starMatch) {
            return decodeURIComponent(starMatch[1]);
        }
        const plainMatch = contentDisposition.match(/filename="?([^";]+)"?/i);
        if (plainMatch) {
            return plainMatch[1];
        }
    }

    try {
        const url = new URL(fallbackUrl, window.location.origin);
        const last = url.pathname.split('/').filter(Boolean).pop();
        if (last) {
            return last;
        }
    } catch {
        // ignore, fallback below
    }

    return 'laporan.pdf';
}
