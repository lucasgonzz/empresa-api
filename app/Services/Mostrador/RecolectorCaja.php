<?php

namespace App\Services\Mostrador;

use App\Http\Controllers\Helpers\agenda\AgendaHelper;
use App\Http\Controllers\Helpers\caja\CajaLiquidacionHelper;
use App\Http\Controllers\Helpers\contabilidad\FlujoCajaHelper;
use App\Http\Controllers\Helpers\sale\VentasSinCobrarHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Client;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Services\RecordatorioCobroSenderService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hechos del informe "Caja y vencimientos" (tipo 'caja', misión mostrador-caja-vencimientos):
 * con cuánta plata arranca el día, qué hay que pagar y qué hay para cobrar en los próximos
 * siete días, si alcanza, y la libreta de la Agenda (las tareas sin gasto).
 *
 * Habla de HOY, como compras y stock. `$hoy` es la `$fecha` que recibe recolectar() y las
 * fechas que compara este archivo se arman en PHP, nunca con CURDATE()/NOW() de MySQL: la zona
 * horaria del MySQL de producción no está confirmada. Dos helpers que se reusan miran el reloj
 * de PHP por su cuenta (CajaLiquidacionHelper y FlujoCajaHelper, para saber qué ya se liquidó):
 * como el informe siempre es de hoy, coinciden. La única comparación contra CURDATE() es
 * heredada a propósito, la de VentasSinCobrarHelper (ver ventas_sin_cobrar_por_cliente()).
 *
 * Cada número sale del lugar que ya lo calcula en el sistema, no de una regla reescrita:
 * - disponible en cajas: CajaLiquidacionHelper::calcular_saldos_liquidez() por caja (lo que
 *   muestra Tesorería), en una sola pasada, con el mismo criterio de moneda y la misma suma que
 *   ContextoFinancieroService, que es de donde sale `compras.contexto_financiero.saldo_cajas`
 *   (ver cajas(): un test compara los dos números para que no se separen);
 * - plata en tránsito: FlujoCajaHelper::liquidaciones_pendientes_total();
 * - vencimientos y libreta: AgendaHelper::ocurrencias_entre() y AgendaHelper::vencidas();
 * - deudas: RecolectorBase (credit_accounts, un solo criterio de moneda);
 * - ventas sin cobrar y recordatorio: VentasSinCobrarHelper y RecordatorioCobroSenderService,
 *   la misma regla que usan el modal y el envío masivo de Alertas → Cobros.
 *
 * Lectura pura (RecolectorBase): ni chats, ni mensajes, ni gastos, ni realizadas de la Agenda.
 * No recorre catálogo: cantidad_de_candidatos() es el 0 de la base y va siempre en el request.
 */
class RecolectorCaja extends RecolectorBase
{
    /** Días del detalle de vencimientos, cheques y cuotas próximas, y de la proyección. */
    const HORIZONTE_DIAS = 7;

    /**
     * Días del total de vencimientos a mediano plazo (`total_proximos_30_dias`) y, hacia atrás,
     * de lo vencido que todavía se considera plata del mes corriente
     * (`total_vencidos_recientes`).
     */
    const HORIZONTE_TOTAL_DIAS = 30;

    /**
     * Días que tiene un cheque recibido para depositarse desde su `fecha_pago`. La regla está
     * escrita a mano en ChequeController::index() (fecha de cobro = fecha_pago + 30 días) y no
     * se toca en esta misión: si cambia allá, tiene que cambiar acá.
     */
    const DIAS_PARA_DEPOSITAR_UN_CHEQUE = 30;

    /** Hasta cuántos días de atraso se listan las cuotas vencidas. */
    const DIAS_CUOTAS_VENCIDAS = 30;

    /** Tope de la lista de próximos vencimientos (Agenda y cheques mezclados). */
    const TOPE_PROXIMOS = 20;

    /** Tope de los proveedores con más deuda. */
    const TOPE_PROVEEDORES = 5;

    /**
     * La proyección queda "ajustada" cuando lo que queda después de pagar es menos de la quinta
     * parte de lo que sale: alcanza, pero cualquier imprevisto la da vuelta.
     */
    const UMBRAL_AJUSTADO = 0.2;

    /** Moneda en dólares de las cajas. */
    const MONEDA_DOLARES = 2;

    /** Extensión que habilita el recordatorio de cobro: el mismo gate que el botón de Cobros.vue. */
    const EXTENSION_WHATSAPP = 'whatsapp';

    /** Motivo de `aplica: false`. */
    const MOTIVO_NO_APLICA = 'Sin cajas, deudas, cheques ni tareas en la agenda';

    /** Motivos del recordatorio que se resuelven acá; el resto los da RecordatorioCobroSenderService. */
    const MOTIVO_SIN_MODULO_WHATSAPP = 'sin_modulo_whatsapp';
    const MOTIVO_CLIENTE_NO_ENCONTRADO = 'cliente_no_encontrado';

    /** Canales por los que sale el recordatorio. */
    const CANAL_TEXTO_LIBRE = 'texto_libre';
    const CANAL_PLANTILLA = 'plantilla';

    /** Estados de la proyección. */
    const ESTADO_SIN_VENCIMIENTOS = 'sin_vencimientos';
    const ESTADO_NO_ALCANZA = 'no_alcanza';
    const ESTADO_AJUSTADO = 'ajustado';
    const ESTADO_ALCANZA = 'alcanza';

    /**
     * @param User $owner
     * @param Carbon $fecha Hoy (caja habla siempre de hoy)
     * @return array
     */
    public function recolectar(User $owner, Carbon $fecha): array
    {
        $hoy = $fecha->copy()->startOfDay();

        if (!$this->hay_algo_que_contar($owner)) {
            return $this->no_aplica($hoy, self::MOTIVO_NO_APLICA);
        }

        // Una sola expansión de la Agenda para los 30 días (de ahí salen los próximos a 7, el
        // total a 30 y las tareas de hoy de la libreta) y una sola de vencidas.
        $ocurrencias = AgendaHelper::ocurrencias_entre($owner->id, $hoy, $hoy->copy()->addDays(self::HORIZONTE_TOTAL_DIAS));
        $vencidas = AgendaHelper::vencidas($owner->id, $hoy);

        $cajas    = $this->cajas($owner);
        $a_pagar  = $this->a_pagar($owner, $hoy, $ocurrencias, $vencidas);
        $a_cobrar = $this->a_cobrar($owner, $hoy);

        return [
            'aplica'         => true,
            'fecha'          => $hoy->format('Y-m-d'),
            'dia_semana'     => self::DIAS_SEMANA[$hoy->dayOfWeek],
            'horizonte_dias' => self::HORIZONTE_DIAS,
            'cajas'          => $cajas,
            'a_pagar'        => $a_pagar,
            'a_cobrar'       => $a_cobrar,
            'proveedores'    => $this->proveedores($owner),
            'proyeccion'     => $this->proyeccion($cajas, $a_pagar, $a_cobrar),
            'agenda'         => $this->libreta($hoy, $ocurrencias, $vencidas),
        ];
    }

