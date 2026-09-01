<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\AfipTicket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Backfill del IVA de las notas de crédito ya facturadas ante ARCA.
 *
 * Hasta el 1/9/2026, `AfipNotaCreditoHelper::update_afip_ticket()` no persistía ni `importe_iva`
 * ni `afip_fecha_emision`: el IVA que se le declaraba a ARCA en `ImpIVA` se perdía apenas volvía la
 * respuesta. Sin ese dato, el renglón "IVA de notas de crédito emitidas" de la Posición Fiscal
 * arranca en cero para todo lo emitido antes de esa fecha. Este comando lo recupera.
 *
 * 🔴 Por qué NO alcanza con recalcular y guardar (que es lo que hace `SetIvaDebito`): el recálculo
 * de una NC vieja NO es confiable. `article_current_acount.iva_percentage` recién existe desde la
 * migración 2026_06_11_000002, así que para una NC anterior `AfipItemCalculator` cae a la alícuota
 * ACTUAL del artículo; la venta asociada se puede haber editado después; y una fila del pivot
 * borrada a mano devuelve un número más chico sin avisar.
 *
 * 🔴 El ancla que resuelve eso: `afip_tickets.importe_total` de la NC SÍ quedó persistido al
 * emitir, desde el mismísimo `getImportes()` que produjo el `ImpIVA` que fue a ARCA. Si el
 * recálculo de hoy reproduce ese total a la centésima, reprodujo el mismo conjunto de
 * ítems/precios/alícuotas y su `iva` es el que se declaró. Si no lo reproduce, se REPORTA y no se
 * escribe: un fallo de medición no puede devolver un valor tranquilizador (APRENDER_NO_PARCHEAR),
 * y menos uno que termina en una DDJJ.
 *
 * Corre en seco por defecto. Escribe solo con `--aplicar`.
 *
 *     php artisan set_iva_notas_credito "Nombre del comercio"
 *     php artisan set_iva_notas_credito "Nombre del comercio" --aplicar
 */
class SetIvaNotasCredito extends Command
{
    /** Tolerancia en pesos para dar por reproducido el total de un comprobante. */
    const TOLERANCIA = 0.01;

    protected $signature = 'set_iva_notas_credito {company_name} {--aplicar}';

    protected $description = 'Recupera el importe_iva y la afip_fecha_emision de las notas de credito ya facturadas ante ARCA (dry-run salvo --aplicar)';

