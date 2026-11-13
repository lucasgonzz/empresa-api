<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\OrderHelper;
use App\Models\AiAvisoDelAsistente;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\ConceptoStockMovement;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * LOS AVISOS QUE EL DUEÑO LE PIDE A SU ASISTENTE (misión asistente-truvari-pedidos-fallidos, C2, 13/11/2026).
 *
 * 🔴 EL CASO REAL (Truvari, conversación 7). Fernando le pidió a su asistente por WhatsApp «avisame cuando
 * entre un pedido nuevo en la tienda online», después «a las 8, a las 12, a las 16 y a las 22 revisá si hay un
 * pedido nuevo y avisame» y «cuando un producto llegue a stock cero avisame por este medio». El asistente armó
 * una tarea de agenda con recordatorio a las 08:00, que no revisa nada, y lo del stock no lo pudo hacer.
 *
 * 🔴 SALEN POR EL CANAL DE RECORDATORIOS, SIN TOCAR EL ADMIN. El admin ya consulta a todos los clientes cada
 * 5 minutos GET admin-sync/asistente/recordatorios-pendientes y, con la ventana de 24 h cerrada, manda la
 * plantilla aprobada `cc_recordatorio_asistente` con el `detalle` como variable (AsistenteRecordatoriosService,
 * master). Para él el `id` es opaco: lo reclama, lo manda y lo devuelve en recordatorios/avisado. Así que estos
 * avisos son ítems más de RecordatoriosDelAsistenteHelper::pendientes(), con la misma forma (id, fecha, hora,
 * detalle, texto, ai_conversation_id, ruta) y un id propio que marcar_avisado() reconoce por el prefijo:
 *
 *   recordatorio:pedido_tienda:{order_id}-{created_at unix}              un pedido nuevo, «al toque»
 *   recordatorio:pedidos_tienda:{desde unix}:{Y-m-d}:{HHMM}              el resumen de una hora fija
 *   recordatorio:stock_cero:{id del último movimiento incluido}          los artículos que se quedaron sin stock
 *
 * 🔴 APAGADOS POR DEFECTO Y CON CURSOR POR ID (revisión independiente del bloque C, D3/D4). Las preferencias
 * viven en `users` del dueño (migración 2026_11_13_100000) y las cambia la tarjeta de proponer_avisos_por_whatsapp
 * (PropuestaAvisosDelDuenoIaHelper). Al prender un aviso se guarda el cursor: el id más alto de `orders` o de
 * `stock_movements` en ese momento. Esta consulta corre cada 5 minutos en bases de millones de movimientos en
 * hosting compartido, y `stock_movements` no tiene índice por user_id ni por created_at: TODA lectura va por
 * `id > cursor` (la clave primaria) con LIMIT. El cursor avanza al sellar lo que salió y al saltear lo que venció.
 *
 * 🔴 STOCK CERO ES LA TRANSICIÓN, Y COMO MUCHO UN AVISO POR CONSULTA (D1, D2, D5). Cuenta solo el movimiento que
 * pasó un artículo de tener stock a no tenerlo (`stock_resultante <= 0` y `stock_resultante - amount > 0`), que no
 * sea de una importación de Excel ni de la actualización masiva del listado. Todos los que haya van en UN solo
 * ítem («Se quedaron sin stock: A, B, C y N más»): fuera de las 24 h cada ítem es una plantilla paga de Meta. El
 * ítem se congela al ofrecerlo (su id es el del último movimiento incluido) hasta que se sella o vence, así un
 * reintento del admin ve siempre el mismo id y el mismo texto.
 *
 * 🔴 LO QUE YA SALIÓ QUEDA SELLADO EN `ai_avisos_del_asistente` (una fila por aviso, nacida con
 * `avisado_por_whatsapp_at`): el camino de avisos nunca la ofrece y acá no se vuelve a ofrecer. El sello es
 * idempotente: un reintento del admin no deja dos mensajes en la conversación.
 *
 * 🔴 Y VENCEN COMO LOS RECORDATORIOS (HORAS_DE_GRACIA): si en tres horas no salieron (ventana cerrada sin
 * plantilla, admin caído), ya no se ofrecen. «Entró un pedido» de ayer no es un aviso.
 *
 * Todo en la zona de la app (America/Argentina/Buenos_Aires). Nunca lanza hacia el admin: si algo falla, los
 * recordatorios de la Agenda siguen saliendo.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class AvisosDelDuenoIaHelper
{
    const PEDIDOS_APAGADO = 'apagado';

    const PEDIDOS_AL_TOQUE = 'al_toque';

    const PEDIDOS_HORAS = 'horas';

    const MODOS_DE_PEDIDOS = [self::PEDIDOS_APAGADO, self::PEDIDOS_AL_TOQUE, self::PEDIDOS_HORAS];

    const PREFIJO_PEDIDO = 'recordatorio:pedido_tienda:';

    const PREFIJO_HORA = 'recordatorio:pedidos_tienda:';

    const PREFIJO_STOCK = 'recordatorio:stock_cero:';

    /** Como los recordatorios: pasadas estas horas el aviso ya no sirve y no se ofrece más. */
    const HORAS_DE_GRACIA = RecordatoriosDelAsistenteHelper::HORAS_DE_GRACIA;

    /** Cuántos pedidos «al toque» viajan por consulta como mucho. */
    const TOPE_DE_PEDIDOS = 10;

    /** Cuántas filas se leen por consulta después del cursor (clave primaria + LIMIT). */
    const FILAS_POR_LECTURA = 500;

    /** Cuántos artículos se nombran en el aviso de stock (el resto va en «y N más»). */
    const ARTICULOS_NOMBRADOS = 10;

    /** Cuántos pedidos se nombran en el resumen de una hora fija (el resto va en «y N más»). */
    const PEDIDOS_EN_EL_RESUMEN = 8;

    /** Cuántas horas fijas puede tener el dueño. */
    const MAX_HORAS = 12;

    /** Los movimientos de stock que no son una venta ni un uso: la importación de Excel. */
    const CONCEPTOS_EXCLUIDOS = ['Importacion de excel'];

    /** La observación con la que la actualización masiva del listado deja sus movimientos (MasiveUpdateHelper). */
    const OBSERVACION_DE_LA_MASIVA = 'Actualizacion masiva del listado%';

    /** El texto que se le ofreció al admin por id (lo que queda en la conversación es lo que salió). */
    const CLAVE_TEXTO_OFRECIDO = 'asistente_ia:aviso_del_dueno_ofrecido:';

    /** El ítem de stock cero ya ofrecido, por dueño (congelado hasta que se sella o vence). */
    const CLAVE_STOCK_OFRECIDO = 'asistente_ia:stock_cero_ofrecido:';

    /** La parte de la tienda en la SPA. */
    const RUTA_DE_LA_TIENDA = '/online';

    /** @var bool|null Si `users` ya tiene las columnas (se pregunta una vez por proceso). */
    protected static $hay_columnas = null;

    /**
     * true si la base ya tiene las columnas de las preferencias (un upgrade a medio correr no rompe nada).
     *
     * @return bool
     */
    public static function hay_columnas(): bool
    {
        if (is_null(self::$hay_columnas)) {

            try {
                self::$hay_columnas = Schema::hasColumn('users', 'agente_aviso_pedidos')
                    && Schema::hasColumn('users', 'agente_aviso_stock_cursor')
                    && AvisosDeTurnosIaHelper::hay_tabla();
            } catch (\Throwable $e) {
                self::$hay_columnas = false;
            }
        }

        return self::$hay_columnas;
    }

    /**
     * Las preferencias del dueño, ya normalizadas.
     *
     * @param  \App\Models\User  $dueno
     * @return array{pedidos: string, horas: array<int, string>, pedidos_desde: \Carbon\Carbon|null, pedidos_cursor: int|null, stock_cero: bool, stock_desde: \Carbon\Carbon|null, stock_cursor: int|null}
     */
    public static function preferencias(User $dueno): array
    {
        $vacias = [
            'pedidos' => self::PEDIDOS_APAGADO, 'horas' => [], 'pedidos_desde' => null, 'pedidos_cursor' => null,
            'stock_cero' => false, 'stock_desde' => null, 'stock_cursor' => null,
        ];

        if (!self::hay_columnas()) {

            return $vacias;
        }

        $fila = DB::table('users')->where('id', (int) $dueno->id)->first([
            'agente_aviso_pedidos', 'agente_aviso_pedidos_horas', 'agente_aviso_pedidos_desde', 'agente_aviso_pedidos_cursor',
            'agente_aviso_stock_cero', 'agente_aviso_stock_desde', 'agente_aviso_stock_cursor',
        ]);

        if (is_null($fila)) {

            return $vacias;
        }

        $modo = in_array((string) $fila->agente_aviso_pedidos, self::MODOS_DE_PEDIDOS, true) ? (string) $fila->agente_aviso_pedidos : self::PEDIDOS_APAGADO;
        $horas = self::normalizar_horas((string) $fila->agente_aviso_pedidos_horas);

        if ($modo === self::PEDIDOS_HORAS && !count($horas)) {

            $modo = self::PEDIDOS_APAGADO;
        }

        return [
            'pedidos'        => $modo,
            'horas'          => $horas,
            'pedidos_desde'  => empty($fila->agente_aviso_pedidos_desde) ? null : Carbon::parse($fila->agente_aviso_pedidos_desde),
            'pedidos_cursor' => is_null($fila->agente_aviso_pedidos_cursor) ? null : (int) $fila->agente_aviso_pedidos_cursor,
            'stock_cero'     => (bool) $fila->agente_aviso_stock_cero,
            'stock_desde'    => empty($fila->agente_aviso_stock_desde) ? null : Carbon::parse($fila->agente_aviso_stock_desde),
            'stock_cursor'   => is_null($fila->agente_aviso_stock_cursor) ? null : (int) $fila->agente_aviso_stock_cursor,
        ];
    }

    /**
     * El id más alto de la tabla ahora (el cursor con el que arranca un aviso recién prendido). Por la clave
     * primaria: no recorre la tabla.
     *
     * @param  string  $tabla  orders | stock_movements
     * @return int
     */
    public static function ultimo_id($tabla): int
    {
        return (int) DB::table((string) $tabla)->max('id');
    }

    /**
     * Las horas fijas en "HH:MM", ordenadas y sin repetir, a partir de una lista o de un texto («8, 12, 16 y 22»,
     * «08:00,12:00», «8 hs»). Lo que no es una hora del día se descarta. Como mucho MAX_HORAS.
     *
     * @param  mixed  $valor
     * @return array<int, string>
     */
    public static function normalizar_horas($valor): array
    {
        if (is_array($valor)) {

            $partes = $valor;

        } else {

            $partes = preg_split('/\s*(?:,|;|\by\b|\s)\s*/u', trim((string) $valor));
        }

        $horas = [];

        foreach (is_array($partes) ? $partes : [] as $parte) {

            if (!is_scalar($parte)) {
                continue;
            }

            $parte = strtolower(trim((string) $parte));

            if (!preg_match('/^(\d{1,2})(?:[:.h](\d{2}))?\s*(?:hs?|horas?)?$/u', $parte, $m)) {
                continue;
            }

            $hora = (int) $m[1];
            $minuto = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;

            if ($hora > 23 || $minuto > 59) {
                continue;
            }

            $horas[sprintf('%02d:%02d', $hora, $minuto)] = true;
        }

        $horas = array_keys($horas);
        sort($horas);

        return array_slice($horas, 0, self::MAX_HORAS);
    }

    /**
     * Los avisos del dueño que tocan ahora, con el momento de cada uno para ordenarlos con los recordatorios.
     * Vacío si no prendió ninguno. Nunca lanza.
     *
     * @param  \App\Models\User  $dueno
     * @param  \App\Models\AiConversation|null  $conversacion  La de WhatsApp vigente (va en ai_conversation_id).
     * @return array<int, array{momento: \Carbon\Carbon, item: array}>
     */
    public static function pendientes(User $dueno, $conversacion = null): array
    {
        try {

            $preferencias = self::preferencias($dueno);

            if ($preferencias['pedidos'] === self::PEDIDOS_APAGADO && !$preferencias['stock_cero']) {

                return [];
            }

            $ahora = Carbon::now();
            $conversacion_id = $conversacion instanceof AiConversation ? (int) $conversacion->id : null;

            $avisos = [];

            if ($preferencias['pedidos'] === self::PEDIDOS_AL_TOQUE) {
                $avisos = array_merge($avisos, self::pedidos_al_toque($dueno, $preferencias, $ahora));
            }

            if ($preferencias['pedidos'] === self::PEDIDOS_HORAS && !is_null($preferencias['pedidos_desde'])) {
                $avisos = array_merge($avisos, self::pedidos_por_hora($dueno, $preferencias, $ahora));
            }

            if ($preferencias['stock_cero']) {
                $avisos = array_merge($avisos, self::stock_cero($dueno, $preferencias, $ahora));
            }

            foreach ($avisos as $i => $aviso) {

                $avisos[$i]['item']['ai_conversation_id'] = $conversacion_id;

                /* Lo que se ofrece primero es lo que queda: el admin manda ESTE texto (como los envíos fallidos). */
                $clave = self::CLAVE_TEXTO_OFRECIDO . (int) $dueno->id . ':' . $aviso['item']['id'];
                Cache::add($clave, $aviso['item']['texto'], Carbon::now()->addDays(2));
                $ofrecido = Cache::get($clave);

                if (is_string($ofrecido) && trim($ofrecido) !== '') {
                    $avisos[$i]['item']['texto'] = $ofrecido;
                }
            }

            return $avisos;

        } catch (\Throwable $e) {

            Log::warning('AvisosDelDuenoIaHelper: no se pudieron armar los avisos del dueño', [
                'owner_id' => (int) $dueno->id,
                'error'    => $e->getMessage(),
            ]);

            return [];
        }
    }

    /* ─────────────────────────────── los cursores ─────────────────────────────── */

    /**
     * Avanza un cursor (nunca lo retrocede). Por la tabla, sin tocar `updated_at` del dueño.
     *
     * @param  \App\Models\User  $dueno
     * @param  string  $columna  agente_aviso_pedidos_cursor | agente_aviso_stock_cursor
     * @param  int  $hasta
     * @return void
     */
    protected static function avanzar_cursor(User $dueno, $columna, $hasta)
    {
        DB::table('users')
            ->where('id', (int) $dueno->id)
            ->where(function ($q) use ($columna, $hasta) {
                $q->whereNull($columna)->orWhere($columna, '<', (int) $hasta);
            })
            ->update([$columna => (int) $hasta]);
    }

    /**
     * El cursor de un aviso prendido sin cursor (no debería pasar: la tarjeta lo deja). Arranca desde ahora.
     *
     * @return int
     */
    protected static function cursor_o_desde_ahora(User $dueno, $cursor, $columna, $tabla): int
    {
        if (!is_null($cursor)) {

            return (int) $cursor;
        }

        $desde_ahora = self::ultimo_id($tabla);
        self::avanzar_cursor($dueno, $columna, $desde_ahora);

        return $desde_ahora;
    }

    /* ─────────────────────────────── pedidos al toque ─────────────────────────────── */

    /**
     * Un ítem por pedido nuevo de la tienda, después del cursor y adentro de la gracia. Los vencidos del
     * principio se saltean avanzando el cursor (D4: lo que queda después del cursor siempre es lo nuevo).
     *
     * @return array<int, array{momento: \Carbon\Carbon, item: array}>
     */
    protected static function pedidos_al_toque(User $dueno, array $preferencias, Carbon $ahora): array
    {
        $cursor = self::cursor_o_desde_ahora($dueno, $preferencias['pedidos_cursor'], 'agente_aviso_pedidos_cursor', 'orders');
        $limite = $ahora->copy()->subHours(self::HORAS_DE_GRACIA);

        $pedidos = Order::where('id', '>', $cursor)
                        ->where('user_id', (int) $dueno->id)
                        ->orderBy('id')
                        ->limit(self::TOPE_DE_PEDIDOS * 3)
                        ->get();

        $vencidos_hasta = null;
        $avisos = [];
        $claves = [];

        foreach ($pedidos as $pedido) {
            $claves[] = self::clave_de_pedido($pedido);
        }

        $sellados = self::sellados($dueno, AiAvisoDelAsistente::TIPO_PEDIDO_TIENDA, $claves);

        foreach ($pedidos as $pedido) {

            $momento = Carbon::parse($pedido->created_at);

            if ($momento->lt($limite)) {

                /* Vencido: si todo lo anterior también venció, el cursor lo puede dejar atrás. */
                if (!count($avisos)) {
                    $vencidos_hasta = (int) $pedido->id;
                }

                continue;
            }

            if ($momento->gt($ahora) || isset($sellados[self::clave_de_pedido($pedido)]) || count($avisos) >= self::TOPE_DE_PEDIDOS) {
                continue;
            }

            $avisos[] = [
                'momento' => $momento,
                'item'    => self::item(
                    self::PREFIJO_PEDIDO . (int) $pedido->id . '-' . $momento->timestamp,
                    $momento,
                    self::detalle_del_pedido($pedido),
                    self::texto_del_pedido($pedido)
                ),
            ];
        }

        if (!is_null($vencidos_hasta)) {
            self::avanzar_cursor($dueno, 'agente_aviso_pedidos_cursor', $vencidos_hasta);
        }

        return $avisos;
    }

    /** La clave del sello de un pedido (entra en los 36 caracteres del uuid de la tabla). */
    protected static function clave_de_pedido(Order $pedido): string
    {
        return 'o' . (int) $pedido->id . '-' . Carbon::parse($pedido->created_at)->timestamp;
    }

    /** «Pedido N° 78 de la tienda: Gabriel Domínguez, $188.000». */
    public static function detalle_del_pedido(Order $pedido): string
    {
        return 'Pedido N° ' . self::numero_del_pedido($pedido) . ' de la tienda: ' . self::comprador($pedido) . ', ' . self::pesos(OrderHelper::get_total($pedido));
    }

    protected static function texto_del_pedido(Order $pedido): string
    {
        return '🛒 Entró un pedido nuevo en la tienda: *N° ' . self::numero_del_pedido($pedido) . '* de ' . self::comprador($pedido)
            . ', por ' . self::pesos(OrderHelper::get_total($pedido)) . ".\nSi querés el detalle, preguntame.";
    }

    /* ─────────────────────────────── pedidos por hora fija ─────────────────────────────── */

    /**
     * Un resumen por cada hora fija que pasó adentro de la gracia, con los pedidos que entraron desde la hora
     * fija anterior. Sin pedidos, no hay aviso: se avisa lo que hay. El sello se mira ANTES de armar el resumen.
     *
     * @return array<int, array{momento: \Carbon\Carbon, item: array}>
     */
    protected static function pedidos_por_hora(User $dueno, array $preferencias, Carbon $ahora): array
    {
        $horas = $preferencias['horas'];
        $desde = $preferencias['pedidos_desde'];

        if (!count($horas)) {

            return [];
        }

        $cursor = self::cursor_o_desde_ahora($dueno, $preferencias['pedidos_cursor'], 'agente_aviso_pedidos_cursor', 'orders');
        $limite = $ahora->copy()->subHours(self::HORAS_DE_GRACIA);
        $hoy = $ahora->copy()->startOfDay();
        $dias = $limite->lt($hoy) ? [$hoy->copy()->subDay(), $hoy] : [$hoy];

        $cursor = self::saltear_pedidos_que_ya_no_avisan($dueno, $horas, $desde, $limite, $ahora, $cursor);

        $avisos = [];

        foreach ($dias as $dia) {

            foreach ($horas as $hora) {

                $momento = $dia->copy()->setTimeFromTimeString($hora . ':00');

                if ($momento->lt($limite) || $momento->gt($ahora) || !$momento->gt($desde)) {
                    continue;
                }

                if (count(self::sellados($dueno, AiAvisoDelAsistente::TIPO_PEDIDOS_HORA, [self::clave_de_hora($desde, $momento)]))) {
                    continue;
                }

                $resumen = self::resumen_de_la_hora($dueno, $horas, $desde, $momento, $cursor);

                if (is_null($resumen)) {
                    continue;
                }

                $avisos[] = [
                    'momento' => $momento,
                    'item'    => self::item(
                        self::PREFIJO_HORA . $desde->timestamp . ':' . $momento->format('Y-m-d') . ':' . $momento->format('Hi'),
                        $momento,
                        $resumen['detalle'],
                        $resumen['texto']
                    ),
                ];
            }
        }

        return $avisos;
    }

    /**
     * 🔴 EN HORAS FIJAS EL CURSOR TAMBIÉN AVANZA SOBRE LO QUE YA NO PUEDE AVISAR (re-revisión del bloque C). Si
     * los resúmenes no salen (ventana cerrada sin plantilla) y se juntan más pedidos que FILAS_POR_LECTURA, los
     * resúmenes siguientes salían vacíos y el aviso moría en silencio. Un pedido ya no puede avisar si su hora
     * fija venció o ya se selló: se calcula hasta qué momento todas las horas fijas desde el cursor están
     * vencidas o selladas, y se avanza sobre el tramo inicial de pedidos creados hasta ese momento. Nunca más
     * allá de un pedido que todavía puede entrar en un resumen pendiente.
     *
     * @param  array<int, string>  $horas
     * @return int  El cursor con que sigue la consulta.
     */
    protected static function saltear_pedidos_que_ya_no_avisan(User $dueno, array $horas, Carbon $desde, Carbon $limite, Carbon $ahora, $cursor): int
    {
        $momentos = [];
        $dia = $limite->copy()->startOfDay()->subDay();

        while (!$dia->gt($ahora)) {
            foreach ($horas as $hora) {
                $momento = $dia->copy()->setTimeFromTimeString($hora . ':00');
                if ($momento->gt($desde) && !$momento->gt($ahora)) {
                    $momentos[] = $momento;
                }
            }
            $dia->addDay();
        }

        usort($momentos, function ($a, $b) {
            return $a->lt($b) ? -1 : ($a->eq($b) ? 0 : 1);
        });

        $muerto = null;

        foreach ($momentos as $momento) {

            if ($momento->lt($limite) || count(self::sellados($dueno, AiAvisoDelAsistente::TIPO_PEDIDOS_HORA, [self::clave_de_hora($desde, $momento)]))) {
                $muerto = $momento;
                continue;
            }

            break;
        }

        if (is_null($muerto)) {

            return (int) $cursor;
        }

        $muertos_hasta = null;

        foreach (Order::where('id', '>', (int) $cursor)
                        ->where('user_id', (int) $dueno->id)
                        ->orderBy('id')
                        ->limit(self::FILAS_POR_LECTURA)
                        ->get(['id', 'created_at']) as $pedido) {

            if (Carbon::parse($pedido->created_at)->gt($muerto)) {
                break;
            }

            $muertos_hasta = (int) $pedido->id;
        }

        if (is_null($muertos_hasta)) {

            return (int) $cursor;
        }

        self::avanzar_cursor($dueno, 'agente_aviso_pedidos_cursor', $muertos_hasta);

        return $muertos_hasta;
    }

    protected static function clave_de_hora(Carbon $desde, Carbon $momento): string
    {
        return 'h' . $desde->timestamp . '-' . $momento->format('YmdHi');
    }

    /**
     * Los pedidos de una hora fija: después del cursor, con LIMIT, entre la hora fija anterior y esta.
     *
     * @return \Illuminate\Support\Collection
     */
    protected static function pedidos_de_la_hora(User $dueno, Carbon $inicio, Carbon $momento, $cursor)
    {
        return Order::where('id', '>', (int) $cursor)
                    ->where('user_id', (int) $dueno->id)
                    ->orderBy('id')
                    ->limit(self::FILAS_POR_LECTURA)
                    ->get()
                    ->filter(function ($pedido) use ($inicio, $momento) {
                        $creado = Carbon::parse($pedido->created_at);

                        return $creado->gt($inicio) && !$creado->gt($momento);
                    })
                    ->values();
    }

    /**
     * El detalle y el texto del resumen de una hora fija, o null si no entró ningún pedido.
     *
     * @param  array<int, string>  $horas
     * @return array{detalle: string, texto: string, hasta_id: int}|null
     */
    protected static function resumen_de_la_hora(User $dueno, array $horas, Carbon $desde, Carbon $momento, $cursor)
    {
        $inicio = self::hora_fija_anterior($horas, $momento);

        if ($inicio->lt($desde)) {
            $inicio = $desde->copy();
        }

        $pedidos = self::pedidos_de_la_hora($dueno, $inicio, $momento, $cursor);

        $cuantos = $pedidos->count();

        if (!$cuantos) {

            return null;
        }

        $total = 0;
        $renglones = [];

        foreach ($pedidos as $i => $pedido) {

            $importe = OrderHelper::get_total($pedido);
            $total += $importe;

            if ($i < self::PEDIDOS_EN_EL_RESUMEN) {
                $renglones[] = '- N° ' . self::numero_del_pedido($pedido) . ' de ' . self::comprador($pedido) . ', ' . self::pesos($importe);
            }
        }

        if ($cuantos > self::PEDIDOS_EN_EL_RESUMEN) {
            $renglones[] = '- y ' . ($cuantos - self::PEDIDOS_EN_EL_RESUMEN) . ' más';
        }

        $desde_las = $inicio->isSameDay($momento) ? 'desde las ' . $inicio->format('H:i') : 'desde ayer a las ' . $inicio->format('H:i');
        $hasta_id = (int) $pedidos->last()->id;

        if ($cuantos === 1) {

            $pedido = $pedidos->first();

            return [
                'detalle'  => 'Pedido N° ' . self::numero_del_pedido($pedido) . ' de la tienda: ' . self::comprador($pedido) . ', ' . self::pesos($total),
                'texto'    => '🛒 Revisé la tienda a las ' . $momento->format('H:i') . ': entró *1 pedido nuevo* ' . $desde_las . ":\n"
                    . implode("\n", $renglones) . "\nSi querés el detalle, preguntame.",
                'hasta_id' => $hasta_id,
            ];
        }

        return [
            'detalle'  => $cuantos . ' pedidos nuevos en la tienda ' . $desde_las . ', ' . self::pesos($total) . ' en total',
            'texto'    => '🛒 Revisé la tienda a las ' . $momento->format('H:i') . ': entraron *' . $cuantos . ' pedidos nuevos* ' . $desde_las
                . ' (' . self::pesos($total) . " en total):\n" . implode("\n", $renglones) . "\nSi querés el detalle de alguno, preguntame.",
            'hasta_id' => $hasta_id,
        ];
    }

    /**
     * La hora fija anterior a ese momento: la de antes del mismo día o, si es la primera, la última de ayer.
     *
     * @param  array<int, string>  $horas  Ordenadas.
     * @param  \Carbon\Carbon  $momento
     * @return \Carbon\Carbon
     */
    protected static function hora_fija_anterior(array $horas, Carbon $momento): Carbon
    {
        $actual = $momento->format('H:i');
        $anterior = null;

        foreach ($horas as $hora) {
            if ($hora < $actual) {
                $anterior = $hora;
            }
        }

        if (!is_null($anterior)) {

            return $momento->copy()->startOfDay()->setTimeFromTimeString($anterior . ':00');
        }

        return $momento->copy()->subDay()->startOfDay()->setTimeFromTimeString(end($horas) . ':00');
    }

    /* ─────────────────────────────── stock cero ─────────────────────────────── */

    /**
     * UN solo ítem con los artículos (sin variantes) que un movimiento pasó de tener stock a no tenerlo,
     * después del cursor y adentro de la gracia, que siguen sin stock. Se congela al ofrecerlo (ver el 🔴 del
     * docblock de la clase). Los movimientos vencidos del principio se saltean avanzando el cursor.
     *
     * @return array<int, array{momento: \Carbon\Carbon, item: array}>
     */
    protected static function stock_cero(User $dueno, array $preferencias, Carbon $ahora): array
    {
        $cursor = self::cursor_o_desde_ahora($dueno, $preferencias['stock_cursor'], 'agente_aviso_stock_cursor', 'stock_movements');
        $limite = $ahora->copy()->subHours(self::HORAS_DE_GRACIA);

        $clave_ofrecido = self::CLAVE_STOCK_OFRECIDO . (int) $dueno->id;
        $ofrecido = Cache::get($clave_ofrecido);

        if (is_array($ofrecido) && isset($ofrecido['hasta_id'], $ofrecido['item'], $ofrecido['momento']) && (int) $ofrecido['hasta_id'] > $cursor) {

            $momento = Carbon::parse($ofrecido['momento']);

            if (!$momento->lt($limite)) {

                return [['momento' => $momento, 'item' => $ofrecido['item']]];
            }

            /* Venció sin salir: se deja atrás y se arma lo que venga después. */
            self::avanzar_cursor($dueno, 'agente_aviso_stock_cursor', (int) $ofrecido['hasta_id']);
            Cache::forget($clave_ofrecido);
            $cursor = (int) $ofrecido['hasta_id'];
        }

        $movimientos = self::movimientos_despues_del_cursor($dueno, $cursor);

        if (!count($movimientos)) {

            return [];
        }

        /*
         * 🔴 EL CURSOR AVANZA SOBRE TODO LO QUE YA NO PUEDE AVISAR (re-revisión del bloque C). Un comercio que
         * vende mucho y casi nunca llega a cero recorría cada 5 minutos todos sus movimientos desde que prendió
         * el aviso, y eso crecía día a día. Se avanza hasta el último id de lo leído que no es una transición a
         * cero o ya venció, pero nunca más allá de la primera transición vigente: esa queda para el aviso.
         */
        $excluidos = self::conceptos_excluidos();
        $muertos_hasta = null;
        $vigentes = [];

        foreach ($movimientos as $movimiento) {

            if (!self::es_transicion_a_cero($movimiento, $excluidos) || Carbon::parse($movimiento->created_at)->lt($limite)) {

                if (!count($vigentes)) {
                    $muertos_hasta = (int) $movimiento->id;
                }

                continue;
            }

            $vigentes[] = $movimiento;
        }

        if (!is_null($muertos_hasta)) {
            self::avanzar_cursor($dueno, 'agente_aviso_stock_cursor', $muertos_hasta);
        }

        if (!count($vigentes)) {

            return [];
        }

        $hasta_id = (int) end($vigentes)->id;
        $momento = null;

        $ultimo_por_articulo = [];

        foreach ($vigentes as $movimiento) {
            $ultimo_por_articulo[(int) $movimiento->article_id] = true;
            $creado = Carbon::parse($movimiento->created_at);
            $momento = is_null($momento) || $creado->gt($momento) ? $creado : $momento;
        }

        $articulos = Article::where('user_id', (int) $dueno->id)
                            ->whereIn('id', array_keys($ultimo_por_articulo))
                            ->whereNotNull('stock')
                            ->where('stock', '<=', 0)
                            ->whereDoesntHave('article_variants')
                            ->orderBy('name')
                            ->get(['id', 'name', 'stock'])
                            ->all();

        if (!count($articulos)) {

            /* Los que se quedaron en cero ya se repusieron: no hay nada que avisar de este tramo. */
            self::avanzar_cursor($dueno, 'agente_aviso_stock_cursor', $hasta_id);

            return [];
        }

        $item = self::item(
            self::PREFIJO_STOCK . $hasta_id,
            $momento,
            self::detalle_de_stock($articulos),
            self::texto_de_stock($articulos)
        );

        Cache::put($clave_ofrecido, ['hasta_id' => $hasta_id, 'momento' => $momento->format('Y-m-d H:i:s'), 'item' => $item], Carbon::now()->addDay());

        return [['momento' => $momento, 'item' => $item]];
    }

    /**
     * TODOS los movimientos de stock del dueño después del cursor, por la clave primaria y con LIMIT (D3: la
     * tabla no tiene índice por user_id ni por created_at). Se filtran en PHP (es_transicion_a_cero()) para que
     * el cursor pueda avanzar también sobre los que no avisan.
     *
     * @param  \App\Models\User  $dueno
     * @param  int  $cursor
     * @return array<int, object>
     */
    protected static function movimientos_despues_del_cursor(User $dueno, $cursor): array
    {
        return DB::table('stock_movements')
                    ->where('id', '>', (int) $cursor)
                    ->where('user_id', (int) $dueno->id)
                    ->orderBy('id')
                    ->limit(self::FILAS_POR_LECTURA)
                    ->get(['id', 'article_id', 'article_variant_id', 'amount', 'stock_resultante', 'concepto_stock_movement_id', 'observations', 'created_at'])
                    ->all();
    }

    /**
     * true si el movimiento dejó un artículo sin variantes sin stock habiendo tenido antes, y no es de la
     * importación de Excel ni de la actualización masiva.
     *
     * @param  object  $movimiento
     * @param  array<int, int>  $excluidos  Los ids de conceptos que no cuentan.
     * @return bool
     */
    protected static function es_transicion_a_cero($movimiento, array $excluidos): bool
    {
        if (!is_null($movimiento->article_variant_id) || is_null($movimiento->article_id) || is_null($movimiento->stock_resultante)) {

            return false;
        }

        $cantidad = (float) $movimiento->amount;
        $resultante = (float) $movimiento->stock_resultante;

        if ($cantidad >= 0 || $resultante > 0 || ($resultante - $cantidad) <= 0) {

            return false;
        }

        if (!is_null($movimiento->concepto_stock_movement_id) && in_array((int) $movimiento->concepto_stock_movement_id, $excluidos, true)) {

            return false;
        }

        $masiva = rtrim(self::OBSERVACION_DE_LA_MASIVA, '%');

        return !(is_string($movimiento->observations) && strpos($movimiento->observations, $masiva) === 0);
    }

    /**
     * Los ids de los conceptos de stock que no cuentan (la importación de Excel).
     *
     * @return array<int, int>
     */
    protected static function conceptos_excluidos(): array
    {
        return ConceptoStockMovement::whereIn('name', self::CONCEPTOS_EXCLUIDOS)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
    }

    /**
     * @param  array<int, \App\Models\Article>  $articulos
     * @return string
     */
    protected static function detalle_de_stock(array $articulos): string
    {
        if (count($articulos) === 1) {

            return 'Sin stock: ' . self::nombre_corto((string) $articulos[0]->name);
        }

        return count($articulos) . ' artículos sin stock: ' . self::lista_de_nombres($articulos);
    }

    /**
     * @param  array<int, \App\Models\Article>  $articulos
     * @return string
     */
    protected static function texto_de_stock(array $articulos): string
    {
        if (count($articulos) === 1) {

            return '📦 Se quedó sin stock *' . self::nombre_corto((string) $articulos[0]->name) . '* (stock ' . self::cantidad($articulos[0]->stock) . ').'
                . "\nSi querés, te ayudo a reponerlo.";
        }

        return '📦 Se quedaron sin stock: ' . self::lista_de_nombres($articulos) . ".\nSi querés, te ayudo a reponerlos.";
    }

    /** «A, B, C y N más» con ARTICULOS_NOMBRADOS nombres como mucho. */
    protected static function lista_de_nombres(array $articulos): string
    {
        $nombres = [];

        foreach (array_slice($articulos, 0, self::ARTICULOS_NOMBRADOS) as $articulo) {
            $nombres[] = self::nombre_corto((string) $articulo->name);
        }

        $resto = count($articulos) - count($nombres);

        if ($resto > 0) {
            $nombres[] = $resto . ' más';
        }

        return self::enumerar($nombres);
    }

    /* ─────────────────────────────── el sello ─────────────────────────────── */

    /**
     * true si el id es de uno de estos avisos (lo mira primero RecordatoriosDelAsistenteHelper::marcar_avisado()).
     *
     * @param  mixed  $id
     * @return bool
     */
    public static function es_id_propio($id): bool
    {
        if (!is_scalar($id)) {

            return false;
        }

        $id = trim((string) $id);

        foreach ([self::PREFIJO_PEDIDO, self::PREFIJO_HORA, self::PREFIJO_STOCK] as $prefijo) {
            if (strpos($id, $prefijo) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sella el aviso como mandado, avanza su cursor y lo deja en la conversación de WhatsApp del dueño, UNA
     * sola vez. false (el 404 del contrato) si el id no parsea o lo que nombra no es de este dueño.
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $id
     * @param  mixed  $whatsapp_message_id
     * @return bool
     */
    public static function marcar(User $dueno, $id, $whatsapp_message_id = null): bool
    {
        if (!self::hay_columnas()) {

            return false;
        }

        $partes = self::partes_del_id($dueno, $id);

        if (is_null($partes)) {

            return false;
        }

        return DB::transaction(function () use ($dueno, $id, $partes, $whatsapp_message_id) {

            /* El dueño bloqueado serializa dos sellos casi juntos del mismo aviso (un reintento del admin). */
            User::where('id', (int) $dueno->id)->lockForUpdate()->first();

            if (count(self::sellados($dueno, $partes['tipo'], [$partes['clave']]))) {

                return true;
            }

            $ofrecido = Cache::get(self::CLAVE_TEXTO_OFRECIDO . (int) $dueno->id . ':' . trim((string) $id));
            $texto = is_string($ofrecido) && trim($ofrecido) !== '' ? $ofrecido : $partes['texto'];

            $conversacion = self::conversacion_para_dejarlo($dueno, $partes['titulo']);
            $wamid = trim((string) $whatsapp_message_id);
            $wamid = $wamid === '' ? null : mb_substr($wamid, 0, 128);
            $ahora = Carbon::now();

            AiAvisoDelAsistente::create([
                'uuid'                    => $partes['clave'],
                'owner_id'                => (int) $dueno->id,
                'ai_conversation_id'      => is_null($conversacion) ? null : (int) $conversacion->id,
                'tipo'                    => $partes['tipo'],
                'texto'                   => $texto,
                'avisado_por_whatsapp_at' => $ahora,
                'whatsapp_message_id'     => $wamid,
            ]);

            if (!is_null($partes['cursor'])) {
                self::avanzar_cursor($dueno, $partes['cursor'][0], $partes['cursor'][1]);
            }

            if ($partes['tipo'] === AiAvisoDelAsistente::TIPO_STOCK_CERO) {
                Cache::forget(self::CLAVE_STOCK_OFRECIDO . (int) $dueno->id);
            }

            if (!is_null($conversacion)) {

                AiMessage::create([
                    'ai_conversation_id'  => $conversacion->id,
                    'rol'                 => 'assistant',
                    'contenido'           => $texto,
                    'estado'              => 'listo',
                    'canal'               => AiMessage::CANAL_WHATSAPP,
                    'whatsapp_message_id' => $wamid,
                ]);

                $conversacion->last_message_at = $ahora;
                $conversacion->save();
            }

            return true;
        });
    }

    /**
     * Las partes de un id propio: el tipo y la clave del sello, el cursor que avanza, un texto de repuesto y el
     * título de una conversación nueva; o null si no tiene la forma o no es de este dueño.
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $id
     * @return array{tipo: string, clave: string, cursor: array|null, texto: string, titulo: string}|null
     */
    protected static function partes_del_id(User $dueno, $id)
    {
        if (!self::es_id_propio($id)) {

            return null;
        }

        $id = trim((string) $id);

        if (preg_match('/^' . preg_quote(self::PREFIJO_PEDIDO, '/') . '(\d{1,12})-(\d{1,12})$/', $id, $m)) {

            $pedido = Order::where('user_id', (int) $dueno->id)->where('id', (int) $m[1])->first();

            if (is_null($pedido) || Carbon::parse($pedido->created_at)->timestamp !== (int) $m[2]) {

                return null;
            }

            return [
                'tipo'   => AiAvisoDelAsistente::TIPO_PEDIDO_TIENDA,
                'clave'  => self::clave_de_pedido($pedido),
                'cursor' => ['agente_aviso_pedidos_cursor', (int) $pedido->id],
                'texto'  => self::texto_del_pedido($pedido),
                'titulo' => 'Pedido N° ' . self::numero_del_pedido($pedido) . ' de la tienda',
            ];
        }

        if (preg_match('/^' . preg_quote(self::PREFIJO_HORA, '/') . '(\d{1,12}):(\d{4}-\d{2}-\d{2}):(\d{2})(\d{2})$/', $id, $m)) {

            $dia = \App\Http\Controllers\Helpers\agenda\AgendaTareaHelper::parsear_fecha($m[2]);

            if (is_null($dia) || (int) $m[3] > 23 || (int) $m[4] > 59 || $dia->gt(Carbon::today())) {

                return null;
            }

            $desde = Carbon::createFromTimestamp((int) $m[1]);
            $momento = $dia->copy()->setTime((int) $m[3], (int) $m[4]);
            $preferencias = self::preferencias($dueno);

            /* La hora anterior sale de las horas del dueño (con la de este aviso, por si la cambió). */
            $horas = array_values(array_unique(array_merge([$momento->format('H:i')], $preferencias['horas'])));
            sort($horas);
            $cursor = is_null($preferencias['pedidos_cursor']) ? 0 : $preferencias['pedidos_cursor'];
            $resumen = self::resumen_de_la_hora($dueno, $horas, $desde, $momento, $cursor);

            return [
                'tipo'   => AiAvisoDelAsistente::TIPO_PEDIDOS_HORA,
                'clave'  => self::clave_de_hora($desde, $momento),
                'cursor' => is_null($resumen) ? null : ['agente_aviso_pedidos_cursor', $resumen['hasta_id']],
                'texto'  => is_null($resumen) ? '🛒 Revisé la tienda a las ' . $momento->format('H:i') . '.' : $resumen['texto'],
                'titulo' => 'Pedidos de la tienda',
            ];
        }

        if (preg_match('/^' . preg_quote(self::PREFIJO_STOCK, '/') . '(\d{1,15})$/', $id, $m)) {

            $movimiento = DB::table('stock_movements')->where('id', (int) $m[1])->where('user_id', (int) $dueno->id)->first(['id']);

            if (is_null($movimiento)) {

                return null;
            }

            return [
                'tipo'   => AiAvisoDelAsistente::TIPO_STOCK_CERO,
                'clave'  => 's' . (int) $movimiento->id,
                'cursor' => ['agente_aviso_stock_cursor', (int) $movimiento->id],
                'texto'  => '📦 Se quedaron artículos sin stock.',
                'titulo' => 'Artículos sin stock',
            ];
        }

        return null;
    }

    /**
     * Las claves ya selladas de ese tipo, indexadas.
     *
     * @param  array<int, string>  $claves
     * @return array<string, bool>
     */
    protected static function sellados(User $dueno, $tipo, array $claves): array
    {
        if (!count($claves)) {

            return [];
        }

        $sellados = [];

        foreach (AiAvisoDelAsistente::where('owner_id', (int) $dueno->id)
                                    ->where('tipo', (string) $tipo)
                                    ->whereIn('uuid', $claves)
                                    ->whereNotNull('avisado_por_whatsapp_at')
                                    ->pluck('uuid') as $clave) {
            $sellados[(string) $clave] = true;
        }

        return $sellados;
    }

    /**
     * La conversación de WhatsApp vigente del dueño (bloqueada) o una nueva con título, como los recordatorios.
     *
     * @param  \App\Models\User  $dueno
     * @param  string  $titulo
     * @return \App\Models\AiConversation|null
     */
    protected static function conversacion_para_dejarlo(User $dueno, $titulo)
    {
        $conversacion = AsistenteCanalHelper::conversacion_vigente($dueno, true);

        if (!is_null($conversacion)) {

            return $conversacion;
        }

        return AiConversation::create([
            'user_id'      => $dueno->id,
            'auth_user_id' => $dueno->id,
            'origen'       => AiConversation::ORIGEN_WHATSAPP,
            'titulo'       => mb_substr((string) $titulo, 0, 150),
        ]);
    }

    /* ─────────────────────────────── formato ─────────────────────────────── */

    /** Un ítem con la forma de los recordatorios (RecordatoriosDelAsistenteHelper::pendientes()). */
    protected static function item($id, Carbon $momento, $detalle, $texto): array
    {
        return [
            'id'                 => (string) $id,
            'tarea_id'           => null,
            'fecha'              => $momento->format('Y-m-d'),
            'hora'               => $momento->format('H:i'),
            'detalle'            => (string) $detalle,
            'texto'              => (string) $texto,
            'ai_conversation_id' => null,
            'ruta'               => self::RUTA_DE_LA_TIENDA,
        ];
    }

    protected static function numero_del_pedido(Order $pedido): string
    {
        return (string) (empty($pedido->num) ? $pedido->id : $pedido->num);
    }

    protected static function comprador(Order $pedido): string
    {
        $buyer = $pedido->buyer;

        $nombre = is_null($buyer) ? '' : trim(trim((string) $buyer->name) . ' ' . trim((string) $buyer->surname));

        return $nombre === '' ? 'un comprador' : str_replace('*', '', $nombre);
    }

    /** $188.000 o $188.000,50. */
    public static function pesos($monto): string
    {
        $monto = round((float) $monto, 2);

        return '$' . number_format($monto, floor($monto) == $monto ? 0 : 2, ',', '.');
    }

    protected static function cantidad($stock): string
    {
        $stock = (float) $stock;

        return floor($stock) == $stock ? (string) (int) $stock : number_format($stock, 2, ',', '.');
    }

    protected static function nombre_corto($nombre): string
    {
        $nombre = trim(str_replace('*', '', (string) $nombre));

        return mb_strlen($nombre) > 60 ? rtrim(mb_substr($nombre, 0, 59)) . '…' : $nombre;
    }

    /**
     * «A, B y C».
     *
     * @param  array<int, string>  $partes
     * @return string
     */
    protected static function enumerar(array $partes): string
    {
        if (count($partes) <= 1) {

            return implode('', $partes);
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes) . ' y ' . $ultima;
    }
}
