# Brief: Extension Granted Flow

## KONTEKS

Day 6 dodol-app. saveVisit() + end trip sudah wired (45 PASS).
Tugas: implement extension_granted flow di ActiveTrip.php + view.
Commit target: feat(operator): extension granted — tunda settle + warning cut off

## BUSINESS RULES (LOCKED)

- Extension = kios minta tunda bayar + ambil BS ke kunjungan berikutnya
- Bisa terjadi di 2 situasi (Q1=C):
    1. drop_and_settle: harusnya settle lama + drop baru, tapi settle ditunda
    2. settle_only: harusnya settle saja, tapi settle ditunda
- Kalau extension granted: visit_action tetap dicatat (drop_and_settle atau settle_only)
  TAPI tidak buat row settlements → pendingDelivery tetap pending di kunjungan berikutnya
- extension_granted = true di kiosk_visits row
- Max extension: 2x per delivery. Lewat 2x → tampilkan warning "Pertimbangkan Cut Off"
  Warning = visual only (Rian tetap bisa lanjut, tidak hard block)

## HITUNG EXTENSION COUNT

Extension count per delivery = KioskVisit::where('settled_delivery_id', $deliveryId)
->where('extension_granted', true)->count()

Tampilkan di modal visit kalau pendingDelivery ada:

- Count 0: normal (tidak ada pesan)
- Count 1: "Perpanjangan ke-1 dari 2" (kuning/warning)
- Count >= 2: "⚠️ Sudah 2x perpanjangan — Pertimbangkan Cut Off" (merah)

## SCHEMA (verified)

kiosk_visits: id,trip_id,kiosk_id,visited_at,visit_action,new_delivery_id,
settled_delivery_id,extension_granted,notes,created_at,updated_at

extension_granted kolom sudah ada (boolean). Default false.

## PERUBAHAN DI ACTIVETRIIP.PHP

### Property baru

public bool $extensionGranted = false;
public int $extensionCount = 0;

### Update openVisitModal()

Setelah load pendingDelivery, hitung extensionCount:
if ($this->pendingDelivery) {
$this->extensionCount = KioskVisit::where('settled_delivery_id', $this->pendingDelivery->id)
->where('extension_granted', true)
->count();
} else {
$this->extensionCount = 0;
}
Reset: $this->extensionGranted = false;

### Update saveVisit()

Setelah resolve $action + $isSettle:

Kalau $extensionGranted === true:

- OVERRIDE: $isSettle = false (tidak buat settlement meskipun action = drop_and_settle/settle_only)
- extension_granted = true di KioskVisit
- Kalau $isDrop tetap true (drop_and_settle): tetap buat Delivery baru (drop jalan, settle saja yang ditunda)
- Kalau action = settle_only + extension: hanya buat KioskVisit (extension_granted=true), tidak buat delivery maupun settlement

Di KioskVisit::create():
'extension_granted' => $this->extensionGranted,

Kalau $extensionGranted === false: behavior existing (tidak berubah), extension_granted = false

## VIEW UPDATE (active-trip.blade.php)

### Di modal visit, section settlement (kalau pendingDelivery ada):

1. Tampilkan extension count warning SEBELUM form settle:
   @if($extensionCount >= 2)
    <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700 font-medium">
        ⚠️ Sudah 2x perpanjangan — Pertimbangkan Cut Off
    </div>
@elseif($extensionCount === 1)
   <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-700">
   Perpanjangan ke-1 dari 2
   </div>
   @endif

2. Tambah toggle extension di bawah form settle (sebelum tombol simpan):
 <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg border border-slate-200">
     <input type="checkbox" wire:model.live="extensionGranted" id="extensionToggle"
         class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
     <label for="extensionToggle" class="text-sm font-medium text-slate-700 cursor-pointer">
         Tunda bayar & ambil BS (perpanjangan)
     </label>
 </div>

3. Kalau extensionGranted = true: sembunyikan/grey-out form input returnFresh, returnExpired, uangDiterima (tidak relevan karena tidak settle)
   Cara: @if(!$extensionGranted) ... @endif di sekitar form settle inputs

## STEP EKSEKUSI

1. Update ActiveTrip.php:
    - Tambah properties extensionGranted + extensionCount
    - Update openVisitModal() tambah hitung extensionCount
    - Update saveVisit() handle extensionGranted logic
2. Update active-trip.blade.php:
    - Warning badge extension count
    - Toggle checkbox extensionGranted
    - Conditional hide form settle kalau extensionGranted
3. php artisan test --compact (target 45+ PASS)
4. Commit:
   git add app/Livewire/Operator/ActiveTrip.php resources/views/livewire/operator/active-trip.blade.php
   git commit -m "feat(operator): extension granted — tunda settle + warning cut off"

## STOP POINTS — TANYA ADVISOR KALAU

1. Logic extensionGranted konflik dengan 4 visit_action existing
2. Test turun dari 45 PASS
3. Schema extension_granted tipe beda dari boolean

JANGAN auto-decide business logic. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---
