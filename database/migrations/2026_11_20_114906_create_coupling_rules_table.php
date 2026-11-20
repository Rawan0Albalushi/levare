<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupling_rules', function (Blueprint $table) {
            $table->id();
            $table->string('from_end', 16);
            $table->string('to_end', 16);
            $table->string('series', 8);
            $table->string('left_shaft', 16);
            $table->string('right_shaft', 16);
            $table->string('shaft_label', 32);
            $table->text('description');
            $table->string('part_number', 32);
            $table->string('old_part_number', 32)->nullable();
            $table->json('alternate_part_numbers')->nullable();
            $table->string('description_left_shaft', 16)->nullable();
            $table->string('description_right_shaft', 16)->nullable();
            $table->string('wells', 32)->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['from_end', 'to_end', 'series']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupling_rules');
    }
};