    /**
     * false solo si el comercio no tiene NADA de lo que habla el informe: ni cajas, ni deuda de
     * clientes o con proveedores, ni cheques pendientes (recibidos o emitidos, de cualquier
     * fecha), ni cuotas pendientes, ni tareas VIGENTES en la Agenda (con o sin gasto). Con
     * cualquiera de esas cosas el informe aplica, aunque varias secciones vengan vacías.
     *
     * 🔴 "Tareas vigentes", no "alguna fila en pendings": con cualquier fila alcanzaba, así que
     * un dueño sin cajas, sin deudas, sin cheques y sin cuotas, con una sola tarea puntual
     * completada en 2024, recibía el informe entero con TODAS las secciones vacías. Es el mismo
     * criterio de vigencia de agenda_con_vencimientos() —recurrente, o no completada— pero sin
     * exigir concepto de gasto: la libreta también es motivo para que el informe exista.
     *
     * @param User $owner
     * @return bool
     */
    protected function hay_algo_que_contar(User $owner): bool
    {
        if (DB::table('cajas')->where('user_id', $owner->id)->exists()) {
            return true;
        }

        foreach (['client', 'provider'] as $model_name) {
            if ($this->consulta_deudas_en_pesos($owner, $model_name)->where('saldo', '>', 0)->exists()) {
                return true;
            }
        }

        if ($this->consulta_cheques_recibidos($owner)->exists() || $this->consulta_cheques_emitidos($owner)->exists()) {
            return true;
        }

        if (DB::table('payment_plan_cuotas')->where('user_id', $owner->id)->where('estado', 'pendiente')->exists()) {
            return true;
        }

        return $this->consulta_tareas_vigentes($owner)->exists();
    }

    /**
     * Tareas de la Agenda del dueño que todavía pueden vencer: recurrentes (una recurrente nunca
     * se "termina": cada período vuelve) o puntuales sin hacer. Una puntual ya completada es
     * historia y no aparece en ningún bloque del informe.
     *
     * @param User $owner
     * @return \Illuminate\Database\Query\Builder
     */
    protected function consulta_tareas_vigentes(User $owner)
    {
        return DB::table('pendings')
            ->where('user_id', $owner->id)
            ->where(function ($q) {
                $q->where('es_recurrente', 1)
                    ->orWhereNull('completado')
                    ->orWhere('completado', 0);
            });
    }

    /**
     * Bloque `cajas`: el disponible en pesos de esta mañana y el detalle por caja.
     *
     * El detalle es CajaLiquidacionHelper::calcular_saldos_liquidez(), lo que muestra Tesorería.
     * 🔴 Ese helper no filtra moneda: la moneda de cada caja se resuelve acá con el criterio de
     * Contabilidad (null, 0 o 1 son pesos, ver moneda_de_caja) y solo las de pesos suman a
     * `disponible_pesos` y a `a_liquidar_pesos`.
     *
     * 🔴 UNA SOLA PASADA POR CAJA, y no se vuelve a ContextoFinancieroService::armar() para
     * `disponible_pesos` aunque sea "el mismo número": ese servicio hace exactamente este mismo
     * loop, así que pedirle el total significaba llamar a calcular_saldos_liquidez() DOS VECES
     * por cada caja de pesos. El helper hace un agregado sobre `movimiento_cajas` sin filtro de
     * fecha, y esa tabla no tiene índice en `caja_id`: en un cliente con años de movimientos es
     * el agregado más caro del informe. `caja` se calcula siempre adentro del request
     * (cantidad_de_candidatos() = 0, nunca va a la cola), así que duplicarlo es riesgo de
     * timeout del proxy. El criterio sigue siendo uno solo porque es la misma suma sobre las
     * mismas cajas; hay un test que compara este número con el del servicio para que no se
     * separen nunca.
     *
     * Con hasta TOPE_LISTA cajas, `disponible_pesos` es la suma del `disponible` de las cajas en
     * pesos de `por_caja`; con más, `cajas_omitidas` dice cuántas no se listan (sin eso, sumar
     * `por_caja` contradice el total y nada lo avisa).
     *
     * @param User $owner
     * @return array
     */
    protected function cajas(User $owner): array
    {
        $filas = DB::table('cajas')
            ->where('user_id', $owner->id)
            ->orderBy('id')
            ->get(['id', 'name', 'moneda_id', 'abierta']);

        $por_caja = [];
        $disponible_pesos = 0.0;
        $a_liquidar_pesos = 0.0;

        // N consultas para N cajas: el helper no acepta una lista de ids (igual que el servicio).
        foreach ($filas as $fila) {
            $saldos = CajaLiquidacionHelper::calcular_saldos_liquidez($fila->id);
            $moneda = $this->moneda_de_caja($fila->moneda_id);

            if ($moneda === 'pesos') {
                $disponible_pesos += (float) $saldos['saldo_disponible'];
                $a_liquidar_pesos += (float) $saldos['saldo_a_liquidar'];
            }

            $por_caja[] = [
                'caja_id'    => (int) $fila->id,
                'nombre'     => trim((string) $fila->name) !== '' ? (string) $fila->name : 'Caja #' . $fila->id,
                'moneda'     => $moneda,
                'disponible' => $this->monto($saldos['saldo_disponible']),
                'a_liquidar' => $this->monto($saldos['saldo_a_liquidar']),
                'abierta'    => (bool) $fila->abierta,
            ];
        }

        usort($por_caja, function ($a, $b) {
            if ($a['disponible'] != $b['disponible']) {
                return $b['disponible'] <=> $a['disponible'];
            }

            return $a['caja_id'] <=> $b['caja_id'];
        });

        return [
            'disponible_pesos' => $this->monto($disponible_pesos),
            'a_liquidar_pesos' => $this->monto($a_liquidar_pesos),
            'por_caja'         => array_slice($por_caja, 0, self::TOPE_LISTA),
            'cajas_omitidas'   => max(0, count($por_caja) - self::TOPE_LISTA),
        ];
    }

