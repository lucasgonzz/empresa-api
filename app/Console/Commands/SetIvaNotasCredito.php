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
 * 🔴 EL TOTAL NO SIRVE PARA VERIFICAR LA ALICUOTA, y esto costó una revisión entera el 1/9/2026.
 * La primera versión de este comando anclaba en `afip_tickets.importe_total` creyendo que
 * reproducirlo probaba que se habían reproducido "los mismos ítems, precios y alícuotas". Es falso
 * para las alícuotas, y se demuestra en una línea: los precios de este sistema son CON IVA
 * INCLUIDO, así que `AfipItemCalculator::get_price_without_iva()` devuelve `P / (1 + r)` y
 * `monto_iva_del_precio()` devuelve `P / (1 + r) * r`. Sumados dan `P`, para CUALQUIER `r`. Medido:
 * una NC de $1.210 declarada al 21% (IVA $210) cuyo artículo hoy esté al 10,5% recalcula
 * gravado 1.095,02 + IVA 114,98 = total 1.210,00 exacto, pasa el control con delta 0,00 y escribe
 * $114,98 — 45% menos, informado como éxito, camino a una DDJJ. Un control que puede fallar hacia
 * "menos IVA cancelado" hace pagar de más.
 *
 * 🔴 Los DOS controles que sí sirven, y por qué hacen falta los dos:
 *   1. `iva_percentage` presente en el pivot de TODOS los artículos de la NC. Es la única prueba de
 *      que la alícuota que va a usar el recálculo es la histórica y no la de hoy. Sin esto, el IVA
 *      se estaría inventando. Es el control que el total no puede dar.
 *   2. El total recalculado reproduce `importe_total`. Esto NO cubre la alícuota (ver arriba), pero
 *      sí cubre lo otro que se puede haber movido: cantidades, precios, descuentos, ítems borrados,
 *      y el salto de un artículo a Exento/No Gravado (que `get_importe_gravado()` resuelve por la
 *      relación actual y sí mueve el total).
 * Lo que no pasa los dos se REPORTA y no se escribe: un fallo de medición no puede devolver un
 * valor tranquilizador (APRENDER_NO_PARCHEAR), y menos uno que termina en una DDJJ.
 *
 * 🔴 Y lo que no se puede recuperar tampoco recibe `afip_fecha_emision`. Las dos columnas se
 * escriben juntas o no se escribe ninguna: con la fecha puesta y el IVA en null, el comprobante
 * ENTRA al período del reporte aportando $0 (SQL `SUM` ignora los null) y aparece en el drill-down
 * como una nota de crédito real valuada en cero, indistinguible de una sin IVA. Sin la fecha queda
 * afuera, que es lo honesto: no se midió.
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
            'sin_alicuota'      => 0,
            'sin_nota_credito'  => 0,
            'sin_factura'       => 0,
            'fecha_puesta'      => 0,
            'fecha_de_riesgo'   => 0,
        ];

        foreach ($tickets as $ticket) {

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
                $this->persistir($ticket, 0, $aplicar, $contadores);
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

            /*
             * Control 1 de los dos: la alicuota historica tiene que estar persistida en el pivot.
             *
             * Es el UNICO control que puede ver la alicuota, porque el del total no puede (ver el
             * docblock de la clase: gravado + iva da el mismo total para cualquier alicuota, asi
             * que un 21 que hoy figura como 10,5 lo atraviesa sin marcar nada). Si a un solo
             * articulo de la NC le falta el `iva_percentage`, el recalculo va a tomar la alicuota
             * de HOY para ese articulo y el IVA resultante no es el que se le declaro a ARCA:
             * seria un numero inventado con cara de medicion.
             *
             * `iva_percentage` lo trae la relacion `articles()` en el pivot y nacio con la
             * migracion 2026_06_11_000002, asi que toda NC anterior a esa fecha cae aca. Es
             * esperable y es correcto que caiga: para esas no hay forma honesta de recuperar el
             * numero desde el sistema, hay que mirar el comprobante.
             */
            $articulos_sin_alicuota = [];

            foreach ($nota_credito->articles as $articulo_de_la_nc) {
                if (is_null($articulo_de_la_nc->pivot->iva_percentage)) {
                    $articulos_sin_alicuota[] = $articulo_de_la_nc->id;
                }
            }

            if (count($articulos_sin_alicuota) > 0) {
                $contadores['sin_alicuota']++;
                $this->error(
                    '  Ticket '.$ticket->id.' (cbte '.$ticket->cbte_numero.'): '.count($articulos_sin_alicuota).
                    ' articulo(s) sin alicuota historica en el pivot (ids: '.implode(', ', $articulos_sin_alicuota).'). '.
                    'El recalculo usaria la alicuota de hoy. NO se escribe nada: hay que mirar el comprobante.'
                );
                continue;
            }

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

            /*
             * Control 2 de los dos: el total reproduce. NO cubre la alicuota (para eso esta el
             * control de arriba); cubre lo otro que se puede haber movido desde que se emitio el
             * comprobante — cantidades, precios, descuentos, un item borrado a mano, o un articulo
             * que paso a Exento/No Gravado (eso `get_importe_gravado()` lo resuelve por la relacion
             * actual y si mueve el total).
             */
            if (abs($total_recalculado - $total_guardado) > self::TOLERANCIA) {
                $contadores['no_reproducibles']++;
                $this->error(
                    '  Ticket '.$ticket->id.' (cbte '.$ticket->cbte_numero.'): el recalculo NO reproduce el comprobante. '.
                    'Guardado '.$total_guardado.', recalculado '.$total_recalculado.' (delta '.round($total_recalculado - $total_guardado, 2).'). '.
                    'NO se escribe el IVA: habria que inventarlo.'
                );
                continue;
            }

            $this->persistir($ticket, $importes['iva'], $aplicar, $contadores);

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

        if ($contadores['sin_alicuota'] > 0) {
            $this->error('  SIN ALICUOTA HISTORICA (revisar a mano):  '.$contadores['sin_alicuota']);
        }
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

    /**
     * Escribe el IVA recuperado y, si falta, la fecha de emisión — SIEMPRE las dos juntas.
     *
     * 🔴 Las dos o ninguna, y no es una preferencia de estilo. Con `afip_fecha_emision` puesta y
     * `importe_iva` en null, el comprobante ENTRA al rango del reporte (la query fechea por esa
     * columna) y suma $0, porque SQL `SUM` ignora los null. En la tarjeta eso no se ve, pero en el
     * drill-down aparece una nota de crédito real de ARCA valuada en cero, sin ninguna marca que la
     * distinga de una que efectivamente no tuvo IVA. Escribiendo las dos juntas, lo que no se pudo
     * recuperar queda sin fecha y por lo tanto fuera del reporte: no se midió, y el reporte no
     * finge que sí.
     *
     * La fecha sale del `created_at` del propio ticket: `AfipNotaCreditoHelper::create_afip_ticket()`
     * crea esa fila en la MISMA request en la que `interno()` manda `CbteFch => date('Ymd')`, así que
     * salen del mismo reloj con segundos de diferencia. El único desvío posible es una NC emitida
     * cruzando la medianoche, y esas se listan aparte para que las mire una persona.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @param  float $importe_iva IVA ya verificado por los dos controles (o 0 para exportación).
     * @param  bool $aplicar Si es false, se cuenta lo que se escribiría pero no se escribe.
     * @param  array $contadores Se modifica por referencia.
     * @return void
     */
    private function persistir($ticket, $importe_iva, $aplicar, &$contadores)
    {
        $fecha_emision = null;

        if (is_null($ticket->afip_fecha_emision)) {

            $creado = Carbon::parse($ticket->created_at);

            if (
                ($creado->hour == 23 && $creado->minute >= 50)
                || ($creado->hour == 0 && $creado->minute <= 10)
            ) {
                $contadores['fecha_de_riesgo']++;
                $this->warn('  Ticket '.$ticket->id.': creado '.$creado->format('Y-m-d H:i').'. Cruce de medianoche posible, verificar contra el comprobante.');
            }

            $fecha_emision = $creado->format('Y-m-d');
            $contadores['fecha_puesta']++;
        }

        if (!$aplicar) {
            return;
        }

        $ticket->importe_iva = $importe_iva;

        if (!is_null($fecha_emision)) {
            $ticket->afip_fecha_emision = $fecha_emision;
        }

        // Un backfill no es una edición del comprobante: `updated_at` tiene que seguir contando
        // cuándo lo tocó una persona, no cuándo pasó este comando.
        $ticket->timestamps = false;
        $ticket->save();
    }
}
