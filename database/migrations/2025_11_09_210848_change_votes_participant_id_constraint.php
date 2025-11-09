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
        Schema::table('votes', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['participant_id']);

            // Make participant_id nullable
            $table->foreignId('participant_id')->nullable()->change();

            // Add new foreign key with SET NULL on delete
            $table->foreign('participant_id')
                ->references('id')->on('participants')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            // Drop the new foreign key
            $table->dropForeign(['participant_id']);

            // Make participant_id non-nullable again
            $table->foreignId('participant_id')->nullable(false)->change();

            // Restore original foreign key with cascade
            $table->foreign('participant_id')
                ->references('id')->on('participants')
                ->onDelete('cascade');
        });
    }
};
