<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    public function up(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_lots', 'lifecycle_status')) {
                $table->string('lifecycle_status', 24)->default('activo')->after('is_active');
            }
            if (! Schema::hasColumn('inventory_lots', 'lifecycle_status_changed_at')) {
                $table->timestamp('lifecycle_status_changed_at')->nullable()->after('lifecycle_status');
            }
            if (! Schema::hasColumn('inventory_lots', 'repair_reason')) {
                $table->text('repair_reason')->nullable()->after('lifecycle_status_changed_at');
            }
            if (! Schema::hasColumn('inventory_lots', 'repair_resolution')) {
                $table->text('repair_resolution')->nullable()->after('repair_reason');
            }
            if (! Schema::hasColumn('inventory_lots', 'decommission_reason')) {
                $table->text('decommission_reason')->nullable()->after('repair_resolution');
            }
            if (! Schema::hasColumn('inventory_lots', 'decommissioned_at')) {
                $table->timestamp('decommissioned_at')->nullable()->after('decommission_reason');
            }
            if (! Schema::hasColumn('inventory_lots', 'decommissioned_by_user_id')) {
                $table->foreignId('decommissioned_by_user_id')->nullable()->after('decommissioned_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('inventory_lots', 'serial_number')) {
                $table->string('serial_number', 120)->nullable()->after('sku');
            }
            if (! Schema::hasColumn('inventory_lots', 'mac_address')) {
                $table->string('mac_address', 120)->nullable()->after('serial_number');
            }
            if (! Schema::hasColumn('inventory_lots', 'fingerprint_hash')) {
                $table->string('fingerprint_hash', 64)->nullable()->after('mac_address')->index();
            }
        });

        if (! $this->indexExists('inventory_lots', 'inventory_lots_lifecycle_tenant_idx')) {
            Schema::table('inventory_lots', function (Blueprint $table) {
                $table->index(['lifecycle_status', 'tenant_company_id'], 'inventory_lots_lifecycle_tenant_idx');
            });
        }

        if (! Schema::hasTable('inventory_lifecycle_transition_requests')) {
            Schema::create('inventory_lifecycle_transition_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->cascadeOnDelete();
                $table->foreignId('tenant_company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('target_status', 24);
                $table->string('status', 24)->default('pending');
                $table->text('reason');
                $table->unsignedTinyInteger('required_approvals')->default(2);
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('resolution_note')->nullable();
                $table->timestamps();

                $table->index(['inventory_lot_id', 'status'], 'inv_lifecycle_req_lot_status_idx');
                $table->index(['status', 'target_status'], 'inv_lifecycle_req_status_target_idx');
            });
        }

        if (! Schema::hasTable('inventory_lifecycle_request_approvals')) {
            Schema::create('inventory_lifecycle_request_approvals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('request_id');
                $table->unsignedBigInteger('approved_by_user_id');
                $table->string('decision', 24)->default('approved');
                $table->text('note')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('request_id', 'inv_lifecycle_req_appr_req_fk')
                    ->references('id')
                    ->on('inventory_lifecycle_transition_requests')
                    ->cascadeOnDelete();
                $table->foreign('approved_by_user_id', 'inv_lifecycle_req_appr_user_fk')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
                $table->unique(['request_id', 'approved_by_user_id'], 'inv_lifecycle_req_approver_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lifecycle_request_approvals');
        Schema::dropIfExists('inventory_lifecycle_transition_requests');

        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->dropIndex('inventory_lots_lifecycle_tenant_idx');
            $table->dropColumn([
                'lifecycle_status',
                'lifecycle_status_changed_at',
                'repair_reason',
                'repair_resolution',
                'decommission_reason',
                'decommissioned_at',
                'serial_number',
                'mac_address',
                'fingerprint_hash',
            ]);
            $table->dropConstrainedForeignId('decommissioned_by_user_id');
        });
    }
};

