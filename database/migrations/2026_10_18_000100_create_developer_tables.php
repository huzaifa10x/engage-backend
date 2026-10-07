<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Developer module: API keys, customer webhook endpoints, and the log of webhook deliveries. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('prefix', 16);                 // first characters, shown so a key can be recognised
            $table->char('key_hash', 64)->unique();       // sha256 of the full key; the key itself is never stored
            $table->jsonb('scopes');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'revoked_at']);
        });
        TenantSchema::enableRls('api_keys');

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('url', 2000);
            $table->string('description', 160)->nullable();
            $table->text('secret');                       // encrypted; signs every delivery
            $table->jsonb('events');
            $table->string('status', 16)->default('active'); // active | paused | disabled (after repeated failures)
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
        });
        TenantSchema::enableRls('webhook_endpoints');

        Schema::create('webhook_endpoint_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('event', 48);
            $table->jsonb('payload');
            $table->string('status', 16)->default('pending'); // pending | delivered | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_excerpt', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->index(['webhook_endpoint_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
        TenantSchema::enableRls('webhook_endpoint_deliveries');
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoint_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_keys');
    }
};
