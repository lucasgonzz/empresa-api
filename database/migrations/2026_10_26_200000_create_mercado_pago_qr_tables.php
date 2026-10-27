<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: crea `mercado_pago_qr_cajas` y `mercado_pago_qr_cobros` (misión
 * cobro-qr-mercado-pago, 26/10/2026).
 *
 * Es el cobro con el QR de Mercado Pago en la pantalla de Vender: el comercio pega en el
 * mostrador el QR estático de una "caja" de Mercado Pago, el cajero arma la venta, ComercioCity
 * le carga el total a ese QR (una orden de la API Orders) y el cliente lo escanea y paga desde su
 * celular. La venta se guarda recién cuando Mercado Pago dice que el pago entró.
 *
 * - `mercado_pago_qr_cajas`: un QR del comercio. Es la pareja sucursal + caja que vive en la
 *   cuenta de Mercado Pago del comercio (`store` y `pos` en el idioma de MP), con la imagen del
 *   QR y el PDF para imprimir que MP devuelve al crearla. Se ata a la CUENTA de MP con la que se
 *   creó (`mp_user_id`): si el comercio conecta otra cuenta, ese QR queda inactivo y no se usa
 *   para cobrar, porque el token nuevo no puede cargarle órdenes a una caja de otra cuenta.
 * - `mercado_pago_qr_cobros`: cada vez que el cajero toca "Cobrar con QR". Es el rastro de la
 *   orden en MP (`mp_order_id`), su estado traducido (`estado`) y, una vez pagado, la venta que
 *   lo usó (`sale_id`). Un cobro pagado se puede usar en UNA sola venta.
 *
 * Sin foreign keys físicas (regla del repo), con nombres de índice cortos y con las strings
 * indexadas acotadas a 120 caracteres o menos (el `utf8mb4` de MySQL con índices sobre
 * `varchar(191)` ya dio problemas en hostings viejos). Guard `hasTable` en cada tabla para que
 * sea segura de re-ejecutar, incluso si una corrida anterior murió entre las dos.
 */
class CreateMercadoPagoQrTables extends Migration
{
    /**
     * Crea las dos tablas, cada una con su propio guard.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('mercado_pago_qr_cajas')) {

            Schema::create('mercado_pago_qr_cajas', function (Blueprint $table) {
                $table->id();

                /* Comercio (owner). Todas las consultas del módulo filtran por acá. */
                $table->integer('user_id');
                $table->index('user_id', 'mpqc_user_idx');

                /* Nombre que el comercio le puso al QR ("Mostrador", "Caja 2"). */
                $table->string('name', 120);

                /* Caja de ComercioCity a la que corresponde (null = QR "General", sin caja). */
                $table->integer('caja_id')->nullable();

                /* Sucursal de ComercioCity (null = sin sucursal). */
                $table->integer('address_id')->nullable();

                /*
                 * Cuenta de Mercado Pago con la que se creó (`platform_user_id` del conector).
                 * Es lo que decide si el QR está activo: solo se cobra con los QR de la cuenta
                 * conectada hoy.
                 */
                $table->string('mp_user_id', 60);

                /* Sucursal en Mercado Pago: id que devolvió MP y external_id que generamos. */
                $table->string('mp_store_id', 60)->nullable();
                $table->string('mp_store_external_id', 60);

                /* Caja en Mercado Pago: id que devolvió MP y external_id que generamos. */
                $table->string('mp_pos_id', 60)->nullable();
                $table->string('mp_pos_external_id', 60);

                /* Lo que muestra la tarjeta de configuración y el modal de Vender. */
                $table->text('qr_image_url')->nullable();
                $table->text('qr_template_url')->nullable();
                $table->text('qr_code')->nullable();

                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('mercado_pago_qr_cobros')) {

            Schema::create('mercado_pago_qr_cobros', function (Blueprint $table) {
                $table->id();

                /* Comercio (owner). */
                $table->integer('user_id');
                $table->index('user_id', 'mpqco_user_idx');

                /* El QR al que se le cargó el monto. */
                $table->integer('mercado_pago_qr_caja_id');
                $table->index('mercado_pago_qr_caja_id', 'mpqco_qr_idx');

                /* Quién cobró (el usuario autenticado: dueño o empleado). */
                $table->integer('employee_id')->nullable();

                /* Caja y sucursal de la venta en el momento de cobrar. */
                $table->integer('caja_id')->nullable();
                $table->integer('address_id')->nullable();

                /* Total cargado en Mercado Pago. Es lo que se compara contra el total de la venta. */
                $table->decimal('total', 14, 2);

                /*
                 * `external_reference` de la orden en MP: `ccqr-{owner}-{cobro}-{4 aleatorios}`.
                 * Único: es lo que permite encontrar el cobro desde MP si algún día se suma el
                 * webhook. 64 es el máximo que acepta Orders.
                 */
                $table->string('external_reference', 64);
                $table->unique('external_reference', 'mpqco_extref_unq');

                /* Orden y pago en Mercado Pago (`ORD...` y `PAY...`). */
                $table->string('mp_order_id', 60)->nullable();
                $table->index('mp_order_id', 'mpqco_order_idx');
                $table->string('mp_payment_id', 60)->nullable();

                /* pendiente | pagado | vencido | cancelado | error. El comando concilia los pendientes. */
                $table->string('estado', 20)->default('pendiente');
                $table->index('estado', 'mpqco_estado_idx');

                /* Estado crudo de la orden en MP, para diagnosticar (nunca el JSON entero). */
                $table->string('mp_status', 60)->nullable();
                $table->string('mp_status_detail', 120)->nullable();

                /* Texto legible para el SPA. Nunca SQL ni una excepción cruda. */
                $table->string('error_message')->nullable();

                $table->timestamp('paid_at')->nullable();

                /* La venta que usó este cobro (null mientras no se guardó ninguna). */
                $table->integer('sale_id')->nullable();
                $table->index('sale_id', 'mpqco_sale_idx');

                $table->timestamps();
            });
        }
    }

    /**
     * Borra las dos tablas.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mercado_pago_qr_cobros');
        Schema::dropIfExists('mercado_pago_qr_cajas');
    }
}
