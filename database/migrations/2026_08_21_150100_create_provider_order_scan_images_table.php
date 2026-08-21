<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión escaneo-factura-compra — las fotos de cada escaneo, una fila por página.
 *
 * Los binarios NO están acá: viven en el disco `local` (privado, nunca el `public`), en
 * storage/app/provider_order_scans/{user_id}/{uuid}/{orden}.webp, ya redimensionados a 1568 px
 * de lado mayor. Esta tabla guarda la ruta y los metadatos que el modal de revisión muestra
 * página por página.
 *
 * 🔴 Por qué esto es una tabla hija y no un JSON adentro de provider_order_scans:
 *
 *  1. Cada imagen tiene metadatos propios (orden/página, mime, bytes, nombre original, ruta)
 *     que el modal muestra por separado. Un JSON obligaría a decodificar todo el blob para
 *     servir una sola foto.
 *  2. El endpoint que sirve el binario chequea tenencia por SQL, en una consulta:
 *     where('user_id', owner)->where('provider_order_scan_id', $id)->where('orden', $n).
 *     Con un JSON serían tres pasos (cargar, decodificar, indexar a mano) y el segundo se
 *     puede olvidar; ahí se filtra la factura de otro comercio.
 *  3. Sin carrera de escritura: el job escribe `resultado` en la fila padre mientras el
 *     usuario podría estar agregando otra foto. Un INSERT acá no pisa ese UPDATE; adentro del
 *     mismo JSON sí lo pisaría (lost update silencioso).
 *  4. Es el patrón del proyecto: provider_order_afip_ticket_ivas es tabla hija de
 *     provider_order_afip_tickets, no un JSON. No inventamos un mecanismo nuevo para lo mismo.
 *
 * Dos columnas están DESNORMALIZADAS a propósito, y no es un descuido:
 *
 *  - provider_order_id: permite "todas las fotos de esta compra" sin join, y sobrevive si
 *    algún día se borra el escaneo.
 *  - user_id: el endpoint que sirve el binario chequea tenencia sin join. Una factura tiene
 *    CUIT, razón social y precios: no puede salir por una URL pública ni por un join
 *    olvidable.
 */
class CreateProviderOrderScanImagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('provider_order_scan_images', function (Blueprint $table) {
            $table->bigIncrements('id');

            /* Escaneo al que pertenece la foto. */
            $table->unsignedBigInteger('provider_order_scan_id');

            /* Desnormalizado a propósito (ver el comentario de cabecera). */
            $table->unsignedInteger('provider_order_id');

            /* Desnormalizado a propósito: tenencia sin join (ver el comentario de cabecera). */
            $table->unsignedInteger('user_id');

            /*
             * Página, en el orden en que el usuario eligió las fotos. Es exactamente lo que le
             * dice a la IA "esta es la página 2 de 3", así que no es cosmético: si se pierde el
             * orden, un renglón cortado entre dos páginas se reconstruye al revés.
             */
            $table->unsignedTinyInteger('orden')->default(1);

            /*
             * Ruta relativa dentro de storage/app. 191 y no 255 para poder indexarla sin líos
             * de charset (utf8mb4 × 255 se pasa del máximo de un índice).
             */
            $table->string('path', 191);

            $table->string('mime', 40)->nullable();
            $table->unsignedInteger('bytes')->nullable();

            /* El nombre del archivo que subió el usuario, para poder mostrárselo. */
            $table->string('nombre_original', 191)->nullable();

            $table->timestamps();

            /* Nombres cortos y explícitos, mismo motivo que en provider_order_scans. */
            $table->index('provider_order_scan_id', 'posi_scan_idx');
            $table->index('provider_order_id', 'posi_order_idx');
            $table->index('user_id', 'posi_user_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('provider_order_scan_images');
    }
}
