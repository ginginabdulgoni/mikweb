<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->text('telegram_bot_token')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->string('whatsapp_api_url')->nullable();
            $table->text('whatsapp_api_token')->nullable();
            $table->string('whatsapp_target_phone')->nullable();
            $table->boolean('notify_router_down')->default(true);
            $table->boolean('notify_router_recovery')->default(true);
            $table->boolean('notify_high_cpu')->default(true);
            $table->unsignedSmallInteger('high_cpu_threshold')->default(90);
            $table->boolean('notify_daily_digest')->default(true);
            $table->boolean('notify_backup_completion')->default(true);
            $table->boolean('notify_security_alert')->default(true);
            $table->timestamps();

            $table->unique('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
