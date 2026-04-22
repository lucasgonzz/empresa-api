<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncedVersionsTable extends Migration
{
    public function up()
    {
        Schema::create('synced_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('version', 30);
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index('is_current');
        });
    }

    public function down()
    {
        Schema::dropIfExists('synced_versions');
    }
}
