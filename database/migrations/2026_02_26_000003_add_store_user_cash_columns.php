<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_user')) {
            return;
        }

        Schema::table('store_user', function (Blueprint $table): void {
            if (! Schema::hasColumn('store_user', 'cash_in_hand')) {
                $table->bigInteger('cash_in_hand')->default(0);
            }

            if (! Schema::hasColumn('store_user', 'role_id')) {
                $table->unsignedBigInteger('role_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('store_user')) {
            return;
        }

        Schema::table('store_user', function (Blueprint $table): void {
            if (Schema::hasColumn('store_user', 'cash_in_hand')) {
                $table->dropColumn('cash_in_hand');
            }

            if (Schema::hasColumn('store_user', 'role_id')) {
                $table->dropColumn('role_id');
            }
        });
    }
};
