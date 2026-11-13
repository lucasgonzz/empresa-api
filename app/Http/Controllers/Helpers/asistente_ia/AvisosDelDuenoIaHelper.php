<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\OrderHelper;
use App\Http\Controllers\Helpers\agenda\AgendaTareaHelper;
use App\Models\AiAvisoDelAsistente;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
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
 *   recordatorio:stock_cero:{Y-m-d}:{article_id}.{article_id}…           artículos que llegaron a stock <= 0
 *
 * 🔴 APAGADOS POR DEFECTO Y DESDE «AHORA». Las preferencias viven en `users` del dueño (migración
 * 2026_11_13_100000) y las cambia la tarjeta de proponer_avisos_por_whatsapp (PropuestaAvisosDelDuenoIaHelper).
 * Al prender uno se guarda el cursor (`*_desde`): nunca se avisa un pedido o un movimiento anterior.
 *
 * 🔴 LO QUE YA SALIÓ QUEDA SELLADO EN `ai_avisos_del_asistente` (una fila por pedido, por resumen y por artículo
 * y día, nacida con `avisado_por_whatsapp_at`): el camino de avisos nunca la ofrece y acá no se vuelve a
 * ofrecer. El sello es idempotente: un reintento del admin no deja dos mensajes en la conversación.
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

    /** Cuántos ítems de cada tipo viajan por consulta como mucho. */
    const TOPE_POR_TIPO = 10;

    /** Cuántos artículos van en un mismo aviso de stock (el id los lleva a todos). */
    const ARTICULOS_POR_AVISO = 10;

    /** Cuántos pedidos se nombran en el resumen de una hora fija (el resto va en «y N más»). */
    const PEDIDOS_EN_EL_RESUMEN = 8;

    /** Cuántas horas fijas puede tener el dueño. */
    const MAX_HORAS = 12;

    /** El texto que se le ofreció al admin por id (lo que queda en la conversación es lo que salió). */
    const CLAVE_TEXTO_OFRECIDO = 'asistente_ia:aviso_del_dueno_ofrecido:';

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
                    && Schema::hasColumn('users', 'agente_aviso_stock_desde')
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
     * @return array{pedidos: string, horas: array<int, string>, pedidos_desde: \Carbon\Carbon|null, stock_cero: bool, stock_desde: \Carbon\Carbon|null}
     */
    public static function preferencias(User $dueno): array
    {
        $vacias = ['pedidos' => self::PEDIDOS_APAGADO, 'horas' => [], 'pedidos_desde' => null, 'stock_cero' => false, 'stock_desde' => null];

        if (!self::hay_columnas()) {

            return $vacias;
        }

        $fila = DB::table('users')->where('id', (int) $dueno->id)->first([
            'agente_aviso_pedidos', 'agente_aviso_pedidos_horas', 'agente_aviso_pedidos_desde', 'agente_aviso_stock_cero', 'agente_aviso_stock_desde',
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
            'pedidos'       => $modo,
            'horas'         => $horas,
            'pedidos_desde' => empty($fila->agente_aviso_pedidos_desde) ? null : Carbon::parse($fila->agente_aviso_pedidos_desde),
            'stock_cero'    => (bool) $fila->agente_aviso_stock_cero,
            'stock_desde'   => empty($fila->agente_aviso_stock_desde) ? null : Carbon::parse($fila->agente_aviso_stock_desde),
        ];
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

            if ($preferencias['pedidos'] === self::PEDIDOS_AL_TOQUE && !is_null($preferencias['pedidos_desde'])) {
                $avisos = array_merge($avisos, self::pedidos_al_toque($dueno, $preferencias['pedidos_desde'], $ahora));
            }

            if ($preferencias['pedidos'] === self::PEDIDOS_HORAS && !is_null($preferencias['pedidos_desde'])) {
                $avisos = array_merge($avisos, self::pedidos_por_hora($dueno, $preferencias['horas'], $preferencias['pedidos_desde'], $ahora));
            }

            if ($preferencias['stock_cero'] && !is_null($preferencias['stock_desde'])) {
                $avisos = array_merge($avisos, self::stock_cero($dueno, $preferencias['stock_desde'], $ahora));
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

    /* ─────────────────────────────── pedidos al toque ─────────────────────────────── */

    /**
     * Un ítem por pedido nuevo de la tienda, desde el cursor y adentro de la gracia.
     *
     * @return array<int, array{momento: \Carbon\Carbon, item: array}>
     */
    protected static function pedidos_al_toque(User $dueno, Carbon $desde, Carbon $ahora): array
    {
        $limite = $ahora->copy()->subHours(self::HORAS_DE_GRACIA);

        $pedidos = Order::where('user_id', (int) $dueno->id)
                        ->where('created_at', '>', $desde->format('Y-m-d H:i:s'))
                        ->where('created_at', '>=', $limite->format('Y-m-d H:i:s'))
                        ->where('created_at', '<=', $ahora->format('Y-m-d H:i:s'))
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->limit(self::TOPE_POR_TIPO * 3)
                        ->get();

        $claves = [];

        foreach ($pedidos as $pedido) {
            $claves[] = self::clave_de_pedido($pedido);
        }

        $sellados = self::sellados($dueno, AiAvisoDelAsistente::TIPO_PEDIDO_TIENDA, $claves);

        $avisos = [];

        foreach ($pedidos as $pedido) {

            if (isset($sellados[self::clave_de_pedido($pedido)]) || count($avisos) >= self::TOPE_POR_TIPO) {
                continue;
            }

            $momento = Carbon::parse($pedido->created_at);

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
     * fija anterior (o desde el cursor, si es más nuevo). Sin pedidos, no hay aviso: se avisa lo que hay.
     *
     * @param  array<int, string>  $horas
     * @return array<int, array{momento: \Carbon\Carbon, item: array}>
     */
    protected static function pedidos_por_hora(User $dueno, array $horas, Carbon $desde, Carbon $ahora): array
    {
        if (!count($horas)) {

            return [];
        }

        $limite = $ahora->copy()->subHours(self::HORAS_DE_GRACIA);
        $hoy = $ahora->copy()->startOfDay();
        $dias = $limite->lt($hoy) ? [$hoy->copy()->subDay(), $hoy] : [$hoy];

        $avisos = [];

        foreach ($dias as $dia) {

            foreach ($horas as $hora) {

                $momento = $dia->copy()->setTimeFromTimeString($hora . ':00');

                if ($momento->lt($limite) || $momento->gt($ahora) || !$momento->gt($desde)) {
                    continue;
                }

                $resumen = self::resumen_de_la_hora($dueno, $horas, $desde, $momento);

                if (is_null($resumen)) {
                    continue;
                }

                $clave = self::clave_de_hora($desde, $momento);

                if (count(self::sellados($dueno, AiAvisoDelAsistente::TIPO_PEDIDOS_HORA, [$clave]))) {
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

        return array_slice($avisos, 0, self::TOPE_POR_TIPO);
    }

    protected static function clave_de_hora(Carbon $desde, Carbon $momento): string
    {
        return 'h' . $desde->timestamp . '-' . $momento->format('YmdHi');
    }

    /**
     * El detalle y el texto del resumen de una hora fija, o null si no entró ningún pedido.
     *
     * @param  array<int, string>  $horas
     * @return array{detalle: string, texto: string}|null
     */
    protected static function resumen_de_la_hora(User $dueno, array $horas, Carbon $desde, Carbon $momento)
    {
        $inicio = self::hora_fija_anterior($horas, $momento);

        if ($inicio->lt($desde)) {
            $inicio = $desde->copy();
        }

        $pedidos = Order::where('user_id', (int) $dueno->id)
                        ->where('created_at', '>', $inicio->format('Y-m-d H:i:s'))
                        ->where('created_at', '<=', $momento->format('Y-m-d H:i:s'))
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->get();

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

        if ($cuantos === 1) {

            $pedido = $pedidos->first();

            return [
                'detalle' => 'Pedido N° ' . self::numero_del_pedido($pedido) . ' de la tienda: ' . self::comprador($pedido) . ', ' . self::pesos($total),
                'texto'   => '🛒 Revisé la tienda a las ' . $momento->format('H:i') . ': entró *1 pedido nuevo* ' . $desde_las . ":\n"
                    . implode("\n", $renglones) . "\nSi querés el detalle, preguntame.",
            ];
        }

        return [
            'detalle' => $cuantos . ' pedidos nuevos en la tienda ' . $desde_las . ', ' . self::pesos($total) . ' en total',
            'texto'   => '🛒 Revisé la tienda a las ' . $momento->format('H:i') . ': entraron *' . $cuantos . ' pedidos nuevos* ' . $desde_las
                . ' (' . self::pesos($total) . " en total):\n" . implode("\n", $renglones) . "\nSi querés el detalle de alguno, preguntame.",
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
     * Los artículos (sin variantes) que un movimiento de stock dejó en cero o menos, desde el cursor y adentro
     * de la gracia, que siguen así y no se avisaron hoy. Agrupados de a ARTICULOS_POR_AVISO.
     *
     * @return array<int, array{momento: \Carbon\Carbon, item: array}>
     */
    protected static function stock_cero(User $dueno, Carbon $desde, Carbon $ahora): array
    {
        $limite = $ahora->copy()->subHours(self::HORAS_DE_GRACIA);
        $inicio = $desde->gt($limite) ? $desde : $limite;

        $movimientos = DB::table('stock_movements')
                        ->where('user_id', (int) $dueno->id)
                        ->whereNull('article_variant_id')
                        ->whereNotNull('article_id')
                        ->where('amount', '<', 0)
                        ->where('created_at', '>', $inicio->format('Y-m-d H:i:s'))
                        ->where('created_at', '<=', $ahora->format('Y-m-d H:i:s'))
                        ->groupBy('article_id')
                        ->select('article_id', DB::raw('MAX(created_at) as ultimo'))
                        ->get();

        if (!$movimientos->count()) {

            return [];
        }

        $ultimo = [];

        foreach ($movimientos as $movimiento) {
            $ultimo[(int) $movimiento->article_id] = Carbon::parse($movimiento->ultimo);
        }

        $articulos = Article::where('user_id', (int) $dueno->id)
                            ->whereIn('id', array_keys($ultimo))
                            ->whereNotNull('stock')
                            ->where('stock', '<=', 0)
                            ->whereDoesntHave('article_variants')
                            ->orderBy('id')
                            ->get(['id', 'name', 'stock']);

        $hoy = $ahora->copy()->startOfDay();
        $claves = [];

        foreach ($articulos as $articulo) {
            $claves[] = self::clave_de_stock((int) $articulo->id, $hoy);
        }

        $sellados = self::sellados($dueno, AiAvisoDelAsistente::TIPO_STOCK_CERO, $claves);

        $sin_avisar = [];

        foreach ($articulos as $articulo) {
            if (!isset($sellados[self::clave_de_stock((int) $articulo->id, $hoy)])) {
                $sin_avisar[] = $articulo;
            }
        }

        $avisos = [];

        foreach (array_chunk($sin_avisar, self::ARTICULOS_POR_AVISO) as $grupo) {

            $ids = [];
            $momento = null;

            foreach ($grupo as $articulo) {
                $ids[] = (int) $articulo->id;
                $movido = $ultimo[(int) $articulo->id];
                $momento = is_null($momento) || $movido->gt($momento) ? $movido : $momento;
            }

            $avisos[] = [
                'momento' => $momento,
                'item'    => self::item(
                    self::PREFIJO_STOCK . $hoy->format('Y-m-d') . ':' . implode('.', $ids),
                    $momento,
                    self::detalle_de_stock($grupo),
                    self::texto_de_stock($grupo)
                ),
            ];

            if (count($avisos) >= self::TOPE_POR_TIPO) {
                break;
            }
        }

        return $avisos;
    }

    protected static function clave_de_stock($article_id, Carbon $dia): string
    {
        return 's' . (int) $article_id . '-' . $dia->format('Ymd');
    }

    /**
     * @param  array<int, \App\Models\Article>  $grupo
     * @return string
     */
    protected static function detalle_de_stock(array $grupo): string
    {
        $nombres = array_map(function ($articulo) {
            return self::nombre_corto((string) $articulo->name);
        }, $grupo);

        if (count($nombres) === 1) {

            return 'Sin stock: ' . $nombres[0];
        }

        return count($nombres) . ' artículos sin stock: ' . self::enumerar($nombres);
    }

    /**
     * @param  array<int, \App\Models\Article>  $grupo
     * @return string
     */
    protected static function texto_de_stock(array $grupo): string
    {
        if (count($grupo) === 1) {

            return '📦 Se quedó sin stock *' . self::nombre_corto((string) $grupo[0]->name) . '* (stock ' . self::cantidad($grupo[0]->stock) . ').'
                . "\nSi querés, te ayudo a reponerlo.";
        }

        $renglones = array_map(function ($articulo) {
            return '- ' . self::nombre_corto((string) $articulo->name) . ' (stock ' . self::cantidad($articulo->stock) . ')';
        }, $grupo);

        return '📦 Se quedaron sin stock *' . count($grupo) . " artículos*:\n" . implode("\n", $renglones) . "\nSi querés, te ayudo a reponerlos.";
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
     * Sella el aviso como mandado y lo deja en la conversación de WhatsApp del dueño, UNA sola vez.
     * false (el 404 del contrato) si el id no parsea o lo que nombra no es de este dueño.
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

            $sellados = self::sellados($dueno, $partes['tipo'], $partes['claves']);

            $nuevas = array_values(array_filter($partes['claves'], function ($clave) use ($sellados) {
                return !isset($sellados[$clave]);
            }));

            if (!count($nuevas)) {

                return true;
            }

            $ofrecido = Cache::get(self::CLAVE_TEXTO_OFRECIDO . (int) $dueno->id . ':' . trim((string) $id));
            $texto = is_string($ofrecido) && trim($ofrecido) !== '' ? $ofrecido : $partes['texto'];

            $conversacion = self::conversacion_para_dejarlo($dueno, $partes['titulo']);
            $wamid = trim((string) $whatsapp_message_id);
            $wamid = $wamid === '' ? null : mb_substr($wamid, 0, 128);
            $ahora = Carbon::now();

            foreach ($nuevas as $clave) {
                AiAvisoDelAsistente::create([
                    'uuid'                    => $clave,
                    'owner_id'                => (int) $dueno->id,
                    'ai_conversation_id'      => is_null($conversacion) ? null : (int) $conversacion->id,
                    'tipo'                    => $partes['tipo'],
                    'texto'                   => $texto,
                    'avisado_por_whatsapp_at' => $ahora,
                    'whatsapp_message_id'     => is_null($wamid) ? null : mb_substr($wamid, 0, 191),
                ]);
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
     * Las partes de un id propio: el tipo del sello, sus claves, un texto de repuesto y el título de una
     * conversación nueva; o null si no tiene la forma o no es de este dueño.
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $id
     * @return array{tipo: string, claves: array<int, string>, texto: string, titulo: string}|null
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
                'claves' => [self::clave_de_pedido($pedido)],
                'texto'  => self::texto_del_pedido($pedido),
                'titulo' => 'Pedido N° ' . self::numero_del_pedido($pedido) . ' de la tienda',
            ];
        }

        if (preg_match('/^' . preg_quote(self::PREFIJO_HORA, '/') . '(\d{1,12}):(\d{4}-\d{2}-\d{2}):(\d{2})(\d{2})$/', $id, $m)) {

            $dia = AgendaTareaHelper::parsear_fecha($m[2]);

            if (is_null($dia) || (int) $m[3] > 23 || (int) $m[4] > 59 || $dia->gt(Carbon::today())) {

                return null;
            }

            $desde = Carbon::createFromTimestamp((int) $m[1]);
            $momento = $dia->copy()->setTime((int) $m[3], (int) $m[4]);
            /* La hora anterior sale de las horas de HOY del dueño (con la de este aviso, por si la cambió). */
            $horas = array_values(array_unique(array_merge([$momento->format('H:i')], self::preferencias($dueno)['horas'])));
            sort($horas);
            $resumen = self::resumen_de_la_hora($dueno, $horas, $desde, $momento);

            return [
                'tipo'   => AiAvisoDelAsistente::TIPO_PEDIDOS_HORA,
                'claves' => [self::clave_de_hora($desde, $momento)],
                'texto'  => is_null($resumen) ? '🛒 Revisé la tienda a las ' . $momento->format('H:i') . '.' : $resumen['texto'],
                'titulo' => 'Pedidos de la tienda',
            ];
        }

        if (preg_match('/^' . preg_quote(self::PREFIJO_STOCK, '/') . '(\d{4}-\d{2}-\d{2}):(\d{1,10}(?:\.\d{1,10}){0,' . (self::ARTICULOS_POR_AVISO - 1) . '})$/', $id, $m)) {

            $dia = AgendaTareaHelper::parsear_fecha($m[1]);

            if (is_null($dia) || $dia->gt(Carbon::today())) {

                return null;
            }

            $ids = array_values(array_unique(array_map('intval', explode('.', $m[2]))));

            $articulos = Article::withTrashed()->where('user_id', (int) $dueno->id)->whereIn('id', $ids)->orderBy('id')->get(['id', 'name', 'stock']);

            if ($articulos->count() !== count($ids)) {

                return null;
            }

            $claves = [];

            foreach ($ids as $article_id) {
                $claves[] = self::clave_de_stock($article_id, $dia);
            }

            return [
                'tipo'   => AiAvisoDelAsistente::TIPO_STOCK_CERO,
                'claves' => $claves,
                'texto'  => self::texto_de_stock($articulos->all()),
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
