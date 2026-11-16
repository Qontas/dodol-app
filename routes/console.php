<?php

use App\Support\KioskInsightExport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('kiosks:insight-prune', function () {
    $count = KioskInsightExport::prune();
    $this->info("Laporan kedai kedaluwarsa dihapus: {$count}.");
})->purpose('Hapus file laporan kedai privat yang berumur lebih dari 24 jam');
