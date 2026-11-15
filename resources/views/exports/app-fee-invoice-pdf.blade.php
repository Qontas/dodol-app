<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $number ?? 'DRAFT' }}</title>
    @php
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $bln = fn (string $ymd) => \App\Support\IdDate::tanggal(\Illuminate\Support\Carbon::parse($ymd));
        $hari = fn (string $ymd) => \App\Support\IdDate::tanggalHari(\Illuminate\Support\Carbon::parse($ymd));
        $statusLabel = ['draft' => 'Draft', 'issued' => 'Terbit', 'paid' => 'Lunas', 'void' => 'Dibatalkan'][$mode];
    @endphp
    <style>
        /* Font OFL terdaftar lewat @font-face (subsetting aktif): Fraunces untuk "INVOICE", total tagihan dan nama
           penerbit (serumpun dengan logo); Lato untuk isi. Lato memuat – — · dan Rp. */
        @font-face { font-family: 'Fraunces'; font-weight: bold; font-style: normal; src: url('{{ $fonts['fraunces_bold'] }}') format('truetype'); }
        @font-face { font-family: 'Lato'; font-weight: normal; font-style: normal; src: url('{{ $fonts['lato'] }}') format('truetype'); }
        @font-face { font-family: 'Lato'; font-weight: bold; font-style: normal; src: url('{{ $fonts['lato_bold'] }}') format('truetype'); }
        @font-face { font-family: 'Lato'; font-weight: normal; font-style: italic; src: url('{{ $fonts['lato_italic'] }}') format('truetype'); }

        /* Palet: gula aren #3F2414 (teks/garis tegas), karamel #D98A2B (aksen tunggal), krem #F5ECDD (hanya
           latar kotak pembayaran) + netral. Tanpa gradien/bayangan/emoji/ikon. Garis hairline. */
        @page { margin: 30pt 44pt 48pt 44pt; }
        * { font-family: 'Lato', 'DejaVu Sans', sans-serif; }
        body { margin: 0; font-size: 9pt; color: #3F2414; line-height: 1.35; }
        .muted { color: #7a6a5e; }
        .serif { font-family: 'Fraunces', 'DejaVu Serif', serif; font-weight: bold; }
        .label { font-size: 6.8pt; letter-spacing: .12em; text-transform: uppercase; color: #7a6a5e; }
        table { border-collapse: collapse; }
        .r { text-align: right !important; }

        .head { width: 100%; }
        .head td { vertical-align: top; padding: 0; }
        .head .right { text-align: right; }
        .logo { width: 128pt; }
        .doc-title { font-size: 21pt; letter-spacing: .14em; color: #3F2414; margin: 0 0 4pt; line-height: 1.05; }
        .meta { margin-left: auto; font-size: 8.3pt; }
        .meta td { padding: .5pt 0 .5pt 12pt; text-align: right; }
        .meta td.k { color: #7a6a5e; }
        .rule { border: 0; border-top: 1.2pt solid #D98A2B; margin: 6pt 0 7pt; }

        .parties { width: 100%; margin-bottom: 6pt; }
        .parties td { vertical-align: top; width: 50%; padding: 0 12pt 0 0; }
        .parties .name { font-size: 10.5pt; margin: 2pt 0 1pt; }
        .parties div { line-height: 1.3; }
        .period { font-size: 9.5pt; }

        table.lines { width: 100%; }
        table.lines thead { display: table-header-group; }
        table.lines tr { page-break-inside: avoid; }
        table.lines th { font-size: 7pt; letter-spacing: .1em; text-transform: uppercase; color: #7a6a5e; font-weight: normal;
            text-align: left; padding: 0 0 3.5pt; border-bottom: .9pt solid #3F2414; }
        table.lines td { padding: 1.2pt 0; border-bottom: .4pt solid #d9cfc2; line-height: 1.2; font-size: 8.8pt; }
        .rate-note { margin: 5pt 0 0; font-size: 8pt; color: #7a6a5e; }

        /* Satu kesatuan (tidak pernah terpotong antar halaman): pembayaran kiri, total + terbilang kanan. */
        table.summary { width: 100%; margin-top: 6pt; page-break-inside: avoid; }
        table.summary > tbody > tr > td { vertical-align: top; padding: 0; }
        .pay { background: #F5ECDD; padding: 6pt 12pt 7pt; margin-right: 14pt; }
        .pay .bank { font-size: 9.5pt; font-weight: bold; margin: 1pt 0 0; }
        .pay .acc { font-size: 12pt; font-weight: bold; letter-spacing: .04em; margin: 0; }
        .pay .due { margin-top: 3pt; }
        .total-box { border-top: .9pt solid #3F2414; padding-top: 5pt; text-align: right; }
        .total-amount { font-size: 19pt; color: #3F2414; margin: 1pt 0 2pt; line-height: 1.1; }
        .terbilang { font-size: 8pt; font-style: italic; color: #7a6a5e; line-height: 1.25; }
        .sub-total td { padding: 0; font-size: 8.5pt; }

        .footnote { margin-top: 4pt; font-size: 8pt; color: #7a6a5e; page-break-inside: avoid; }

        .period-row { width: 100%; margin-bottom: 5pt; }
        .period-row td { padding: 0; vertical-align: middle; }
        .stamp { display: inline-block; border: 1.1pt solid; padding: 1.5pt 9pt; font-size: 11pt; font-weight: bold;
            letter-spacing: .1em; line-height: 1.15; }
        .stamp small { font-size: 7.5pt; letter-spacing: .04em; font-weight: normal; }
        .stamp.paid { color: #1F5F3A; border-color: #1F5F3A; }
        .stamp.void { color: #8B1E1E; border-color: #8B1E1E; }
        .void-reason { margin-top: 4pt; font-size: 8pt; color: #8B1E1E; page-break-inside: avoid; }

        /* Watermark DI BELAKANG konten: z-index negatif membuat dompdf mengecat lebih dulu; warna sangat pudar. */
        .watermark { position: fixed; z-index: -10; top: 360pt; left: 0; width: 507pt; text-align: center; transform: rotate(-32deg);
            font-size: 36pt; font-weight: bold; white-space: nowrap; letter-spacing: .03em; color: #efebe5; }
    </style>
</head>
<body>
    @if ($mode === 'draft')
        <div class="watermark">DRAFT — SEMENTARA</div>
    @endif
    <table class="head">
        <tr>
            <td style="width:50%;"><img class="logo" src="{{ $logo }}" alt="Cemilan Qontas"></td>
            <td class="right">
                <p class="doc-title serif">INVOICE</p>
                <table class="meta">
                    <tr><td class="k">Nomor</td><td><b>{{ $number ?? 'DRAFT — belum bernomor' }}</b></td></tr>
                    <tr><td class="k">Tanggal terbit</td><td>{{ $issued_at ? $bln($issued_at->toDateString()) : '—' }}</td></tr>
                    <tr><td class="k">Jatuh tempo</td><td>{{ $due_date ? $bln($due_date->toDateString()) : '—' }}</td></tr>
                    <tr><td class="k">Status</td><td><b>{{ $statusLabel }}</b></td></tr>
                </table>
            </td>
        </tr>
    </table>
    <hr class="rule">

    <table class="parties">
        <tr>
            <td>
                <div class="label">Dari</div>
                <div class="name serif">{{ $issuer['issuer_name'] }}</div>
                @if ($issuer['address'])<div>{!! nl2br(e($issuer['address'])) !!}</div>@endif
                @if ($issuer['phone'])<div class="muted">WA {{ $issuer['phone'] }}</div>@endif
                @if ($issuer['email'])<div class="muted">{{ $issuer['email'] }}</div>@endif
            </td>
            <td>
                <div class="label">Ditagihkan kepada</div>
                <div class="name" style="font-weight:bold;">{{ $billed_to['business'] }}</div>
                @if ($show_attention)
                    <div class="muted">u.p. {{ $billed_to['owner_name'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    @php
        $stamp = match ($mode) {
            'paid' => ['paid', 'LUNAS', $bln($paid_at->toDateString())],
            'void' => ['void', 'DIBATALKAN', $bln($voided_at->toDateString())],
            default => null,
        };
    @endphp
    {{-- Periode kiri, stempel status (satu baris ringkas) kanan — hemat tinggi supaya 20 baris tetap 1 halaman. --}}
    <table class="period-row">
        <tr>
            <td class="period"><span class="label">Periode&nbsp;&nbsp;</span><b>{{ $period_range }}</b></td>
            @if ($stamp)
                <td class="r"><span class="stamp {{ $stamp[0] }}">{{ $stamp[1] }}&nbsp;<small>{{ $stamp[2] }}</small></span></td>
            @endif
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th class="r">Mika kena fee</th>
                <th class="r">Fee (Rp)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $l)
                <tr>
                    <td>{{ $hari($l['date']) }}</td>
                    <td class="r">{{ $rp($l['mika']) }}</td>
                    <td class="r">{{ $rp($l['fee']) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted" style="padding:12pt 0;">Tidak ada mika kena fee di periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($uniform_rate !== null)
        <p class="rate-note">Tarif Rp {{ $rp($uniform_rate) }}/mika</p>
    @endif

    <table class="summary">
        <tr>
            <td style="width:50%;">
                <div class="pay">
                    <div class="label">Pembayaran ke</div>
                    @if ($issuer['bank_name'] || $issuer['account_number'])
                        <div class="bank">{{ $issuer['bank_name'] }}</div>
                        <div class="acc">{{ $issuer['account_number'] }}</div>
                        @if ($issuer['account_holder'])<div>a.n. {{ $issuer['account_holder'] }}</div>@endif
                    @else
                        <div class="muted" style="margin-top:2pt;">Rekening belum diisi di Pengaturan Invoice.</div>
                    @endif
                    <div class="due"><span class="label">Jatuh tempo&nbsp;&nbsp;</span><b>{{ $due_date ? $bln($due_date->toDateString()) : 'Dihitung saat terbit' }}</b></div>
                </div>
            </td>
            <td class="total-box">
                <table class="sub-total" style="width:100%;">
                    <tr><td class="muted" style="text-align:left;">Total mika kena fee</td><td class="r">{{ $rp($total_mika) }}</td></tr>
                </table>
                <div class="label" style="margin-top:4pt;">Total tagihan</div>
                <div class="total-amount serif">Rp {{ $rp($total_fee) }}</div>
                <div class="terbilang">{{ $terbilang }}</div>
            </td>
        </tr>
    </table>

    @if ($mode === 'void' && $void_reason)
        <div class="void-reason"><b>Alasan pembatalan:</b> {{ $void_reason }}</div>
    @endif

    @if ($mode === 'paid' && $paid_note)
        <div class="footnote"><b>Pembayaran:</b> {{ $paid_note }}</div>
    @endif

    @if ($issuer['footer_note'])
        <div class="footnote">{!! nl2br(e($issuer['footer_note'])) !!}</div>
    @endif
</body>
</html>
