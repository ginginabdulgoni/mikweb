<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();

            $table->unsignedTinyInteger('cpu_load')->nullable();      // percent
            $table->unsignedBigInteger('mem_used')->nullable();       // bytes
            $table->unsignedBigInteger('mem_total')->nullable();
            $table->unsignedBigInteger('disk_used')->nullable();
            $table->unsignedBigInteger('disk_total')->nullable();
            $table->string('uptime')->nullable();
            $table->boolean('reachable')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('recorded_at')->index();

            $table->index(['router_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_metrics');
    }
};
