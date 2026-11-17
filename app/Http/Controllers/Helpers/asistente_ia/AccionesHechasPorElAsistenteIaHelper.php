<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessageAction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «¿QUÉ ME CARGASTE? ¿QUÉ FACTURASTE? ¿QUÉ HICISTE?» — LA HERRAMIENTA DE LECTURA
 * consultar_lo_que_hizo_el_asistente (misión asistente-diario-20261117, parte B, A2).
 *
 * 🔴 EL CASO REAL (Truvari, 16/11/2026, conversación 40). El dueño preguntó qué le había cargado y
 * facturado el asistente y recibió listas que se contradecían de un mensaje al otro, con nombres
 * faltantes que el sistema sí tenía y una venta (la 3105) que quedó afuera. El asistente armaba la
 * respuesta de memoria y de los RESÚMENES de las charlas viejas: lo que hizo de verdad vive en
 * `ai_message_actions` (las tarjetas confirmadas), y esa tabla no era consultable.
 *
 * POR QUÉ UNA HERRAMIENTA PROPIA Y NO UNA ENTIDAD MÁS DE consultar_datos (la elección del mecanismo):
 *  - `ai_message_actions` está en EsquemaDeDatosIaHelper::TABLAS_EXCLUIDAS a propósito: sus columnas
 *    `datos` y `presentacion` son el payload interno de ejecución y pueden traer firmas fiscales,
 *    rutas o tokens. Abrirla como entidad derivada obligaría a pelear columna por columna contra esa
 *    lista negra, y `resultado` es un JSON de forma libre que el motor genérico devolvería crudo.
 *  - El motor genérico no puede recortar por PERSONA (solo por dueño) ni cruzar el resultado con la
 *    venta que creó: acá hace falta las dos cosas.
 *  - Es el mismo patrón que consultar_ventas_sin_cobrar o consultar_rendimiento_de_compras: una
 *    entrada en el registro de lectura de AsistenteIaService con su handler, que llega sola a los
 *    tres canales (sistema, WhatsApp del dueño y MCP).
 *
 * 🔴 QUÉ SE VE Y QUIÉN LO VE:
 *  - Siempre acotado al negocio: `ai_message_actions.user_id` = el dueño.
 *  - El dueño y el administrador (PermisosIaHelper::es_admin) ven todo lo que el asistente hizo en el
 *    negocio, con quién lo pidió. Un empleado sin acceso de administrador ve SOLO lo que hizo para él
 *    (`auth_user_id` = esa persona): no había un criterio de permisos previo para este dato, y lo que
 *    otro empleado le pidió al asistente no es suyo. Sin persona comprobada no se ve nada.
 *  - Solo las tarjetas CONFIRMADAS (lo que de verdad se ejecutó). Las propuestas, canceladas o
 *    vencidas no son algo que el asistente «hizo».
 *  - 🔴 NUNCA `datos` ni `presentacion`, y de `resultado` solo una lista blanca de claves escalares
 *    (texto, entidad, id, nombre, numero, total, estado fiscal). El texto pasa sin links (un link de
 *    PDF o de Excel lleva un token) y recortado.
 *
 * Y LOS DATOS DE HOY, NO LOS DE ENTONCES: si la acción creó o tocó una VENTA (una venta del
 * asistente, un alta o edición de venta del ABM genérico, o una acción de pantalla sobre una venta
 * como facturarla), se completa con la venta tal como está AHORA: su número, su cliente, su total, si
 * está anulada y si tiene comprobante de ARCA con CAE (el criterio de ResumenDeVentasIaHelper::
 * condicion_facturada(): `afip_tickets.cae` no vacío, incluida la factura de la consolidación).
 *
 * Es SOLO LECTURA. Corre sin Auth (el dueño viene explícito).
 *
 * PHP 7.4: sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class AccionesHechasPorElAsistenteIaHelper
{
    /** Cuántas acciones viajan si no se pide otra cosa. */
    const LIMITE_POR_DEFECTO = 50;

    /** Techo de la lista: la respuesta viaja entera en cada vuelta del loop. */
    const LIMITE_MAXIMO = 200;

    /** Días hacia atrás si no se manda período (hoy incluido). */
    const DIAS_POR_DEFECTO = 30;

    /** Techo del rango, en días. */
    const TOPE_DIAS = 400;

    /** Largo máximo del texto del resultado que viaja al modelo. */
    const LARGO_DEL_TEXTO = 300;

    /**
     * Las claves de `resultado` que pueden viajar (la lista blanca). Todo lo demás se descarta: hay
     * resultados que traen el cuerpo entero de la respuesta de una pantalla (`respuesta`) o la ruta
     * interna (`uri`), y nada de eso es para el modelo.
     *
     * @var array<int, string>
     */
    const CLAVES_DEL_RESULTADO = ['entidad', 'id', 'nombre', 'numero', 'total', 'estado_fiscal'];

    /**
     * Cómo se nombra cada tipo de tarjeta para la persona. Lo que no está acá viaja con el tipo tal
     * cual, con los guiones bajos pasados a espacios.
     *
     * @var array<string, string>
     */
    const TIPOS_LEGIBLES = [
        AiMessageAction::TIPO_GASTO                => 'gasto',
        AiMessageAction::TIPO_PAGO                 => 'pago',
        AiMessageAction::TIPO_TAREA_NUEVA          => 'tarea nueva',
        AiMessageAction::TIPO_TAREA_EDITAR         => 'tarea editada',
        AiMessageAction::TIPO_TAREA_COMPLETAR      => 'tarea completada',
        AiMessageAction::TIPO_COMBO                => 'combo',
        AiMessageAction::TIPO_OFERTA               => 'oferta',
        AiMessageAction::TIPO_COMPRA_CON_FACTURA   => 'compra con factura',
        AiMessageAction::TIPO_ALTA                 => 'alta',
        AiMessageAction::TIPO_EDICION              => 'edición',
        AiMessageAction::TIPO_BAJA                 => 'baja',
        AiMessageAction::TIPO_VENTA                => 'venta',
        AiMessageAction::TIPO_PRESUPUESTO          => 'presupuesto',
        AiMessageAction::TIPO_ACCION_PANTALLA      => 'acción de pantalla',
        AiMessageAction::TIPO_BORRADO_PANTALLA     => 'borrado',
        AiMessageAction::TIPO_MOVIMIENTO_STOCK     => 'movimiento de stock',
        AiMessageAction::TIPO_STOCK_DEPOSITO       => 'stock de un depósito',
        AiMessageAction::TIPO_FOTO_ARTICULO        => 'foto de artículo',
        AiMessageAction::TIPO_FOTO_SUCURSAL        => 'foto de sucursal',
        AiMessageAction::TIPO_ACTUALIZACION_MASIVA => 'actualización masiva',
    ];

    /**
     * LA HERRAMIENTA.
     *
     * @param  int  $owner_id  Dueño. Nunca Auth.
     * @param  AiConversation|null  $conversation  Para saber QUIÉN pregunta (y marcar lo de esta charla).
     * @param  array  $input  desde, hasta, tipo, limite.
     * @return array<string, mixed>
     */
    public static function consultar($owner_id, $conversation, array $input)
    {
        $owner_id = (int) $owner_id;

        $owner = User::find($owner_id);

        if (is_null($owner)) {

            return RespuestaDeCargaIa::error('No se encontró el negocio.');
        }

        // ------------------------------------------------------------------ quién pregunta
        $persona = self::persona($owner_id, $conversation);

        if (is_null($persona)) {

            return RespuestaDeCargaIa::error('No sé quién está preguntando, así que no puedo mostrar lo que hizo el asistente. Decíselo tal cual.');
        }

        $ve_todo = PermisosIaHelper::es_admin($persona);

        // ------------------------------------------------------------------ período
        $rango = self::rango($input);

        if (RespuestaDeCargaIa::es_negativa($rango)) {

            return $rango;
        }

        list($inicio, $fin) = $rango;

        $limite = isset($input['limite']) ? (int) $input['limite'] : self::LIMITE_POR_DEFECTO;
        $limite = max(1, min(self::LIMITE_MAXIMO, $limite));

        // ------------------------------------------------------------------ la consulta
        $fecha = 'COALESCE(ai_message_actions.resuelta_at, ai_message_actions.created_at)';

        $base = DB::table('ai_message_actions')
            ->where('ai_message_actions.user_id', $owner_id)
            ->where('ai_message_actions.estado', AiMessageAction::ESTADO_CONFIRMADA)
            ->whereRaw($fecha . ' >= ?', [$inicio->copy()->startOfDay()->format('Y-m-d H:i:s')])
            ->whereRaw($fecha . ' <= ?', [$fin->copy()->endOfDay()->format('Y-m-d H:i:s')]);

        if (!$ve_todo) {

            $base->where('ai_message_actions.auth_user_id', (int) $persona->id);
        }

        $tipo = isset($input['tipo']) ? trim((string) $input['tipo']) : '';

        if ($tipo !== '') {

            $base->where('ai_message_actions.tipo', $tipo);
        }

        $total = (int) (clone $base)->count();

        /*
         * 🔴 Solo las columnas de la lista: `datos` y `presentacion` ni siquiera se leen de la base, así
         * que no hay forma de que se escapen por un descuido más abajo.
         */
        $filas = (clone $base)
            ->leftJoin('users as quien', 'quien.id', '=', 'ai_message_actions.auth_user_id')
            ->orderByRaw($fecha . ' DESC')
            ->orderBy('ai_message_actions.id', 'DESC')
            ->limit($limite)
            ->get([
                'ai_message_actions.id',
                'ai_message_actions.ai_conversation_id',
                'ai_message_actions.auth_user_id',
                'ai_message_actions.tipo',
                'ai_message_actions.resultado',
                'ai_message_actions.created_at',
                'ai_message_actions.resuelta_at',
                'quien.name as quien_nombre',
            ]);

        $acciones = [];
        $ventas_pedidas = [];

        foreach ($filas as $fila) {

            $accion = self::accion($fila, $conversation, $ve_todo);

            if (!is_null($accion['venta_id'])) {
                $ventas_pedidas[] = $accion['venta_id'];
            }

            $acciones[] = $accion;
        }

        // ------------------------------------------------------------------ las ventas, como están hoy
        $ventas = self::ventas_de_hoy($owner_id, array_values(array_unique($ventas_pedidas)));

        foreach ($acciones as $i => $accion) {

            $venta_id = $accion['venta_id'];

            unset($acciones[$i]['venta_id']);

            if (!is_null($venta_id) && isset($ventas[$venta_id])) {

                $acciones[$i]['venta'] = $ventas[$venta_id];
            }
        }

        return [
            'ok'                 => true,
            'periodo'            => ['desde' => $inicio->format('Y-m-d'), 'hasta' => $fin->format('Y-m-d')],
            'alcance'            => $ve_todo
                ? 'todo lo que el asistente hizo en el negocio, lo haya pedido quien lo haya pedido'
                : 'solo lo que el asistente hizo para quien pregunta (' . $persona->name . ')',
            'acciones_en_total'  => $total,
            'acciones_en_lista'  => count($acciones),
            'acciones'           => $acciones,
            'nota'               => self::nota($total, count($acciones)),
        ];
    }

    /**
     * Quien pregunta, comprobado: tiene que ser el dueño o un empleado de ESTE negocio. Null si la
     * conversación no dice quién es o si la persona es de otra cuenta.
     *
     * @param  int  $owner_id
     * @param  AiConversation|null  $conversation
     * @return User|null
     */
    protected static function persona($owner_id, $conversation)
    {
        if (!($conversation instanceof AiConversation) || empty($conversation->auth_user_id)) {

            return null;
        }

        $persona = User::find((int) $conversation->auth_user_id);

        if (is_null($persona)) {

            return null;
        }

        $es_el_dueno = (int) $persona->id === (int) $owner_id;
        $es_su_empleado = !empty($persona->owner_id) && (int) $persona->owner_id === (int) $owner_id;

        return ($es_el_dueno || $es_su_empleado) ? $persona : null;
    }

    /**
     * El período: desde/hasta en AAAA-MM-DD. Sin ninguno, los últimos DIAS_POR_DEFECTO días con hoy
     * incluido; con uno solo, el otro es hoy (o el mismo día).
     *
     * @param  array  $input
     * @return array  [Carbon inicio, Carbon fin] o la respuesta negativa.
     */
    protected static function rango(array $input)
    {
        $desde = isset($input['desde']) ? trim((string) $input['desde']) : '';
        $hasta = isset($input['hasta']) ? trim((string) $input['hasta']) : '';

        if ($desde === '' && $hasta === '') {

            return [Carbon::today()->subDays(self::DIAS_POR_DEFECTO - 1), Carbon::today()];
        }

        $valido = function ($texto) {
            return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) && strtotime($texto) !== false;
        };

        if (($desde !== '' && !$valido($desde)) || ($hasta !== '' && !$valido($hasta))) {

            return RespuestaDeCargaIa::error('desde y hasta van en formato AAAA-MM-DD.');
        }

        $inicio = $desde === '' ? Carbon::parse($hasta)->startOfDay() : Carbon::parse($desde)->startOfDay();
        $fin = $hasta === '' ? Carbon::today() : Carbon::parse($hasta)->startOfDay();

        if ($inicio > $fin) {

            return RespuestaDeCargaIa::error('desde (' . $inicio->format('Y-m-d') . ') es posterior a hasta (' . $fin->format('Y-m-d') . ').');
        }

        if ($inicio->diffInDays($fin) + 1 > self::TOPE_DIAS) {

            return RespuestaDeCargaIa::error('El rango tiene más de ' . self::TOPE_DIAS . ' días. Acotalo.');
        }

        return [$inicio, $fin];
    }

    /**
     * Una acción como la ve el modelo, más la clave interna `venta_id` (la venta que tocó, o null), que
     * consultar() usa para completarla y después saca.
     *
     * @param  \stdClass  $fila
     * @param  AiConversation|null  $conversation
     * @param  bool  $ve_todo  Si quien pregunta ve lo de todos (entonces viaja quién lo pidió).
     * @return array<string, mixed>
     */
    protected static function accion($fila, $conversation, $ve_todo)
    {
        $resultado = self::resultado_decodificado($fila->resultado);

        $cuando = !empty($fila->resuelta_at) ? $fila->resuelta_at : $fila->created_at;

        $accion = [
            'fecha'       => is_null($cuando) ? null : Carbon::parse($cuando)->format('Y-m-d H:i'),
            'tipo'        => (string) $fila->tipo,
            'tipo_legible' => isset(self::TIPOS_LEGIBLES[$fila->tipo])
                ? self::TIPOS_LEGIBLES[$fila->tipo]
                : str_replace('_', ' ', (string) $fila->tipo),
            'que_hizo'    => self::texto_limpio(isset($resultado['texto']) ? $resultado['texto'] : null),
        ];

        // La lista blanca del resultado: solo escalares.
        foreach (self::CLAVES_DEL_RESULTADO as $clave) {

            if (array_key_exists($clave, $resultado) && (is_scalar($resultado[$clave]) || is_null($resultado[$clave]))) {

                if (!is_null($resultado[$clave])) {
                    $accion[$clave] = $resultado[$clave];
                }
            }
        }

        if ($ve_todo) {
            $accion['pedido_por'] = is_null($fila->quien_nombre) ? null : (string) $fila->quien_nombre;
        }

        $accion['en_esta_conversacion'] = ($conversation instanceof AiConversation)
            && (int) $fila->ai_conversation_id === (int) $conversation->id;

        $accion['venta_id'] = self::venta_que_toco((string) $fila->tipo, $resultado);

        return $accion;
    }

    /**
     * `resultado` decodificado como array, o [] si no es un JSON de objeto.
     *
     * @param  mixed  $crudo
     * @return array<string, mixed>
     */
    protected static function resultado_decodificado($crudo)
    {
        if (is_null($crudo) || $crudo === '') {

            return [];
        }

        $decodificado = json_decode((string) $crudo, true);

        return is_array($decodificado) ? $decodificado : [];
    }

    /**
     * El texto del resultado, sin links (un link de PDF o de Excel lleva su token) y recortado.
     *
     * @param  mixed  $texto
     * @return string|null
     */
    protected static function texto_limpio($texto)
    {
        if (!is_string($texto) || trim($texto) === '') {

            return null;
        }

        $sin_links = preg_replace('/https?:\/\/\S+/iu', '[link]', $texto);

        $sin_links = trim((string) preg_replace('/\s+/u', ' ', (string) $sin_links));

        return mb_substr($sin_links, 0, self::LARGO_DEL_TEXTO);
    }

    /**
     * El id de la VENTA que tocó la acción, o null. Tres caminos:
     *  - la venta del asistente (PropuestaVentaIaHelper): `resultado.venta_id`;
     *  - el ABM genérico sobre la entidad `sale`: `resultado.id`;
     *  - una acción de pantalla sobre una venta (facturarla, editarla): el id que lleva la ruta
     *    (`sale/{id}/...`) o la venta que devolvió la pantalla (`respuesta.sale.id`).
     * El id se valida después contra el dueño en ventas_de_hoy(): acá solo se lee.
     *
     * @param  string  $tipo
     * @param  array  $resultado
     * @return int|null
     */
    protected static function venta_que_toco($tipo, array $resultado)
    {
        if ($tipo === AiMessageAction::TIPO_VENTA && !empty($resultado['venta_id'])) {

            return (int) $resultado['venta_id'];
        }

        if (isset($resultado['entidad']) && $resultado['entidad'] === 'sale' && !empty($resultado['id']) && is_numeric($resultado['id'])) {

            return (int) $resultado['id'];
        }

        if ($tipo === AiMessageAction::TIPO_ACCION_PANTALLA) {

            if (isset($resultado['respuesta']['sale']['id']) && is_numeric($resultado['respuesta']['sale']['id'])) {

                return (int) $resultado['respuesta']['sale']['id'];
            }

            if (isset($resultado['uri']) && is_string($resultado['uri'])
                && preg_match('/(?:^|\/)sale\/(\d+)(?:\/|$)/', $resultado['uri'], $coincidencia)) {

                return (int) $coincidencia[1];
            }
        }

        return null;
    }

    /**
     * Las ventas pedidas, como están HOY, solo las del dueño: número, cliente, total, si está anulada y
     * su comprobante de ARCA.
     *
     * @param  int  $owner_id
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>  venta_id => datos.
     */
    protected static function ventas_de_hoy($owner_id, array $ids)
    {
        if (!count($ids)) {

            return [];
        }

        $con_consolidacion = Schema::hasColumn('sales', 'consolidacion_facturacion_id');

        $columnas = ['sales.id', 'sales.num', 'sales.total', 'sales.deleted_at', 'clients.name as cliente'];

        if ($con_consolidacion) {
            $columnas[] = 'sales.consolidacion_facturacion_id';
        }

        // Sin el scope de borrado a propósito: una venta anulada se informa como anulada, no desaparece.
        $filas = DB::table('sales')
            ->leftJoin('clients', function ($join) use ($owner_id) {
                $join->on('clients.id', '=', 'sales.client_id')->where('clients.user_id', '=', $owner_id);
            })
            ->where('sales.user_id', $owner_id)
            ->whereIn('sales.id', $ids)
            ->get($columnas);

        $con_ticket = [];

        foreach ($filas as $fila) {

            $con_ticket[] = (int) $fila->id;

            if ($con_consolidacion && !empty($fila->consolidacion_facturacion_id)) {
                $con_ticket[] = (int) $fila->consolidacion_facturacion_id;
            }
        }

        $comprobantes = self::comprobantes($con_ticket);

        $ventas = [];

        foreach ($filas as $fila) {

            $comprobante = isset($comprobantes[(int) $fila->id]) ? $comprobantes[(int) $fila->id] : null;

            // La venta consolidada no tiene ticket propio: su factura la emitió la contenedora.
            if (is_null($comprobante) && $con_consolidacion && !empty($fila->consolidacion_facturacion_id)
                && isset($comprobantes[(int) $fila->consolidacion_facturacion_id])) {

                $comprobante = $comprobantes[(int) $fila->consolidacion_facturacion_id];
                $comprobante['por_consolidacion'] = true;
            }

            $ventas[(int) $fila->id] = [
                'numero'      => is_null($fila->num) ? null : (int) $fila->num,
                'cliente'     => is_null($fila->cliente) ? 'consumidor final / sin cliente' : (string) $fila->cliente,
                'total'       => is_null($fila->total) ? null : round((float) $fila->total, 2),
                'anulada'     => !is_null($fila->deleted_at),
                'factura'     => is_null($comprobante) ? ['estado' => 'sin_factura'] : $comprobante,
            ];
        }

        return $ventas;
    }

    /**
     * El comprobante de ARCA de cada venta (no borrado). Con CAE manda sobre cualquier otro: es el
     * criterio canónico del sistema (ResumenDeVentasIaHelper::condicion_facturada()). Sin CAE y con
     * resultado R, rechazada; sin CAE y sin rechazo, pendiente.
     *
     * @param  array<int, int>  $sale_ids
     * @return array<int, array<string, mixed>>  sale_id => comprobante.
     */
    protected static function comprobantes(array $sale_ids)
    {
        if (!count($sale_ids)) {

            return [];
        }

        $tickets = DB::table('afip_tickets')
            ->whereIn('sale_id', array_values(array_unique($sale_ids)))
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['sale_id', 'cae', 'resultado', 'cbte_letra', 'punto_venta', 'cbte_numero']);

        $por_venta = [];

        foreach ($tickets as $ticket) {

            $sale_id = (int) $ticket->sale_id;

            $con_cae = !is_null($ticket->cae) && trim((string) $ticket->cae) !== '';

            if ($con_cae) {
                $estado = 'emitida';
            } elseif (strtoupper(trim((string) $ticket->resultado)) === 'R') {
                $estado = 'rechazada';
            } else {
                $estado = 'pendiente';
            }

            // Una emitida no se pisa con otro intento; lo demás, el último manda.
            if (isset($por_venta[$sale_id]) && $por_venta[$sale_id]['estado'] === 'emitida') {
                continue;
            }

            $comprobante = ['estado' => $estado];

            if ($con_cae) {
                $comprobante['comprobante'] = trim('Factura ' . (string) $ticket->cbte_letra)
                    . ' ' . str_pad((string) (int) $ticket->punto_venta, 4, '0', STR_PAD_LEFT)
                    . '-' . str_pad((string) (int) $ticket->cbte_numero, 8, '0', STR_PAD_LEFT);
                $comprobante['cae'] = (string) $ticket->cae;
            }

            $por_venta[$sale_id] = $comprobante;
        }

        return $por_venta;
    }

    /**
     * La nota para el modelo.
     *
     * @param  int  $total
     * @param  int  $en_lista
     * @return string
     */
    protected static function nota($total, $en_lista)
    {
        $nota = 'Es lo que el asistente EJECUTÓ de verdad (tarjetas confirmadas), leído del sistema, de la más nueva a la más vieja. '
            . 'Contestá «qué cargaste / facturaste / hiciste» con ESTA lista y no con la memoria ni con resúmenes de charlas viejas. '
            . 'En una venta, `venta` trae cómo está HOY: número, cliente, total, si está anulada y factura.estado (emitida con su comprobante y CAE, rechazada, pendiente o sin_factura). ';

        if ($total > $en_lista) {
            $nota .= 'La lista está recortada: hay ' . $total . ' acciones en el período y viajan ' . $en_lista . '; acotá con desde/hasta o tipo, o subí limite. ';
        }

        if ($total === 0) {
            $nota .= 'No hay acciones confirmadas en el período: decilo así, sin inventar. ';
        }

        return trim($nota);
    }
}
