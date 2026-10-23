<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();
            $table->string('provider_reference', 100)->unique();
            $table->string('policy_number', 100)->unique();
            $table->string('status', 30)->default('ISSUED');
            $table->json('request_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_policies');
    }
};
