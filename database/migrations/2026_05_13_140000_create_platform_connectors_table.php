<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de conectores OAuth hacia plataformas externas (Mercado Libre, Tienda Nube).
 * Las credenciales de app y tokens viven en BD; las URLs de callback siguen en .env.
 */
class CreatePlatformConnectorsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_connectors', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->index();
            $table->string('platform', 30)->index();
            $table->string('status', 30)->default('sin_conectar')->index();

            $table->string('client_id', 120)->nullable();
            $table->text('client_secret')->nullable();
            $table->json('extra_config')->nullable();

            $table->text('auth_code')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->string('platform_user_id', 80)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_connectors');
    }
}
