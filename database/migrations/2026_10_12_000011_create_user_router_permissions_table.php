<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_router_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Scope: a specific router, a whole group, or (both null) every
            // router in the user's organization.
            $table->foreignId('router_id')->nullable()->constrained('routers')->cascadeOnDelete();
            $table->foreignId('router_group_id')->nullable()->constrained('router_groups')->cascadeOnDelete();

            // Granular abilities granted within the scope, e.g.
            // ["router.view", "ppp.manage", "firewall.view"]. ["*"] = all.
            $table->json('abilities');

            $table->timestamps();

            $table->index(['user_id', 'router_id']);
            $table->index(['user_id', 'router_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_router_permissions');
    }
};
