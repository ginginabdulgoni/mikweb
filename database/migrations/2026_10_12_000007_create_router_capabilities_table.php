<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();

            $table->unsignedTinyInteger('routeros_major')->nullable(); // 6 or 7
            $table->boolean('supports_rest')->default(false);
            $table->boolean('has_wifi_package')->default(false);       // modern "wifi" package
            $table->boolean('has_wireless_package')->default(false);   // legacy "wireless"
            $table->boolean('has_capsman')->default(false);
            $table->boolean('has_container')->default(false);

            // Full detected feature map / installed package list.
            $table->json('features')->nullable();
            $table->json('packages')->nullable();

            $table->timestamp('detected_at')->nullable();
            $table->timestamps();

            $table->unique('router_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_capabilities');
    }
};
