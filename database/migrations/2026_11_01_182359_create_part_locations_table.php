<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('location')->index();
            $table->decimal('quantity', 14, 2)->default(0);
            $table->string('quantity_raw')->nullable();
            $table->string('updated_on')->nullable();
            $table->string('condition')->nullable();
            $table->string('status')->nullable();
            $table->string('reference')->nullable();
            $table->index(['part_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_locations');
    }
};
