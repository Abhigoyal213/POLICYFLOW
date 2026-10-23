<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique(); // Idempotency key — prevents duplicate callback processing
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->json('payload');
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('policy_request_id', 'idx_request_events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
