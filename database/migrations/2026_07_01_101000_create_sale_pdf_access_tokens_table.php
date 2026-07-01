<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla compartida (misma BD que tienda-api) de tokens de un solo uso para
 * autorizar la generación del PDF de una venta desde la tienda.
 */
class CreateSalePdfAccessTokensTable extends Migration
{
    /**
     * Crea la tabla sin FKs (convención del proyecto).
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sale_pdf_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 128)->unique();
            $table->unsignedBigInteger('sale_id')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla creada.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sale_pdf_access_tokens');
    }
}
