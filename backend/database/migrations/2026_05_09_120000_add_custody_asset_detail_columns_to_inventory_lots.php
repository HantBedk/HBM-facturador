<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->string('asset_type', 120)->nullable()->after('description');
            $table->string('asset_subtype', 120)->nullable()->after('asset_type');
            $table->string('brand', 120)->nullable()->after('asset_subtype');
            $table->string('model', 120)->nullable()->after('brand');
            $table->string('site_label', 255)->nullable()->after('model');
            $table->string('area_label', 255)->nullable()->after('site_label');
            $table->string('physical_condition', 120)->nullable()->after('area_label');
            $table->date('warranty_until')->nullable()->after('physical_condition');
            $table->date('purchase_date')->nullable()->after('warranty_until');
            $table->date('custody_received_at')->nullable()->after('purchase_date');
            $table->string('responsible_name', 255)->nullable()->after('custody_received_at');
            $table->string('responsible_role', 120)->nullable()->after('responsible_name');
            $table->index(['tenant_company_id', 'asset_type'], 'inventory_lots_tenant_asset_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->dropIndex('inventory_lots_tenant_asset_type_idx');
            $table->dropColumn([
                'asset_type',
                'asset_subtype',
                'brand',
                'model',
                'site_label',
                'area_label',
                'physical_condition',
                'warranty_until',
                'purchase_date',
                'custody_received_at',
                'responsible_name',
                'responsible_role',
            ]);
        });
    }
};
