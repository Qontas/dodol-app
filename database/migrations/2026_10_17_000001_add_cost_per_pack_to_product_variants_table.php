<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HPP per kemasan (mika) milik varian produk ini — sebelum ini HPP hanya ada di
 * level owner (users.hpp_per_mika), flat untuk SEMUA produk owner tsb. Nullable +
 * fallback di accessor (bukan default level-DB) supaya baris lama terlihat jelas
 * belum diisi, bukan diam-diam dianggap terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('cost_per_pack', 10, 2)->nullable()->after('sale_price_per_pack');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('cost_per_pack');
        });
    }
};
