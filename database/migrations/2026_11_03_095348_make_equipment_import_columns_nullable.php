<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('port_number')->nullable()->change();
            $table->date('recorded_on')->nullable()->change();
            $table->string('condition', 16)->nullable()->default('new')->change();
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('port_number')->nullable(false)->change();
            $table->date('recorded_on')->nullable(false)->change();
            $table->string('condition', 16)->nullable(false)->default('new')->change();
        });
    }
};
