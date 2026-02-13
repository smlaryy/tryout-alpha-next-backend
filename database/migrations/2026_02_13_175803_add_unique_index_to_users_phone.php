<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // kalau sebelumnya sudah ada index biasa, drop dulu biar gak bentrok
            // nama index default biasanya: users_phone_index
            if (Schema::hasColumn('users', 'phone')) {
                $table->dropIndex(['phone']);
            }

            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->index('phone');
        });
    }
};
