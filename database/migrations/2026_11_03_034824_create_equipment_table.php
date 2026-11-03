<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('port_number');
            $table->string('serial_number')->nullable();
            $table->text('description')->nullable();
            $table->string('assigned_well')->nullable();
            $table->date('recorded_on')->index();
            $table->string('location')->nullable();
            $table->string('condition', 16)->default('new')->index();
            $table->date('tested_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
