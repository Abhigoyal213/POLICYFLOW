<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('scenario', 50)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('status', 30); // SENT, SUCCESS, FAILED, TIMEOUT
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['policy_request_id', 'attempt_number'], 'uq_request_attempt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
