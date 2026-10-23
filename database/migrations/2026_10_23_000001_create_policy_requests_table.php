<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_request_id', 100);
            $table->string('agent_id', 100);
            $table->string('customer_name', 255);
            $table->date('date_of_birth');
            $table->string('destination', 20);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 20);
            $table->unsignedInteger('sum_insured');
            $table->decimal('premium', 12, 2)->nullable();
            $table->string('state', 30)->default('RECEIVED');
            $table->string('policy_number', 100)->nullable()->unique();
            $table->string('provider_reference', 255)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->json('last_provider_response')->nullable();
            $table->text('error_details')->nullable();
            $table->timestamps();

            // DB-level idempotency for duplicate intake
            $table->unique(['agent_id', 'client_request_id'], 'uq_agent_client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
