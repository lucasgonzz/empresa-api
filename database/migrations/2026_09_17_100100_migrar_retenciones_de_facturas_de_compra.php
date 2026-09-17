<?php

use App\Models\RetencionSufrida;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Pasa a `retenciones_sufridas` las retenciones que hoy viven en las columnas viejas de
 * `provider_order_afip_tickets` (misión compras-factura-manual-alicuotas, 17/9/2026, parte C).
 *
 * 🔴 POR QUÉ NO SE PUEDE SALTEAR. Hasta esta misión la Posición Fiscal leía las retenciones de
 * `provider_order_afip_tickets`. Al cambiar la fuente a la tabla nueva, todo cliente que ya las
 * haya cargado ahí se quedaría con el renglón de "retenciones sufridas" en CERO, y eso no es un
 * detalle de pantalla: le cambia el saldo de IVA y el de IIBB del período. Un cero indistinguible
 * de "este mes no hubo ninguna" es exactamente la diferencia entre lo que el comercio paga y lo
 * que tendría que pagar.
 *
 * Qué se copia y qué no:
 *   - `fecha`  <- `issued_at` del comprobante (si está vacío, `created_at`; si tampoco, hoy). La
 *     columna es obligatoria porque es la que fecha el período fiscal, así que no puede quedar nula.
 *   - `client_id` en NULL: de una factura de COMPRA no hay de dónde sacar qué cliente retuvo. El
 *     dato no existe; inventarlo sería peor que no tenerlo.
 *   - `current_acount_id` en NULL: estas retenciones nunca estuvieron atadas a un cobro.
 *   - `origen` = `migracion_factura_compra` y `provider_order_afip_ticket_id` = la factura, para
 *     que se pueda distinguir de un certificado cargado por una persona y para que el drill-down
 *     del reporte siga llevando a la compra de origen.
 *
 * 🔴 LAS COLUMNAS VIEJAS NO SE BORRAN. `retencion_iva`, `retencion_iibb` y `retencion_ganancias`
 * quedan donde están, con su valor, por si hay que volver. Esta migración solo agrega filas.
 *
 * Es idempotente por (factura, impuesto): si vuelve a correr sobre una base ya migrada —el caso de
 * los clientes con huecos en la tabla `migrations`— no duplica nada.
 */
class MigrarRetencionesDeFacturasDeCompra extends Migration
{
    /**
     * Las tres columnas viejas y el impuesto al que corresponde cada una.
     *
     * @var array<string,string>
     */
    const COLUMNAS = [
        'retencion_iva'       => 'iva',
        'retencion_iibb'      => 'iibb',
        'retencion_ganancias' => 'ganancias',
    ];

    /**
     * @return void
     */
    public function up()
    {
        $movidas = $this->migrar();

        Log::info('MigrarRetencionesDeFacturasDeCompra: se pasaron '.$movidas.' retenciones de provider_order_afip_tickets a retenciones_sufridas.');
    }

    /**
     * El trabajo de verdad, separado de `up()` para poder correrlo desde un test sin tener que
     * reconstruir la base entera.
     *
     * @return int Cantidad de certificados creados en esta corrida.
     */
    public function migrar()
    {
        if (!Schema::hasTable('retenciones_sufridas') || !Schema::hasTable('provider_order_afip_tickets')) {

            return 0;
        }

        $movidas = 0;

        foreach (self::COLUMNAS as $columna => $impuesto) {

            if (!Schema::hasColumn('provider_order_afip_tickets', $columna)) {

                continue;
            }

            $movidas += $this->migrar_columna($columna, $impuesto);
        }

        return $movidas;
    }

    /**
     * Pasa una de las tres columnas viejas.
     *
     * @param  string $columna Nombre de la columna en `provider_order_afip_tickets`.
     * @param  string $impuesto Valor que va en `retenciones_sufridas.impuesto`.
     * @return int
     */
    private function migrar_columna($columna, $impuesto)
    {
        $movidas = 0;
        $ahora = Carbon::now();

        DB::table('provider_order_afip_tickets')
            ->where($columna, '>', 0)
            ->orderBy('id')
            ->select(['id', 'user_id', 'issued_at', 'created_at', $columna.' as importe'])
            ->chunk(500, function ($tickets) use ($columna, $impuesto, $ahora, &$movidas) {

                $ids = [];

                foreach ($tickets as $ticket) {
                    $ids[] = $ticket->id;
                }

                // Las que esta misma migración ya trajo en una corrida anterior.
                $ya_migradas = DB::table('retenciones_sufridas')
                                ->where('origen', RetencionSufrida::ORIGEN_MIGRACION_COMPRA)
                                ->where('impuesto', $impuesto)
                                ->whereIn('provider_order_afip_ticket_id', $ids)
                                ->pluck('provider_order_afip_ticket_id')
                                ->all();

                $ya_migradas = array_flip($ya_migradas);

                $filas = [];

                foreach ($tickets as $ticket) {

                    if (isset($ya_migradas[$ticket->id])) {

                        continue;
                    }

                    $filas[] = [
                        'user_id'                       => $ticket->user_id,
                        'current_acount_id'             => null,
                        'client_id'                     => null,
                        'impuesto'                      => $impuesto,
                        'numero_certificado'            => null,
                        'fecha'                         => $this->fecha_del_ticket($ticket),
                        'regimen'                       => null,
                        'base_imponible'                => null,
                        'alicuota'                      => null,
                        'importe'                       => $ticket->importe,
                        'origen'                        => RetencionSufrida::ORIGEN_MIGRACION_COMPRA,
                        'provider_order_afip_ticket_id' => $ticket->id,
                        'created_at'                    => $ahora,
                        'updated_at'                    => $ahora,
                    ];
                }

                if (count($filas)) {

                    DB::table('retenciones_sufridas')->insert($filas);

                    $movidas += count($filas);
                }
            });

        return $movidas;
    }

    /**
     * La fecha con la que entra la retención al período fiscal: la de emisión del comprobante, y si
     * está vacía, la de carga. Nunca nula (la columna es obligatoria).
     *
     * @param  object $ticket Fila cruda de `provider_order_afip_tickets`.
     * @return string Fecha en formato Y-m-d.
     */
    private function fecha_del_ticket($ticket)
    {
        if (!is_null($ticket->issued_at) && $ticket->issued_at != '') {

            return Carbon::parse($ticket->issued_at)->format('Y-m-d');
        }

        if (!is_null($ticket->created_at) && $ticket->created_at != '') {

            return Carbon::parse($ticket->created_at)->format('Y-m-d');
        }

        return Carbon::now()->format('Y-m-d');
    }

    /**
     * No borra nada: las filas migradas son la única copia que la Posición Fiscal lee después de
     * esta misión, y las columnas viejas siguen intactas de todos modos.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
