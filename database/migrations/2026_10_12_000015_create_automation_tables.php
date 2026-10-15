<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            // router_offline | cpu_high | router_unreachable
            $table->string('trigger');
            $table->json('conditions')->nullable();   // e.g. {"threshold": 80}
            // notify_log | notify_webhook | notify_telegram
            $table->string('action')->default('notify_log');
            $table->json('action_params')->nullable(); // redacted secrets
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'enabled']);
        });

        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
            $table->string('status');            // triggered | ok | error
            $table->text('message')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['automation_rule_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
        Schema::dropIfExists('automation_rules');
    }
};
