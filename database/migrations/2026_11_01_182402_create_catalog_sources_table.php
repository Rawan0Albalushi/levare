<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_sources', function (Blueprint $table) {
            $table->id();
            $table->string('store_name');
            $table->string('critical_name')->nullable();
            $table->timestamp('imported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_sources');
    }
};
