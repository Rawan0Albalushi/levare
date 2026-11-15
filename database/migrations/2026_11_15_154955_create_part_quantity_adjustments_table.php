<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_quantity_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location');
            $table->string('action', 16);
            $table->decimal('quantity_before', 14, 2);
            $table->decimal('quantity_after', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_quantity_adjustments');
    }
};