    /**
     * Moneda de una caja como la lee el informe: null, 0 o 1 → 'pesos', 2 → 'dolares', otro
     * valor → 'otra'.
     *
     * 🔴 El criterio de que el 0 es pesos sale de Contabilidad (RecolectorBase::MONEDAS_PESOS) y
     * es el mismo que usan ContextoFinancieroService, de donde salía `disponible_pesos`, y
     * FlujoCajaHelper, de donde sale `liquidaciones_pendientes_pesos`. Contando solo null y 1,
     * una caja de pesos con `moneda_id = 0` quedaba como moneda "otra" y su disponible afuera de
     * `disponible_pesos`, pero sus liquidaciones adentro de `liquidaciones_pendientes_pesos`:
     * plata en tránsito de una caja que el informe decía que no era de pesos, y un "no_alcanza"
     * falso.
     *
     * @param mixed $moneda_id
     * @return string
     */
    protected function moneda_de_caja($moneda_id): string
    {
        if ($this->es_pesos($moneda_id)) {
            return 'pesos';
        }

        if ((int) $moneda_id === self::MONEDA_DOLARES) {
            return 'dolares';
        }

        return 'otra';
    }

    /**
     * Bloque `a_pagar`: lo que sale.
     *
     * - `vencidos`: UNA FILA POR TAREA de la Agenda con gasto que venció y no se marcó, no una
     *   por ocurrencia, con la ocurrencia más reciente como representante (`fecha`,
     *   `dias_vencido`, `monto`) y dos claves que cuentan el arrastre: `ocurrencias_vencidas`
     *   (períodos sin marcar) y `monto_acumulado` (lo que suman, null si la tarea no tiene
     *   monto). 🔴 Por ocurrencia, un alquiler mensual cargado en 2023 y pagado siempre por
     *   fuera del sistema llenaba las 10 filas con renglones de 2023 y escondía el impuesto que
     *   venció anteayer. Por eso el orden es por fecha DESCENDENTE: lo más reciente es lo
     *   accionable, el arrastre viejo se cuenta pero no tapa. `vencidos_omitidos` son las tareas
     *   que no entraron en el tope.
     *   ⚠️ El arrastre que se ve es el que ve AgendaHelper::vencidas(), que devuelve hasta
     *   TOPE_VENCIDAS_POR_TAREA (30) ocurrencias por tarea: una tarea diaria ignorada dos años
     *   informa 30 períodos, no 700. Las tres cifras (ocurrencias, acumulado y total) salen de
     *   las mismas ocurrencias, así que nunca se contradicen entre ellas.
     * - `proximos`: tareas con gasto sin hacer de hoy a 7 días (AgendaHelper::ocurrencias_entre)
     *   y cheques emitidos a un proveedor que se cobran en ese lapso, mezclados por fecha y, el
     *   mismo día, el monto más grande primero.
     * - Los totales suman TODAS las filas que cumplen, no solo las que entran en el tope, y
     *   suman por ocurrencia (una tarea mensual vencida dos veces suma dos veces). Una fila sin
     *   monto no suma. `total_vencidos` es el arrastre histórico completo; `total_vencidos_recientes`,
     *   solo las ocurrencias de los últimos HORIZONTE_TOTAL_DIAS días, que es lo que la
     *   proyección puede tratar como plata que todavía hay que poner (ver proyeccion()).
     * - `sin_monto` cuenta TAREAS con gasto y sin monto (null o 0) entre las vencidas y las
     *   próximas de los 30 días, no ocurrencias: es lo que el dueño tiene que corregir, y se
     *   corrige una sola vez, en la tarea. Contando ocurrencias, una tarea semanal sin monto
     *   olvidada hace un mes le aparecería como seis vencimientos sin monto.
     * - `agenda_con_vencimientos`: si el dueño tiene cargada alguna tarea con gasto que todavía
     *   pueda vencer. Si es false, la skill le cuenta dónde se cargan.
     *
     * @param User $owner
     * @param Carbon $hoy
     * @param array $ocurrencias AgendaHelper::ocurrencias_entre de hoy a hoy + 30
     * @param array $vencidas AgendaHelper::vencidas de hoy
     * @return array
     */
    protected function a_pagar(User $owner, Carbon $hoy, array $ocurrencias, array $vencidas): array
    {
        $limite_detalle = $hoy->copy()->addDays(self::HORIZONTE_DIAS)->format('Y-m-d');
        $desde_reciente = $hoy->copy()->subDays(self::HORIZONTE_TOTAL_DIAS)->format('Y-m-d');

        // Indexado por pending_id: una tarea con varias ocurrencias sin monto cuenta una vez.
        $tareas_sin_monto = [];

        // Indexado por pending_id: una fila por tarea, no por ocurrencia.
        $por_tarea = [];
        $total_vencidos = 0.0;
        $total_vencidos_recientes = 0.0;

        // vencidas() ya viene ordenada por fecha ascendente y tarea, así que la última ocurrencia
        // que se ve de cada tarea es la más reciente: la que queda como representante de la fila.
        foreach ($vencidas as $ocurrencia) {
            if (!$this->tiene_gasto($ocurrencia)) {
                continue;
            }

            $pending_id = (int) $ocurrencia['pending_id'];
            $monto = $this->monto($ocurrencia['expense_amount']);

            if (empty($monto)) {
                $tareas_sin_monto[$pending_id] = true;
            } else {
                $total_vencidos += $monto;

                if ($ocurrencia['fecha'] >= $desde_reciente) {
                    $total_vencidos_recientes += $monto;
                }
            }

            $ocurrencias_previas = isset($por_tarea[$pending_id]) ? $por_tarea[$pending_id]['ocurrencias_vencidas'] : 0;
            $acumulado_previo = isset($por_tarea[$pending_id]) ? $por_tarea[$pending_id]['monto_acumulado'] : null;

            // Una tarea sin monto arrastra null, no 0: no se sabe cuánto debe, y decir "$ 0" sería
            // afirmar que no debe nada.
            $acumulado = is_null($monto) ? $acumulado_previo : (float) $acumulado_previo + $monto;

            $por_tarea[$pending_id] = [
                'origen'               => 'agenda',
                'pending_id'           => $pending_id,
                'detalle'              => $ocurrencia['detalle'],
                'concepto'             => $this->concepto_de($ocurrencia),
                'fecha'                => $ocurrencia['fecha'],
                'dias_vencido'         => Carbon::parse($ocurrencia['fecha'])->startOfDay()->diffInDays($hoy),
                'monto'                => $monto,
                'ocurrencias_vencidas' => $ocurrencias_previas + 1,
                'monto_acumulado'      => $this->monto($acumulado),
            ];
        }

        $vencidos = array_values($por_tarea);

        usort($vencidos, function ($a, $b) {
            if ($a['fecha'] !== $b['fecha']) {
                // Descendente: lo que venció anteayer va antes que el arrastre de 2023.
                return strcmp($b['fecha'], $a['fecha']);
            }

            return $a['pending_id'] <=> $b['pending_id'];
        });

        $proximos = [];
        $total_proximos = 0.0;
        $total_proximos_30_dias = 0.0;

        foreach ($ocurrencias as $ocurrencia) {
            if (!$this->tiene_gasto($ocurrencia) || $ocurrencia['completado']) {
                continue;
            }

            $monto = $this->monto($ocurrencia['expense_amount']);

            // 🔴 sin_monto se cuenta acá, ANTES del corte de los 7 días: es el mismo horizonte de
            // 30 que total_proximos_30_dias, que es justamente el total al que estas tareas le
            // faltan. Contándolo después del corte, una tarea sin monto que vence en 20 días no
            // le aparecía al dueño en ningún lado.
            if (empty($monto)) {
                $tareas_sin_monto[(int) $ocurrencia['pending_id']] = true;
            } else {
                $total_proximos_30_dias += $monto;
            }

            if ($ocurrencia['fecha'] > $limite_detalle) {
                continue;
            }

            if (!is_null($monto)) {
                $total_proximos += $monto;
            }

            $proximos[] = [
                'origen'           => 'agenda',
                'pending_id'       => (int) $ocurrencia['pending_id'],
                'cheque_id'        => null,
                'detalle'          => $ocurrencia['detalle'],
                'concepto'         => $this->concepto_de($ocurrencia),
                'proveedor'        => null,
                'banco'            => null,
                'numero'           => null,
                'fecha'            => $ocurrencia['fecha'],
                'dias_para_vencer' => $hoy->diffInDays(Carbon::parse($ocurrencia['fecha'])->startOfDay()),
                'monto'            => $monto,
            ];
        }

        $cheques = $this->consulta_cheques_emitidos($owner)
            ->leftJoin('providers', function ($join) use ($owner) {
                $join->on('providers.id', '=', 'cheques.provider_id')->where('providers.user_id', $owner->id);
            })
            ->whereBetween('cheques.fecha_pago', [$hoy->format('Y-m-d'), $hoy->copy()->addDays(self::HORIZONTE_TOTAL_DIAS)->format('Y-m-d')])
            ->orderBy('cheques.fecha_pago')
            ->orderBy('cheques.id')
            ->get(['cheques.id', 'cheques.provider_id', 'cheques.banco', 'cheques.numero', 'cheques.amount', 'cheques.fecha_pago', 'providers.name as proveedor']);

        foreach ($cheques as $cheque) {
            $monto = $this->monto($cheque->amount);
            $fecha = $this->fecha_ymd($cheque->fecha_pago);

            if (!is_null($monto)) {
                $total_proximos_30_dias += $monto;
            }

            if ($fecha > $limite_detalle) {
                continue;
            }

            if (!is_null($monto)) {
                $total_proximos += $monto;
            }

            $proveedor = trim((string) $cheque->proveedor) !== '' ? (string) $cheque->proveedor : 'Proveedor #' . $cheque->provider_id;

            $proximos[] = [
                'origen'           => 'cheque',
                'pending_id'       => null,
                'cheque_id'        => (int) $cheque->id,
                'detalle'          => 'Cheque emitido a ' . $proveedor,
                'concepto'         => null,
                'proveedor'        => $proveedor,
                'banco'            => $cheque->banco,
                'numero'           => $cheque->numero,
                'fecha'            => $fecha,
                'dias_para_vencer' => $hoy->diffInDays(Carbon::parse($fecha)->startOfDay()),
                'monto'            => $monto,
            ];
        }

        usort($proximos, function ($a, $b) {
            if ($a['fecha'] !== $b['fecha']) {
                return strcmp($a['fecha'], $b['fecha']);
            }

            // El mismo día, el monto más grande primero; sin monto, al final.
            $monto_a = is_null($a['monto']) ? -1.0 : $a['monto'];
            $monto_b = is_null($b['monto']) ? -1.0 : $b['monto'];

            if ($monto_a != $monto_b) {
                return $monto_b <=> $monto_a;
            }

            // usort no es estable en PHP 7.4: desempate fijo por origen y por id.
            if ($a['origen'] !== $b['origen']) {
                return strcmp($a['origen'], $b['origen']);
            }

            return (int) ($a['pending_id'] ?: $a['cheque_id']) <=> (int) ($b['pending_id'] ?: $b['cheque_id']);
        });

        return [
            'vencidos'                 => array_slice($vencidos, 0, self::TOPE_LISTA),
            'vencidos_omitidos'        => max(0, count($vencidos) - self::TOPE_LISTA),
            'proximos'                 => array_slice($proximos, 0, self::TOPE_PROXIMOS),
            'total_vencidos'           => $this->monto($total_vencidos),
            'total_vencidos_recientes' => $this->monto($total_vencidos_recientes),
            'total_proximos'           => $this->monto($total_proximos),
            'total_proximos_30_dias'   => $this->monto($total_proximos_30_dias),
            'sin_monto'                => count($tareas_sin_monto),
            'agenda_con_vencimientos'  => $this->agenda_con_vencimientos($owner),
        ];
    }

