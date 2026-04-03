<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('public_access_token', 255)->nullable()->after('sent_at');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 120);
            $table->text('description');
            $table->timestamps();
        });

        DB::table('invoices')
            ->whereIn('status', ['aprobada', 'enviada', 'parcialmente_pagada', 'pagada'])
            ->whereNull('public_access_token')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('invoices')->where('id', $row->id)->update([
                        'public_access_token' => Hash::make(Str::random(40)),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('public_access_token');
        });
    }
};
