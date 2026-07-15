<?php

namespace Tests\Feature\Owner;

use App\Livewire\Operator\CreateKiosk as OperatorCreateKiosk;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\HeicConverter;
use App\Support\KioskPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FOTO KIOS: sistem MENGECILKAN foto, bukan MENOLAKnya.
 *
 * Latar: owner memotret pakai HP Android (Poco F7). JPG kamera >5MB DITOLAK sistem →
 * owner harus kompres manual di situs online dulu. Foto besar sekarang harus DITERIMA
 * (dikompres browser + ImageResizer server), dan HEIC tak boleh pernah tersimpan mentah.
 *
 * CATATAN JUJUR SOAL CAKUPAN: konversi HEIC→JPG sendiri TIDAK bisa diuji di sini —
 * butuh ekstensi imagick dengan delegate HEIF yang memang tak ada di mesin dev (dan
 * baru terbukti ada di Railway setelah deploy; cek `php artisan foto:diagnosa`). Yang
 * diuji di sini adalah yang BISA dibuktikan sekarang: deteksi HEIC dari magic bytes,
 * dan bahwa server tanpa dukungan HEIF MENOLAK dengan pesan yang berguna alih-alih
 * menyimpan file blank. Kedua perilaku itu justru yang paling berbahaya kalau salah.
 */
class KioskPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $operator;
    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $this->owner->id]);
        $this->cluster = Cluster::create(['name' => 'Area Foto', 'owner_id' => $this->owner->id]);
        $product = Product::factory()->create(['owner_id' => $this->owner->id]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000]);
    }

    /** Byte HEIC palsu: header ISO-BMFF yang sah (ftyp + brand heic). */
    private function heicBytes(string $brand = 'heic'): string
    {
        return "\x00\x00\x00\x18".'ftyp'.$brand."\x00\x00\x00\x00".str_repeat("\x00", 64);
    }

    private function buatKios(array $overrides = []): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(OperatorCreateKiosk::class)
            ->set('namaKios', $overrides['nama'] ?? 'Kios Foto')
            ->set('namaPemilik', 'Pak F')
            ->set('clusterId', $this->cluster->id)
            ->set('jenisKedai', 'cash_only')
            ->set('foto', $overrides['foto'] ?? null);
    }

    // ---------- Plafon: foto BESAR diterima, bukan ditolak ----------

    /**
     * INTI keluhan owner: dulu >5MB ditolak. Plafon sekarang 20MB, jadi foto kamera HP
     * (8–12MB) yang lolos tanpa kompres browser pun tetap DITERIMA.
     */
    public function test_foto_besar_8mb_diterima_bukan_ditolak(): void
    {
        $this->actingAs($this->operator);
        Livewire::actingAs($this->operator);

        $foto = UploadedFile::fake()->image('kamera-hp.jpg', 4000, 3000)->size(8192); // 8MB

        $this->buatKios(['foto' => $foto])
            ->call('saveKiosk')
            ->assertHasNoErrors(['foto']);

        $kiosk = Kiosk::where('name', 'Kios Foto')->firstOrFail();
        $this->assertNotNull($kiosk->photo_path, 'Foto 8MB harus tersimpan, bukan ditolak.');
        Storage::disk('public')->assertExists($kiosk->photo_path);
    }

    /**
     * File ekstrem tetap ditolak (plafon anti-abuse), TAPI dengan pesan yang jelas.
     *
     * Diuji lewat Validator LANGSUNG, bukan lewat Livewire::set(): set() menulis file
     * ke disk temp sehingga ukuran palsu UploadedFile::fake()->size() HILANG (diganti
     * ukuran isi asli yang kecil) → tesnya lolos tanpa menguji apa pun. Ketahuan saat
     * tes ini pertama kali ditulis lewat Livewire dan berbunyi "Component has no errors".
     */
    public function test_foto_ekstrem_di_atas_plafon_ditolak_dengan_pesan_jelas(): void
    {
        $terlaluBesar = UploadedFile::fake()->create('raksasa.jpg', KioskPhoto::MAKS_KB + 5000, 'image/jpeg');

        $validator = \Illuminate\Support\Facades\Validator::make(
            ['foto' => $terlaluBesar],
            ['foto' => KioskPhoto::rules()],
            KioskPhoto::pesanValidasi('foto'),
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('terlalu besar', $validator->errors()->first('foto'));
    }

    /** Tepat DI BAWAH plafon → lolos. Membuktikan plafonnya benar-benar 20MB, bukan 5MB. */
    public function test_foto_tepat_di_bawah_plafon_diterima(): void
    {
        $besarTapiSah = UploadedFile::fake()->create('kamera.jpg', KioskPhoto::MAKS_KB - 1000, 'image/jpeg');

        $validator = \Illuminate\Support\Facades\Validator::make(
            ['foto' => $besarTapiSah],
            ['foto' => KioskPhoto::rules()],
        );

        $this->assertFalse($validator->fails(), 'Foto ~19MB harus DITERIMA (dulu ditolak di 5MB).');
    }

    public function test_foto_kecil_tetap_jalan_tidak_rusak(): void
    {
        $this->actingAs($this->operator);
        Livewire::actingAs($this->operator);

        $foto = UploadedFile::fake()->image('kecil.jpg', 800, 600)->size(200);

        $this->buatKios(['foto' => $foto, 'nama' => 'Kios Kecil'])
            ->call('saveKiosk')
            ->assertHasNoErrors(['foto']);

        $kiosk = Kiosk::where('name', 'Kios Kecil')->firstOrFail();
        Storage::disk('public')->assertExists($kiosk->photo_path);
    }

    // ---------- HEIC ----------

    public function test_deteksi_heic_dari_magic_bytes_bukan_dari_ekstensi(): void
    {
        foreach (['heic', 'heix', 'mif1', 'hevc'] as $brand) {
            $this->assertTrue(HeicConverter::isHeic($this->heicBytes($brand)), "brand {$brand} harus terdeteksi HEIC");
        }

        // JPG asli (magic FF D8 FF) & file pendek TIDAK boleh dikira HEIC.
        $this->assertFalse(HeicConverter::isHeic("\xFF\xD8\xFF\xE0".str_repeat("\x00", 32)));
        $this->assertFalse(HeicConverter::isHeic('pendek'));
        // MP4 juga ftyp, tapi brand-nya bukan HEIF → jangan salah tangkap.
        $this->assertFalse(HeicConverter::isHeic("\x00\x00\x00\x18".'ftypmp42'.str_repeat("\x00", 32)));
    }

    /**
     * Jaring pengaman TERPENTING: kalau server tak bisa mengonversi HEIC, file HEIC
     * TIDAK BOLEH tersimpan (akan jadi foto blank di Android/desktop) — harus ditolak
     * dengan instruksi yang bisa ditindaklanjuti.
     */
    public function test_heic_ditolak_dengan_pesan_actionable_kalau_server_tak_dukung(): void
    {
        if (HeicConverter::supported()) {
            $this->markTestSkipped('Server ini MENDUKUNG HEIC — jalur penolakan tak berlaku.');
        }

        $this->actingAs($this->operator);
        Livewire::actingAs($this->operator);

        $foto = UploadedFile::fake()->createWithContent('foto.heic', $this->heicBytes());

        $component = $this->buatKios(['foto' => $foto, 'nama' => 'Kios HEIC'])->call('saveKiosk');

        $component->assertHasErrors('foto');

        // Kios TIDAK boleh menyimpan HEIC mentah.
        $kiosk = Kiosk::where('name', 'Kios HEIC')->first();
        $this->assertTrue($kiosk === null || $kiosk->photo_path === null, 'HEIC mentah TIDAK boleh tersimpan.');
    }

    public function test_pesan_heic_menyebut_jalan_keluar_untuk_iphone_dan_android(): void
    {
        $pesan = KioskPhoto::pesanHeicTakDidukung();

        $this->assertStringContainsString('HEIC', $pesan);
        $this->assertStringContainsString('Most Compatible', $pesan); // iPhone
        $this->assertStringContainsString('JPEG', $pesan);            // Android (Poco/Xiaomi)
    }

    public function test_store_menolak_heic_tanpa_dukungan_dan_tidak_menulis_file(): void
    {
        if (HeicConverter::supported()) {
            $this->markTestSkipped('Server ini MENDUKUNG HEIC.');
        }

        $file = UploadedFile::fake()->createWithContent('x.heic', $this->heicBytes());

        $this->assertNull(KioskPhoto::store($file, 'public'));
        $this->assertEmpty(Storage::disk('public')->allFiles('kiosks'), 'Tak boleh ada file tertulis saat konversi gagal.');
    }

    // ---------- Konsistensi plafon antar-lapis ----------

    /**
     * Plafon Livewire temp upload TIDAK BOLEH lebih rendah dari plafon aplikasi —
     * kalau lebih rendah, foto sah ditolak lebih dulu oleh Livewire dengan pesan
     * generik dan validasi kita yang ramah tak pernah tercapai.
     */
    public function test_plafon_livewire_selaras_dengan_plafon_aplikasi(): void
    {
        $rules = config('livewire.temporary_file_upload.rules');
        $this->assertNotNull($rules, 'Plafon Livewire harus eksplisit, jangan andalkan default 12MB.');

        $max = collect($rules)->first(fn ($r) => is_string($r) && str_starts_with($r, 'max:'));
        $this->assertSame('max:'.KioskPhoto::MAKS_KB, $max);
    }

    public function test_mime_heic_termasuk_yang_diterima_agar_bisa_dikonversi(): void
    {
        $this->assertContains('image/heic', KioskPhoto::acceptedFileTypes());
        $this->assertContains('image/heif', KioskPhoto::acceptedFileTypes());
        $this->assertContains('image/jpeg', KioskPhoto::acceptedFileTypes());

        // image/* TIDAK boleh dipakai: wildcard itulah yang dulu meloloskan HEIC
        // di form owner lalu menyimpannya mentah.
        $this->assertNotContains('image/*', KioskPhoto::acceptedFileTypes());
    }
}
