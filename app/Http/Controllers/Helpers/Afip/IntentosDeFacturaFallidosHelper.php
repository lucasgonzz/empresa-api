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
 * 🔴 **Y las tres cuentan SOLO contra una factura autorizada por el MISMO IMPORTE PEDIDO**
 * (`facturar_importe_personalizado`): las dos por la venta entera (nulo o 0), o las dos por el
 * mismo importe (con la tolerancia de 0,01 que ya usa `consultar_comprobante()`). Con la
 * facturación en partes, una factura autorizada por OTRA porción no supera al intento: venta de
 * $100.000, F1 por $60.000 sale `R`, se factura F2 por $40.000. F2 no dice nada de los $60.000, y
 * borrar F1 haría desaparecer de Alertas el único recordatorio de que esa porción sigue sin
 * facturar (lo que había pedido Lucas era "otro comprobante con CAE por el total").
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
 *  - Nada, si la venta no tiene ninguna factura autorizada por el mismo importe pedido: sin esa
 *    factura no hay nada que "supere" al intento, y la venta tiene que seguir en Alertas.
 *
 * No hay restricción por orden de creación: si Consultar le da el CAE a un fallido viejo, los
 * reintentos posteriores que cumplan el criterio también se descartan.
 *
 * ─── Quién lo usa ─────────────────────────────────────────────────────────────────────────────
 *
 * Los cuatro caminos por los que una factura queda autorizada llaman a
 * `restaurar_si_quedo_borrado()` y después a `descartar_superados()` apenas escribieron el CAE:
 * `AfipWsfeHelper::solicitar_cae()` (emisión normal), `AfipWsfeHelper::consultar_comprobante()`
 * (consulta manual, o la automática después de un error de red al emitir),
 * `AfipFexHelper::update_afip_ticket()` (Factura E) y `AfipFexHelper::consultar_comprobante()`
 * (consulta de una Factura E). Lo que quedó de antes lo limpia el comando
 * `afip:descartar-intentos-fallidos`, que usa el MISMO `motivo_de_descarte()` y el MISMO
 * `descartar()`.
 *
 * ─── La invariante: un comprobante con CAE nunca queda borrado ───────────────────────────────
 *
 * Dos emisiones de la misma venta pueden correr en paralelo. Se sostiene por los dos lados, porque
 * cada uno tapa una mitad de la carrera:
 *
 *  1. `descartar()` borra con un `UPDATE` condicional que vuelve a exigir el motivo (sin CAE, y
 *     además sin número / mismo comprobante / `R`). Si el otro proceso ya escribió algo, no borra.
 *  2. `restaurar_si_quedo_borrado()` restaura un ticket que recibió su CAE estando borrado (lo
 *     borraron sin número y después salió autorizado), y `restaurar_si_va_a_arca()` lo restaura
 *     apenas tiene número, antes de mandarlo, para que un error de red posterior no deje escondido
 *     un comprobante que ARCA pudo haber autorizado.
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
     *         CAE, las notas de crédito, las de otra venta y las de OTRO importe pedido se ignoran
     *         solas).
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

        /**
         * @var array $facturas_autorizadas Las que de verdad superan al fallido: facturas con CAE de
         * SU venta y por el MISMO importe pedido (ver el docblock de la clase).
         */
        $facturas_autorizadas = [];

        foreach ($autorizados as $autorizado) {

            if (
                self::tiene_cae($autorizado)
                && !self::es_nota_de_credito($autorizado)
                && !is_null($autorizado->sale_id)
                && (int) $autorizado->sale_id === (int) $fallido->sale_id
                && (int) $autorizado->id !== (int) $fallido->id
                && self::mismo_importe_pedido($autorizado, $fallido)
            ) {
                $facturas_autorizadas[] = $autorizado;
            }
        }

        // Sin una factura autorizada de la venta por el mismo importe no hay nada que supere al intento.
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
     * El descarte se hizo: el `UPDATE` condicional borró la fila.
     */
    const DESCARTE_HECHO = 'hecho';

    /**
     * No se tocó nada porque el ticket cambió entre la lectura y el borrado (otra emisión en curso):
     * el `UPDATE` condicional afectó 0 filas. Es el caso esperado de la carrera, no un error.
     */
    const DESCARTE_CAMBIO_ENTRE_MEDIO = 'cambio_entre_medio';

    /**
     * Falló con una excepción (ya reportada). Es el único caso que es un error.
     */
    const DESCARTE_FALLO = 'fallo';

    /**
     * Como `descartar_con_resultado()`, pero solo dice si quedó borrado. Es lo que necesita la
     * limpieza en vivo, que no distingue por qué no se borró.
     *
     * @param  \App\Models\AfipTicket $fallido Ticket tal como se leyó.
     * @param  string $motivo Una de las constantes `MOTIVO_*`.
     * @param  string $origen Quién lo descarta, para el log.
     * @return bool true si quedó borrado.
     */
    public static function descartar($fallido, $motivo, $origen)
    {
        return self::descartar_con_resultado($fallido, $motivo, $origen) === self::DESCARTE_HECHO;
    }

    /**
     * Borra (suave) un intento ya calificado por `motivo_de_descarte()` y deja la línea en el log.
     *
     * 🔴 NO borra la instancia que se leyó: borra con UNA consulta que vuelve a exigir, en el mismo
     * `UPDATE`, que el ticket siga cumpliendo el motivo. Entre la lectura y el borrado otro proceso
     * puede estar emitiendo ESE MISMO ticket (dos emisiones de la misma venta en paralelo): si ya
     * escribió el CAE, si ya pidió su número (regla a), si ya no dice `R` (regla c) o si su
     * comprobante cambió (regla b), el `UPDATE` no encuentra la fila y no se toca nada. Con
     * SoftDeletes, `delete()` sobre el builder es un `update deleted_at` con todos esos `where`
     * adentro, más el `deleted_at IS NULL` del scope.
     *
     * Es una de las dos mitades de la invariante **"un comprobante con CAE nunca queda borrado"**;
     * la otra es `restaurar_si_quedo_borrado()`, para la carrera que este `UPDATE` no puede ver (el
     * ticket se borra legítimamente sin número y DESPUÉS sale autorizado).
     *
     * El importe pedido (`facturar_importe_personalizado`) NO se repite en el `UPDATE`: solo lo
     * escribe `MakeAfipTicket::make_afip_ticket()` al CREAR el ticket (verificado el 9/10/2026; los
     * otros dos lugares que lo tocan, `SaleHelper::set_total_a_facturar()` y
     * `MakeAfipTicket::get_tope_en_pesos()`, arman un ticket en memoria que nunca se guarda), así
     * que no puede cambiar entre la lectura y el borrado.
     *
     * Los `afip_errors` y `afip_observations` del intento NO se tocan: quedan como historia de por
     * qué falló.
     *
     * @param  \App\Models\AfipTicket $fallido Ticket tal como se leyó.
     * @param  string $motivo Una de las constantes `MOTIVO_*`.
     * @param  string $origen Quién lo descarta, para el log.
     * @return string `DESCARTE_HECHO`, `DESCARTE_CAMBIO_ENTRE_MEDIO` o `DESCARTE_FALLO`.
     */
    public static function descartar_con_resultado($fallido, $motivo, $origen)
    {
        try {

            $borrado = AfipTicket::where('id', $fallido->id)
                                ->where(function ($q) {
                                    $q->whereNull('cae')
                                      ->orWhere('cae', '');
                                })
                                ->whereNull('nota_credito_id')
                                ->whereNull('sale_nota_credito_id');

            if ($motivo === self::MOTIVO_SIN_NUMERO) {

                $borrado->where(function ($q) {
                    $q->whereNull('cbte_numero')
                      ->orWhere('cbte_numero', '');
                });

            } else if ($motivo === self::MOTIVO_MISMO_NUMERO) {

                // El comprobante tiene que seguir siendo el que se comparó contra la factura autorizada.
                foreach (['cuit_negocio', 'punto_venta', 'cbte_tipo', 'cbte_numero'] as $columna) {
                    if (is_null($fallido->{$columna})) {
                        $borrado->whereNull($columna);
                    } else {
                        $borrado->where($columna, $fallido->{$columna});
                    }
                }

            } else if ($motivo === self::MOTIVO_RECHAZADO) {

                $borrado->where('resultado', 'R');

            } else {

                // Un motivo desconocido no borra nada.
                return self::DESCARTE_CAMBIO_ENTRE_MEDIO;
            }

            $filas = $borrado->delete();

            if ($filas < 1) {

                Log::info(
                    'IntentosDeFacturaFallidos: venta '.$fallido->sale_id.', el ticket '.$fallido->id
                    .' NO se descartó: cambió entre la lectura y el borrado (otro proceso lo está emitiendo, '
                    .'o ya no cumple '.$motivo.'). '.$origen.'.'
                );

                return self::DESCARTE_CAMBIO_ENTRE_MEDIO;
            }

            Log::info(
                'IntentosDeFacturaFallidos: venta '.$fallido->sale_id.', se descartó el ticket '.$fallido->id
                .' ('.self::comprobante_legible($fallido).') por '.$motivo.': '.$origen.'.'
            );

            return self::DESCARTE_HECHO;

        } catch (\Throwable $e) {

            report($e);

            Log::warning(
                'IntentosDeFacturaFallidos: no se pudo descartar el ticket '.$fallido->id.' de la venta '
                .$fallido->sale_id.': '.$e->getMessage()
            );

            return self::DESCARTE_FALLO;
        }
    }

    /**
     * 🔴 Restaura un comprobante CON CAE que haya quedado borrado. Se llama en cada punto donde un
     * ticket recibe su CAE, ANTES de `descartar_superados()`.
     *
     * Es la otra mitad de la invariante **"un comprobante con CAE nunca queda borrado"**. El borrado
     * condicional de `descartar()` no puede ver esta carrera: la emisión A queda autorizada y borra
     * un intento B que en ESE momento no tenía número (regla a, legítimo), pero B estaba en vuelo —se
     * acababa de crear— y después pide su número y sale autorizado. `update()` escribe el CAE igual
     * sobre la fila borrada (Eloquent actualiza por id, sin el scope de SoftDeletes), y sin esto B
     * quedaría con CAE y `deleted_at`: invisible para el Libro IVA y los TXT, que es exactamente el
     * bug de masquito (11/9/2026).
     *
     * La restauración también es un solo `UPDATE` que vuelve a exigir CAE no vacío y `deleted_at`
     * no nulo. No tira nunca: corre con el comprobante ya autorizado en ARCA.
     *
     * @param  \App\Models\AfipTicket|null $afip_ticket
     * @return bool true si lo restauró.
     */
    public static function restaurar_si_quedo_borrado($afip_ticket)
    {
        try {

            if (is_null($afip_ticket) || is_null($afip_ticket->id)) {
                return false;
            }

            $guardado = AfipTicket::withTrashed()->find($afip_ticket->id);

            if (is_null($guardado) || !$guardado->trashed() || !self::tiene_cae($guardado)) {
                return false;
            }

            $filas = AfipTicket::withTrashed()
                                ->where('id', $guardado->id)
                                ->whereNotNull('deleted_at')
                                ->whereNotNull('cae')
                                ->where('cae', '!=', '')
                                ->restore();

            if ($filas < 1) {
                return false;
            }

            $venta = !is_null($guardado->sale_id) ? $guardado->sale_id : $guardado->sale_nota_credito_id;

            Log::warning(
                'IntentosDeFacturaFallidos: el ticket '.$guardado->id.' de la venta '.$venta.' tenía CAE '
                .$guardado->cae.' y estaba borrado; se restauró (un comprobante autorizado no puede quedar '
                .'fuera del Libro IVA).'
            );

            return true;

        } catch (\Throwable $e) {

            report($e);

            Log::warning(
                'IntentosDeFacturaFallidos: no se pudo revisar/restaurar el ticket '
                .(is_null($afip_ticket) ? '-' : $afip_ticket->id).': '.$e->getMessage()
            );

            return false;
        }
    }

    /**
     * 🔴 Restaura un intento que quedó borrado justo cuando está por ir a ARCA. Se llama apenas el
     * ticket tiene número, ANTES de mandarlo (`FECAESolicitar` / `FEXAuthorize`).
     *
     * Cierra el caso de la carrera que `restaurar_si_quedo_borrado()` no cubre: el intento B se borró
     * sin número (regla a) mientras estaba en vuelo, y después de mandarlo ARCA no contesta (error
     * de red). B puede estar autorizado en ARCA —la respuesta se perdió— y es justo el fallido que
     * tiene que seguir en Alertas para Consultarlo. Un ticket que va a ARCA dejó de ser "un intento
     * que nunca llegó": no puede estar borrado, salga como salga.
     *
     * Un ticket que el usuario borró con el tacho nunca vuelve a pasar por acá (reintentar crea uno
     * nuevo), así que lo único que esto restaura es un intento en vuelo. No tira nunca.
     *
     * @param  \App\Models\AfipTicket|null $afip_ticket
     * @return bool true si lo restauró.
     */
    public static function restaurar_si_va_a_arca($afip_ticket)
    {
        try {

            if (is_null($afip_ticket) || is_null($afip_ticket->id)) {
                return false;
            }

            $filas = AfipTicket::withTrashed()
                                ->where('id', $afip_ticket->id)
                                ->whereNotNull('deleted_at')
                                ->restore();

            if ($filas < 1) {
                return false;
            }

            Log::warning(
                'IntentosDeFacturaFallidos: el ticket '.$afip_ticket->id.' de la venta '.$afip_ticket->sale_id
                .' estaba borrado y se está mandando a ARCA; se restauró (otra emisión de la misma venta lo '
                .'había descartado sin número mientras estaba en vuelo).'
            );

            return true;

        } catch (\Throwable $e) {

            report($e);

            Log::warning(
                'IntentosDeFacturaFallidos: no se pudo revisar/restaurar el ticket en vuelo '
                .(is_null($afip_ticket) ? '-' : $afip_ticket->id).': '.$e->getMessage()
            );

            return false;
        }
    }

    /**
     * Importe que se pidió facturar con el ticket (`facturar_importe_personalizado`), o null si se
     * pidió la venta entera (nulo, vacío o 0, igual que lo normaliza `MakeAfipTicket`).
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return float|null
     */
    public static function importe_pedido($ticket)
    {
        $valor = $ticket->facturar_importe_personalizado;

        if (is_null($valor) || !is_numeric($valor) || (float) $valor <= 0) {
            return null;
        }

        return (float) $valor;
    }

    /**
     * ¿Los dos tickets pidieron facturar lo mismo? Los dos la venta entera, o los dos el mismo
     * importe, con la tolerancia de 0,01 que ya usa `AfipWsfeHelper::consultar_comprobante()` para
     * comparar importes (`abs(...) < 0.01`).
     *
     * @param  \App\Models\AfipTicket $a
     * @param  \App\Models\AfipTicket $b
     * @return bool
     */
    public static function mismo_importe_pedido($a, $b)
    {
        $importe_a = self::importe_pedido($a);
        $importe_b = self::importe_pedido($b);

        if (is_null($importe_a) || is_null($importe_b)) {
            return is_null($importe_a) && is_null($importe_b);
        }

        return abs($importe_a - $importe_b) < 0.01;
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
