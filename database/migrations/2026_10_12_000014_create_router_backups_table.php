<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type')->default('export');   // export (text) | binary
            $table->string('disk')->default('local');
            $table->string('path');                        // storage path
            $table->string('filename');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('routeros_version')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['router_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_backups');
    }
};
