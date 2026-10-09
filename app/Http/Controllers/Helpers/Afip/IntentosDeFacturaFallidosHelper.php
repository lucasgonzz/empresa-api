<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Models\AfipTicket;
use Illuminate\Support\Facades\Log;

/**
 * Descarta los intentos de factura fallidos de una venta que ya quedaron SUPERADOS por una factura
 * autorizada de esa misma venta (misión facturas-reintentadas-salen-de-alertas, 9/10/2026).
 *
 * ─── El defecto ───────────────────────────────────────────────────────────────────────────────
 *
 * Alertas → "Facturacion" (y el aviso "Facturas no autorizadas") lista toda venta que tenga ALGÚN
 * `afip_ticket` sin CAE (`AfipTicketController::problemas_al_facturar()`). Reemitir
 * (`MakeAfipTicket::make_afip_ticket()`) siempre crea un ticket NUEVO y no toca el fallido, así que
 * la venta quedaba facturada **y** seguía en Alertas hasta que alguien usaba el tacho de la tarjeta.
 * Y si en vez del tacho se apretaba Consultar sobre el fallido, `motivo_de_comprobante_ajeno()` lo
 * descartaba diciendo que el número "ya pertenece a otra venta"… que era la misma.
 *
 * ─── El criterio (elegido por Lucas: (a)+(b)+(c)) ─────────────────────────────────────────────
 *
 * Cuando una FACTURA de una venta queda autorizada (CAE no vacío), se descartan con **borrado
 * suave** —SoftDeletes, exactamente lo mismo que hace el tacho (`AfipTicketController::destroy()`)—
 * los otros tickets de esa misma venta que se puede PROBAR que nunca se autorizaron:
 *
 *  (a) **sin número** (`cbte_numero` nulo o vacío): nunca llegó a ARCA. `set_numero_comprobante()`
 *      escribe el número ANTES de `FECAESolicitar` (y `AfipFexHelper` antes de `FEXAuthorize`), así
 *      que un ticket sin número es uno al que le falló el pedido del último número: no se mandó nada.
 *  (b) **mismo número**: mismo emisor (CUIT), punto de venta, tipo y número que una factura
 *      autorizada de la venta. ARCA no da dos veces el mismo número: si el reintento recibió N,
 *      es porque N no estaba autorizado cuando lo pidió, y después ya no se le puede dar a otro.
 *      El CUIT entra porque ARCA numera por CUIT + punto de venta + tipo: dos emisores distintos
 *      pueden tener, legítimamente, el mismo número.
 *  (c) **rechazo explícito** (`resultado = 'R'`): ARCA contestó que no lo autorizaba. WSFE lo
 *      guarda desde esta misión (`AfipWsfeHelper::update_afip_ticket()`); la Factura E ya lo
 *      guardaba.
 *
 * ─── Lo que NUNCA se toca ─────────────────────────────────────────────────────────────────────
 *
 *  - Un ticket con CAE: ese comprobante existe en ARCA y borrarlo lo saca del Libro IVA y de los
 *    TXT (el bug de masquito, 11/9/2026). Ni aunque diga `resultado = 'R'`.
 *  - Una nota de crédito (`nota_credito_id` o `sale_nota_credito_id` no nulos): tiene su propio
 *    circuito de reintento (`AfipNotaCreditoHelper::create_afip_ticket()` reutiliza el fallido).
 *  - 🔴 **Un fallido CON OTRO NÚMERO y sin rechazo explícito.** Es el que tiró error de red al
 *    emitir y no se pudo recuperar consultando: puede ser una factura que ARCA SÍ autorizó y cuya
 *    respuesta se perdió. Si el reintento recibió N+1 es justamente porque N ya estaba tomado. Esa
 *    venta puede tener una factura DUPLICADA en ARCA, y lo único que lo pone en evidencia es que
 *    siga en Alertas para que alguien apriete Consultar. Borrarlo sería esconder un comprobante
 *    autorizado que el sistema nunca declararía.
 *  - Nada, si la venta no tiene ninguna factura autorizada: sin factura no hay nada que "supere"
 *    al intento, y la venta tiene que seguir en Alertas.
 *
 * No hay restricción por orden de creación: si Consultar le da el CAE a un fallido viejo, los
 * reintentos posteriores que cumplan el criterio también se descartan.
 *
 * ─── Quién lo usa ─────────────────────────────────────────────────────────────────────────────
 *
 * Los tres caminos por los que una factura queda autorizada llaman a `descartar_superados()`
 * apenas escribieron el CAE: `AfipWsfeHelper::solicitar_cae()` (emisión normal),
 * `AfipWsfeHelper::consultar_comprobante()` (consulta manual, o la automática después de un error
 * de red al emitir) y `AfipFexHelper::update_afip_ticket()` (Factura E). Lo que quedó de antes lo
 * limpia el comando `afip:descartar-intentos-fallidos`, que usa el MISMO `motivo_de_descarte()`.
 *
 * 🔴 La limpieza nunca puede romper una emisión: cuando se llama, la factura YA está autorizada en
 * ARCA. Cualquier falla se reporta (`report()`) y se sigue; en el peor caso el intento viejo queda
 * en Alertas como antes y el comando lo levanta después.
 */
