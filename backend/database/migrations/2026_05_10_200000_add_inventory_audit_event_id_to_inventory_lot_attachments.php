<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_lot_attachments', function (Blueprint $table) {
            $table->foreignId('inventory_audit_event_id')
                ->nullable()
                ->after('uploaded_by_user_id')
                ->constrained('inventory_audit_events')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_lot_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_audit_event_id');
        });
    }
};