    /**
     * true si el dueño tiene alguna tarea con gasto que todavía pueda vencer: la vigencia de
     * consulta_tareas_vigentes() más el concepto de gasto, que es lo que vuelve a una tarea un
     * vencimiento y no un recordatorio de la libreta.
     *
     * @param User $owner
     * @return bool
     */
    protected function agenda_con_vencimientos(User $owner): bool
    {
        return $this->consulta_tareas_vigentes($owner)
            ->where('expense_concept_id', '>', 0)
            ->exists();
    }

    /**
     * Cheques emitidos a un proveedor que todavía no se marcaron (ni cobrados ni rechazados).
     *
     * 🔴 SOLO los que tienen provider_id. ChequeHelper::get_tipo() marca 'emitido' todo cheque
     * cuyo modelo no tiene client_id, así que una venta de mostrador sin cliente cobrada con
     * cheque queda grabada como "emitido": es plata que ENTRA, y contarla acá le inventaría al
     * dueño una deuda que no existe. El precio de este criterio es que un cheque emitido sin
     * proveedor cargado (por ejemplo, el de un gasto sin proveedor) tampoco se proyecta.
     *
     * @param User $owner
     * @return \Illuminate\Database\Query\Builder
     */
    protected function consulta_cheques_emitidos(User $owner)
    {
        return DB::table('cheques')
            ->where('cheques.user_id', $owner->id)
            ->where('cheques.tipo', 'emitido')
            ->whereNotNull('cheques.provider_id')
            ->whereNull('cheques.estado_manual');
    }

