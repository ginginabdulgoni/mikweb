<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('host');                 // IP address or hostname
            $table->unsignedSmallInteger('api_port')->default(8728);
            $table->unsignedSmallInteger('api_ssl_port')->default(8729);
            $table->unsignedSmallInteger('ssh_port')->default(22);

            // api | api-ssl | ssh | rest
            $table->string('preferred_transport')->default('api');
            $table->boolean('use_tls')->default(false);
            $table->boolean('verify_tls')->default(true);

            // SNMP monitoring
            $table->boolean('snmp_enabled')->default(false);
            $table->string('snmp_version')->nullable();     // v2c | v3
            $table->unsignedSmallInteger('snmp_port')->default(161);

            // Location / inventory metadata
            $table->string('site')->nullable();
            $table->string('isp')->nullable();
            $table->string('location')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('tags')->nullable();

            // Discovered from the device (source of truth = RouterOS).
            $table->string('identity')->nullable();
            $table->string('routeros_version')->nullable();
            $table->string('hardware_model')->nullable();
            $table->string('board_name')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('architecture')->nullable();

            // Live status snapshot (detail lives in router_metrics).
            $table->string('status')->default('unknown'); // online|offline|unreachable|unknown
            $table->timestamp('last_seen_at')->nullable();
            $table->text('last_error')->nullable();

            // Monitoring controls
            $table->boolean('monitoring_enabled')->default(true);
            $table->unsignedInteger('poll_interval_seconds')->default(30);

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index('host');
            $table->unique(['organization_id', 'host']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
