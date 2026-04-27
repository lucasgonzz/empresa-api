<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncedVersionNotificationReadsTable extends Migration
{
    public function up()
    {
        Schema::create('synced_version_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('synced_version_notification_id');
            // Debe coincidir con users.id (increments = unsigned int), no con bigInteger.
            $table->unsignedInteger('user_id');
            $table->timestamp('read_at');
            $table->timestamp('synced_to_admin_at')->nullable();
            $table->timestamps();

            $table->foreign('synced_version_notification_id', 'svnr_notification_fk')
                ->references('id')->on('synced_version_notifications')
                ->onDelete('cascade');

            $table->foreign('user_id', 'svnr_user_fk')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->unique(
                ['synced_version_notification_id', 'user_id'],
                'svnr_notification_user_uq'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('synced_version_notification_reads');
    }
}
