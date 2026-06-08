<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Bulanan</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1e293b; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #64748b; }
        .header { border-bottom: 2px solid #d97706; padding-bottom: 8px; margin-bottom: 14px; }
        table.data { width: 100%; border-collapse: collapse; margin: 6px 0 16px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 5px 7px; text-align: left; }
        table.data th { background: #f8fafc; font-size: 10px; text-transform: uppercase; color: #475569; }
        td.num, th.num { text-align: right; }
        tr.total td { background: #fffbeb; font-weight: bold; }
        .section-title { font-size: 13px; font-weight: bold; margin: 16px 0 4px; color: #0f172a; }
        .cards td { padding: 8px 10px; border: 1px solid #e2e8f0; background: #f8fafc; }
        .bar { background: #2563eb; height: 9px; display: inline-block; vertical-align: middle; }
        .footer { margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 8px; font-size: 10px; color: #94a3b8; text-align: center; }
        @php($maxOmset = collect($dailyOmset)->max('total') ?: 1)
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $businessName }}</h1>
        <div class="muted">Laporan Bulanan — {{ \Illuminate\Support\Carbon::parse($month.'-01')->translatedFormat('F Y') }}</div>
    </div>

    <table class="cards" style="width:100%; border-collapse:collapse; margin-bottom:14px;">
        <tr>
            <td>Total Kios Di-settle<br><strong>{{ $analisisKios['total_kios_settled'] }}</strong></td>
            <td>Kios Baru Bulan Ini<br><strong>{{ $analisisKios['kios_baru'] }}</strong></td>
            <td>Total Omset<br><strong>Rp {{ number_format($totals['omset'], 0, ',', '.') }}</strong></td>
            <td>Untung Bersih<br><strong>Rp {{ number_format($totals['untung_bersih'], 0, ',', '.') }}</strong></td>
        </tr>
    </table>

    <div class="section-title">Rekap Per Trip</div>
    <table class="data">
        <tr>
            <th>Tanggal</th><th>Operator</th><th class="num">Kios</th><th class="num">Mika Drop</th>
            <th class="num">Omset</th><th class="num">Komisi</th><th class="num">Untung Bersih</th>
        </tr>
        @forelse ($rows as $r)
            <tr>
                <td>{{ $r['trip_date'] }}</td>
                <td>{{ $r['operator'] }}</td>
                <td class="num">{{ $r['kios_dikunjungi'] }}</td>
                <td class="num">{{ number_format($r['mika_drop'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($r['omset'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($r['komisi'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($r['untung_bersih'], 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Belum ada trip selesai di bulan ini.</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="2">TOTAL</td>
            <td class="num">{{ $totals['kios_dikunjungi'] }}</td>
            <td class="num">{{ number_format($totals['mika_drop'], 0, ',', '.') }}</td>
            <td class="num">{{ number_format($totals['omset'], 0, ',', '.') }}</td>
            <td class="num">{{ number_format($totals['komisi'], 0, ',', '.') }}</td>
            <td class="num">{{ number_format($totals['untung_bersih'], 0, ',', '.') }}</td>
        </tr>
    </table>

    @if (count($dailyOmset) > 0)
        <div class="section-title">Omset Harian</div>
        <table class="data">
            @foreach ($dailyOmset as $d)
                <tr>
                    <td style="width:70px;">{{ $d['date'] }}</td>
                    <td><span class="bar" style="width: {{ max(2, round(($d['total'] / $maxOmset) * 240)) }}px;"></span></td>
                    <td class="num" style="width:120px;">Rp {{ number_format($d['total'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="section-title">Analisis Frekuensi Kios</div>
    <table class="data">
        <tr><th>Nama Kios</th><th>Cluster</th><th class="num">Kunjungan</th><th class="num">Settle</th></tr>
        @forelse ($analisisKios['frekuensi'] as $k)
            <tr>
                <td>{{ $k['kiosk'] }}</td>
                <td>{{ $k['cluster'] }}</td>
                <td class="num">{{ $k['kunjungan'] }}</td>
                <td class="num">{{ $k['settle'] }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Belum ada kunjungan bulan ini.</td></tr>
        @endforelse
    </table>

    <div class="footer">
        Digenerate oleh sistem dodol-app &middot; {{ now()->format('d M Y H:i') }}
    </div>
</body>
</html>
