<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_command_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('transport');        // api | ssh | rest
            $table->string('command');          // e.g. /interface/print
            $table->json('arguments')->nullable(); // redacted
            $table->boolean('success')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('result_count')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['router_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_command_logs');
    }
};
