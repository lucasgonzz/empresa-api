<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\AfipTicket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lista las notas de crédito que el Libro IVA Ventas y los TXT de ARCA exportaron SUBDECLARADAS.
 *
 * Hasta el 1/9/2026, `AfipController::get_importes()` armaba el `AfipHelper` de una nota de crédito
 * pasándole SOLO `articles`: sin los servicios, sin las líneas de descripción libre
 * (`nota_credito_descriptions`) y sin el modelo de la nota de crédito, que es el que aporta sus
 * descuentos y recargos propios. El camino que emite la nota de crédito ante ARCA
 * (`AfipNotaCreditoHelper::interno()`) le pasa las cinco cosas. O sea: se le declaraba un número a
 * ARCA y después se exportaba otro.
 *
 * A dónde llegaba el número mal — los tres consumidores de `get_importes()` son justo los archivos
 * que el contador sube a ARCA: `exportAlicuotasTxt()` (base imponible e IVA por alícuota),
 * `exportComprobantesTxt()` (vía `get_cantidad_iva()`, la cantidad de renglones de IVA) y el PDF del
 * Libro IVA Ventas.
 *
 * 🔴 Para qué sirve este comando y no el arreglo: el arreglo corrige lo que se exporte de ahora en
 * más, pero no dice nada de las DDJJ YA PRESENTADAS. Esa es una pregunta concreta —¿alguna salió mal
 * y por cuánto?— y este comando la contesta con números, comercio por comercio.
 *
 * Cómo lo mide: por cada nota de crédito autorizada calcula los importes DE LAS DOS FORMAS —la
 * incompleta (solo `articles`, exactamente como lo hacía `get_importes()`) y la completa— y lista
 * las que difieren, con el delta.
 *
 * 🔴 La forma incompleta se arma A MANO acá adentro y NO llamando a `AfipController::get_importes()`.
 * Después de la misión del 1/9/2026 ese método ya ES la forma completa: si el comando lo llamara,
 * las dos ramas darían igual y no mediría nada — un cero tranquilizador y falso.
 *
 * 🔴 Es de SOLO LECTURA. No escribe nada, nunca, y por eso no lleva `--aplicar`: no hay nada que
 * aplicar. El arreglo es el código; esto es el diagnóstico de lo ya exportado.
 *
 *     php artisan auditar_libro_iva_notas_credito "Nombre del comercio"
 *     php artisan auditar_libro_iva_notas_credito "Nombre del comercio" --desde=2026-01-01 --hasta=2026-08-31
 */
class AuditarLibroIvaNotasCredito extends Command
{
    /** Tolerancia en pesos: por debajo de un centavo es ruido de redondeo, no subdeclaración. */
    const TOLERANCIA = 0.01;

    protected $signature = 'auditar_libro_iva_notas_credito {company_name} {--desde=} {--hasta=}';

    protected $description = 'Lista las notas de credito que el Libro IVA Ventas y los TXT de ARCA exportaron subdeclaradas (solo lectura, no escribe nada)';

