<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every amount of money is a whole number of nanodollars (see App\Support\Money).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->bigInteger('balance')->default(0);
            $table->boolean('is_operator')->default(false);
        });

        Schema::create('generations', function (Blueprint $table): void {
            // Our own id. It is also the Idempotency-Key and the metadata we send to Pixelforge.
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            $table->string('status');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // What we told the user before submitting.
            $table->bigInteger('estimated_lowest');
            $table->bigInteger('estimated_expected');
            $table->bigInteger('estimated_highest');
            // Credit set aside for this generation until it finishes.
            $table->bigInteger('reserved')->default(0);

            // Known once Pixelforge is done.
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->bigInteger('provider_cost')->nullable();
            $table->bigInteger('charged')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->timestamp('started_at')->nullable();
            // Pixelforge bills on the UTC day a generation completed, so reports group by this.
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'checked_at']);
            $table->index('completed_at');
        });

        Schema::create('webhook_events', function (Blueprint $table): void {
            // Pixelforge's event id. Being the primary key is what makes a second delivery a no-op.
            $table->string('id')->primary();
            $table->string('type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            // Positive for a purchase, negative for a generation.
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            // Unique: the database itself refuses to charge a generation twice.
            $table->foreignUuid('generation_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('generations');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['balance', 'is_operator']);
        });
    }
};
