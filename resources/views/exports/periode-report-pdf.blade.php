<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Periode</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1e293b; margin: 0; }
        h1 { font-size: 17px; margin: 0 0 2px; }
        .muted { color: #64748b; }
        .header { border-bottom: 2px solid #d97706; padding-bottom: 8px; margin-bottom: 12px; }
        .cards { width: 100%; border-collapse: collapse; margin: 6px 0 14px; }
        .cards td { width: 25%; border: 1px solid #e2e8f0; padding: 6px 8px; text-align: center; }
        .cards .label { display: block; font-size: 9px; text-transform: uppercase; color: #64748b; margin-bottom: 2px; }
        .cards .value { font-size: 13px; font-weight: bold; }
        table.data { width: 100%; border-collapse: collapse; margin: 4px 0 14px; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 4px 6px; text-align: left; }
        table.data th { background: #f8fafc; font-size: 9px; text-transform: uppercase; letter-spacing: .02em; color: #475569; }
        td.num { text-align: right; }
        .section-title { font-size: 12px; font-weight: bold; margin: 14px 0 4px; color: #0f172a; }
        tr.total-row td { background: #fffbeb; font-weight: bold; }
        .footer { margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 6px; font-size: 9px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $businessName }}</h1>
        <div class="muted">Laporan Periode &middot; {{ $label }}</div>
    </div>

    <div class="section-title">Ringkasan</div>
    <table class="cards">
        <tr>
            <td><span class="label">Omset</span><span class="value">Rp {{ number_format($totals['omset'], 0, ',', '.') }}</span></td>
            <td><span class="label">HPP</span><span class="value">Rp {{ number_format($totals['hpp'], 0, ',', '.') }}</span></td>
            <td><span class="label">Untung Kotor</span><span class="value">Rp {{ number_format($totals['untung_kotor'], 0, ',', '.') }}</span></td>
            <td><span class="label">Komisi</span><span class="value">Rp {{ number_format($totals['komisi'], 0, ',', '.') }}</span></td>
        </tr>
        <tr>
            <td><span class="label">Untung Bersih</span><span class="value">Rp {{ number_format($totals['untung_bersih'], 0, ',', '.') }}</span></td>
            <td><span class="label">Mika Diantar</span><span class="value">{{ number_format($totals['mika_diantar'], 0, ',', '.') }}</span></td>
            <td><span class="label">Kios Dikunjungi</span><span class="value">{{ number_format($totals['kios_dikunjungi'], 0, ',', '.') }}</span></td>
            <td><span class="label">Piutang Baru</span><span class="value">Rp {{ number_format($totals['piutang_baru'], 0, ',', '.') }}</span></td>
        </tr>
    </table>

    <div class="section-title">Per Trip ({{ $trips->count() }} trip)</div>
    <table class="data">
        <tr>
            <th>Tanggal</th><th>Operator</th>
            <th style="text-align:right;">Kios</th>
            <th style="text-align:right;">Mika</th>
            <th style="text-align:right;">Omset</th>
            <th style="text-align:right;">Komisi</th>
            <th style="text-align:right;">Untung Bersih</th>
        </tr>
        @forelse ($trips as $r)
            <tr>
                <td>{{ $r['trip_date'] }}</td>
                <td>{{ $r['operator'] }}</td>
                <td class="num">{{ $r['kios_dikunjungi'] }}</td>
                <td class="num">{{ number_format($r['mika_diantar'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($r['omset'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($r['komisi'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($r['untung_bersih'], 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Tidak ada trip pada periode ini.</td></tr>
        @endforelse
        @if ($trips->count() > 0)
            <tr class="total-row">
                <td colspan="2">TOTAL</td>
                <td class="num">{{ $trips->sum('kios_dikunjungi') }}</td>
                <td class="num">{{ number_format($trips->sum('mika_diantar'), 0, ',', '.') }}</td>
                <td class="num">{{ number_format($trips->sum('omset'), 0, ',', '.') }}</td>
                <td class="num">{{ number_format($trips->sum('komisi'), 0, ',', '.') }}</td>
                <td class="num">{{ number_format($trips->sum('untung_bersih'), 0, ',', '.') }}</td>
            </tr>
        @endif
    </table>

    <div class="section-title">Per Operator</div>
    <table class="data">
        <tr>
            <th>Operator</th>
            <th style="text-align:right;">Trip</th>
            <th style="text-align:right;">Kios</th>
            <th style="text-align:right;">Omset</th>
            <th style="text-align:right;">Komisi</th>
            <th style="text-align:right;">Untung Bersih</th>
        </tr>
        @forelse ($perOperator as $o)
            <tr>
                <td>{{ $o['operator'] }}</td>
                <td class="num">{{ $o['trip_count'] }}</td>
                <td class="num">{{ $o['kios_dikunjungi'] }}</td>
                <td class="num">{{ number_format($o['omset'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($o['komisi'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($o['untung_bersih'], 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Tidak ada data operator.</td></tr>
        @endforelse
    </table>

    <div class="footer">
        Digenerate oleh sistem dodol-app &middot; {{ now()->format('d M Y H:i') }}
    </div>
</body>
</html>
