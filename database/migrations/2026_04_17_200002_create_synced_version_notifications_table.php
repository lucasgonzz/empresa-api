<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncedVersionNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('synced_version_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('synced_version_id');
            $table->uuid('admin_uuid')->unique();
            $table->string('title', 200);
            $table->text('body');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('synced_version_id')
                ->references('id')->on('synced_versions')
                ->onDelete('cascade');

            $table->index(['synced_version_id', 'is_active']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('synced_version_notifications');
    }
}
