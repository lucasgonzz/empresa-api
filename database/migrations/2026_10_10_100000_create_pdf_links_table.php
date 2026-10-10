<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePdfLinksTable extends Migration
{
    /**
     * Links con token para los PDF que salen del sistema (misión pdf-de-venta-publico, 10/10/2026).
     *
     * Hasta esta misión las rutas de PDF de `routes/web.php` eran públicas por id: cualquiera que
     * adivinara un número abría el comprobante de cualquier comercio de la base. Ahora se sirven con
     * la sesión del comercio dueño, o con un `?t=<token>` que vive en esta tabla.
     *
     * 🔴 ES UN CONTRATO CON tienda-api (misma base física): tienda-api inserta y lee filas acá para
     * los PDF de cuenta corriente que abre el comprador. No se renombra ni se saca ninguna columna
     * sin hablarlo, y `tipo` usa exactamente los valores de `PdfLinkHelper::TIPOS_CON_TOKEN`.
     *
     * - `user_id`: el dueño del recurso (el `user_id` del modelo), para poder listar o revocar los
     *   links de un comercio. No interviene en la validación del token.
     * - `tipo` + `model_id`: el recurso. Un token abre ESE recurso y ningún otro.
     * - `token`: `Str::random(48)`. Único: es lo que se busca en cada descarga.
     * - `revoked_at`: con fecha, el link deja de abrir.
     *
     * Sin foreign keys (regla del repo) y con nombres de índice cortos.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('pdf_links')) {
            return;
        }

        Schema::create('pdf_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('tipo', 40);
            $table->unsignedBigInteger('model_id');
            $table->string('token', 64);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('token', 'pdf_links_token_uq');
            $table->index(['tipo', 'model_id'], 'pdf_links_tipo_model_idx');
            $table->index('user_id', 'pdf_links_user_idx');
        });
    }

    /**
     * Saca la tabla. ⚠️ Con ella mueren todos los links con token ya compartidos: después de un
     * rollback, esos links abren solo mientras la ruta vuelva a ser pública (código viejo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pdf_links');
    }
}
