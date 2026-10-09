<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\Afip\IntentosDeFacturaFallidosHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Saca de Alertas → "Facturacion" las ventas que ya están facturadas pero siguen ahí por un intento
 * fallido viejo (misión facturas-reintentadas-salen-de-alertas, 9/10/2026).
 *
 * Desde esa misión, cuando una factura queda autorizada, los cuatro caminos de éxito
 * (`AfipWsfeHelper::solicitar_cae()`, `AfipWsfeHelper::consultar_comprobante()`,
 * `AfipFexHelper::update_afip_ticket()` y `AfipFexHelper::consultar_comprobante()`) descartan solos
 * los intentos superados de esa venta. Esto es para los que quedaron de antes: recorre los tickets
 * de factura sin CAE de las ventas que YA tienen al menos una factura autorizada, y les aplica el
 * MISMO criterio (`IntentosDeFacturaFallidosHelper::motivo_de_descarte()`): (a) sin número, (b)
 * mismo comprobante que la factura autorizada, (c) rechazado por ARCA (`resultado = 'R'`).
 *
 * 🔴 Un intento con OTRO número y sin rechazo explícito NO se toca: puede ser una factura que ARCA
 * autorizó y cuya respuesta se perdió, o sea una posible factura duplicada. Tiene que seguir en
 * Alertas para que alguien apriete Consultar. El listado los muestra como "queda" para eso.
 *
 *   - Sin `--aplicar`: lista cada intento (venta, ticket, motivo) y NO escribe nada.
 *   - Con `--aplicar`: hace el borrado suave (SoftDeletes, lo mismo que el tacho de la tarjeta).
 *     Los `afip_errors` del intento quedan como historia. Borra con el
 *     MISMO `IntentosDeFacturaFallidosHelper::descartar_con_resultado()` que la limpieza en vivo: un
 *     `UPDATE` que vuelve a exigir el motivo, así que si entre el listado y el borrado ese ticket
 *     recibió el CAE o su número (una emisión en curso), no se toca. Eso NO es un error: se informa
 *     aparte ("cambió entre medio") y no va al log como warning. "Error" es solo una excepción.
 *   - `{user_id?}`: solo las ventas de ese comercio (`sales.user_id`). En una base compartida, sin
 *     esto se recorren las de todos.
 *
 * Ojo con los rechazos viejos: antes de esta misión WSFE no guardaba la `R`, así que un rechazo de
 * Factura A/B/C anterior solo sale si cumple (a) o (b). Es lo esperable, no una falla del comando:
 * sin la `R` asentada no hay forma de distinguirlo de un error de red.
 *
 * 🔴 SALE SIEMPRE CON EXIT 0, aunque algún intento no se pueda descartar o la corrida se corte a
 * mitad (ver handle()). Va en el despliegue, y con un exit distinto de 0 el despliegue del admin
 * frena antes de rotar el frente (mismo criterio que `cuenta_corriente:crear_cuentas_faltantes`).
 * Los fallidos quedan en el log (con report() de la excepción) y en la salida.
 *
 * Idempotente: una segunda corrida no encuentra nada que descartar.
 */
class DescartarIntentosDeFacturaFallidos extends Command
{
    protected $signature = 'afip:descartar-intentos-fallidos
                            {user_id? : Solo las ventas de este comercio}
                            {--aplicar : Descarta los intentos (sin esto, solo lista)}';

    protected $description = 'Lista (y con --aplicar descarta con borrado suave) los intentos de factura sin CAE que una factura autorizada de la misma venta ya superó: sin número, mismo número o rechazados por ARCA.';

    /**
     * 🔴 Todo el cuerpo va adentro de un try: el exit 0 no puede depender de que la falla caiga en
     * el try de cada intento. Lo descartado antes del corte queda, y volver a correrlo completa el
     * resto: es idempotente.
     *
     * @return int
     */
    public function handle()
    {
        try {

            return $this->revisar();

        } catch (\Throwable $e) {

            // Sin report() este fallo no llegaría al reporter de errores.
            report($e);

            Log::warning('afip:descartar-intentos-fallidos: se cortó antes de terminar: '.$e->getMessage());

            $this->error('afip:descartar-intentos-fallidos: se cortó antes de terminar: '.$e->getMessage());

            // Siempre 0: ver el docblock de la clase.
            return 0;
        }
    }