    public function handle()
    {
        $user = User::where('company_name', $this->argument('company_name'))->first();

        if (is_null($user)) {
            $this->error('No existe ningun usuario con company_name "'.$this->argument('company_name').'".');
            return 1;
        }

        $desde = $this->option('desde');
        $hasta = $this->option('hasta');

        $this->info('Auditoria de notas de credito exportadas al Libro IVA Ventas — '.$user->company_name);
        $this->line('  (SOLO LECTURA: este comando no escribe nada)');

        if (!is_null($desde) || !is_null($hasta)) {
            $this->line('  Periodo: '.(is_null($desde) ? 'sin limite' : $desde).' a '.(is_null($hasta) ? 'sin limite' : $hasta));
        }

        $this->line('');

        $tickets = $this->notas_de_credito_del_comercio($user, $desde, $hasta);

        $contadores = [
            'total'          => count($tickets),
            'con_snapshot'   => 0,
            'sin_diferencia' => 0,
            'subdeclaradas'  => 0,
            'sobredeclaradas'=> 0,
            'no_medibles'    => 0,
        ];

        /** Acumuladores del delta, en pesos: lo que la exportacion se comio (o inflo). */
        $delta_gravado_total = 0;
        $delta_iva_total = 0;

        foreach ($tickets as $ticket) {

            /*
             * Una NC con snapshot fiscal persistido NO se exporto mal: `AfipImportesResolver::
             * resolve()` prioriza el snapshot y el recalculo incompleto nunca llego al TXT ni al
             * PDF. Historicamente esto va a dar 0 —`AfipNotaCreditoHelper` no persistia el
             * snapshot hasta el 1/9/2026— y de ahi en mas tiene que ser el bucket que crece.
             */
            if (!is_null($ticket->imp_total_enviado)) {
                $contadores['con_snapshot']++;
                continue;
            }

            $nota_credito = $ticket->nota_credito;

            if (is_null($nota_credito)) {
                $contadores['no_medibles']++;
                $this->error('  Ticket '.$ticket->id.': su movimiento de nota de credito (nota_credito_id='.$ticket->nota_credito_id.') no existe. No se puede medir.');
                continue;
            }

            $venta = $ticket->sale_nota_credito;

            if (is_null($venta)) {
                $contadores['no_medibles']++;
                $this->error('  Ticket '.$ticket->id.': no tiene la venta asociada (sale_nota_credito_id='.$ticket->sale_nota_credito_id.'). El calculador la necesita para los combos y las promociones. No se puede medir.');
                continue;
            }

            try {

                $incompletos = $this->importes_forma_incompleta($ticket, $nota_credito, $venta, $user);
                $completos = $this->importes_forma_completa($ticket, $nota_credito, $venta, $user);

            } catch (Throwable $e) {

                /*
                 * Una fila rota no puede tumbar la corrida entera: esto se corre sobre la base de
                 * un comercio de produccion, con datos de varios anios. Se dice cual y por que, y
                 * se sigue — un fallo de medicion se REPORTA, no se convierte en un cero.
                 */
                $contadores['no_medibles']++;
                $this->error('  Ticket '.$ticket->id.': no se pudo calcular ('.get_class($e).': '.$e->getMessage().').');
                continue;
            }

            /** Cuanto MAS declara la forma correcta que la que se exporto. Positivo = subdeclarado. */
            $delta_gravado = round((float) $completos['gravado'] - (float) $incompletos['gravado'], 2);
            $delta_iva = round((float) $completos['iva'] - (float) $incompletos['iva'], 2);

            if (abs($delta_gravado) <= self::TOLERANCIA && abs($delta_iva) <= self::TOLERANCIA) {
                $contadores['sin_diferencia']++;
                continue;
            }

            if ($delta_gravado > 0 || $delta_iva > 0) {
                $contadores['subdeclaradas']++;
            } else {
                $contadores['sobredeclaradas']++;
            }

            $delta_gravado_total += $delta_gravado;
            $delta_iva_total += $delta_iva;

            $this->error(
                '  Ticket '.$ticket->id.
                ' | cbte '.$ticket->cbte_numero.
                ' | '.$this->fecha_legible($ticket).
                ($this->es_exportacion($ticket) ? ' | 🔴 NC de EXPORTACION (cbte_tipo 21): no lleva IVA discriminado, revisar el renglon a mano' : '')
            );
            $this->line(
                '      exportado (solo articles): gravado '.number_format((float) $incompletos['gravado'], 2, ',', '.').
                ' | IVA '.number_format((float) $incompletos['iva'], 2, ',', '.')
            );
            $this->line(
                '      correcto  (las 5 partes):  gravado '.number_format((float) $completos['gravado'], 2, ',', '.').
                ' | IVA '.number_format((float) $completos['iva'], 2, ',', '.')
            );
            $this->line(
                '      delta:                     gravado '.number_format($delta_gravado, 2, ',', '.').
                ' | IVA '.number_format($delta_iva, 2, ',', '.').
                ($delta_gravado > 0 || $delta_iva > 0 ? '   (SUBDECLARADO)' : '   (declarado de mas)')
            );
        }

        $this->line('');
        $this->info('Resumen para '.$user->company_name.':');
        $this->info('  Notas de credito autorizadas en el periodo: '.$contadores['total']);
        $this->info('  Con snapshot fiscal (se exportaron bien):   '.$contadores['con_snapshot']);
        $this->info('  Sin diferencia entre las dos formas:        '.$contadores['sin_diferencia']);

        if ($contadores['subdeclaradas'] > 0) {
            $this->error('  🔴 SUBDECLARADAS:                           '.$contadores['subdeclaradas']);
        }
        if ($contadores['sobredeclaradas'] > 0) {
            $this->warn('  Declaradas de mas:                          '.$contadores['sobredeclaradas']);
        }
        if ($contadores['no_medibles'] > 0) {
            $this->warn('  No medibles (ver el detalle de arriba):     '.$contadores['no_medibles']);
        }

        $this->line('');

        if ($contadores['subdeclaradas'] == 0 && $contadores['sobredeclaradas'] == 0) {
            $this->info('  No hay ninguna nota de credito con diferencia en el periodo. Nada que rectificar.');
            return 0;
        }

        /*
         * El numero que Lucas necesita para contestar "¿alguna DDJJ ya presentada salio mal?": el
         * total del delta del periodo. En IVA, el signo importa — un delta positivo significa que
         * el archivo de alicuotas informo MENOS credito del que correspondia.
         */
        $this->error('  Delta total del periodo (lo que la exportacion NO declaro):');
        $this->error('    base imponible: '.number_format($delta_gravado_total, 2, ',', '.'));
        $this->error('    IVA:            '.number_format($delta_iva_total, 2, ',', '.'));
        $this->line('');
        $this->warn(
            '  Si alguna DDJJ del periodo ya se presento con estos archivos, el numero informado era '.
            'el de la columna "exportado". Hay que decidir con el contador si corresponde rectificar.'
        );

        return 0;
    }

