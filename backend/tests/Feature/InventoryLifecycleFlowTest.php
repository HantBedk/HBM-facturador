<?php

namespace Tests\Feature;

use App\Models\InventoryLifecycleTransitionRequest;
use App\Models\InventoryLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryLifecycleFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_lot_to_repair_with_reason(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'name' => 'Router core',
            'sku' => 'RTR-001',
            'quantity_available' => 2,
            'unit_price' => '1000.00',
            'is_active' => true,
            'allow_sale' => true,
            'allow_rental' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/inventory/lots/{$lot->id}/lifecycle/report", [
            'target_status' => 'reparacion',
            'reason' => 'Falla de fuente de poder',
        ])->assertOk()
            ->assertJsonPath('mode', 'direct')
            ->assertJsonPath('lot.lifecycle_status', InventoryLot::LIFECYCLE_REPARACION);

        $this->assertDatabaseHas('inventory_lots', [
            'id' => $lot->id,
            'lifecycle_status' => InventoryLot::LIFECYCLE_REPARACION,
            'is_active' => 0,
            'allow_sale' => 0,
            'allow_rental' => 0,
        ]);
    }

    public function test_admin_request_baja_counts_as_first_approval_and_second_admin_finalizes(): void
    {
        $admin1 = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $admin2 = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin1->id,
            'name' => 'Switch dañado',
            'sku' => 'SW-001',
            'serial_number' => 'SER-LOW-1',
            'quantity_available' => 1,
            'unit_price' => '200.00',
            'is_active' => true,
            'allow_sale' => true,
            'allow_rental' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($admin1);
        $requestResp = $this->postJson("/api/inventory/lots/{$lot->id}/lifecycle/report", [
            'target_status' => 'baja',
            'reason' => 'Equipo quemado irreparable',
        ])->assertCreated()
            ->assertJsonPath('mode', 'approval_required');

        $requestId = (int) $requestResp->json('request.id');
        $this->assertDatabaseHas('inventory_lifecycle_transition_requests', [
            'id' => $requestId,
            'status' => InventoryLifecycleTransitionRequest::STATUS_PENDING,
        ]);
        $this->assertDatabaseCount('inventory_lifecycle_request_approvals', 1);

        Sanctum::actingAs($admin2);
        $this->postJson("/api/inventory/lifecycle/requests/{$requestId}/approve", [
            'decision' => 'approved',
            'note' => 'Confirmo baja',
        ])->assertOk()
            ->assertJsonPath('request.status', InventoryLifecycleTransitionRequest::STATUS_APPROVED);

        $this->assertDatabaseHas('inventory_lots', [
            'id' => $lot->id,
            'lifecycle_status' => InventoryLot::LIFECYCLE_BAJA,
            'is_active' => 0,
            'allow_sale' => 0,
            'allow_rental' => 0,
        ]);
    }

    public function test_cannot_reuse_fingerprint_from_decommissioned_lot(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/inventory/lots', [
            'name' => 'Activo viejo',
            'sku' => 'A-001',
            'serial_number' => 'SER-RX-01',
            'mac_address' => 'AA-BB-CC-DD-EE-FF',
            'quantity_available' => 1,
            'unit_price' => '10',
            'allow_sale' => true,
            'allow_rental' => false,
        ])->assertCreated();

        $lot = InventoryLot::query()->firstOrFail();
        $lot->update([
            'lifecycle_status' => InventoryLot::LIFECYCLE_BAJA,
            'is_active' => false,
            'allow_sale' => false,
            'allow_rental' => false,
        ]);

        $this->postJson('/api/inventory/lots', [
            'name' => 'Activo nuevo',
            'sku' => 'A-002',
            'serial_number' => 'SER-RX-01',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'quantity_available' => 1,
            'unit_price' => '11',
            'allow_sale' => true,
            'allow_rental' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['serial_number']);
    }
}

