<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Support\CatalogPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTechnicianCatalogPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_includes_margin_constraints_matching_floors(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $r = $this->getJson('/api/admin/service-catalog/technician-pricing')->assertOk()
            ->assertJsonPath('data.can_edit_margin_floors', false);
        $floorSvc = (float) $r->json('data.technician_service_margin_floor_percent');
        $minFromConstraints = (float) $r->json('data.margin_constraints.technician_service_discount_percent.min');
        $this->assertEqualsWithDelta($floorSvc, $minFromConstraints, 0.001);
        $this->assertEqualsWithDelta(95.0, (float) $r->json('data.margin_constraints.technician_service_discount_percent.max'), 0.001);
    }

    public function test_update_clamps_service_margin_when_below_floor(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_SUPER_ADMIN]);
        Sanctum::actingAs($admin);

        $r = $this->putJson('/api/admin/service-catalog/technician-pricing', [
            'technician_service_discount_percent' => 4,
            'technician_inventory_sale_discount_percent' => 10,
            'technician_inventory_rental_discount_percent' => 8,
            'technician_service_margin_floor_percent' => 5,
            'technician_inventory_sale_margin_floor_percent' => 10,
            'technician_inventory_rental_margin_floor_percent' => 8,
        ])->assertOk();

        $this->assertEquals(5.0, (float) $r->json('data.technician_service_discount_percent'));
    }

    public function test_admin_cannot_change_margin_floors(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_FLOOR_PERCENT, '5');
        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_INVENTORY_SALE_DISCOUNT_FLOOR_PERCENT, '10');
        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_INVENTORY_RENTAL_DISCOUNT_FLOOR_PERCENT, '8');

        $this->putJson('/api/admin/service-catalog/technician-pricing', [
            'technician_service_discount_percent' => 12,
            'technician_inventory_sale_discount_percent' => 15,
            'technician_inventory_rental_discount_percent' => 11,
            'technician_service_margin_floor_percent' => 20,
            'technician_inventory_sale_margin_floor_percent' => 25,
            'technician_inventory_rental_margin_floor_percent' => 22,
        ])->assertForbidden();

        $this->assertEquals(5.0, AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_FLOOR_PERCENT, 0));
    }

    public function test_admin_can_update_margins_without_sending_floors(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_FLOOR_PERCENT, '5');

        $this->putJson('/api/admin/service-catalog/technician-pricing', [
            'technician_service_discount_percent' => 12,
            'technician_inventory_sale_discount_percent' => 15,
            'technician_inventory_rental_discount_percent' => 11,
        ])->assertOk()
            ->assertJsonPath('data.technician_service_discount_percent', 12)
            ->assertJsonPath('data.can_edit_margin_floors', false);

        $this->assertEquals(5.0, AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_FLOOR_PERCENT, 0));
    }

    public function test_update_persists_floors(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_SUPER_ADMIN]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/service-catalog/technician-pricing', [
            'technician_service_discount_percent' => 12,
            'technician_inventory_sale_discount_percent' => 15,
            'technician_inventory_rental_discount_percent' => 11,
            'technician_service_margin_floor_percent' => 7,
            'technician_inventory_sale_margin_floor_percent' => 12,
            'technician_inventory_rental_margin_floor_percent' => 9,
        ])->assertOk();

        $this->assertEquals(7.0, AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_FLOOR_PERCENT, 0));
        $this->assertEquals(12.0, AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_INVENTORY_SALE_DISCOUNT_FLOOR_PERCENT, 0));
        $this->assertEquals(9.0, AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_INVENTORY_RENTAL_DISCOUNT_FLOOR_PERCENT, 0));
    }

    public function test_update_accepts_exact_minimums_relative_to_configured_floors(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_SUPER_ADMIN]);
        Sanctum::actingAs($admin);

        $fSvc = CatalogPricing::floorServiceDiscountPercent();
        $fSale = CatalogPricing::floorInventorySaleDiscountPercent();
        $fRent = CatalogPricing::floorInventoryRentalDiscountPercent();

        $res = $this->putJson('/api/admin/service-catalog/technician-pricing', [
            'technician_service_discount_percent' => $fSvc,
            'technician_inventory_sale_discount_percent' => $fSale,
            'technician_inventory_rental_discount_percent' => $fRent,
            'technician_service_margin_floor_percent' => $fSvc,
            'technician_inventory_sale_margin_floor_percent' => $fSale,
            'technician_inventory_rental_margin_floor_percent' => $fRent,
        ])->assertOk();

        $this->assertEqualsWithDelta($fSvc, (float) $res->json('data.technician_service_discount_percent'), 0.001);
    }
}
