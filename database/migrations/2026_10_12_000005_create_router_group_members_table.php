<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();
            $table->foreignId('router_group_id')->constrained('router_groups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['router_id', 'router_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_group_members');
    }
};
