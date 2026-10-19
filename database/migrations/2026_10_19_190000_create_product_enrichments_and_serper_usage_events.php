<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductEnrichmentsAndSerperUsageEvents extends Migration
{
    public function up()
    {
        Schema::create('product_enrichments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('auth_user_id');
            $table->string('bar_code', 14);
            $table->string('gtin', 14)->index();
            $table->string('status', 20)->default('queued');
            $table->boolean('cache_hit')->default(false);
            $table->json('product')->nullable();
            $table->json('classification')->nullable();
            $table->string('message', 255)->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->unsignedInteger('search_attempts')->default(0);
            $table->timestamp('search_started_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'auth_user_id', 'gtin', 'status'], 'product_enrichment_pending');
        });
        Schema::create('serper_usage_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('event_uuid')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('auth_user_id')->nullable();
            $table->string('auth_user_name', 191)->nullable();
            $table->string('process', 80);
            $table->string('endpoint', 10);
            $table->string('query', 255)->nullable();
            $table->boolean('ok');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->decimal('credits', 12, 4)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('serper_usage_events');
        Schema::dropIfExists('product_enrichments');
    }
}
