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
        .negative { color: #dc2626; }
        tr.total-row td { background: #fffbeb; font-weight: bold; }
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
        {{-- BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" item 4 — trip yang ditutup
             SISTEM (operator lupa tekan "Akhiri Trip"), bukan manual. --}}
        @if ($trip->ended_reason === 'auto_closed')
            <tr><td>Status Akhir</td><td>: <strong>Ditutup otomatis</strong> — Operator lupa menekan Akhiri Trip, cek sisa stok manual.</td></tr>
        @endif
    </table>

    <div class="section-title">Ringkasan Mika</div>
    <table class="data">
        <tr><th>Mika Dibawa</th><th>Mika Dititip</th><th>Mika Sisa</th></tr>
        <tr>
            <td class="num">{{ $summary['mika_dibawa_display'] }}</td>
            <td class="num">
                {{ number_format($summary['mika_drop'], 0, ',', '.') }}
                <br><span class="muted" style="font-size:9px;">({{ number_format($summary['biji_drop'], 0, ',', '.') }} biji)</span>
            </td>
            <td class="num {{ $summary['mika_sisa'] < 0 ? 'negative' : '' }}">
                {{ number_format($summary['mika_sisa'], 0, ',', '.') }}
            </td>
        </tr>
    </table>
    {{-- Riwayat "➕ Tambah Stok di Motor" (brief 27 Okt 2026) — kosong kalau trip ini
         tidak pernah pakai fitur tambah stok. --}}
    @if(!empty($summary['stock_additions']))
        <table class="data" style="margin-top:-4px;">
            <tr><th>Riwayat Tambah Stok</th><th style="text-align:right;">Jumlah</th><th>Oleh</th></tr>
            @foreach($summary['stock_additions'] as $addition)
                <tr>
                    <td>{{ $addition['created_at']->format('d M Y H:i') }}</td>
                    <td class="num">+{{ number_format($addition['qty'], 0, ',', '.') }}</td>
                    <td>{{ $addition['added_by'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    @if ($summary['mika_sisa'] < 0)
        {{-- ✅ DIPERBAIKI BRIEF 27 Okt 2026 — bukan lagi hipotesis "mika BS ikut
             terhitung" (sudah dikoreksi investigasi, lihat TripExportController::
             buildTripData()). Trip baru tak bisa negatif lagi (server menolak titip/
             jual melebihi stok di motor) — ini murni data trip LAMA. --}}
        <p class="negative" style="font-size:10px; margin:2px 0 0;">
            ⚠ Mika Sisa negatif — data trip lama sebelum ada pengaman stok di motor
            (titip melebihi stok dibawa). Data historis, tidak diubah.
        </p>
    @endif

    <div class="section-title">Ringkasan Finansial</div>
    <table class="data">
        <tr><th>Keterangan</th><th style="text-align:right;">Nilai (Rp)</th></tr>
        <tr><td>Omset</td><td class="num">{{ number_format($finansial['omset'], 0, ',', '.') }}</td></tr>
        <tr><td>HPP</td><td class="num">{{ number_format($finansial['hpp'], 0, ',', '.') }}</td></tr>
        <tr><td>Untung Kotor</td><td class="num">{{ number_format($finansial['untung_kotor'], 0, ',', '.') }}</td></tr>
        <tr>
            <td>Mika Komisi (Drop)</td>
            <td class="num">
                {{ number_format($finansial['mika_komisi'], 0, ',', '.') }}
                <br><span class="muted" style="font-size:9px;">({{ number_format($finansial['biji_komisi'], 0, ',', '.') }} biji)</span>
            </td>
        </tr>
        <tr><td>Komisi {{ $operatorName }} (Rp {{ number_format($komisiPerMika, 0, ',', '.') }} &times; mika drop)</td><td class="num">{{ number_format($finansial['total_komisi'], 0, ',', '.') }}</td></tr>
        <tr class="highlight"><td>Untung Bersih Owner</td><td class="num">{{ number_format($finansial['untung_bersih'], 0, ',', '.') }}</td></tr>
    </table>

    <div class="section-title">Detail Kunjungan ({{ count($visits) }} kios)</div>
    <table class="data">
        <tr>
            <th>Kios</th><th>Aksi</th><th>Waktu</th>
            <th style="text-align:right;">Mika Titip</th>
            <th style="text-align:right;">Biji Terjual</th>
            <th style="text-align:right;">BS (biji)</th>
            <th style="text-align:right;">Tagihan (Rp)</th>
            <th style="text-align:right;">Uang Diterima (Rp)</th>
            <th style="text-align:right;">Piutang (Rp)</th>
            <th>Catatan/Janji Bayar</th>
        </tr>
        @forelse ($visits as $v)
            <tr>
                <td>{{ $v['kiosk'] }}</td>
                <td>{{ $v['action'] }}</td>
                <td>{{ $v['time'] }}</td>
                <td class="num">{{ $v['mika_titip'] ? number_format($v['mika_titip'], 0, ',', '.') : '—' }}</td>
                <td class="num">{{ $v['biji_terjual'] ? number_format($v['biji_terjual'], 0, ',', '.') : '—' }}</td>
                <td class="num">{{ $v['bs_biji'] ? number_format($v['bs_biji'], 0, ',', '.') : '—' }}</td>
                <td class="num">{{ $v['tagihan'] ? number_format($v['tagihan'], 0, ',', '.') : '—' }}</td>
                <td class="num">{{ $v['uang_diterima'] ? number_format($v['uang_diterima'], 0, ',', '.') : '—' }}</td>
                <td class="num">{{ $v['piutang'] ? number_format($v['piutang'], 0, ',', '.') : '—' }}</td>
                <td>{{ $v['catatan_gabungan'] ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="muted">Tidak ada kunjungan tercatat.</td></tr>
        @endforelse
        @if (count($visits) > 0)
            <tr class="total-row">
                <td colspan="3">TOTAL</td>
                <td class="num">{{ number_format(array_sum(array_column($visits, 'mika_titip')), 0, ',', '.') }}</td>
                <td class="num">{{ number_format(array_sum(array_column($visits, 'biji_terjual')), 0, ',', '.') }}</td>
                <td class="num">{{ number_format(array_sum(array_column($visits, 'bs_biji')), 0, ',', '.') }}</td>
                <td class="num">{{ number_format(array_sum(array_column($visits, 'tagihan')), 0, ',', '.') }}</td>
                <td class="num">{{ number_format(array_sum(array_column($visits, 'uang_diterima')), 0, ',', '.') }}</td>
                <td class="num">{{ number_format(array_sum(array_column($visits, 'piutang')), 0, ',', '.') }}</td>
                <td></td>
            </tr>
        @endif
    </table>

    <div class="footer">
        Digenerate oleh sistem dodol-app &middot; {{ now()->format('d M Y H:i') }}
    </div>
</body>
</html>
