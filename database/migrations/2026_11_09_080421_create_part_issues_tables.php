<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_issues', function (Blueprint $table) {
            $table->id();
            $table->string('assembly_part_number')->index();
            $table->text('assembly_description');
            $table->timestamps();
        });

        Schema::create('part_issue_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('item_code')->nullable();
            $table->string('part_number')->nullable();
            $table->text('description');
            $table->string('uom', 32)->nullable();
            $table->decimal('quantity', 14, 2);
            $table->timestamps();
        });

        Schema::create('part_issue_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_issue_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location');
            $table->decimal('quantity', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_issue_allocations');
        Schema::dropIfExists('part_issue_lines');
        Schema::dropIfExists('part_issues');
    }
};
