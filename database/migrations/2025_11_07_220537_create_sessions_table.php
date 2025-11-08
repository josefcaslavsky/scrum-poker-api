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
        Schema::create('poker_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique();
            $table->unsignedBigInteger('host_id')->nullable();
            $table->integer('current_round')->default(1);
            $table->enum('status', ['waiting', 'voting', 'revealed'])->default('waiting');
            $table->timestamps();

            $table->index('code');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poker_sessions');
    }
};