class IntentosDeFacturaFallidosHelper
{
    /** (a) El intento nunca llegó a ARCA: no tiene número de comprobante. */
    const MOTIVO_SIN_NUMERO = 'sin_numero';

    /** (b) El intento tiene el mismo comprobante (CUIT, punto de venta, tipo y número) que una factura autorizada. */
    const MOTIVO_MISMO_NUMERO = 'mismo_numero';

    /** (c) ARCA rechazó el intento de forma explícita (`resultado = 'R'`). */
    const MOTIVO_RECHAZADO = 'rechazado';

    /**
     * Motivo por el que `$fallido` se puede descartar porque una factura autorizada de su venta ya
     * lo superó, o null si NO se puede descartar.
     *
     * Es el único lugar donde vive el criterio: lo usan la limpieza en vivo
     * (`descartar_superados()`) y el comando de saneo, para que los dos nunca diverjan.
     *
     * El orden de los motivos es solo informativo (el primero que aplica es el que se loguea): los
     * tres alcanzan por sí solos.
     *
     * @param  \App\Models\AfipTicket $fallido Ticket sin CAE candidato a descartarse.
     * @param  iterable|\App\Models\AfipTicket $autorizados Facturas de la venta (las que no tengan
     *         CAE, las notas de crédito y las de otra venta se ignoran solas).
     * @return string|null Una de las constantes `MOTIVO_*`, o null.
     */
    public static function motivo_de_descarte($fallido, $autorizados)
    {
        if (is_null($fallido) || self::tiene_cae($fallido) || self::es_nota_de_credito($fallido)) {
            return null;
        }

        if ($autorizados instanceof AfipTicket) {
            $autorizados = [$autorizados];
        }

        /** @var array $facturas_autorizadas Las que de verdad superan al fallido: facturas con CAE de SU venta. */
        $facturas_autorizadas = [];

        foreach ($autorizados as $autorizado) {

            if (
                self::tiene_cae($autorizado)
                && !self::es_nota_de_credito($autorizado)
                && !is_null($autorizado->sale_id)
                && (int) $autorizado->sale_id === (int) $fallido->sale_id
                && (int) $autorizado->id !== (int) $fallido->id
            ) {
                $facturas_autorizadas[] = $autorizado;
            }
        }

        // Sin una factura autorizada de la venta no hay nada que supere al intento.
        if (count($facturas_autorizadas) == 0) {
            return null;
        }

        if (self::sin_numero($fallido)) {
            return self::MOTIVO_SIN_NUMERO;
        }

        foreach ($facturas_autorizadas as $autorizada) {
            if (self::mismo_comprobante($fallido, $autorizada)) {
                return self::MOTIVO_MISMO_NUMERO;
            }
        }

        if (strtoupper(trim((string) $fallido->resultado)) === 'R') {
            return self::MOTIVO_RECHAZADO;
        }

        // Otro número y sin rechazo: puede estar autorizado en ARCA. Se queda (ver el docblock).
        return null;
    }

