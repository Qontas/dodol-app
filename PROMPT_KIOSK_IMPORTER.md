# Brief: Fix KioskImporter — Format Spreadsheet Abang Aidil

## KONTEKS

83 PASS. KioskImporter existing expect kolom standar.
Data abang Aidil punya format berbeda yang perlu di-handle.
Tugas: update KioskImporter + buat template CSV untuk abang.

## FORMAT DATA ABANG (dari Google Sheets yang sudah dianalisis)

Kolom spreadsheet abang:

- A: Nomor urut
- B: Nomor cluster (angka: 1, 2, blank)
- D: Nama kios (contoh: "Karya 1 (masjid)", "Gaperta 3")
- E: Qty mika (angka: 1, 2, 3, 5, 10)
- F: Deskripsi lokasi (teks: "Jl. Karya (disamping Masjid...)")
- G: Koordinat/link CAMPUR:
    - Format DMS: "3° 36' 15.5196\" N 98° 39' 54.072\" E"
    - Google Maps short link: "https://goo.gl/maps/..." atau "https://maps.app.goo.gl/..."
    - Nama tempat: "WARKOP NST", "Jl. Karya 29-37"
    - Kosong
- H: Foto (nama file screenshot / Google Drive link) → SKIP
- I: Tanggal pertama titip (format: DD/MM/YYYY, contoh: "26/02/2025")
- J: Nama pengantar (Aidil, zuranda, ADRI) → SKIP (bukan pemilik kios)

TIDAK ADA kolom nama pemilik kios.

## TEMPLATE CSV YANG AKAN DIBUAT

Template untuk abang mengikuti kolom spreadsheet-nya:
nomor,cluster,nama_kios,qty_mika,deskripsi_lokasi,koordinat_atau_link,tanggal_pertama_titip
1,1,Karya 1 (masjid),3,"Jl. Karya (disamping Masjid, depan mie aceh)","3° 36' 15.5196"" N 98° 39' 54.072"" E",26/02/2025
2,2,Karya 2 (alfa midi),2,Jl. Karya (Jual sayur depan Alfa Midi),https://goo.gl/maps/xxx,26/02/2025

## PARSER KOLOM G (koordinat_atau_link)

Fungsi parseKoordinat($value): array ['lat' => float|null, 'lng' => float|null]

### Case 1: DMS Format

Pattern: `\d+°\s*\d+'\s*[\d.]+"\s*[NS]\s+\d+°\s*\d+'\s*[\d.]+"\s*[EW]`
Contoh: "3° 36' 15.5196" N 98° 39' 54.072" E"
Konversi DMS ke Decimal:

- degrees + (minutes/60) + (seconds/3600)
- S/W = negatif
  Result: lat = 3.604311, lng = 98.665020

### Case 2: Google Maps link

Pattern: starts with "http" dan contains "goo.gl/maps" atau "maps.app.goo.gl" atau "maps.google.com"
Action: simpan link di location_description, lat/lng = null
(Tidak bisa resolve koordinat dari short link tanpa API)
Catatan di kolom notes: "GPS: {link}"

### Case 3: Teks biasa / nama tempat

Pattern: tidak cocok Case 1 atau 2
Action: simpan di location_description saja, lat/lng = null

### Case 4: Kosong

Action: lat/lng = null, location_description tetap dari kolom F

## CLUSTER MAPPING

Kolom B berisi angka (1, 2, blank) atau nama cluster.

Logic:

1. Kalau value angka → cari Cluster milik owner yang sedang import
   dengan nama LIKE "%{angka}%" atau name = "{angka}"
2. Kalau tidak ditemukan → buat Cluster baru:
   name = "Cluster {value}", owner_id = owner yang import
3. Kalau blank/kosong → cari atau buat cluster "Uncategorized"
   dengan owner_id = owner yang import

## PERUBAHAN KioskImporter

### Cek file existing:

app/Filament/Imports/KioskImporter.php

### Update resolveRecord() + resolveCluster():

Tambah private helper:

