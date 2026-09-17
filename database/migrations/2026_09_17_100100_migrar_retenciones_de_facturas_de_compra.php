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
 * 🔴 EL CRITERIO QUE ORDENA TODOS LOS BORDES: ESTA MIGRACIÓN NO PUEDE CAMBIAR NINGÚN NÚMERO DE
 * NINGÚN REPORTE. Lo único que cambia es de dónde sale. De ahí salen las dos reglas que parecen
 * caprichosas y no lo son:
 *
 *   1. **Se copian los importes NEGATIVOS igual que los positivos.** La consulta vieja era un
 *      `SUM(retencion_iva)` sobre el período: un ajuste cargado en negativo RESTABA. Filtrar por
 *      `> 0` lo dejaría afuera y el renglón subiría sin que nadie tocara nada.
 *   2. **Las facturas con `issued_at` NULO no se copian.** La consulta vieja era
 *      `whereDate('issued_at', ...)`, y con NULL eso es falso en TODOS los períodos: esa retención
 *      no entraba en ninguno. Si se copiara con la fecha de carga, empezaría a entrar en el mes
 *      del despliegue — le inventaría crédito fiscal a un cliente y le podría dar vuelta el saldo
 *      de IVA de "a pagar" a "a favor". Si una fila no contaba, no puede empezar a contar. El dato
 *      no se pierde: las columnas viejas quedan intactas y el log dice cuántas se saltearon.
 *
 * Qué se copia y qué no:
 *   - `fecha`  <- `issued_at` del comprobante, siempre (ver regla 2: sin `issued_at` no hay fila).
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
    /**
     * Facturas que se saltearon por no tener `issued_at` (ver regla 2 del PHPDoc de clase). Se
     * cuentan para dejarlo dicho en el log: es dato que se queda en las columnas viejas.
     *
     * @var int
     */
    private $sin_fecha = 0;

    /**
     * @return void
     */
    public function up()
    {
        $movidas = $this->migrar();

        Log::info('MigrarRetencionesDeFacturasDeCompra: se pasaron '.$movidas.' retenciones de provider_order_afip_tickets a retenciones_sufridas.');

        if ($this->sin_fecha) {

            Log::warning('MigrarRetencionesDeFacturasDeCompra: se saltearon '.$this->sin_fecha.' retenciones de facturas sin issued_at. No entraban en ningun periodo de la Posicion Fiscal y seguirian sin entrar; el dato queda en las columnas viejas de provider_order_afip_tickets.');
        }
    }

    /**
     * Cuántas facturas se saltearon por no tener `issued_at` en la última corrida de migrar().
     *
     * @return int
     */
    public function sin_fecha()
    {
        return $this->sin_fecha;
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
        $this->sin_fecha = 0;

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
            /*
             * 🔴 `!= 0`, NO `> 0`. Un ajuste cargado en negativo RESTABA en el SUM() de la consulta
             * vieja; con `> 0` quedaría afuera y el renglón del reporte subiría solo (ver regla 1
             * del PHPDoc de clase). `whereNotNull` porque en MySQL `NULL != 0` no es verdadero,
             * pero se deja explícito para que se lea la intención.
             */
            ->whereNotNull($columna)
            ->where($columna, '!=', 0)
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

                    $fecha = $this->fecha_del_ticket($ticket);

                    if (is_null($fecha)) {

                        /*
                         * Sin `issued_at` la fila no entraba en ningún período de la Posición
                         * Fiscal, y copiarla con otra fecha la haría entrar en uno (regla 2 del
                         * PHPDoc de clase). Se saltea y se cuenta.
                         */
                        $this->sin_fecha++;

                        continue;
                    }

                    $filas[] = [
                        'user_id'                       => $ticket->user_id,
                        'current_acount_id'             => null,
                        'client_id'                     => null,
                        'impuesto'                      => $impuesto,
                        'numero_certificado'            => null,
                        'fecha'                         => $fecha,
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
     * La fecha con la que entra la retención al período fiscal: la de emisión del comprobante, y
     * NADA MAS.
     *
     * 🔴 SIN `issued_at` DEVUELVE NULL Y LA FILA NO SE COPIA, y acá está el borde que cuesta ver:
     * `issued_at` es nullable y la consulta vieja de la Posición Fiscal era
     * `whereDate('issued_at', '>=', $desde)->whereDate('issued_at', '<=', $hasta)`. Con NULL eso
     * da falso en TODOS los períodos, o sea que esa retención nunca sumó en ningún reporte.
     * Caer a `created_at` —que es lo que hacía la primera versión de este archivo— la haría
     * aparecer en el mes del despliegue: crédito fiscal que el cliente nunca tuvo, apareciendo
     * solo porque se migró. La migración no puede cambiar ningún número.
     *
     * @param  object $ticket Fila cruda de `provider_order_afip_tickets`.
     * @return string|null Fecha en formato Y-m-d, o null si el comprobante no tiene emisión.
     */
    private function fecha_del_ticket($ticket)
    {
        if (is_null($ticket->issued_at) || $ticket->issued_at == '') {

            return null;
        }

        return Carbon::parse($ticket->issued_at)->format('Y-m-d');
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