    /**
     * Descarta (borrado suave) los intentos fallidos de la venta de `$autorizado` que una factura
     * autorizada ya superó. Se llama justo después de escribir el CAE.
     *
     * El criterio se evalúa contra TODAS las facturas autorizadas de la venta, no solo contra la
     * que acaba de autorizarse, para dar exactamente el mismo resultado que el comando de saneo
     * (`afip:descartar-intentos-fallidos`).
     *
     * 🔴 No tira nunca: la factura ya está autorizada en ARCA cuando esto corre, y una falla de la
     * limpieza no puede convertir esa emisión en un error para el usuario. Se reporta y se sigue.
     *
     * @param  \App\Models\AfipTicket|null $autorizado Factura que acaba de quedar con CAE.
     * @return int Cantidad de intentos descartados.
     */
    public static function descartar_superados($autorizado)
    {
        try {

            if (
                is_null($autorizado)
                || !self::tiene_cae($autorizado)
                || self::es_nota_de_credito($autorizado)
                || is_null($autorizado->sale_id)
            ) {
                return 0;
            }

            $fallidos = self::intentos_sin_cae()
                                ->where('sale_id', $autorizado->sale_id)
                                ->orderBy('id')
                                ->get();

            if ($fallidos->count() == 0) {
                return 0;
            }

            $por_venta = self::facturas_autorizadas_por_venta([$autorizado->sale_id]);

            $autorizadas = isset($por_venta[(int) $autorizado->sale_id]) ? $por_venta[(int) $autorizado->sale_id] : [];

            $descartados = 0;

            foreach ($fallidos as $fallido) {

                $motivo = self::motivo_de_descarte($fallido, $autorizadas);

                if (is_null($motivo)) {
                    continue;
                }

                if (self::descartar($fallido, $motivo, 'la factura '.$autorizado->id.' quedó autorizada')) {
                    $descartados++;
                }
            }

            return $descartados;

        } catch (\Throwable $e) {

            // Sin report() esta falla no llegaría al reporter de errores.
            report($e);

            Log::warning(
                'IntentosDeFacturaFallidosHelper: no se pudieron descartar los intentos superados de la venta '
                .(is_null($autorizado) ? '-' : $autorizado->sale_id).' (factura '.(is_null($autorizado) ? '-' : $autorizado->id).'): '
                .$e->getMessage()
            );

            return 0;
        }
    }

    /**
     * Borra (suave) un intento ya calificado por `motivo_de_descarte()` y deja la línea en el log.
     *
     * Los `afip_errors` y `afip_observations` del intento NO se tocan: quedan como historia de por
     * qué falló.
     *
     * @param  \App\Models\AfipTicket $fallido
     * @param  string $motivo Una de las constantes `MOTIVO_*`.
     * @param  string $origen Quién lo descarta, para el log.
     * @return bool true si quedó borrado; false si falló (ya reportado).
     */
    public static function descartar($fallido, $motivo, $origen)
    {
        try {

            $fallido->delete();

            Log::info(
                'IntentosDeFacturaFallidos: venta '.$fallido->sale_id.', se descartó el ticket '.$fallido->id
                .' ('.self::comprobante_legible($fallido).') por '.$motivo.': '.$origen.'.'
            );

            return true;

        } catch (\Throwable $e) {

            report($e);

            Log::warning(
                'IntentosDeFacturaFallidos: no se pudo descartar el ticket '.$fallido->id.' de la venta '
                .$fallido->sale_id.': '.$e->getMessage()
            );

            return false;
        }
    }

    /**
     * Tickets de FACTURA sin CAE (nulo o vacío, el mismo criterio que
     * `AfipTicketController::problemas_al_facturar()`), con venta y que no son notas de crédito.
     * Son los únicos candidatos a descartarse. El scope de SoftDeletes deja afuera los ya borrados.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function intentos_sin_cae()
    {
        return AfipTicket::where(function ($q) {
                                $q->whereNull('cae')
                                  ->orWhere('cae', '');
                            })
                            ->whereNotNull('sale_id')
                            ->whereNull('nota_credito_id')
                            ->whereNull('sale_nota_credito_id');
    }

    /**
     * Facturas autorizadas (CAE no vacío, no notas de crédito, vivas) de esas ventas, agrupadas por
     * venta, en UNA consulta.
     *
     * @param  array $sale_ids
     * @return array [sale_id => AfipTicket[]]
     */
    public static function facturas_autorizadas_por_venta(array $sale_ids)
    {
        $por_venta = [];

        if (count($sale_ids) == 0) {
            return $por_venta;
        }

        $autorizadas = AfipTicket::whereIn('sale_id', $sale_ids)
                                ->whereNotNull('cae')
                                ->where('cae', '!=', '')
                                ->whereNull('nota_credito_id')
                                ->whereNull('sale_nota_credito_id')
                                ->get();

        foreach ($autorizadas as $autorizada) {
            $por_venta[(int) $autorizada->sale_id][] = $autorizada;
        }

        return $por_venta;
    }