```php
private function parseKoordinat(string $value): array
{
    $value = trim($value);

    // Case 1: DMS format
    if (preg_match('/(\d+)°\s*(\d+)\'\s*([\d.]+)"\s*([NS])\s+(\d+)°\s*(\d+)\'\s*([\d.]+)"\s*([EW])/u', $value, $m)) {
        $lat = $m[1] + $m[2]/60 + $m[3]/3600;
        $lng = $m[5] + $m[6]/60 + $m[7]/3600;
        if ($m[4] === 'S') $lat = -$lat;
        if ($m[8] === 'W') $lng = -$lng;
        return ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'notes' => null];
    }

    // Case 2: Google Maps link
    if (str_starts_with($value, 'http')) {
        return ['lat' => null, 'lng' => null, 'notes' => 'GPS: '.$value];
    }

    // Case 3: teks biasa / kosong
    return ['lat' => null, 'lng' => null, 'notes' => null];
}

private function resolveCluster(string $value, int $ownerId): int
{
    $value = trim($value);
    if ($value === '') $value = 'Uncategorized';

    // Cari cluster existing milik owner ini
    $cluster = \App\Models\Cluster::where('owner_id', $ownerId)
        ->where(function($q) use ($value) {
            $q->where('name', $value)
              ->orWhere('name', 'LIKE', "%{$value}%");
        })
        ->first();

    if (!$cluster) {
        $name = is_numeric($value) ? "Cluster {$value}" : $value;
        $cluster = \App\Models\Cluster::create([
            'name' => $name,
            'owner_id' => $ownerId,
            'is_active' => true,
        ]);
    }

    return $cluster->id;
}
```

### Update kolom mapping di KioskImporter:

Kolom yang di-map dari format abang:

- `nama_kios` atau `nama` → name (wajib)
- `cluster` atau `b` → cluster_id (via resolveCluster)
- `qty_mika` atau `e` → default_qty_mika
- `deskripsi_lokasi` atau `f` atau `alamat` → location_description
- `koordinat_atau_link` atau `g` → parse via parseKoordinat → lat, lng, notes
- `tanggal_pertama_titip` atau `i` → first_titip_date (parse DD/MM/YYYY)
- `pemilik` → owner_name (nullable, kosong jika tidak ada)
- `telepon` → phone (nullable)
- `lat` → latitude (direct, kalau ada)
- `lng` → longitude (direct, kalau ada)

owner_id di-set dari auth()->user() yang melakukan import.
is_active = true default.

### first_titip_date parsing:

```php
// Handle DD/MM/YYYY format dari spreadsheet abang
if ($value && preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $value, $m)) {
    return \Carbon\Carbon::createFromDate($m[3], $m[2], $m[1])->toDateString();
}
return null; // kalau kosong = null (bukan today())
```

## BUAT TEMPLATE CSV

Buat file: public/templates/kiosk-import-template.csv
Buat file: public/templates/kiosk-import-template-aidil.csv (format abang)

Template standar (format baru yang direkomendasikan):
nama,pemilik,cluster,qty_mika,telepon,alamat,lat,lng,tanggal_pertama_titip
Contoh Kedai Bu Sari,Bu Sari,Karya,3,081234567890,"Jl. Karya No. 1 Medan",3.604311,98.665020,26/02/2025

Template format abang (sesuai spreadsheet existing):
nomor,cluster,nama_kios,qty_mika,deskripsi_lokasi,koordinat_atau_link,tanggal_pertama_titip
1,1,"Karya 1 (masjid)",3,"Jl. Karya (disamping Masjid)","3° 36' 15.5196"" N 98° 39' 54.072"" E",26/02/2025

## STEP EKSEKUSI

1. Baca KioskImporter existing (full)
2. Tambah helper parseKoordinat() + resolveCluster()
3. Update column definitions + resolveRecord()
4. Buat public/templates/ folder + 2 template CSV
5. php artisan test --compact (target 83+ PASS)
6. Commit:
   git add app/Filament/Imports/KioskImporter.php public/templates/
   git commit -m "feat(import): KioskImporter handle format spreadsheet abang — DMS parser + cluster auto-create + template CSV"
   git push origin main

## STOP POINTS — TANYA ADVISOR KALAU

1. KioskImporter tidak punya resolveRecord() atau struktur berbeda
2. owner_id tidak accessible di KioskImporter context
3. Test turun dari 83 PASS
4. DMS regex tidak match format abang (ada variasi format)

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---