    public function handle()
    {
        $aplicar = (bool) $this->option('aplicar');

        $user = User::where('company_name', $this->argument('company_name'))->first();

        if (is_null($user)) {
            $this->error('No existe ningun usuario con company_name "'.$this->argument('company_name').'".');
            return 1;
        }

        if (!$aplicar) {
            $this->warn('CORRIDA EN SECO: no se escribe nada. Volve a correrlo con --aplicar cuando el resumen cierre.');
        }

        /*
         * 🔴 `select('afip_tickets.*')` no es opcional: sin eso el join pisa la columna `id` con la
         * de `current_acounts` y todos los `save()` irian a la fila equivocada.
         */
        $tickets = AfipTicket::query()
            ->join('current_acounts', 'current_acounts.id', '=', 'afip_tickets.nota_credito_id')
            ->where('current_acounts.user_id', $user->id)
            ->where('afip_tickets.resultado', 'A')
            ->orderBy('afip_tickets.id', 'ASC')
            ->select('afip_tickets.*')
            ->get();

        $contadores = [
            'total'             => count($tickets),
            'ya_tenian'         => 0,
            'actualizadas'      => 0,
            'exportacion'       => 0,
            'no_reproducibles'  => 0,
            'sin_nota_credito'  => 0,
            'sin_factura'       => 0,
            'fecha_puesta'      => 0,
            'fecha_de_riesgo'   => 0,
        ];

        foreach ($tickets as $ticket) {

            // Fecha primero: aunque el IVA no se pueda reproducir, la fecha se puede recuperar y es
            // la que decide a que periodo se imputa el comprobante.
            if (is_null($ticket->afip_fecha_emision)) {

                $creado = Carbon::parse($ticket->created_at);

                /*
                 * `created_at` es el proxy correcto: la fila la crea `create_afip_ticket()` en la
                 * MISMA request en la que `interno()` manda `CbteFch => date('Ymd')`. El unico
                 * desvio posible es una NC emitida cruzando la medianoche, y esas se listan aparte
                 * para que las mire una persona.
                 */
                if ($creado->hour == 23 && $creado->minute >= 50) {
                    $contadores['fecha_de_riesgo']++;
                    $this->warn('  Ticket '.$ticket->id.': creado '.$creado->format('Y-m-d H:i').'. Cruce de medianoche posible, verificar contra el comprobante.');
                } else if ($creado->hour == 0 && $creado->minute <= 10) {
                    $contadores['fecha_de_riesgo']++;
                    $this->warn('  Ticket '.$ticket->id.': creado '.$creado->format('Y-m-d H:i').'. Cruce de medianoche posible, verificar contra el comprobante.');
                }

                if ($aplicar) {
                    $ticket->afip_fecha_emision = $creado->format('Y-m-d');
                    $ticket->timestamps = false;
                    $ticket->save();
                }

                $contadores['fecha_puesta']++;
            }

            // Idempotente: lo ya recuperado (o lo emitido despues del fix) no se vuelve a tocar.
            if (!is_null($ticket->importe_iva)) {
                $contadores['ya_tenian']++;
                continue;
            }

            /*
             * NC de exportacion (cbte_tipo 21): FEXAuthorize no lleva ImpIVA, el comprobante sale
             * sin IVA discriminado. El valor es 0 EXACTO, no hay nada que recalcular ni que
             * verificar.
             */
            if ((string) $ticket->cbte_tipo === '21') {
                if ($aplicar) {
                    $ticket->importe_iva = 0;
                    $ticket->timestamps = false;
                    $ticket->save();
                }
                $contadores['exportacion']++;
                continue;
            }

            $nota_credito = $ticket->nota_credito;

            if (is_null($nota_credito)) {
                $contadores['sin_nota_credito']++;
                $this->error('  Ticket '.$ticket->id.': su movimiento de nota de credito (nota_credito_id='.$ticket->nota_credito_id.') no existe. No se puede recalcular.');
                continue;
            }

            $afip_ticket_de_la_venta = $ticket->sale_afip;

            if (is_null($afip_ticket_de_la_venta)) {
                $contadores['sin_factura']++;
                $this->error('  Ticket '.$ticket->id.': no tiene la factura de venta asociada (sale_afip_ticket_id='.$ticket->sale_afip_ticket_id.'). No se puede recalcular.');
                continue;
            }

            /*
             * Se replica EXACTAMENTE la construccion de `AfipNotaCreditoHelper::interno()`: el
             * primer argumento es el afip_ticket de la FACTURA DE VENTA (de ahi sale la venta y la
             * condicion de IVA del emisor), y despues van los articulos, servicios y descripciones
             * de la NOTA DE CREDITO mas el modelo de la NC (que aporta sus descuentos y recargos).
             *
             * 🔴 `AfipController::get_importes()` arma esto MAL para las NC (no pasa las
             * descripciones ni el modelo de la NC), asi que no sirve de referencia.
             */
            $nota_credito->load(['discounts', 'surchages']);

            $afip_helper = new AfipHelper(
                $afip_ticket_de_la_venta,
                $nota_credito->articles,
                $nota_credito->services,
                $user,
                null,
                $nota_credito->nota_credito_descriptions,
                $nota_credito
            );

            $importes = $afip_helper->getImportes();

            $total_guardado = (float) $ticket->importe_total;
            $total_recalculado = (float) $importes['total'];

            if (abs($total_recalculado - $total_guardado) > self::TOLERANCIA) {
                $contadores['no_reproducibles']++;
                $this->error(
                    '  Ticket '.$ticket->id.' (cbte '.$ticket->cbte_numero.'): el recalculo NO reproduce el comprobante. '.
                    'Guardado '.$total_guardado.', recalculado '.$total_recalculado.' (delta '.round($total_recalculado - $total_guardado, 2).'). '.
                    'NO se escribe el IVA: habria que inventarlo.'
                );
                continue;
            }

            if ($aplicar) {
                $ticket->importe_iva = $importes['iva'];
                $ticket->timestamps = false;
                $ticket->save();
            }

            $contadores['actualizadas']++;
            $this->info('  Ticket '.$ticket->id.' (cbte '.$ticket->cbte_numero.'): IVA '.$importes['iva'].' sobre un total de '.$total_guardado.'.');
        }

        /*
         * Poblacion invisible: notas de credito cuyo movimiento de cuenta corriente quedo sin
         * user_id. La query de arriba (y la del reporte) no las ve, asi que no basta con no
         * contarlas: hay que decir cuantas son.
         */
        $invisibles = AfipTicket::query()
            ->join('sales', 'sales.id', '=', 'afip_tickets.sale_nota_credito_id')
            ->join('current_acounts', 'current_acounts.id', '=', 'afip_tickets.nota_credito_id')
            ->where('sales.user_id', $user->id)
            ->where('afip_tickets.resultado', 'A')
            ->whereNull('current_acounts.user_id')
            ->count();

        $this->line('');
        $this->info('Resumen para '.$user->company_name.($aplicar ? ' (APLICADO)' : ' (EN SECO)').':');
        $this->info('  Notas de credito autorizadas encontradas: '.$contadores['total']);
        $this->info('  Ya tenian importe_iva:                    '.$contadores['ya_tenian']);
        $this->info('  Recuperadas:                              '.$contadores['actualizadas']);
        $this->info('  De exportacion (IVA 0):                   '.$contadores['exportacion']);
        $this->info('  afip_fecha_emision puesta desde created_at: '.$contadores['fecha_puesta']);

        if ($contadores['no_reproducibles'] > 0) {
            $this->error('  NO REPRODUCIBLES (revisar a mano):        '.$contadores['no_reproducibles']);
        }
        if ($contadores['sin_nota_credito'] > 0) {
            $this->error('  Sin movimiento de nota de credito:        '.$contadores['sin_nota_credito']);
        }
        if ($contadores['sin_factura'] > 0) {
            $this->error('  Sin factura de venta asociada:            '.$contadores['sin_factura']);
        }
        if ($contadores['fecha_de_riesgo'] > 0) {
            $this->warn('  Fechas de riesgo (cruce de medianoche):   '.$contadores['fecha_de_riesgo']);
        }
        if ($invisibles > 0) {
            $this->error(
                '  🔴 '.$invisibles.' nota(s) de credito con current_acounts.user_id en NULL: el reporte NO las ve '.
                'y este comando tampoco las toco. Hay que decidir que hacer con ellas antes de dar el renglon por bueno.'
            );
        }

        return 0;
    }
}
