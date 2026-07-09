<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->integer('sort_order')->nullable()->after('cluster_id');
            $table->index(['cluster_id', 'sort_order']);
        });

        // Backfill kios existing: urutan rute PER-CLUSTER (per-area), tiap area
        // mulai dari 1, ikut abjad saat ini sebagai titik awal. cluster_id sudah
        // menentukan owner (satu cluster = satu owner) — jadi grouping per
        // cluster otomatis tidak pernah mencampur kios lintas-tenant.
        $rows = DB::table('kiosks')
            ->whereNotNull('cluster_id')
            ->orderBy('cluster_id')
            ->orderBy('name')
            ->select('id', 'cluster_id')
            ->get();

        $counters = [];
        foreach ($rows as $row) {
            $counters[$row->cluster_id] = ($counters[$row->cluster_id] ?? 0) + 1;

            DB::table('kiosks')->where('id', $row->id)->update(['sort_order' => $counters[$row->cluster_id]]);
        }
    }

    public function down(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->dropIndex(['kiosks_cluster_id_sort_order_index']);
            $table->dropColumn('sort_order');
        });
    }
};
