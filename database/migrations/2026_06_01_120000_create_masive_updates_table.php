<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMasiveUpdatesTable extends Migration
{
    /**
     * Crea tablas para historial de actualizaciones masivas y su pivot con artículos.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('masive_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('employee_id')->nullable()->index();
            $table->string('model_name', 60);
            $table->string('action', 20)->default('update');
            $table->string('status', 30)->default('pending');
            $table->boolean('from_filter')->default(false);
            $table->unsignedBigInteger('parent_masive_update_id')->nullable()->index();
            $table->longText('criteria_json')->nullable();
            $table->longText('non_article_items_json')->nullable();
            $table->integer('affected_count')->default(0);
            $table->integer('changes_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'model_name'], 'masive_upd_user_model_idx');
        });

        Schema::create('masive_update_article', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('masive_update_id')->index();
            $table->unsignedInteger('article_id')->index();
            $table->longText('changes_json');
            $table->timestamps();

            $table->index(['masive_update_id', 'article_id'], 'masive_upd_art_idx');
        });
    }

    /**
     * Elimina tablas de actualizaciones masivas.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('masive_update_article');
        Schema::dropIfExists('masive_updates');
    }
}
