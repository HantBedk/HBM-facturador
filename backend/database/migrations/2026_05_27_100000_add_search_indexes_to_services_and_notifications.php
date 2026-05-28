<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $servicesIdx = collect(DB::select('SHOW INDEX FROM services'))->pluck('Key_name')->unique()->all();

        Schema::table('services', function (Blueprint $table) use ($servicesIdx) {
            if (! in_array('services_kind_index', $servicesIdx)) {
                $table->index('kind');
            }
            if (! in_array('services_status_index', $servicesIdx)) {
                $table->index('status');
            }
        });

        $notifIdx = collect(DB::select('SHOW INDEX FROM panel_notifications'))->pluck('Key_name')->unique()->all();

        Schema::table('panel_notifications', function (Blueprint $table) use ($notifIdx) {
            if (! in_array('panel_notifications_user_id_read_index', $notifIdx)) {
                $table->index(['user_id', 'read']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndexIfExists('services_kind_index');
            $table->dropIndexIfExists('services_status_index');
        });

        Schema::table('panel_notifications', function (Blueprint $table) {
            $table->dropIndexIfExists('panel_notifications_user_id_read_index');
        });
    }
};
