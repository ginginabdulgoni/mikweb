<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();

            $table->string('action');                 // router.created, router.test_connection, ...
            $table->nullableMorphs('auditable');      // auditable_type + auditable_id
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('old_values')->nullable();   // redacted
            $table->json('new_values')->nullable();   // redacted
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