    /**
     * Notas de crédito autorizadas del comercio, opcionalmente acotadas a un período.
     *
     * Scope por usuario: el mismo criterio que `ContabilidadRepository::query_iva_notas_credito()`
     * — join contra `current_acounts` por `nota_credito_id` y filtro por `current_acounts.user_id`.
     * El `afip_ticket` de una nota de crédito nace con `sale_id` en NULL, así que no hay otra forma
     * de scopearlo por comercio.
     *
     * 🔴 `select('afip_tickets.*')` no es opcional: sin eso el join pisa la columna `id` con la de
     * `current_acounts` y todo lo que se imprima apunta a la fila equivocada.
     *
     * 🔴 EL FILTRO DE FECHA VA POR `created_at` Y NO POR `afip_fecha_emision`, que es lo contrario
     * de lo que hace `ContabilidadRepository`. Los dos motivos:
     *
     *   1. Este comando audita lo que SALIO EN EL LIBRO IVA VENTAS, y los tres exportadores
     *      (`exportVentas()`, `exportAlicuotasTxt()`, `iva_ventas_pdf()`) filtran por
     *      `afip_tickets.created_at`. Auditar por otra columna sería auditar otro conjunto de
     *      comprobantes que el que efectivamente se exportó.
     *   2. `afip_fecha_emision` está en NULL para toda nota de crédito anterior al 1/9/2026 (recién
     *      ese día `AfipNotaCreditoHelper::update_afip_ticket()` empezó a escribirla, y el backfill
     *      es `set_iva_notas_credito`). Filtrar por ahí dejaría afuera justamente a la población
     *      que se exportó mal, y el comando devolvería 0 con cara de "no hay nada".
     *
     * @param  \App\Models\User $user
     * @param  string|null $desde Fecha Y-m-d inclusive, o null.
     * @param  string|null $hasta Fecha Y-m-d inclusive, o null.
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function notas_de_credito_del_comercio($user, $desde, $hasta)
    {
        $query = AfipTicket::query()
            ->join('current_acounts', 'current_acounts.id', '=', 'afip_tickets.nota_credito_id')
            ->whereNotNull('afip_tickets.nota_credito_id')
            ->where('current_acounts.user_id', $user->id)
            ->where('afip_tickets.resultado', 'A')
            ->orderBy('afip_tickets.id', 'ASC')
            ->select('afip_tickets.*');

        if (!is_null($desde)) {
            $query->whereDate('afip_tickets.created_at', '>=', Carbon::parse($desde)->format('Y-m-d'));
        }

        if (!is_null($hasta)) {
            $query->whereDate('afip_tickets.created_at', '<=', Carbon::parse($hasta)->format('Y-m-d'));
        }

        return $query->get();
    }

    /**
     * Reproduce la construcción INCOMPLETA: exactamente la que `AfipController::get_importes()`
     * usaba para una nota de crédito hasta el 1/9/2026 — solo `articles`, `services` en `[]`, sin
     * descripciones y sin el modelo de la nota de crédito.
     *
     * 🔴 Se arma acá y no se llama a `get_importes()`: ese método ya es la forma completa.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @param  \App\Models\CurrentAcount $nota_credito
     * @param  \App\Models\Sale $venta
     * @param  \App\Models\User $user
     * @return array
     */
    private function importes_forma_incompleta($ticket, $nota_credito, $venta, $user)
    {
        $afip_helper = new AfipHelper($ticket, $nota_credito->articles, [], $user, $venta);

        return $afip_helper->getImportes();
    }

