<?php

declare(strict_types=1);

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
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->string('whatsapp_device_id')->nullable()->after('whatsapp_target_phone');
            $table->text('template_router_down')->nullable()->after('notify_security_alert');
            $table->text('template_router_recovery')->nullable()->after('template_router_down');
            $table->text('template_high_cpu')->nullable()->after('template_router_recovery');
            $table->text('template_daily_digest')->nullable()->after('template_high_cpu');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_device_id',
                'template_router_down',
                'template_router_recovery',
                'template_high_cpu',
                'template_daily_digest',
            ]);
        });
    }
};