    /**
     * Cheques recibidos que siguen en mano: sin marca manual (cobrado o rechazado) y sin endosar
     * a un proveedor. Es la partición de ChequeController::index(), incluido que un endoso se
     * reconoce por un endosado_a_provider_id cargado (ni null ni 0).
     *
     * @param User $owner
     * @return \Illuminate\Database\Query\Builder
     */
    protected function consulta_cheques_recibidos(User $owner)
    {
        return DB::table('cheques')
            ->where('cheques.user_id', $owner->id)
            ->where('cheques.tipo', 'recibido')
            ->whereNull('cheques.estado_manual')
            ->where(function ($q) {
                $q->whereNull('cheques.endosado_a_provider_id')->orWhere('cheques.endosado_a_provider_id', 0);
            });
    }

    /**
     * Bloque `a_cobrar`: lo que entra.
     *
     * Cheques recibidos: con la regla de ChequeController::index(), un cheque se deposita desde
     * su `fecha_pago` hasta `fecha_pago + DIAS_PARA_DEPOSITAR_UN_CHEQUE`. Los que ya se pueden
     * depositar van por lo que les queda (el que vence antes, primero); los que todavía no, los
     * de mañana a 7 días. Los totales suman todas las filas que cumplen.
     *
     * `liquidaciones_pendientes_pesos` es plata en tránsito (tarjetas y QR con liquidación
     * futura): no está en caja y no entra en la proyección.
     *
     * @param User $owner
     * @param Carbon $hoy
     * @return array
     */
    protected function a_cobrar(User $owner, Carbon $hoy): array
    {
        $para_depositar = $this->cheques_recibidos_entre($owner, $hoy->copy()->subDays(self::DIAS_PARA_DEPOSITAR_UN_CHEQUE), $hoy);
        $proximos = $this->cheques_recibidos_entre($owner, $hoy->copy()->addDay(), $hoy->copy()->addDays(self::HORIZONTE_DIAS));

        $lista_para_depositar = [];

        foreach ($para_depositar['filas'] as $fila) {
            $fecha_pago = Carbon::parse($fila->fecha_pago)->startOfDay();

            $lista_para_depositar[] = $this->fila_cheque_recibido($fila) + [
                'vence_en_dias' => $hoy->diffInDays($fecha_pago->copy()->addDays(self::DIAS_PARA_DEPOSITAR_UN_CHEQUE)),
            ];
        }

        $lista_proximos = [];

        foreach ($proximos['filas'] as $fila) {
            $lista_proximos[] = $this->fila_cheque_recibido($fila) + [
                'dias_para_cobrar' => $hoy->diffInDays(Carbon::parse($fila->fecha_pago)->startOfDay()),
            ];
        }

        return [
            'cheques_para_depositar'         => $lista_para_depositar,
            'cheques_proximos'               => $lista_proximos,
            'total_cheques_para_depositar'   => $para_depositar['total'],
            'total_cheques_proximos'         => $proximos['total'],
            // 1 = pesos para FlujoCajaHelper (cajas con moneda null, 0 o 1); null serían todas.
            'liquidaciones_pendientes_pesos' => $this->monto(FlujoCajaHelper::liquidaciones_pendientes_total($owner->id, self::MONEDA_PESOS)),
            'cuotas'                         => $this->cuotas($owner, $hoy),
            'clientes_para_cobrar'           => $this->clientes_para_cobrar($owner, $hoy),
            'deuda_clientes_total'           => $this->deuda_total_en_pesos($owner, 'client'),
        ];
    }

    /**
     * Cheques recibidos en mano con `fecha_pago` en [desde, hasta]: el total de todos y los
     * primeros TOPE_LISTA por fecha de pago (el más viejo primero, que es el que vence antes).
     *
     * @param User $owner
     * @param Carbon $desde
     * @param Carbon $hasta
     * @return array{filas: \Illuminate\Support\Collection, total: float}
     */
    protected function cheques_recibidos_entre(User $owner, Carbon $desde, Carbon $hasta): array
    {
        $rango = [$desde->format('Y-m-d'), $hasta->format('Y-m-d')];

        $total = $this->consulta_cheques_recibidos($owner)
            ->whereBetween('cheques.fecha_pago', $rango)
            ->sum('cheques.amount');

        // El join a clients lleva el user_id del dueño: un client_id de otro comercio no trae nombre.
        $filas = $this->consulta_cheques_recibidos($owner)
            ->leftJoin('clients', function ($join) use ($owner) {
                $join->on('clients.id', '=', 'cheques.client_id')->where('clients.user_id', $owner->id);
            })
            ->whereBetween('cheques.fecha_pago', $rango)
            ->orderBy('cheques.fecha_pago')
            ->orderBy('cheques.id')
            ->limit(self::TOPE_LISTA)
            ->get(['cheques.id', 'cheques.banco', 'cheques.numero', 'cheques.amount', 'cheques.fecha_pago', 'clients.name as cliente']);

        return [
            'filas' => $filas,
            'total' => (float) $this->monto($total),
        ];
    }

    /**
     * Las claves comunes de un cheque recibido.
     *
     * @param object $fila
     * @return array
     */
    protected function fila_cheque_recibido($fila): array
    {
        return [
            'cheque_id'  => (int) $fila->id,
            'cliente'    => trim((string) $fila->cliente) !== '' ? (string) $fila->cliente : null,
            'banco'      => $fila->banco,
            'numero'     => $fila->numero,
            'monto'      => $this->monto($fila->amount),
            'fecha_pago' => $this->fecha_ymd($fila->fecha_pago),
        ];
    }

