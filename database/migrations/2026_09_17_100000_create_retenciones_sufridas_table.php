<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `retenciones_sufridas`: un registro por CERTIFICADO de retención que le practicaron al
 * comercio (misión compras-factura-manual-alicuotas, 17/9/2026, parte C).
 *
 * 🔴 POR QUÉ NO VIVE EN LA FACTURA DE COMPRA. Una retención NO existe en una factura de compra: la
 * practica el CLIENTE cuando te paga, no el proveedor cuando te factura. Hasta hoy los tres montos
 * se cargaban en `provider_order_afip_tickets` (columnas `retencion_iva`, `retencion_iibb`,
 * `retencion_ganancias`) y de ahí las leía la Posición Fiscal. Desde esta misión el hecho se
 * registra donde ocurre —el cobro de la cuenta corriente de un cliente— y el certificado queda acá.
 *
 * La retención, además, es un MEDIO DE PAGO más: si el cliente te debe $100.000 y te retiene
 * $2.000, te paga $98.000 y la deuda se cancela por $100.000. Por eso el monto viaja por
 * `current_acount_payment_methods` (tipo con slug `retencion`, que no toca caja, igual que el
 * cheque) y esta tabla guarda SOLO los datos administrativos del papel.
 *
 * Los campos son los del certificado de retención de ARCA (RG 2233/2007, art. 8 y Anexo V). Los
 * datos del agente (CUIT, denominación) y del sujeto retenido no se repiten acá: el agente es el
 * cliente (`client_id`, que ya tiene su CUIT cargado) y el retenido es el comercio.
 *
 * 🔴 TODO NULLABLE MENOS `impuesto`, `fecha` E `importe`, Y ES A PROPÓSITO. Un comercio chico
 * muchas veces tiene el papel incompleto (sin número de certificado, sin régimen, sin base
 * imponible). Exigirle el régimen para poder registrar el cobro sería bloquear un hecho económico
 * real —la plata que entró— por un campo administrativo, y eso es peor que guardar el dato flojo:
 * el cobro no se puede posponer, el dato del papel sí se puede completar después.
 *
 * ⚠️ `regimen` es TEXTO LIBRE y no una lista cerrada, también a propósito: las retenciones de
 * Ingresos Brutos NO son de ARCA sino de régimen provincial (ARBA, AGIP y demás), con su propia
 * numeración, y ahí ese campo se usa como JURISDICCIÓN. Una lista cerrada de regímenes de ARCA
 * dejaría afuera a todas las de IIBB.
 *
 * Sin foreign keys, como todo el repo. La baja en cascada de los certificados cuando se borra el
 * cobro la hace el evento `deleting` de `App\Models\CurrentAcount`.
 */
class CreateRetencionesSufridasTable extends Migration
{
    /**
     * Crea la tabla si no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('retenciones_sufridas')) {
            return;
        }

        Schema::create('retenciones_sufridas', function (Blueprint $table) {

            $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id')->nullable();

            /*
             * El movimiento de cuenta corriente (el cobro) donde se cargó el certificado. Queda en
             * NULL en las filas que trajo la migración de datos desde las facturas de compra: esas
             * retenciones nunca estuvieron atadas a un cobro.
             */
            $table->unsignedBigInteger('current_acount_id')->nullable();

            /*
             * El agente de retención, o sea el cliente que te pagó reteniendo. Denormalizado para
             * que el reporte no tenga que salir a buscarlo por el cobro. También NULL en las filas
             * migradas: de una factura de compra no hay de dónde sacar qué cliente retuvo.
             */
            $table->unsignedBigInteger('client_id')->nullable();

            // `ganancias` | `iva` | `iibb`. Ver App\Models\RetencionSufrida::IMPUESTOS.
            $table->string('impuesto', 20);

            $table->string('numero_certificado', 60)->nullable();

            // Obligatoria: es la que fecha el período fiscal al que entra la retención.
            $table->date('fecha');

            // Régimen de ARCA, o JURISDICCIÓN cuando el impuesto es IIBB (ver PHPDoc de clase).
            $table->string('regimen', 120)->nullable();

            // Monto del comprobante que originó la retención. Mismo ancho que las columnas viejas.
            $table->decimal('base_imponible', 22, 2)->nullable();

            // Informativa: el importe manda, no se recalcula a partir de la alícuota.
            $table->decimal('alicuota', 8, 3)->nullable();

            // Obligatorio: el monto retenido, igual al `amount` de su fila de medio de pago.
            $table->decimal('importe', 22, 2);

            /*
             * De dónde salió la fila: `cobro` (el circuito normal) o `migracion_factura_compra`
             * (las que trajo la migración de datos desde `provider_order_afip_tickets`). Sin esta
             * marca no habría forma de distinguir un certificado cargado por una persona de uno
             * reconstruido a partir de un dato viejo, que no tiene ni cliente ni cobro.
             */
            $table->string('origen', 30)->nullable();

            /*
             * La factura de compra de la que salió la fila migrada. Sirve para dos cosas: que la
             * migración sea idempotente (no duplica lo que ya trajo) y que el drill-down del
             * reporte pueda seguir llevando a la compra de origen en las filas viejas.
             */
            $table->unsignedBigInteger('provider_order_afip_ticket_id')->nullable();

            $table->timestamps();

            // El reporte siempre pregunta por dueño + rango de fechas.
            $table->index(['user_id', 'fecha'], 'retenciones_sufridas_user_fecha_idx');
            $table->index('current_acount_id', 'retenciones_sufridas_current_acount_idx');
            $table->index('provider_order_afip_ticket_id', 'retenciones_sufridas_ticket_idx');
        });
    }

    /**
     * Borra la tabla. Las columnas viejas de `provider_order_afip_tickets` siguen en su lugar, así
     * que volver atrás no pierde el dato histórico.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('retenciones_sufridas');
    }
}
