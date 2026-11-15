<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $number ?? 'DRAFT' }}</title>
    @php
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $bln = fn (string $ymd) => \App\Support\IdDate::tanggal(\Illuminate\Support\Carbon::parse($ymd));
        $statusLabel = ['draft' => 'Draft', 'issued' => 'Terbit', 'paid' => 'Lunas', 'void' => 'Dibatalkan'][$mode];
    @endphp
    <style>
        /* Palet: gula aren #3F2414 (teks/garis tegas), karamel #D98A2B (aksen tunggal), krem #F5ECDD (hanya
           latar kotak pembayaran) + netral. Tanpa gradien/bayangan/emoji/ikon. Garis hairline 0.5pt. */
        @page { margin: 40pt 44pt 62pt 44pt; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; font-size: 9.5pt; color: #3F2414; line-height: 1.45; }
        .muted { color: #7a6a5e; }
        .label { font-size: 7pt; letter-spacing: .12em; text-transform: uppercase; color: #7a6a5e; }
        table { border-collapse: collapse; }

        .head { width: 100%; }
        .head td { vertical-align: top; padding: 0; }
        .head .right { text-align: right; }
        .doc-title { font-size: 22pt; font-weight: bold; letter-spacing: .14em; color: #3F2414; margin: 0 0 6pt; }
        .meta { margin-left: auto; font-size: 8.5pt; }
        .meta td { padding: 1pt 0 1pt 14pt; text-align: right; }
        .meta td.k { color: #7a6a5e; }
        .rule { border: 0; border-top: 1.5pt solid #D98A2B; margin: 14pt 0 16pt; }

        .parties { width: 100%; margin-bottom: 18pt; }
        .parties td { vertical-align: top; width: 50%; padding: 0 12pt 0 0; }
        .parties .name { font-size: 11pt; font-weight: bold; margin: 3pt 0 2pt; }
        .period { margin-bottom: 14pt; font-size: 10pt; }
        .period b { font-weight: bold; }

        table.lines { width: 100%; }
        table.lines thead { display: table-header-group; }
        table.lines tr { page-break-inside: avoid; }
        table.lines th { font-size: 7.5pt; letter-spacing: .1em; text-transform: uppercase; color: #7a6a5e; font-weight: normal;
            text-align: left; padding: 0 0 5pt; border-bottom: 1pt solid #3F2414; }
        table.lines td { padding: 4.5pt 0; border-bottom: .5pt solid #d9cfc2; }
        .r { text-align: right !important; }

        .rate-note { margin: 8pt 0 0; font-size: 8.5pt; color: #7a6a5e; }

        .total-wrap { width: 100%; margin-top: 16pt; page-break-inside: avoid; }
        .total-wrap td { vertical-align: top; padding: 0; }
        .total-box { border-top: 1pt solid #3F2414; padding-top: 8pt; text-align: right; }
        .total-amount { font-size: 21pt; font-weight: bold; color: #3F2414; margin: 2pt 0 3pt; }
        .terbilang { font-size: 8.5pt; font-style: italic; color: #7a6a5e; }
        .sub-total td { padding: 2pt 0; font-size: 9pt; }

        .pay { width: 100%; margin-top: 22pt; page-break-inside: avoid; background: #F5ECDD; }
        .pay td { padding: 11pt 14pt; vertical-align: top; }
        .pay .bank { font-size: 10.5pt; font-weight: bold; margin: 2pt 0 0; }
        .pay .acc { font-size: 13pt; font-weight: bold; letter-spacing: .04em; margin: 1pt 0; }

        .footnote { margin-top: 16pt; font-size: 8.5pt; color: #7a6a5e; page-break-inside: avoid; }

        .status-area { text-align: right; margin: 0 0 18pt; page-break-inside: avoid; }
        .stamp { display: inline-block; border: 1.5pt solid; padding: 5pt 14pt;
            font-size: 18pt; font-weight: bold; letter-spacing: .1em; text-align: center; }
        .stamp small { display: block; font-size: 8pt; letter-spacing: .06em; font-weight: normal; }
        .stamp.paid { color: #1F5F3A; border-color: #1F5F3A; }
        .stamp.void { color: #8B1E1E; border-color: #8B1E1E; }
        .void-reason { margin-top: 12pt; font-size: 8.5pt; color: #8B1E1E; page-break-inside: avoid; }

        .watermark { position: fixed; top: 330pt; left: 0; width: 507pt; text-align: center; transform: rotate(-32deg);
            font-size: 34pt; font-weight: bold; white-space: nowrap; letter-spacing: .02em; color: #e6e1da; }
    </style>
</head>
<body>
    @if ($mode === 'draft')
        <div class="watermark">DRAFT — SEMENTARA</div>
    @endif
    <table class="head">
        <tr>
            <td style="width:50%;"><img src="{{ $logo }}" style="width:150pt;" alt="Cemilan Qontas"></td>
            <td class="right">
                <p class="doc-title">INVOICE</p>
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
                <div class="name">{{ $issuer['issuer_name'] }}</div>
                @if ($issuer['address'])<div>{!! nl2br(e($issuer['address'])) !!}</div>@endif
                @if ($issuer['phone'])<div class="muted">WA {{ $issuer['phone'] }}</div>@endif
                @if ($issuer['email'])<div class="muted">{{ $issuer['email'] }}</div>@endif
            </td>
            <td>
                <div class="label">Ditagihkan kepada</div>
                <div class="name">{{ $billed_to['business'] }}</div>
                @if (($billed_to['owner_name'] ?? '') && $billed_to['owner_name'] !== $billed_to['business'])
                    <div class="muted">u.p. {{ $billed_to['owner_name'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if ($mode === 'paid')
        <div class="status-area"><div class="stamp paid">LUNAS<small>{{ $bln($paid_at->toDateString()) }}</small></div></div>
    @elseif ($mode === 'void')
        <div class="status-area"><div class="stamp void">DIBATALKAN<small>{{ $bln($voided_at->toDateString()) }}</small></div></div>
    @endif

    <div class="period"><span class="label">Periode&nbsp;&nbsp;</span><b>{{ $period_range }}</b></div>

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
                    <td>{{ $bln($l['date']) }}</td>
                    <td class="r">{{ $rp($l['mika']) }}</td>
                    <td class="r">{{ $rp($l['fee']) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted" style="padding:14pt 0;">Tidak ada mika kena fee di periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($uniform_rate !== null)
        <p class="rate-note">Tarif Rp {{ $rp($uniform_rate) }}/mika</p>
    @endif

    <table class="total-wrap">
        <tr>
            <td style="width:40%;"></td>
            <td class="total-box">
                <table class="sub-total" style="width:100%;">
                    <tr><td class="muted" style="text-align:left;">Total mika kena fee</td><td class="r">{{ $rp($total_mika) }}</td></tr>
                </table>
                <div class="label" style="margin-top:6pt;">Total tagihan</div>
                <div class="total-amount">Rp {{ $rp($total_fee) }}</div>
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

    <table class="pay">
        <tr>
            <td style="width:58%;">
                <div class="label">Pembayaran ke</div>
                @if ($issuer['bank_name'] || $issuer['account_number'])
                    <div class="bank">{{ $issuer['bank_name'] }}</div>
                    <div class="acc">{{ $issuer['account_number'] }}</div>
                    @if ($issuer['account_holder'])<div>a.n. {{ $issuer['account_holder'] }}</div>@endif
                @else
                    <div class="muted" style="margin-top:3pt;">Rekening belum diisi di Pengaturan Invoice.</div>
                @endif
            </td>
            <td class="r">
                <div class="label">Jatuh tempo</div>
                <div class="bank">{{ $due_date ? $bln($due_date->toDateString()) : 'Dihitung saat terbit' }}</div>
            </td>
        </tr>
    </table>

    @if ($issuer['footer_note'])
        <div class="footnote">{!! nl2br(e($issuer['footer_note'])) !!}</div>
    @endif
</body>
</html>