    /**
     * Texto corto del motivo, para el listado del comando.
     *
     * @param  string $motivo
     * @return string
     */
    public static function descripcion_del_motivo($motivo)
    {
        $descripciones = [
            self::MOTIVO_SIN_NUMERO   => 'sin número: nunca llegó a ARCA',
            self::MOTIVO_MISMO_NUMERO => 'mismo comprobante que una factura autorizada de la venta: ARCA no da dos veces el mismo número',
            self::MOTIVO_RECHAZADO    => 'rechazado por ARCA (resultado R)',
        ];

        return isset($descripciones[$motivo]) ? $descripciones[$motivo] : (string) $motivo;
    }

    /**
     * "punto de venta-tipo-número" del ticket, o "sin número".
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return string
     */
    public static function comprobante_legible($ticket)
    {
        if (self::sin_numero($ticket)) {
            return 'sin número';
        }

        return 'pv '.self::normalizar($ticket->punto_venta).', tipo '.self::normalizar($ticket->cbte_tipo)
            .', N° '.self::normalizar($ticket->cbte_numero);
    }

    /**
     * ¿Tiene CAE? Mismo criterio que la consulta de Alertas: NULL y '' son "no tiene".
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return bool
     */
    public static function tiene_cae($ticket)
    {
        return !is_null($ticket->cae) && (string) $ticket->cae !== '';
    }

    /**
     * ¿Es una nota de crédito? Las facturas cuelgan de la venta por `sale_id`; las notas de crédito
     * por `nota_credito_id` (el movimiento de la cuenta corriente) y `sale_nota_credito_id`.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return bool
     */
    public static function es_nota_de_credito($ticket)
    {
        return !is_null($ticket->nota_credito_id) || !is_null($ticket->sale_nota_credito_id);
    }

    /**
     * ¿El ticket no tiene número de comprobante?
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return bool
     */
    public static function sin_numero($ticket)
    {
        return trim((string) $ticket->cbte_numero) === '';
    }

    /**
     * ¿Los dos tickets son el mismo comprobante para ARCA? Mismo CUIT del emisor, punto de venta,
     * tipo y número. Las columnas son texto: se comparan normalizadas ("0005" y "5" son el mismo
     * punto de venta; el CUIT, solo por sus dígitos).
     *
     * @param  \App\Models\AfipTicket $a
     * @param  \App\Models\AfipTicket $b
     * @return bool
     */
    protected static function mismo_comprobante($a, $b)
    {
        if (self::sin_numero($a) || self::sin_numero($b)) {
            return false;
        }

        return preg_replace('/\D/', '', (string) $a->cuit_negocio) === preg_replace('/\D/', '', (string) $b->cuit_negocio)
            && self::normalizar($a->punto_venta) === self::normalizar($b->punto_venta)
            && self::normalizar($a->cbte_tipo) === self::normalizar($b->cbte_tipo)
            && self::normalizar($a->cbte_numero) === self::normalizar($b->cbte_numero);
    }

    /**
     * Normaliza un dato numérico guardado como texto: sin espacios y sin ceros a la izquierda.
     * Lo que no son solo dígitos queda tal cual (sin espacios).
     *
     * @param  mixed $valor
     * @return string
     */
    protected static function normalizar($valor)
    {
        $texto = trim((string) $valor);

        if ($texto !== '' && ctype_digit($texto)) {

            $sin_ceros = ltrim($texto, '0');

            return $sin_ceros === '' ? '0' : $sin_ceros;
        }

        return $texto;
    }
}
