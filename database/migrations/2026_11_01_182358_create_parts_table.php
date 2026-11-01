<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('item_code')->nullable()->index();
            $table->string('part_number')->nullable()->index();
            $table->text('description');
            $table->string('uom', 32)->nullable();
            $table->text('store_type')->nullable();
            $table->json('categories');
            $table->string('priority', 32)->nullable();
            $table->boolean('below_minimum')->default(false);
            $table->string('min_qty')->nullable();
            $table->string('crit_stock')->nullable();
            $table->string('reused')->nullable();
            $table->string('single_eq')->nullable();
            $table->string('plan_90')->nullable();
            $table->string('plan_6')->nullable();
            $table->string('backup')->nullable();
            $table->text('used_in')->nullable();
            $table->text('notes')->nullable();
            $table->text('alternative_note')->nullable();
            $table->json('alternatives')->nullable();
            $table->boolean('is_critical')->default(false)->index();
            $table->unsignedInteger('sort_order')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parts');
    }
};
