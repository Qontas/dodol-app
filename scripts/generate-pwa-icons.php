<?php

/**
 * Generator ikon PWA "Cemilan Qontas".
 *
 * Menghasilkan ikon tipografi huruf "Q" putih di atas gradient amber
 * (#F59E0B -> #D97706, sesuai tema Filament Amber). Dijalankan sekali via
 * `php scripts/generate-pwa-icons.php`; outputnya di-commit ke public/.
 *
 * - Versi "any"      : sudut membulat (squircle ringan), Q besar.
 * - Versi "maskable" : full-bleed (mengisi kanvas penuh, tanpa transparansi),
 *                      Q lebih kecil di dalam safe-area ~20% padding agar tidak
 *                      kepotong saat di-mask bulat/squircle Android.
 */

$publicDir = __DIR__ . '/../public';
$fontPath = 'C:/Windows/Fonts/ariblk.ttf'; // Arial Black — tebal & modern

if (! extension_loaded('gd')) {
    fwrite(STDERR, "GD extension tidak tersedia.\n");
    exit(1);
}
if (! is_file($fontPath)) {
    fwrite(STDERR, "Font tidak ditemukan: {$fontPath}\n");
    exit(1);
}

// Warna gradient amber (atas -> bawah).
$top = [0xF5, 0x9E, 0x0B];    // #F59E0B
$bottom = [0xD9, 0x77, 0x06]; // #D97706

/**
 * Buat satu ikon kotak ukuran $size.
 *
 * @param int   $size        sisi kanvas (px)
 * @param float $cornerRatio radius sudut sebagai rasio dari $size (0 = full bleed)
 * @param float $glyphRatio  tinggi area glyph sebagai rasio dari $size
 */
function makeIcon(int $size, float $cornerRatio, float $glyphRatio, array $top, array $bottom, string $fontPath): \GdImage
{
    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, false);
    imagesavealpha($img, true);

    // Mulai dari transparan penuh.
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefilledrectangle($img, 0, 0, $size, $size, $transparent);

    $radius = (int) round($size * $cornerRatio);

    // Gambar gradient vertikal baris-per-baris, dibatasi bentuk rounded-rect.
    for ($y = 0; $y < $size; $y++) {
        $t = $size > 1 ? $y / ($size - 1) : 0;
        $r = (int) round($top[0] + ($bottom[0] - $top[0]) * $t);
        $g = (int) round($top[1] + ($bottom[1] - $top[1]) * $t);
        $b = (int) round($top[2] + ($bottom[2] - $top[2]) * $t);
        $color = imagecolorallocate($img, $r, $g, $b);

        // Tentukan batas kiri/kanan baris ini (untuk sudut membulat).
        $xLeft = 0;
        $xRight = $size - 1;

        if ($radius > 0) {
            $dy = null;
            if ($y < $radius) {
                $dy = $radius - $y;            // di area sudut atas
            } elseif ($y > $size - 1 - $radius) {
                $dy = $y - ($size - 1 - $radius); // di area sudut bawah
            }
            if ($dy !== null) {
                // Lebar inset di baris ini mengikuti lingkaran sudut.
                $inset = $radius - (int) floor(sqrt(max(0, $radius * $radius - $dy * $dy)));
                $xLeft = $inset;
                $xRight = $size - 1 - $inset;
            }
        }

        if ($xRight >= $xLeft) {
            imageline($img, $xLeft, $y, $xRight, $y, $color);
        }
    }

    // Tulis huruf "Q" putih, center, dengan tinggi ~ $glyphRatio * size.
    $white = imagecolorallocate($img, 255, 255, 255);
    $letter = 'Q';

    // Cari ukuran font (pt) yang membuat tinggi glyph sesuai target.
    $targetH = $size * $glyphRatio;
    $fontSize = $targetH; // tebakan awal (pt ~ px untuk Arial Black cukup dekat)
    for ($i = 0; $i < 8; $i++) {
        $bbox = imagettfbbox($fontSize, 0, $fontPath, $letter);
        $h = abs($bbox[7] - $bbox[1]); // tinggi glyph
        if ($h <= 0) {
            break;
        }
        $fontSize *= $targetH / $h;
    }

    $bbox = imagettfbbox($fontSize, 0, $fontPath, $letter);
    $glyphW = $bbox[2] - $bbox[0];
    $glyphH = abs($bbox[7] - $bbox[1]);
    // Origin (x,y) di baseline kiri. Pusatkan secara horizontal & vertikal.
    $x = (int) round(($size - $glyphW) / 2 - $bbox[0]);
    $y = (int) round(($size - $glyphH) / 2 - $bbox[7]);

    imagettftext($img, $fontSize, 0, $x, $y, $white, $fontPath, $letter);

    return $img;
}

function save(\GdImage $img, string $path): void
{
    imagepng($img, $path);
    imagedestroy($img);
}

// --- Generate semua ikon ---
$jobs = [
    // path, size, cornerRatio, glyphRatio
    ['icon-192.png',          192, 0.22, 0.64],
    ['icon-512.png',          512, 0.22, 0.64],
    ['icon-maskable-192.png', 192, 0.0,  0.46], // full bleed + safe area
    ['icon-maskable-512.png', 512, 0.0,  0.46],
    ['apple-touch-icon.png',  180, 0.0,  0.64], // iOS mask sendiri -> full bleed
    ['favicon.png',           48,  0.22, 0.70],
];

foreach ($jobs as [$name, $size, $corner, $glyph]) {
    $img = makeIcon($size, $corner, $glyph, $top, $bottom, $fontPath);
    save($img, "{$publicDir}/{$name}");
    echo "OK  {$name} ({$size}x{$size})\n";
}

// --- favicon.ico: bungkus PNG 32x32 ke dalam container ICO yang valid ---
$icoImg = makeIcon(32, 0.22, 0.70, $top, $bottom, $fontPath);
ob_start();
imagepng($icoImg);
$pngData = ob_get_clean();
imagedestroy($icoImg);

$png32 = strlen($pngData);
$ico = pack('vvv', 0, 1, 1); // reserved, type=icon, count=1
$ico .= pack('CCCC', 32, 32, 0, 0); // width, height, colors, reserved
$ico .= pack('vv', 1, 32); // planes, bitcount
$ico .= pack('VV', $png32, 22); // bytesInRes, imageOffset (6+16)
$ico .= $pngData;
file_put_contents("{$publicDir}/favicon.ico", $ico);
echo "OK  favicon.ico (32x32, PNG-embedded, {$png32} bytes png)\n";

echo "Selesai.\n";
