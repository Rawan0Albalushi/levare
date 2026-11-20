<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupling_pumps', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('series', 8);
            $table->string('shaft', 16);
            $table->string('kind', 16);
            $table->unsignedInteger('position');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupling_pumps');
    }
};
