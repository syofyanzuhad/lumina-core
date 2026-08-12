<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_visitor_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('date');
            $table->string('visitor_hash', 64);
            $table->unsignedInteger('views')->default(1);
            $table->timestamps();

            $table->unique(['site_id', 'date', 'visitor_hash'], 'site_date_visitor_unique');
            $table->index(['site_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_visitor_stats');
    }
};