    /**
     * Bloque `a_cobrar.cuotas`: cuotas de planes de pago pendientes, vencidas (hasta 30 días de
     * atraso) y próximas (de hoy a 7 días). `monto` es lo que falta pagar de cada cuota.
     *
     * @param User $owner
     * @param Carbon $hoy
     * @return array
     */
    protected function cuotas(User $owner, Carbon $hoy): array
    {
        $vencidas = $this->cuotas_entre($owner, $hoy->copy()->subDays(self::DIAS_CUOTAS_VENCIDAS), $hoy->copy()->subDay());
        $proximas = $this->cuotas_entre($owner, $hoy, $hoy->copy()->addDays(self::HORIZONTE_DIAS));

        $lista_vencidas = [];

        foreach ($vencidas['filas'] as $fila) {
            $lista_vencidas[] = $this->fila_cuota($fila) + [
                'dias_vencida' => Carbon::parse($fila->fecha_vencimiento)->startOfDay()->diffInDays($hoy),
            ];
        }

        $lista_proximas = [];

        foreach ($proximas['filas'] as $fila) {
            $lista_proximas[] = $this->fila_cuota($fila) + [
                'dias_para_vencer' => $hoy->diffInDays(Carbon::parse($fila->fecha_vencimiento)->startOfDay()),
            ];
        }

        return [
            'vencidas'       => $lista_vencidas,
            'proximas'       => $lista_proximas,
            'total_vencidas' => $vencidas['total'],
            'total_proximas' => $proximas['total'],
        ];
    }

    /**
     * Cuotas pendientes con vencimiento en [desde, hasta]: el total de lo que falta pagar de
     * todas y las primeras TOPE_LISTA por fecha.
     *
     * @param User $owner
     * @param Carbon $desde
     * @param Carbon $hasta
     * @return array{filas: \Illuminate\Support\Collection, total: float}
     */
    protected function cuotas_entre(User $owner, Carbon $desde, Carbon $hasta): array
    {
        $rango = [$desde->format('Y-m-d'), $hasta->format('Y-m-d')];

        // Lo que falta pagar de la cuota: amount menos lo ya pagado, nunca negativo.
        $saldo = 'GREATEST(payment_plan_cuotas.amount - COALESCE(payment_plan_cuotas.amount_paid, 0), 0)';

        $total = $this->consulta_cuotas_pendientes($owner)
            ->whereBetween('payment_plan_cuotas.fecha_vencimiento', $rango)
            ->selectRaw('COALESCE(SUM(' . $saldo . '), 0) as total')
            ->value('total');

        $filas = $this->consulta_cuotas_pendientes($owner)
            ->leftJoin('clients', function ($join) use ($owner) {
                $join->on('clients.id', '=', 'payment_plan_cuotas.client_id')->where('clients.user_id', $owner->id);
            })
            ->whereBetween('payment_plan_cuotas.fecha_vencimiento', $rango)
            ->orderBy('payment_plan_cuotas.fecha_vencimiento')
            ->orderBy('payment_plan_cuotas.id')
            ->limit(self::TOPE_LISTA)
            ->selectRaw('payment_plan_cuotas.id, payment_plan_cuotas.client_id, clients.name as cliente, payment_plan_cuotas.fecha_vencimiento, ' . $saldo . ' as saldo')
            ->get();

        return [
            'filas' => $filas,
            'total' => (float) $this->monto($total),
        ];
    }

    /**
     * Cuotas pendientes del dueño, SOLO de clientes que hoy deben plata en pesos.
     *
     * 🔴 Una cuota queda 'pendiente' si el pago no se imputó a ESA cuota:
     * CurrentAcountCuotaHelper::pagar_cuota() la marca solo cuando el pago entra desde la cuota,
     * y un cliente que pagó por cuenta corriente sin elegirla la deja pendiente para siempre. Sin
     * este filtro por la deuda de credit_accounts, un cliente al día aparecería con cuotas
     * vencidas. Con el filtro igual puede colarse una cuota ya pagada de un cliente que debe por
     * otra cosa: la skill no la afirma como deuda cierta.
     *
     * @param User $owner
     * @return \Illuminate\Database\Query\Builder
     */
    protected function consulta_cuotas_pendientes(User $owner)
    {
        $clientes_con_deuda = $this->consulta_deudas_en_pesos($owner, 'client')
            ->groupBy('model_id')
            ->havingRaw('SUM(saldo) > 0')
            ->select('model_id');

        return DB::table('payment_plan_cuotas')
            ->where('payment_plan_cuotas.user_id', $owner->id)
            ->where('payment_plan_cuotas.estado', 'pendiente')
            ->whereIn('payment_plan_cuotas.client_id', $clientes_con_deuda);
    }

    /**
     * Las claves comunes de una cuota.
     *
     * @param object $fila
     * @return array
     */
    protected function fila_cuota($fila): array
    {
        $client_id = (int) $fila->client_id;

        return [
            'cuota_id'          => (int) $fila->id,
            'client_id'         => $client_id,
            'cliente'           => trim((string) $fila->cliente) !== '' ? (string) $fila->cliente : 'Cliente #' . $client_id,
            'monto'             => $this->monto($fila->saldo),
            'fecha_vencimiento' => $this->fecha_ymd($fila->fecha_vencimiento),
        ];
    }

    /**
     * Bloque `a_cobrar.clientes_para_cobrar`: los clientes con más deuda (la misma lista que
     * `dia.cobranzas.clientes_con_mas_deuda`, con `saldo` renombrado a `deuda`), cuántas ventas
     * sin cobrar tiene cada uno y si se le puede mandar el recordatorio de cobro por el WhatsApp
     * del negocio.
     *
     * @param User $owner
     * @param Carbon $hoy
     * @return array
     */
    protected function clientes_para_cobrar(User $owner, Carbon $hoy): array
    {
        $clientes = $this->clientes_con_mas_deuda($owner, $hoy);

        if (empty($clientes)) {
            return [];
        }

        $client_ids = array_column($clientes, 'client_id');
        $ventas_por_cliente = $this->ventas_sin_cobrar_por_cliente($owner, $client_ids);

        $owner->load('extencions');
        $tiene_whatsapp = UserHelper::hasExtencion(self::EXTENSION_WHATSAPP, $owner);

        $config = null;
        $modelos = [];

        // Sin el módulo no hay botón que ofrecer: no se consulta nada del recordatorio.
        if ($tiene_whatsapp) {
            $config = WhatsappBotConfig::getForUser($owner->id);

            // SoftDeletes deja afuera a los borrados, igual que el controller del recordatorio.
            $modelos = Client::whereIn('id', $client_ids)
                ->where('user_id', $owner->id)
                ->get()
                ->keyBy('id')
                ->all();
        }

        $servicio = new RecordatorioCobroSenderService();
        $lista = [];

        foreach ($clientes as $cliente) {
            $client_id = (int) $cliente['client_id'];
            $ventas = isset($ventas_por_cliente[$client_id]) ? $ventas_por_cliente[$client_id] : 0;
            $client = isset($modelos[$client_id]) ? $modelos[$client_id] : null;

            $lista[] = [
                'client_id'         => $client_id,
                'nombre'            => $cliente['nombre'],
                'deuda'             => $cliente['saldo'],
                'dias_sin_pagar'    => $cliente['dias_sin_pagar'],
                'ventas_sin_cobrar' => $ventas,
                'recordatorio'      => $this->recordatorio($owner, $tiene_whatsapp, $client, $ventas, $config, $servicio),
            ];
        }

        return $lista;
    }

