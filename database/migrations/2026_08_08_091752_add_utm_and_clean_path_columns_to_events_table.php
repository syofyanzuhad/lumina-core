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
            $table->string('clean_path')->nullable()->after('path');
            $table->string('utm_source')->nullable()->after('country_name');
            $table->string('utm_medium')->nullable()->after('utm_source');
            $table->string('utm_campaign')->nullable()->after('utm_medium');
            $table->string('utm_term')->nullable()->after('utm_campaign');
            $table->string('utm_content')->nullable()->after('utm_term');

            $table->index(['site_id', 'clean_path', 'created_at']);
            $table->index(['site_id', 'utm_source', 'created_at']);
            $table->index(['site_id', 'utm_medium', 'created_at']);
            $table->index(['site_id', 'utm_campaign', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'clean_path', 'created_at']);
            $table->dropIndex(['site_id', 'utm_source', 'created_at']);
            $table->dropIndex(['site_id', 'utm_medium', 'created_at']);
            $table->dropIndex(['site_id', 'utm_campaign', 'created_at']);

            $table->dropColumn([
                'clean_path',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_term',
                'utm_content',
            ]);
        });
    }
};