    /**
     * La construcción COMPLETA, la misma que `AfipNotaCreditoHelper::interno()` usa para emitir la
     * nota de crédito ante ARCA: artículos, servicios, descripciones libres y el modelo de la nota
     * de crédito (que aporta sus descuentos y recargos).
     *
     * El `$sale` va explícito y no en null, a diferencia de `interno()`: allá el ticket es el de la
     * FACTURA (y resuelve la venta solo, por `$afip_ticket->sale`), mientras que acá es el de la
     * NOTA DE CREDITO, que tiene `sale_id` en NULL. Es el mismo motivo por el que `get_importes()`
     * también lo pasa explícito.
     *
     * El `$user` va explícito porque esto corre en consola, sin sesión: sin él, `AfipHelper` haría
     * `$this->user()` y no tendría de dónde sacarlo. Es el mismo comercio que resolvería la sesión
     * web, así que `factura_solo_algunos_metodos_de_pago` se evalúa igual que en la exportación.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @param  \App\Models\CurrentAcount $nota_credito
     * @param  \App\Models\Sale $venta
     * @param  \App\Models\User $user
     * @return array
     */
    private function importes_forma_completa($ticket, $nota_credito, $venta, $user)
    {
        $nota_credito->load(['discounts', 'surchages']);

        $afip_helper = new AfipHelper(
            $ticket,
            $nota_credito->articles,
            $nota_credito->services,
            $user,
            $venta,
            $nota_credito->nota_credito_descriptions,
            $nota_credito
        );

        return $afip_helper->getImportes();
    }

    /**
     * Fecha con la que el comprobante entró (o entraría) al Libro IVA Ventas.
     *
     * Se imprime `created_at` porque es la columna por la que filtran los exportadores, y al lado
     * la `afip_fecha_emision` cuando existe, que es la fiscalmente correcta. Cuando las dos no
     * coinciden, el renglón lo tiene que mirar una persona.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return string
     */
    private function fecha_legible($ticket)
    {
        $created = Carbon::parse($ticket->created_at)->format('Y-m-d');

        if (is_null($ticket->afip_fecha_emision)) {
            return $created;
        }

        $emision = Carbon::parse($ticket->afip_fecha_emision)->format('Y-m-d');

        if ($emision === $created) {
            return $created;
        }

        return $created.' (emision '.$emision.')';
    }

    /**
     * Informa si el comprobante es una nota de crédito de exportación (WSFEX).
     *
     * Se marcan aparte porque una NC de exportación no lleva IVA discriminado: cualquier alícuota
     * que aparezca en su renglón es de por sí algo para mirar, no solamente el delta.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return bool
     */
    private function es_exportacion($ticket)
    {
        return (string) $ticket->cbte_tipo === '21';
    }
}
