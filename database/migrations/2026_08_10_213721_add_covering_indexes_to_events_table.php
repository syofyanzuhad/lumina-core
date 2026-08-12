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
        Schema::table('events', function (Blueprint $table) {
            $table->index(['site_id', 'created_at', 'os'], 'events_site_id_created_at_os_index');
            $table->index(['site_id', 'created_at', 'browser'], 'events_site_id_created_at_browser_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_site_id_created_at_os_index');
            $table->dropIndex('events_site_id_created_at_browser_index');
        });
    }
};
