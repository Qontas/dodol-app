<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Kedai</title>
    @php
        $text = fn ($value) => \App\Support\KioskInsightsSummary::pdfText($value);
        $rp = fn ($value) => 'Rp '.number_format((float) $value, 0, ',', '.');
        $num = fn ($value) => $value === null ? '—' : number_format((float) $value, 1, ',', '.');
        $pct = fn ($value) => $value === null ? '—' : number_format((float) $value, 1, ',', '.').'%';
        $date = fn ($value) => $value ? \App\Support\IdDate::tanggal(\Illuminate\Support\Carbon::parse($value)) : 'Belum ada';
        $clip = fn ($value, $length = 160) => \Illuminate\Support\Str::limit($text($value), $length);
        $labelNames = ['insufficient' => 'Data belum cukup', 'fast' => 'Laris', 'stable' => 'Stabil', 'slow' => 'Lambat', 'problem' => 'Bermasalah', 'inactive' => 'Tidak aktif'];
        $reportName = ($data['all_owners'] ?? false) ? 'Semua owner' : ($data['business_name'] ?? '');
    @endphp
    <style>
        @font-face { font-family: 'Fraunces'; font-weight: bold; src: url('{{ $fonts['fraunces_bold'] }}') format('truetype'); }
        @font-face { font-family: 'Lato'; font-weight: normal; src: url('{{ $fonts['lato'] }}') format('truetype'); }
        @font-face { font-family: 'Lato'; font-weight: bold; src: url('{{ $fonts['lato_bold'] }}') format('truetype'); }
        @page { margin: 30pt 36pt 42pt; }
        body { font-family: 'Lato', sans-serif; font-size: 8pt; line-height: 1.3; color: #3F2414; margin: 0; overflow-wrap: break-word; }
        .serif, h1, h2 { font-family: 'Fraunces', serif; font-weight: bold; }
        h1 { font-size: 23pt; margin: 0; }
        h2 { font-size: 14pt; margin: 13pt 0 5pt; page-break-after: avoid; }
        h2 + p { page-break-after: avoid; }
        .muted { color: #7A6A5E; }
        .small { font-size: 7pt; }
        .right { text-align: right; }
        .head { width: 100%; border-bottom: 1.2pt solid #D98A2B; margin-bottom: 9pt; }
        .head td { vertical-align: middle; padding: 0 0 7pt; }
        .logo { width: 108pt; }
        .eyebrow { text-transform: uppercase; letter-spacing: .1em; font-size: 7pt; }
        table { border-collapse: collapse; }
        .kpis { width: 100%; margin: 9pt 0; }
        .kpis td { width: 33.33%; padding: 7pt 9pt; border: .5pt solid #D9CFC2; }
        .kpis .value { font-family: 'Fraunces', serif; font-size: 16pt; margin-top: 2pt; }
        .data { width: 100%; table-layout: fixed; }
        .data thead { display: table-header-group; }
        .data th { text-align: left; font-size: 7pt; padding: 4pt; border-bottom: 1pt solid #3F2414; background: #F5ECDD; }
        .data td { padding: 4pt; border-bottom: .4pt solid #D9CFC2; vertical-align: top; word-wrap: break-word; }
        .data tr { page-break-inside: avoid; }
        .labels { margin: 7pt 0; }
        .labels span { display: inline-block; margin: 0 9pt 4pt 0; }
        .note { padding: 7pt 9pt; background: #F5ECDD; margin: 8pt 0; font-size: 7.3pt; }
        .page { page-break-before: always; }
        .group { margin: 0 0 8pt; padding-bottom: 5pt; border-bottom: .7pt solid #D9CFC2; }
        .cards { width: 100%; table-layout: fixed; }
        .cards > tbody > tr > td { width: 50%; padding: 0 5pt 8pt 0; vertical-align: top; }
        .cards > tbody > tr > td + td { padding: 0 0 8pt 5pt; }
        .card { border: .6pt solid #D9CFC2; padding: 8pt; height: 195pt; }
        .card h3 { font-family: 'Fraunces', serif; font-size: 12pt; line-height: 1.1; margin: 0 0 4pt; }
        .photo { width: 63pt; height: 48pt; object-fit: contain; }
        .photo-empty { width: 63pt; height: 38pt; padding-top: 10pt; text-align: center; background: #F5ECDD; font-size: 6.8pt; }
        .identity { width: 100%; table-layout: fixed; }
        .identity td { vertical-align: top; }
        .identity .image { width: 70pt; }
        .badge { display: inline-block; font-size: 7pt; font-weight: bold; padding: 2pt 5pt; background: #F5ECDD; border-left: 2pt solid #7A6A5E; }
        .badge.fast { border-left-color: #1F5F3A; }
        .badge.slow { border-left-color: #A36813; }
        .badge.problem { border-left-color: #8B1E1E; }
        .metrics { width: 100%; margin-top: 3pt; font-size: 7.1pt; }
        .metrics td { padding: 1.5pt 0; border-bottom: .3pt solid #E8E0D5; vertical-align: top; }
        .metrics td:first-child { color: #7A6A5E; width: 46%; }
        .suggest { margin-top: 5pt; padding-top: 4pt; border-top: .6pt solid #D98A2B; font-size: 7pt; }
        a { color: #3F2414; text-decoration: underline; }
    </style>
</head>
<body>
@if ($includeSummary)
<table class="head"><tr>
    <td style="width: 28%"><img class="logo" src="{{ $logo }}" alt="Logo"></td>
    <td class="right"><div class="eyebrow">{{ $text($reportName) }}</div><h1>Laporan Kedai</h1>
        <div class="muted">Per {{ $date($data['as_of']) }}</div>
    </td>
</tr></table>
@endif
@if ($includeSummary)
    <div class="eyebrow">Ringkasan usaha</div>
    <p class="muted small">Periode: {{ ($data['period_start'] ?? null) ? $date($data['period_start']) : 'Seluruh riwayat' }} s.d. {{ $date($data['as_of']) }} · Dibuat {{ $text($data['generated_at'] ?? '') }}</p>
    <table class="kpis">
        <tr>
            <td><div class="muted">Kedai aktif / total</div><div class="value">{{ $summary['totals']['active'] }} / {{ $summary['totals']['kiosks'] }}</div></td>
            <td><div class="muted">Omset 30 hari</div><div class="value">{{ $rp($summary['totals']['revenue_30']) }}</div></td>
            <td><div class="muted">Rerata sell-through</div><div class="value">{{ $pct($summary['avg_sell_through_pct']) }}</div></td>
        </tr>
        <tr>
            <td><div class="muted">Stok titipan sekarang</div><div class="value">{{ $num($summary['totals']['pending_packs']) }} mika</div></td>
            <td><div class="muted">Nilai titipan sekarang</div><div class="value">{{ $rp($summary['totals']['pending_value']) }}</div></td>
            <td><div class="muted">Piutang sekarang</div><div class="value">{{ $rp($summary['totals']['receivable']) }}</div></td>
        </tr>
    </table>
    <div class="labels">
        @foreach ($summary['labels'] as $key => $count)
            <span><b>{{ $text($data['rules']['labels'][$key] ?? $labelNames[$key]) }}</b> {{ $count }}</span>
        @endforeach
    </div>
    <div class="note">Titipan belum selesai adalah stok, bukan piutang. Stok, piutang, harga, jatah dan identitas adalah posisi saat laporan dibuat. Omset dan kunjungan mengikuti periode; kolom kosong bukan nol. Rerata sell-through hanya dari kedai konsinyasi dengan data.</div>
    <h2>Ringkasan per area</h2>
    <table class="data"><thead><tr><th style="width: 35%">Usaha / Area</th><th>Kedai / aktif</th><th class="right">Omset 30 hari</th><th class="right">Nilai titipan</th><th class="right">Piutang</th></tr></thead><tbody>
        @forelse ($summary['areas'] as $area)
            <tr><td>{{ $clip($area['business_name'], 60) }}<br><b>{{ $clip($area['area'], 60) }}</b></td><td>{{ $area['kiosks'] }} / {{ $area['active'] }}</td><td class="right">{{ $rp($area['revenue_30']) }}</td><td class="right">{{ $rp($area['pending_value']) }}</td><td class="right">{{ $rp($area['receivable']) }}</td></tr>
        @empty
            <tr><td colspan="5">Belum ada kedai dalam cakupan laporan.</td></tr>
        @endforelse
    </tbody></table>
    @foreach (['top10' => '10 kedai tercepat', 'slow10' => '10 kedai terlambat'] as $key => $heading)
        <h2>{{ $heading }}</h2>
        <p class="muted small">Hanya konsinyasi dengan sedikitnya {{ $data['rules']['min_settlements'] ?? 3 }} siklus selesai. Urut laju biji per minggu, bukan omset.</p>
        <table class="data"><thead><tr><th style="width: 41%">Kedai / Usaha / Area</th><th class="right">Biji / minggu</th><th class="right">Sell-through</th><th class="right">Siklus</th><th>Label</th></tr></thead><tbody>
            @forelse ($summary[$key] as $r)
                <tr><td><b>{{ $clip($r['name'], 55) }}</b><br><span class="muted small">{{ $clip($r['business_name'].' / '.$r['area'], 65) }}</span></td><td class="right">{{ $num($r['weekly_sales']) }}</td><td class="right">{{ $pct($r['sell_through_pct']) }}</td><td class="right">{{ $r['settled_count'] }}</td><td>{{ $clip($r['label'], 35) }}</td></tr>
            @empty
                <tr><td colspan="5">Belum ada kedai yang memenuhi syarat.</td></tr>
            @endforelse
        </tbody></table>
    @endforeach
    <h2>Cara membaca laporan</h2>
    <div class="note">Performa hanya untuk Konsinyasi, bukan Cash/Booking. Settlement termasuk pembayaran tertunda tetap dihitung; stok belum diselesaikan tidak masuk penilaian. Laju ditimbang durasi siklus selesai, termasuk siklus terjual nol. Trip titipan awal/sentinel masuk stok, bukan kunjungan. Retur/BS mencakup retur segar dan kedaluwarsa; redistribusi tidak menilai performa konsinyasi. Ambang: data minimum {{ $data['rules']['min_settlements'] ?? 3 }} siklus, lambat &lt;{{ $data['rules']['slow_sell_through_pct'] ?? 50 }}%, laris ≥{{ $data['rules']['fast_sell_through_pct'] ?? 80 }}% dan persentil laju {{ $data['rules']['fast_percentile'] ?? 75 }}, BS ≥{{ $data['rules']['problem_bs_pct'] ?? 25 }}%, umur piutang {{ $data['rules']['debt_age_days'] ?? 30 }} hari. Label mengikuti prioritas aturan; saran bukan perubahan jatah otomatis.</div>
@endif
@foreach ($catalogPages as $page)
    @if ($includeSummary || ! $loop->first)<div class="page"></div>@endif
    <div class="group"><span class="eyebrow">Katalog kedai</span><h2 style="margin-top: 2pt">{{ $clip($page[0]['business_name'], 90) }} / {{ $clip($page[0]['area'] ?: 'Tanpa area', 90) }}</h2></div>
    <table class="cards"><tbody>
    @foreach (array_chunk($page, 2) as $pair)
        <tr>
        @foreach ($pair as $r)
            @php
                $photo = \App\Support\KioskInsightsSummary::thumbnail($r);
                $photoStatus = \App\Support\KioskInsightsSummary::photoStatus($r);
                $maps = \App\Support\KioskInsightsSummary::mapsUrl($r['maps_url'] ?? null);
                $labelKey = in_array($r['label_key'] ?? '', array_keys($labelNames), true) ? $r['label_key'] : 'insufficient';
            @endphp
            <td><div class="card">
                <table class="identity"><tr><td>
                    <h3>{{ $clip($r['name'], 52) }}</h3>
                    <div class="badge {{ $labelKey }}">{{ $clip($r['label'], 32) }}</div>
                    <div class="small muted" style="margin-top: 4pt">{{ $text($r['type']) }} · {{ ($r['is_active'] ?? false) ? 'Aktif' : 'Nonaktif' }}</div>
                </td><td class="image">
                    @if ($photo)<img class="photo" src="{{ $photo }}" alt="Foto kedai">@else<div class="photo-empty">{{ $photoStatus }}</div>@endif
                </td></tr></table>
                <div class="small" style="margin-top: 4pt">{{ $clip(($r['owner_name'] ?? '').' · '.($r['phone'] ?? ''), 65) }}</div>
                <div class="small muted">{{ $clip($r['address'] ?? '', 100) }} @if ($maps)<a href="{{ $maps }}">Maps</a>@endif</div>
                <table class="metrics">
                    <tr><td>Harga / Jatah</td><td>{{ $rp($r['price_per_pack'] ?? 0) }} / {{ $num($r['default_qty_mika'] ?? 0) }} mika</td></tr>
                    <tr><td>Kunjungan terakhir</td><td>{{ $date($r['last_visit_at'] ?? null) }} · {{ $clip($r['last_operator'] ?? '', 25) }}</td></tr>
                    <tr><td>Laju / Sell-through</td><td>{{ $num($r['weekly_sales'] ?? null) }} biji/mgg · {{ $pct($r['sell_through_pct'] ?? null) }}</td></tr>
                    <tr><td>Titipan / Umur</td><td>{{ $num($r['pending_packs'] ?? 0) }} mika · {{ $num($r['pending_age_days'] ?? null) }} hari</td></tr>
                    <tr><td>Piutang / Umur</td><td>{{ $rp($r['receivable'] ?? 0) }} · {{ $num($r['receivable_age_days'] ?? null) }} hari</td></tr>
                </table>
                <div class="suggest"><b>Saran:</b> {{ $clip(implode(' · ', $r['suggestions'] ?? []) ?: 'Belum ada saran khusus.', 175) }}</div>
            </div></td>
        @endforeach
        @if (count($pair) === 1)<td></td>@endif
        </tr>
    @endforeach
    </tbody></table>
@endforeach
@if (! $includeSummary && ! $catalogPages)<p>Belum ada kedai dalam cakupan laporan.</p>@endif
</body>
</html>
