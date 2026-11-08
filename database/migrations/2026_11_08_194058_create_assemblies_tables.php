<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assemblies', function (Blueprint $table) {
            $table->id();
            $table->string('part_number')->unique();
            $table->text('description');
            $table->timestamps();
        });

        Schema::create('assembly_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assembly_id')->constrained()->cascadeOnDelete();
            $table->string('section', 16);
            $table->unsignedSmallInteger('position');
            $table->string('item_code')->nullable();
            $table->string('part_number')->nullable();
            $table->text('description');
            $table->string('uom', 32)->nullable();
            $table->decimal('quantity', 14, 2);
            $table->timestamps();

            $table->unique(['assembly_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assembly_lines');
        Schema::dropIfExists('assemblies');
    }
};
