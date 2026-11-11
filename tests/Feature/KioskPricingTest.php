<?php

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\KioskPricing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class KioskPricingTest extends TestCase
{
    use RefreshDatabase;

    private function variant(?int $ownerId = null, int $price = 12000): ProductVariant
    {
        return ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['owner_id' => $ownerId])->id,
            'sale_price_per_pack' => $price,
            'units_per_pack' => 20,
        ]);
    }

    private function kiosk(?int $ownerId = null): Kiosk
    {
        return Kiosk::factory()->create(['cluster_id' => Cluster::factory()->create(['owner_id' => $ownerId])->id]);
    }

    private function tier(ProductVariant $variant, array $attributes = []): PriceTier
    {
        return PriceTier::create(array_merge([
            'product_variant_id' => $variant->id,
            'name' => 'Khusus',
            'sale_price_per_pack' => 16000,
            'is_default' => false,
            'is_active' => true,
        ], $attributes));
    }

    public function test_new_variants_get_standard_default_and_legacy_price_is_not_the_truth(): void
    {
        $variant = $this->variant();
        $tier = $variant->defaultPriceTier;
        $this->assertSame('Standar', $tier->name);
        $this->assertTrue($tier->is_active);
        $this->assertNull($tier->owner_id);
        $variant->update(['sale_price_per_pack' => 99000]);
        $this->assertSame(12000.0, KioskPricing::pricePerPack($this->kiosk(), $variant));
        $tier->update(['sale_price_per_pack' => 14000]);
        $this->assertSame(14000.0, KioskPricing::pricePerPack($this->kiosk(), $variant->fresh()));
    }

    public function test_custom_tier_only_applies_to_its_owner_and_requested_variant(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $variant = $this->variant($owner->id);
        $otherVariant = $this->variant($owner->id, 18000);
        $otherOwnerVariant = $this->variant(User::factory()->create(['role' => 'owner'])->id, 22000);
        $tier = $this->tier($variant);
        $kiosk = $this->kiosk($owner->id);
        $kiosk->update(['price_tier_id' => $tier->id]);
        $this->assertSame(16000.0, KioskPricing::pricePerPack($kiosk->fresh(), $variant));
        $this->assertSame(18000.0, KioskPricing::pricePerPack($kiosk->fresh(), $otherVariant));
        $this->assertSame(22000.0, KioskPricing::pricePerPack($kiosk->fresh(), $otherOwnerVariant));
        // Corrupt/legacy assignments must not leak another owner's price.
        $foreignKiosk = $this->kiosk($otherOwnerVariant->product->owner_id);
        DB::table('kiosks')->where('id', $foreignKiosk->id)->update(['price_tier_id' => $tier->id]);
        $this->assertSame(12000.0, KioskPricing::pricePerPack($foreignKiosk->fresh(), $variant));
    }

    public function test_inactive_existing_assignment_still_applies_but_new_choices_are_rejected(): void
    {
        $variant = $this->variant();
        $tier = $this->tier($variant);
        $kiosk = $this->kiosk();
        $kiosk->update(['price_tier_id' => $tier->id]);
        $tier->update(['is_active' => false]);
        $kiosk->update(['name' => 'Tetap berlaku']);
        $this->assertSame(16000.0, KioskPricing::pricePerPack($kiosk->fresh(), $variant));
        $this->assertArrayNotHasKey($tier->id, KioskPricing::options(null, $variant->id));
        $this->expectException(ValidationException::class);
        KioskPricing::validateTier($tier->id, null, $variant->id);
    }

    public function test_validation_rejects_cross_owner_and_wrong_variant_with_expected_field(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $variant = $this->variant($owner->id);
        $other = $this->variant($owner->id);
        $tier = $this->tier($variant);
        foreach ([[null, $variant->id], [$owner->id, $other->id]] as [$ownerId, $variantId]) {
            try {
                KioskPricing::validateTier($tier->id, $ownerId, $variantId);
                $this->fail('Invalid tier accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('price_tier_id', $e->errors());
            }
        }
        KioskPricing::validateTier(null, $owner->id);
        $this->assertTrue(true);
    }

    public function test_kiosk_model_rejects_cross_tenant_assignment(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $tier = $this->tier($this->variant($owner->id));
        $this->expectException(ValidationException::class);
        $this->kiosk()->update(['price_tier_id' => $tier->id]);
    }

    public function test_default_switch_is_atomic_and_stale_instance_cannot_deactivate_new_default(): void
    {
        $variant = $this->variant();
        $old = $variant->defaultPriceTier;
        $new = $this->tier($variant);
        $stale = $new->fresh();
        $new->update(['is_default' => true]);
        $this->assertFalse($old->fresh()->is_default);
        $this->assertSame(1, $variant->priceTiers()->where('is_default', true)->count());
        try {
            $stale->update(['is_active' => false]);
            $this->fail('Stale instance deactivated default.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_active', $e->errors());
        }
        $this->assertTrue($new->fresh()->is_active);
    }

    public function test_default_cannot_be_unset_deactivated_or_deleted(): void
    {
        $tier = $this->variant()->defaultPriceTier;
        foreach ([fn () => $tier->fresh()->update(['is_default' => false]),
            fn () => $tier->fresh()->update(['is_active' => false]),
            fn () => $tier->fresh()->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Default invariant was bypassed.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertTrue($tier->fresh()->is_default);
    }

    public function test_sql_rejects_a_second_default_even_when_bypassing_model(): void
    {
        $variant = $this->variant();
        $this->expectException(QueryException::class);
        DB::table('price_tiers')->insert([
            'product_variant_id' => $variant->id,
            'name' => 'Illegal duplicate', 'sale_price_per_pack' => 10000,
            'is_default' => true, 'is_active' => true,
        ]);
    }

    public function test_owner_scope_options_label_and_active_variant_are_isolated(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $mine = $this->variant($owner->id);
        $foreign = $this->variant(User::factory()->create(['role' => 'owner'])->id);
        $tier = $this->tier($mine);
        $this->actingAs($owner);
        $this->assertSame($mine->id, KioskPricing::activeVariantForOwner($owner->id)->id);
        $this->assertSame('Khusus — Rp 16.000/mika · Rp 800/biji', KioskPricing::label($tier));
        $this->assertArrayHasKey($tier->id, KioskPricing::options($owner->id));
        $this->assertSame([], KioskPricing::options($owner->id, $foreign->id));
        $this->assertFalse(PriceTier::where('product_variant_id', $foreign->id)->exists());
    }

    public function test_eager_loaded_pricing_does_not_query_per_kiosk(): void
    {
        $variant = $this->variant();
        $tier = $this->tier($variant);
        for ($i = 0; $i < 3; $i++) {
            $this->kiosk()->update(['price_tier_id' => $tier->id]);
        }
        $variant->load(['product', 'defaultPriceTier']);
        $kiosks = Kiosk::with(['cluster', 'priceTier'])->get();
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($kiosks as $kiosk) {
            $this->assertSame(16000.0, KioskPricing::pricePerPack($kiosk, $variant));
        }
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_unsaved_legacy_variant_falls_back_without_a_default(): void
    {
        $this->assertSame(12000.0, KioskPricing::pricePerPack(new Kiosk, new ProductVariant(['sale_price_per_pack' => 12000])));
    }
}
