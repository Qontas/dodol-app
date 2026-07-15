/**
 * Kompres foto kios DI BROWSER sebelum upload.
 *
 * KENAPA: foto kamera HP bisa 8–17MB. Dulu sistem MENOLAK file besar (owner harus
 * kompres manual di situs online dulu — makan waktu). Sekarang dikecilkan dulu di HP,
 * jadi operator/owner tak perlu memikirkan ukuran file sama sekali.
 *
 * Pakai canvas bawaan browser — TANPA library. Satu sumber untuk semua input foto
 * operator (create-kiosk & active-trip); form owner (Filament) pakai plugin transform
 * FilePond bawaannya sendiri.
 *
 * EXIF ORIENTATION: <img> di browser modern (Chrome/Safari/Firefox) sudah otomatis
 * memutar foto sesuai EXIF (`image-orientation: from-image` = default CSS sejak
 * Chrome 81), dan img.width/height ikut nilai yang SUDAH diputar. Jadi drawImage()
 * menghasilkan foto ber-orientasi benar, dan canvas membuang EXIF-nya → tak ada
 * putaran dobel di server. Diverifikasi pakai foto ber-EXIF Orientation=6.
 *
 * HEIC: browser non-Apple (mis. Chrome di Android) TIDAK bisa decode HEIC → sengaja
 * dilewati di sini dan dikirim MENTAH; server yang mengonversinya ke JPG
 * (App\Support\HeicConverter). Jangan tambah decoder WASM cuma untuk ini.
 */

const MAKS_SISI = 1600;
const KUALITAS = 0.8;
const LEWATI_DI_BAWAH = 1024 * 1024; // 1MB — sudah kecil, tak usah re-encode.

function muatGambar(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Gambar tak bisa dibaca browser'));
        };
        img.src = url;
    });
}

function keBlob(canvas, quality) {
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
}

function namaJpg(nama) {
    return (nama || 'foto').replace(/\.[^.]+$/, '') + '.jpg';
}

export function adalahHeic(file) {
    return /hei[cf]/i.test(file?.type || '') || /\.hei[cf]$/i.test(file?.name || '');
}

/**
 * @returns {Promise<File>} file terkompres, atau file ASLI kalau tak perlu/tak bisa
 *                          dikompres (pemanggil tak perlu menangani error).
 */
export async function kompresFotoKios(file, opts = {}) {
    const maksSisi = opts.maksSisi ?? MAKS_SISI;
    const kualitas = opts.kualitas ?? KUALITAS;
    const lewatiDiBawah = opts.lewatiDiBawah ?? LEWATI_DI_BAWAH;

    if (!file || !file.type || !file.type.startsWith('image/')) return file;

    // HEIC → biarkan mentah, server yang konversi (browser Android tak bisa decode).
    if (adalahHeic(file)) return file;

    try {
        const img = await muatGambar(file);

        let w = img.width;
        let h = img.height;
        const perluKecilkan = w > maksSisi || h > maksSisi;

        // Sudah kecil & resolusinya wajar → pakai apa adanya (jangan re-encode percuma,
        // re-encode JPEG selalu menurunkan kualitas).
        if (!perluKecilkan && file.size <= lewatiDiBawah) return file;

        if (perluKecilkan) {
            if (w >= h) {
                h = Math.round((h * maksSisi) / w);
                w = maksSisi;
            } else {
                w = Math.round((w * maksSisi) / h);
                h = maksSisi;
            }
        }

        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        if (!ctx) return file;

        // Latar putih: cegah PNG/WEBP transparan jadi hitam saat disimpan sebagai JPEG.
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(img, 0, 0, w, h);

        const blob = await keBlob(canvas, kualitas);
        if (!blob || blob.size === 0) return file;

        // Jaring pengaman: kalau hasil "kompres" malah lebih besar (foto kecil tapi
        // ber-noise), pakai yang asli.
        if (blob.size >= file.size && !perluKecilkan) return file;

        return new File([blob], namaJpg(file.name), { type: 'image/jpeg' });
    } catch (e) {
        return file; // Server masih punya lapis kedua (ImageResizer) + plafon ukuran.
    }
}

window.kompresFotoKios = kompresFotoKios;
window.adalahHeicKios = adalahHeic;
