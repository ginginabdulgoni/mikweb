<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_connection_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();

            $table->string('transport');   // api | api-ssl | ssh | snmp | rest
            $table->string('event');       // connect | test | health_check | error | disconnect
            $table->boolean('success')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('message')->nullable();   // redacted, human-readable
            $table->json('context')->nullable();   // redacted structured context
            $table->timestamp('created_at')->nullable();

            $table->index(['router_id', 'created_at']);
            $table->index(['router_id', 'success']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_connection_logs');
    }
};
