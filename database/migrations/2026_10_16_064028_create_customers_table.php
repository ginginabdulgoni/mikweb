<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type')->default('pppoe'); // pppoe | hotspot | static
            $table->string('username')->index();
            $table->string('customer_name');
            $table->string('phone_number')->nullable();
            $table->text('address')->nullable();
            $table->decimal('monthly_bill', 12, 2)->default(0);
            $table->unsignedTinyInteger('due_day')->default(10); // Day of month (1-31)
            $table->string('billing_status')->default('unpaid'); // paid | unpaid | overdue
            $table->boolean('is_active')->default(true);
            $table->boolean('is_isolated')->default(false);
            $table->timestamp('last_paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'service_type', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