    /**
     * El cuerpo del comando: valida el user_id, recorre los intentos y deja el resumen.
     *
     * @return int
     */
    protected function revisar()
    {
        $aplicar = (bool) $this->option('aplicar');
        $user_id = $this->argument('user_id');

        if (!is_null($user_id) && $user_id !== '' && !ctype_digit((string) $user_id)) {

            // Un user_id raro NO puede terminar recorriendo las ventas de toda la base.
            $this->error('afip:descartar-intentos-fallidos: user_id inválido ("'.$user_id.'"). No se revisa nada.');
            Log::warning('afip:descartar-intentos-fallidos: user_id inválido ("'.$user_id.'"). No se revisó nada.');

            return 0;
        }

        /**
         * Solo los intentos de ventas que ya tienen una factura autorizada: sin eso
         * `motivo_de_descarte()` devuelve null igual, y así no se recorren todas las ventas con un
         * intento pendiente de verdad.
         */
        $query = IntentosDeFacturaFallidosHelper::intentos_sin_cae()
                    ->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                          ->from('afip_tickets as autorizadas')
                          ->whereColumn('autorizadas.sale_id', 'afip_tickets.sale_id')
                          ->whereNotNull('autorizadas.cae')
                          ->where('autorizadas.cae', '!=', '')
                          ->whereNull('autorizadas.deleted_at')
                          ->whereNull('autorizadas.nota_credito_id')
                          ->whereNull('autorizadas.sale_nota_credito_id');
                    });

        if (!is_null($user_id) && $user_id !== '') {
            $query->whereIn('sale_id', function ($q) use ($user_id) {
                $q->select('id')
                  ->from('sales')
                  ->where('user_id', (int) $user_id);
            });
        }

        $revisados = 0;
        $quedan = 0;
        $descartables = [
            IntentosDeFacturaFallidosHelper::MOTIVO_SIN_NUMERO   => 0,
            IntentosDeFacturaFallidosHelper::MOTIVO_MISMO_NUMERO => 0,
            IntentosDeFacturaFallidosHelper::MOTIVO_RECHAZADO    => 0,
        ];
        $descartados = 0;
        $cambiaron = 0;
        $fallidos = [];

        $query->chunkById(200, function ($intentos) use ($aplicar, &$revisados, &$quedan, &$descartables, &$descartados, &$cambiaron, &$fallidos) {

            $sale_ids = $intentos->pluck('sale_id')->unique()->values()->all();

            $autorizadas_por_venta = IntentosDeFacturaFallidosHelper::facturas_autorizadas_por_venta($sale_ids);

            // Dueño de cada venta, solo para el listado (sin el scope de SoftDeletes de Sale).
            $comercio_por_venta = DB::table('sales')->whereIn('id', $sale_ids)->pluck('user_id', 'id');

            foreach ($intentos as $intento) {

                $revisados++;

                $autorizadas = isset($autorizadas_por_venta[(int) $intento->sale_id]) ? $autorizadas_por_venta[(int) $intento->sale_id] : [];

                $motivo = IntentosDeFacturaFallidosHelper::motivo_de_descarte($intento, $autorizadas);

                $prefijo = 'Venta '.$intento->sale_id.' (comercio '.(isset($comercio_por_venta[$intento->sale_id]) ? $comercio_por_venta[$intento->sale_id] : '-').'): '
                    .'ticket #'.$intento->id.' ('.IntentosDeFacturaFallidosHelper::comprobante_legible($intento).')';

                if (is_null($motivo)) {

                    $quedan++;

                    $this->line('  Queda '.$prefijo.': otro número y sin rechazo, puede estar autorizado en ARCA. Hay que Consultarlo.');

                    continue;
                }

                $descartables[$motivo]++;

                $this->line('Descartable '.$prefijo.': '.IntentosDeFacturaFallidosHelper::descripcion_del_motivo($motivo).'.');

                if (!$aplicar) {
                    continue;
                }

                $resultado = IntentosDeFacturaFallidosHelper::descartar_con_resultado($intento, $motivo, 'afip:descartar-intentos-fallidos');

                if ($resultado === IntentosDeFacturaFallidosHelper::DESCARTE_HECHO) {

                    $descartados++;

                } else if ($resultado === IntentosDeFacturaFallidosHelper::DESCARTE_CAMBIO_ENTRE_MEDIO) {

                    // El caso esperado de la carrera: otra emisión le escribió el CAE o el número.
                    $cambiaron++;

                    $this->line('  No se tocó el ticket #'.$intento->id.': cambió entre el listado y el borrado (otra emisión en curso).');

                } else {

                    $this->error('  No se pudo descartar el ticket #'.$intento->id.' (ver el log).');

                    $fallidos[] = $intento->id;
                }
            }
        });

        $total_descartables = array_sum($descartables);

        $resumen = 'Intentos sin CAE revisados (de ventas con una factura autorizada): '.$revisados.'. '
            .'Descartables: '.$total_descartables.' ('
            .'sin número: '.$descartables[IntentosDeFacturaFallidosHelper::MOTIVO_SIN_NUMERO].', '
            .'mismo número: '.$descartables[IntentosDeFacturaFallidosHelper::MOTIVO_MISMO_NUMERO].', '
            .'rechazados: '.$descartables[IntentosDeFacturaFallidosHelper::MOTIVO_RECHAZADO].'). '
            .'Quedan en Alertas: '.$quedan.'.';

        if ($aplicar) {
            $resumen .= ' Descartados: '.$descartados.'. Cambiaron entre medio (no se tocaron): '.$cambiaron.'.'
                .' Sin descartar por error: '.count($fallidos)
                .(count($fallidos) ? ' (tickets '.implode(', ', $fallidos).')' : '').'.';
        } else {
            $resumen .= ' (sin --aplicar: no se escribió nada)';
        }

        $this->info($resumen);

        if ($aplicar && $descartados > 0) {
            Log::info('afip:descartar-intentos-fallidos: '.$resumen);
        }

        if (count($fallidos)) {
            Log::warning('afip:descartar-intentos-fallidos: '.$resumen);
        }

        // Siempre 0: ver el docblock de la clase.
        return 0;
    }
}
