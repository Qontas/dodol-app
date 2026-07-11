<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Trip Report</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 12px; color: #1e293b; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #64748b; }
        .header { border-bottom: 2px solid #d97706; padding-bottom: 8px; margin-bottom: 14px; }
        .meta td { padding: 2px 0; }
        table.data { width: 100%; border-collapse: collapse; margin: 6px 0 16px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; }
        table.data th { background: #f8fafc; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: #475569; }
        td.num { text-align: right; }
        .section-title { font-size: 13px; font-weight: bold; margin: 16px 0 4px; color: #0f172a; }
        .highlight { background: #fffbeb; font-weight: bold; }
        .footer { margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 8px; font-size: 10px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $businessName }}</h1>
        <div class="muted">Laporan Trip</div>
    </div>

    <table class="meta">
        <tr><td style="width:130px;">Tanggal Trip</td><td>: {{ $trip->trip_date->format('d M Y') }}</td></tr>
        <tr><td>Operator</td><td>: {{ $operatorName }}</td></tr>
        <tr><td>Area</td><td>: {{ $clusterName }}</td></tr>
        <tr><td>Trip ke-</td><td>: {{ $trip->trip_number_of_day }}</td></tr>
    </table>

    <div class="section-title">Ringkasan Mika</div>
    <table class="data">
        <tr><th>Mika Dibawa</th><th>Mika Dititip</th><th>Mika Sisa</th></tr>
        <tr>
            <td class="num">{{ number_format($summary['mika_dibawa'], 0, ',', '.') }}</td>
            <td class="num">{{ number_format($summary['mika_drop'], 0, ',', '.') }}</td>
            <td class="num">{{ number_format($summary['mika_sisa'], 0, ',', '.') }}</td>
        </tr>
    </table>

    <div class="section-title">Ringkasan Finansial</div>
    <table class="data">
        <tr><th>Keterangan</th><th style="text-align:right;">Nilai (Rp)</th></tr>
        <tr><td>Omset</td><td class="num">{{ number_format($finansial['omset'], 0, ',', '.') }}</td></tr>
        <tr><td>HPP</td><td class="num">{{ number_format($finansial['hpp'], 0, ',', '.') }}</td></tr>
        <tr><td>Untung Kotor</td><td class="num">{{ number_format($finansial['untung_kotor'], 0, ',', '.') }}</td></tr>
        <tr><td>Mika Komisi (Drop)</td><td class="num">{{ number_format($finansial['mika_komisi'], 0, ',', '.') }}</td></tr>
        <tr><td>Komisi Rian (Rp 1.000 &times; mika drop)</td><td class="num">{{ number_format($finansial['total_komisi'], 0, ',', '.') }}</td></tr>
        <tr class="highlight"><td>Untung Bersih Owner</td><td class="num">{{ number_format($finansial['untung_bersih'], 0, ',', '.') }}</td></tr>
    </table>

    <div class="section-title">Detail Kunjungan ({{ count($visits) }} kios)</div>
    <table class="data">
        <tr><th>Kios</th><th>Aksi</th><th>Waktu</th></tr>
        @forelse ($visits as $v)
            <tr>
                <td>{{ $v['kiosk'] }}</td>
                <td>{{ $v['action'] }}</td>
                <td>{{ $v['time'] }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">Tidak ada kunjungan tercatat.</td></tr>
        @endforelse
    </table>

    <div class="footer">
        Digenerate oleh sistem dodol-app &middot; {{ now()->format('d M Y H:i') }}
    </div>
</body>
</html>