    /**
     * Cuántas ventas sin cobrar tiene cada cliente del lote, en una consulta.
     *
     * 🔴 Es A PROPÓSITO VentasSinCobrarHelper::query_de_ventas(), incluida la precedencia rara de
     * su orWhere y su CURDATE(): el botón del informe abre el modal del recordatorio, y el modal
     * trabaja sobre esas ventas. Si acá se contara "lo correcto", el informe ofrecería mandarle
     * el recordatorio a un cliente para el que el modal después dice que no hay ventas.
     *
     * Por lo mismo, el umbral de antigüedad es el que usa RecordatorioCobroController cuando el
     * modal no manda `dias` (el dueño no filtró Alertas → Cobros en esa sesión): para el dueño,
     * sin recorte de empleado, users.dias_alertar_administradores_ventas_no_cobradas (null cuenta
     * como 0). Si el dueño sí filtró Cobros, el modal manda ese filtro y puede no coincidir: es
     * un límite conocido, no se arregla desde acá.
     *
     * @param User $owner
     * @param array $client_ids
     * @return array Mapa client_id => cantidad
     */
    protected function ventas_sin_cobrar_por_cliente(User $owner, array $client_ids): array
    {
        // Lo mismo que devuelve RecordatorioCobroController::dias_del_request() al dueño sin `dias`.
        $dias = (int) $owner->dias_alertar_administradores_ventas_no_cobradas;

        $ids = VentasSinCobrarHelper::query_de_ventas($owner->id, null, $dias)
            ->whereIn('client_id', $client_ids)
            ->pluck('client_id')
            ->all();

        $conteo = [];

        foreach ($ids as $client_id) {
            $client_id = (int) $client_id;

            if (!isset($conteo[$client_id])) {
                $conteo[$client_id] = 0;
            }

            $conteo[$client_id]++;
        }

        return $conteo;
    }

    /**
     * Si el recordatorio de cobro se le puede mandar a este cliente y por qué canal, con la
     * MISMA regla que el modal y el envío masivo: no se reimplementa nada.
     *
     * Los cortes van en el orden del RecordatorioCobroController:
     *   1. sin la extensión whatsapp no hay botón (y no se consulta nada más);
     *   2. un cliente borrado o de otro negocio no se encuentra (el preview responde 404);
     *   3. sin ventas sin cobrar el preview responde 422 (`sin_ventas`);
     *   4. el resto lo decide RecordatorioCobroSenderService::motivo_de_salteo(), que es solo
     *      lectura (a diferencia de enviar(), no crea el chat).
     * `canal` viaja solo cuando se puede: texto libre con la ventana de 24 h abierta, plantilla
     * con la ventana cerrada.
     *
     * @param User $owner
     * @param bool $tiene_whatsapp
     * @param Client|null $client
     * @param int $ventas
     * @param WhatsappBotConfig|null $config
     * @param RecordatorioCobroSenderService $servicio
     * @return array{disponible: bool, motivo: string|null, canal: string|null}
     */
    protected function recordatorio(User $owner, bool $tiene_whatsapp, $client, int $ventas, $config, RecordatorioCobroSenderService $servicio): array
    {
        if (!$tiene_whatsapp) {
            return $this->recordatorio_no_disponible(self::MOTIVO_SIN_MODULO_WHATSAPP);
        }

        if (is_null($client)) {
            return $this->recordatorio_no_disponible(self::MOTIVO_CLIENTE_NO_ENCONTRADO);
        }

        if ($ventas === 0) {
            return $this->recordatorio_no_disponible(RecordatorioCobroSenderService::CODE_SIN_VENTAS);
        }

        $chat = RecordatorioCobroSenderService::buscar_chat($owner->id, $client);
        $motivo = $servicio->motivo_de_salteo($owner->id, $client, $config, $chat);

        if (!is_null($motivo)) {
            return $this->recordatorio_no_disponible($motivo['motivo']);
        }

        return [
            'disponible' => true,
            'motivo'     => null,
            'canal'      => !is_null($chat) && $chat->is_within_service_window() ? self::CANAL_TEXTO_LIBRE : self::CANAL_PLANTILLA,
        ];
    }

    /**
     * @param string $motivo
     * @return array
     */
    protected function recordatorio_no_disponible(string $motivo): array
    {
        return [
            'disponible' => false,
            'motivo'     => $motivo,
            'canal'      => null,
        ];
    }

    /**
     * Bloque `proveedores`: la deuda total en pesos y los proveedores a los que más se les debe.
     * Sale de RecolectorBase::consulta_deudas_en_pesos, el mismo criterio de toda deuda del
     * mostrador.
     *
     * @param User $owner
     * @return array
     */
    protected function proveedores(User $owner): array
    {
        $cuentas = $this->consulta_deudas_en_pesos($owner, 'provider')
            ->where('saldo', '>', 0)
            ->orderByDesc('saldo')
            ->orderBy('model_id')
            ->limit(self::TOPE_PROVEEDORES)
            ->get(['model_id', 'saldo']);

        $nombres = [];

        if ($cuentas->isNotEmpty()) {
            $nombres = DB::table('providers')
                ->whereIn('id', $cuentas->pluck('model_id')->all())
                ->where('user_id', $owner->id)
                ->pluck('name', 'id')
                ->all();
        }

        $con_mas_deuda = [];

        foreach ($cuentas as $cuenta) {
            $provider_id = (int) $cuenta->model_id;
            $nombre = isset($nombres[$provider_id]) ? trim((string) $nombres[$provider_id]) : '';

            $con_mas_deuda[] = [
                'provider_id' => $provider_id,
                'nombre'      => $nombre !== '' ? $nombre : 'Proveedor #' . $provider_id,
                'deuda'       => $this->monto($cuenta->saldo),
            ];
        }

        return [
            'deuda_total'   => $this->deuda_total_en_pesos($owner, 'provider'),
            'con_mas_deuda' => $con_mas_deuda,
        ];
    }

