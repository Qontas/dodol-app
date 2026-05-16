<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_warehouse_stock AS
            SELECT
                pv.id AS product_variant_id,
                pv.name AS variant_name,
                COALESCE((
                    SELECT SUM(pb.qty_packs)
                    FROM procurement_batches pb
                    WHERE pb.product_variant_id = pv.id
                ), 0)
              + COALESCE((
                    SELECT SUM(s.qty_returned_fresh)
                    FROM settlements s
                    JOIN deliveries d ON s.delivery_id = d.id
                    WHERE d.product_variant_id = pv.id
                ), 0)
              - COALESCE((
                    SELECT SUM(d.qty_delivered)
                    FROM deliveries d
                    WHERE d.product_variant_id = pv.id
                ), 0) AS qty_in_warehouse
            FROM product_variants pv
            WHERE pv.is_active = true
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_warehouse_stock');
    }
};
