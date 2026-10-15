<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();

            // api | ssh | snmp
            $table->string('type');

            $table->string('username')->nullable();

            // All secret columns are encrypted at the model layer (never stored
            // in plaintext, never written to logs).
            $table->text('secret')->nullable();            // API/SSH password or SNMP community
            $table->text('private_key')->nullable();       // SSH private key (PEM)
            $table->string('ssh_host_fingerprint')->nullable(); // for host-key verification

            // SNMP v3
            $table->string('snmp_security_level')->nullable(); // noAuthNoPriv|authNoPriv|authPriv
            $table->string('snmp_auth_protocol')->nullable();  // MD5|SHA
            $table->text('snmp_auth_password')->nullable();
            $table->string('snmp_priv_protocol')->nullable();  // DES|AES
            $table->text('snmp_priv_password')->nullable();

            $table->timestamps();

            $table->unique(['router_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_credentials');
    }
};