    /**
     * Bloque `proyeccion`: los próximos 7 días, solo en pesos.
     *
     * `entra` son los cheques recibidos (para depositar y los que se cobran en la semana): lo
     * único con fecha cierta. No suma deudas de clientes ni liquidaciones pendientes.
     *
     * 🔴 `sale` es `total_vencidos_recientes` + `total_proximos`, NO el arrastre histórico
     * completo (`total_vencidos`), y eso no se "simplifica" de vuelta. Una tarea recurrente que
     * el dueño paga siempre por fuera del sistema y nunca marca acumula una ocurrencia vencida
     * por período para siempre: un alquiler de $ 800.000 cargado en 2023 arrastra $ 24.000.000
     * que nadie va a pagar este mes. Sumado a `sale`, la proyección daba "no_alcanza" TODOS los
     * días, en todos los comercios con una recurrente sin marcar — o sea, el informe mentía
     * siempre y el dueño dejaba de creerle. Con la ventana de HORIZONTE_TOTAL_DIAS días, lo que
     * entra en `sale` es lo que de verdad puede haber quedado sin pagar del mes corriente; el
     * arrastre viejo sigue viajando en `total_vencidos` y en `monto_acumulado` de cada tarea,
     * para que la skill lo pueda contar como lo que es (deuda vieja o tareas mal marcadas).
     *
     * @param array $cajas
     * @param array $a_pagar
     * @param array $a_cobrar
     * @return array
     */
    protected function proyeccion(array $cajas, array $a_pagar, array $a_cobrar): array
    {
        $disponible = (float) $cajas['disponible_pesos'];
        $entra = (float) $this->monto($a_cobrar['total_cheques_para_depositar'] + $a_cobrar['total_cheques_proximos']);
        $sale = (float) $this->monto($a_pagar['total_vencidos_recientes'] + $a_pagar['total_proximos']);
        $queda = (float) $this->monto($disponible + $entra - $sale);

        if ($sale <= 0) {
            $estado = self::ESTADO_SIN_VENCIMIENTOS;
        } elseif ($queda < 0) {
            $estado = self::ESTADO_NO_ALCANZA;
        } elseif ($queda < $sale * self::UMBRAL_AJUSTADO) {
            $estado = self::ESTADO_AJUSTADO;
        } else {
            $estado = self::ESTADO_ALCANZA;
        }

        return [
            'disponible_hoy' => $this->monto($disponible),
            'entra'          => $entra,
            'sale'           => $sale,
            'queda'          => $queda,
            'estado'         => $estado,
        ];
    }

    /**
     * Bloque `agenda`: la libreta, las tareas SIN gasto.
     *
     * - `vencidas`: lo más reciente primero, con tope.
     * - `vencidas_omitidas`: las vencidas que no se listan. Suma las que quedaron afuera del tope
     *   de esta lista y las que AgendaHelper::vencidas() ya había dejado afuera por su tope de 30
     *   por tarea (una tarea diaria ignorada un año no son 20 omitidas, son cientos): cada
     *   ocurrencia de una tarea recortada trae el mismo número, así que se suma una vez por tarea.
     * - `hoy`: las de hoy sin hacer.
     *
     * @param Carbon $hoy
     * @param array $ocurrencias AgendaHelper::ocurrencias_entre de hoy a hoy + 30
     * @param array $vencidas AgendaHelper::vencidas de hoy
     * @return array
     */
    protected function libreta(Carbon $hoy, array $ocurrencias, array $vencidas): array
    {
        $sin_gasto = [];

        foreach ($vencidas as $ocurrencia) {
            if (!$this->tiene_gasto($ocurrencia)) {
                $sin_gasto[] = $ocurrencia;
            }
        }

        usort($sin_gasto, function ($a, $b) {
            if ($a['fecha'] !== $b['fecha']) {
                return strcmp($b['fecha'], $a['fecha']);
            }

            return $a['pending_id'] <=> $b['pending_id'];
        });

        $omitidas_por_tarea = [];

        foreach ($sin_gasto as $ocurrencia) {
            if ((int) $ocurrencia['vencidas_omitidas'] > 0) {
                $omitidas_por_tarea[(int) $ocurrencia['pending_id']] = (int) $ocurrencia['vencidas_omitidas'];
            }
        }

        $lista_vencidas = [];

        foreach (array_slice($sin_gasto, 0, self::TOPE_LISTA) as $ocurrencia) {
            $lista_vencidas[] = [
                'pending_id'   => (int) $ocurrencia['pending_id'],
                'detalle'      => $ocurrencia['detalle'],
                'notas'        => $ocurrencia['notas'],
                'fecha'        => $ocurrencia['fecha'],
                'dias_vencida' => Carbon::parse($ocurrencia['fecha'])->startOfDay()->diffInDays($hoy),
            ];
        }

        $hoy_ymd = $hoy->format('Y-m-d');
        $de_hoy = [];

        foreach ($ocurrencias as $ocurrencia) {
            if ($ocurrencia['fecha'] !== $hoy_ymd || $ocurrencia['completado'] || $this->tiene_gasto($ocurrencia)) {
                continue;
            }

            if (count($de_hoy) >= self::TOPE_LISTA) {
                break;
            }

            $de_hoy[] = [
                'pending_id' => (int) $ocurrencia['pending_id'],
                'detalle'    => $ocurrencia['detalle'],
                'notas'      => $ocurrencia['notas'],
            ];
        }

        return [
            'vencidas'          => $lista_vencidas,
            'hoy'               => $de_hoy,
            'vencidas_omitidas' => max(0, count($sin_gasto) - self::TOPE_LISTA) + array_sum($omitidas_por_tarea),
        ];
    }

    /**
     * true si la ocurrencia es de una tarea con concepto de gasto. Un id 0 es "sin gasto", como
     * lo lee PendingController (un select sin elegir llega como 0): una fila vieja guardada con 0
     * no es un vencimiento.
     *
     * @param array $ocurrencia
     * @return bool
     */
    protected function tiene_gasto(array $ocurrencia): bool
    {
        return (int) $ocurrencia['expense_concept_id'] > 0;
    }

    /**
     * Nombre del concepto de gasto de una ocurrencia (null si el concepto ya no existe).
     *
     * @param array $ocurrencia
     * @return string|null
     */
    protected function concepto_de(array $ocurrencia)
    {
        return isset($ocurrencia['expense_concept']['name']) ? (string) $ocurrencia['expense_concept']['name'] : null;
    }
}
