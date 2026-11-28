<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AJUSTE DE STOCK POR LOTE: muchos artículos, cada uno con SU cantidad final, en UNA sola tarjeta
 * (misión asistente-diario-20261116, rama B, ítem 30/10 #2).
 *
 * 🔴 EL CASO REAL (Ferre Total, 30/10/2026, conv 30, mensajes 185-188). Pablo mandó la foto de un
 * conteo («necesito hacer ajuste de stock»): una lista de artículos con la cantidad que contó de cada
 * uno. El agente ubicó casi todo y pidió aclarar 4 renglones; con la segunda foto quiso hacerlo
 * renglón por renglón, se le acabaron las vueltas del turno, probó una acción de pantalla que la
 * pantalla rechazó y el turno terminó en error. El ajuste nunca se hizo y Pablo no supo qué quedó.
 *
 * Esta herramienta cierra eso:
 *   - UNA llamada con todos los renglones (artículo → cantidad FINAL contada), UNA tarjeta.
 *   - Lo que no se pudo ubicar NO frena al resto: queda afuera de la tarjeta, la respuesta lo trae en
 *     `quedaron_afuera` (con los candidatos si el nombre encaja con varios) y la tarjeta lo avisa,
 *     para que el dueño confirme lo claro y aclare lo dudoso en otra tarjeta.
 *   - Al confirmar, cada renglón se ejecuta por EL MISMO CAMINO que proponer_stock_en_deposito
 *     (PropuestaStockIaHelper::ejecutar_stock_en_deposito(): `PUT api/article-update-addresses` con
 *     depósitos, o el movimiento "Ingreso manual" sobre el total sin depósitos / con una sola
 *     sucursal), cada uno en su propio punto de guardado: si un renglón falla se revierte SOLO ése, y
 *     el resultado dice `hechos` y `no_hechos` con el motivo. Nunca "quedó todo" si quedó algo.
 *
 * Por qué hereda de PropuestaStockIaHelper: para usar SUS resoluciones (artículo, depósito, permisos,
 * stock total) sin copiarlas ni cambiarles la visibilidad. Una segunda copia de esas reglas sería la
 * clase "el mismo invariante con dos criterios" de APRENDER_NO_PARCHEAR.md.
 *
 * El modo por defecto es "fijar": un conteo dice cuánto HAY. Si la persona dice que se SUMA a lo que hay,
 * el lote lleva `modo: sumar` (misión asistente-diario-20261128, P4): cada renglón suma su cantidad al stock
 * del momento de confirmar. Restar a un solo artículo sigue estando en proponer_stock_en_deposito.
 *
 * Una corrección NO pierde renglones (P4, caso real conv. 31): la tarjeta nueva hereda los renglones de la que
 * reemplaza (misma clave o `reemplaza_a`), lo nuevo gana sobre lo heredado del mismo artículo y `quitar: true`
 * saca uno a propósito. Lo que no se pudo ubicar se guarda en la tarjeta y el resultado del «sí» lo nombra.
 *
 * 🔴 NUNCA se auto-confirma (no está en AUTO_CONFIRMABLES ni en AUTO_CONFIRMABLES_DIRECTO): toca el
 * stock de muchos artículos de un saque, igual que la actualización masiva.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types, sin `?->`.
 */
class PropuestaAjusteDeStockPorLoteIaHelper extends PropuestaStockIaHelper
{
    /**
     * Cuántos renglones entran en una tarjeta. Un conteo de una foto rara vez pasa de 40; con más, la
     * tarjeta deja de poder leerse antes de confirmar y conviene partirla.
     */
    const TOPE_RENGLONES = 60;

    /** Título de la tarjeta. */
    const TITULO = 'Ajuste de stock por lote';

    /**
     * Herramienta proponer_ajuste_de_stock_por_lote.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  renglones* [{articulo|articulo_id, cantidad}], deposito, reemplaza_a
     * @return array  La propuesta (con quedaron_afuera y sin_cambios) o la respuesta de negocio.
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input): array
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)
            && !PermisosIaHelper::puede($contexto->persona, self::PERMISO_SOLO_SU_SUCURSAL)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('el stock de los depósitos'));
        }

        $renglones = EntradaDeCargaIa::valor($input, 'renglones');

        if (!is_array($renglones) || !count($renglones)) {

            return RespuestaDeCargaIa::faltan(['qué artículos y en cuánto queda el stock de cada uno']);
        }

        if (count($renglones) > self::TOPE_RENGLONES) {

            return self::rechazo(
                'mandaste ' . count($renglones) . ' renglones y una tarjeta lleva hasta ' . self::TOPE_RENGLONES
                . '. Partí la lista en tandas de hasta ' . self::TOPE_RENGLONES . ' y llamá una vez por tanda.'
            );
        }

        $lugar = self::lugar_del_ajuste($contexto, EntradaDeCargaIa::texto($input, 'deposito'));

        if (RespuestaDeCargaIa::es_negativa($lugar)) {

            return $lugar;
        }

        list($deposito, $sin_depositos) = $lugar;

        // La identidad del lote: un lote corregido en el mismo depósito reemplaza la tarjeta anterior.
        $clave = self::clave(is_null($deposito) ? 0 : (int) $deposito->id);

        $reemplaza_a = EntradaDeCargaIa::valor($input, 'reemplaza_a');

        // Las tarjetas de lote anteriores que esta llamada va a reemplazar (su contenido se hereda).
        $anteriores = self::tarjetas_anteriores($contexto, $clave, $reemplaza_a);

        // El modo del lote: el que dijo el modelo o, si no dijo ninguno, «sumar» si la tarjeta anterior ya sumaba.
        $modo = self::modo_del_lote($input, $anteriores['mismas']);

        // El resultado de cada artículo de la tarjeta, por article_id y en orden: primero lo heredado, después lo nuevo.
        $filas = [];
        // Los article_id que vinieron de la tarjeta anterior (para contar cuáles se conservaron sin reenviar).
        $heredados_ids = [];
        // Lo no ubicado de las tarjetas anteriores, para arrastrarlo (sale de $filas si lo vuelven a mandar).
        $afuera_heredado = [];
        $quedaron_afuera = [];
        $sin_cambios = [];
        $vistos = [];

        // 1. Lo heredado: los renglones de la(s) tarjeta(s) que se reemplazan, vueltos a resolver contra el stock de hoy.
        foreach ($anteriores['mismas'] as $anterior) {

            $datos_anteriores = is_array($anterior->datos) ? $anterior->datos : [];

            foreach ((isset($datos_anteriores['esperados']) && is_array($datos_anteriores['esperados'])) ? $datos_anteriores['esperados'] : [] as $esperado) {

                $heredada = self::fila_heredada($contexto, is_array($esperado) ? $esperado : [], $deposito, $sin_depositos);

                if (isset($heredada['afuera'])) {

                    $quedaron_afuera[] = array_merge(['renglon' => 0, 'articulo' => self::como_lo_dijo(['articulo_id' => isset($esperado['article_id']) ? $esperado['article_id'] : 0])], $heredada['afuera']);

                    continue;
                }

                if (!$heredada['sin_cambio']) {

                    $filas[$heredada['article_id']] = $heredada;
                    $heredados_ids[$heredada['article_id']] = true;
                }
            }

            foreach ((isset($datos_anteriores['quedaron_afuera']) && is_array($datos_anteriores['quedaron_afuera'])) ? $datos_anteriores['quedaron_afuera'] : [] as $afuera) {

                $afuera_heredado[] = $afuera;
            }
        }

        // 2. Lo que mandó el modelo en esta llamada: gana sobre lo heredado del mismo artículo.
        $dichos = [];

        foreach (array_values($renglones) as $indice => $renglon) {

            $renglon = is_array($renglon) ? $renglon : [];

            $numero = $indice + 1;

            $dicho = self::como_lo_dijo($renglon);

            $dichos[mb_strtolower(trim($dicho))] = true;

            // `quitar: true` saca el artículo de lo heredado (si no estaba, no hace nada).
            if (!empty($renglon['quitar'])) {

                $articulo_a_quitar = self::resolver_articulo($contexto, $renglon, 'qué artículo es el renglón ' . $numero);

                if (!RespuestaDeCargaIa::es_negativa($articulo_a_quitar)) {

                    unset($filas[(int) $articulo_a_quitar->id], $heredados_ids[(int) $articulo_a_quitar->id]);
                    $vistos[(int) $articulo_a_quitar->id] = $numero;
                }

                continue;
            }

            $fila = self::resolver_renglon($contexto, $renglon, $numero, $deposito, $sin_depositos, $modo);

            if (isset($fila['afuera'])) {

                $quedaron_afuera[] = array_merge(['renglon' => $numero, 'articulo' => $dicho], $fila['afuera']);

                continue;
            }

            if (isset($vistos[$fila['article_id']])) {

                $quedaron_afuera[] = [
                    'renglon'  => $numero,
                    'articulo' => $dicho,
                    'motivo'   => 'es el mismo artículo que el renglón ' . $vistos[$fila['article_id']] . ': vale el primero',
                ];

                continue;
            }

            $vistos[$fila['article_id']] = $numero;

            // Lo que mandó la persona pisa lo heredado: ya no cuenta como «conservado sin reenviar».
            unset($heredados_ids[$fila['article_id']]);

            if ($fila['sin_cambio']) {

                // Ya está en ese número: no hace falta moverlo y tampoco queda el valor viejo de la tarjeta anterior.
                unset($filas[$fila['article_id']]);

                $sin_cambios[] = $fila['nombre'] . ' (ya está en ' . self::numero($fila['final']) . ')';

                continue;
            }

            $filas[$fila['article_id']] = $fila;
        }

        // Lo no ubicado de las tarjetas anteriores se arrastra, salvo lo que esta llamada volvió a mandar.
        foreach ($afuera_heredado as $afuera) {

            $texto_afuera = isset($afuera['articulo']) ? mb_strtolower(trim((string) $afuera['articulo'])) : '';

            if ($texto_afuera !== '' && isset($dichos[$texto_afuera])) {

                continue;
            }

            $quedaron_afuera[] = $afuera;
        }

        // Los artículos de una tarjeta de OTRO depósito no se mezclan: se avisa que quedaron en la anterior.
        $fuera_de_la_tarjeta = self::fuera_de_la_tarjeta($contexto, $anteriores['otro_deposito']);

        if (!count($filas)) {

            return self::sin_nada_para_ajustar($quedaron_afuera, $sin_cambios);
        }

        $esperados = [];
        $renglones_de_la_tarjeta = [];
        $heredados = [];

        foreach ($filas as $fila) {

            $esperados[] = $fila['esperado'];

            $renglones_de_la_tarjeta[] = [
                'etiqueta' => $fila['nombre'],
                'valor'    => $fila['antes_texto'] . ' → ' . self::numero($fila['final'])
                    . ($fila['esperado']['modo'] === self::MODO_SUMAR ? ' (suma ' . self::numero($fila['esperado']['cantidad']) . ')' : ''),
            ];

            if (isset($heredados_ids[$fila['article_id']])) {

                $heredados[] = $fila['nombre'];
            }
        }

        $encabezado = [];

        if (!is_null($deposito)) {

            $encabezado[] = ['etiqueta' => 'Depósito', 'valor' => self::nombre_de_deposito($deposito)];
        }

        $encabezado[] = ['etiqueta' => 'Artículos', 'valor' => self::texto_de_los_modos($esperados)];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_AJUSTE_STOCK_LOTE,
            $clave,
            /*
             * Cada `esperado` tiene LA MISMA FORMA que el de la tarjeta de un solo artículo
             * (proponer_stock_en_deposito / proponer_stock_total), con modo "fijar" o "sumar": al confirmar se
             * le pasa tal cual a ejecutar_stock_en_deposito(), que rearma el payload con los números
             * de ese momento (y en «sumar» recalcula actual + cantidad). Ver el 🔴 de ese método.
             *
             * `quedaron_afuera` y `fuera_de_la_tarjeta` se guardan para que el resultado del «sí» los nombre
             * aunque el modelo no lo cuente (misión asistente-diario-20261128, P4).
             */
            [
                'esperados'           => $esperados,
                'modo'                => self::modo_de_los_esperados($esperados),
                'quedaron_afuera'     => $quedaron_afuera,
                'fuera_de_la_tarjeta' => $fuera_de_la_tarjeta,
            ],
            [
                'titulo'    => self::TITULO,
                'renglones' => array_merge($encabezado, $renglones_de_la_tarjeta),
                'aviso'     => self::aviso_de_la_tarjeta($quedaron_afuera, $sin_cambios),
            ],
            $reemplaza_a
        );

        $resumen = 'Ajuste de stock de ' . count($esperados) . (count($esperados) === 1 ? ' artículo' : ' artículos')
            . (is_null($deposito) ? '' : ' en ' . self::nombre_de_deposito($deposito));

        $extra = [
            'quedaron_afuera' => $quedaron_afuera,
            'sin_cambios'     => $sin_cambios,
            'heredados'       => $heredados,
            'nota_del_lote'   => count($quedaron_afuera)
                ? 'La tarjeta lleva solo los renglones que pude ubicar. Contale a la persona cuáles quedaron afuera y por qué (quedaron_afuera), preguntale por esos y armá otra tarjeta con ellos; no digas que el ajuste está completo.'
                : 'La tarjeta lleva todos los renglones.',
        ];

        if (count($heredados)) {

            $extra['nota_del_lote'] .= ' La tarjeta conserva ' . count($heredados) . (count($heredados) === 1 ? ' renglón' : ' renglones')
                . ' de la anterior que no volviste a mandar: ' . implode(', ', $heredados) . '; para sacar uno, mandalo con quitar: true.';
        }

        if (count($fuera_de_la_tarjeta)) {

            $extra['fuera_de_la_tarjeta'] = $fuera_de_la_tarjeta;
        }

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen, $extra);
    }

    /**
     * Las tarjetas de lote anteriores que esta llamada va a reemplazar, separadas por depósito.
     *
     * Son las `propuesta` de la conversación con la MISMA clave (el reemplazo por clave de `crear()`) y la de id
     * `reemplaza_a` si es de lote y está `propuesta` o `reemplazada` (el modelo corrige en un mensaje posterior).
     * 'mismas' son las del mismo depósito (se hereda su contenido, en orden de id); 'otro_deposito' las de otro
     * (no se mezclan).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $clave  La clave de esta llamada.
     * @param  mixed  $reemplaza_a  Id de una tarjeta anterior, o null.
     * @return array{mismas: array, otro_deposito: array}
     */
    protected static function tarjetas_anteriores(ContextoDeCargaIa $contexto, $clave, $reemplaza_a): array
    {
        $reemplaza_a = (int) $reemplaza_a;

        $tarjetas = AiMessageAction::where('ai_conversation_id', $contexto->conversation->id)
            ->where('tipo', AiMessageAction::TIPO_AJUSTE_STOCK_LOTE)
            ->where(function ($consulta) use ($clave, $reemplaza_a) {

                $consulta->where(function ($por_clave) use ($clave) {
                    $por_clave->where('clave', $clave)->where('estado', AiMessageAction::ESTADO_PROPUESTA);
                });

                if ($reemplaza_a > 0) {

                    $consulta->orWhere(function ($por_id) use ($reemplaza_a) {
                        $por_id->where('id', $reemplaza_a)
                            ->whereIn('estado', [AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::ESTADO_REEMPLAZADA]);
                    });
                }
            })
            ->orderBy('id')
            ->get();

        $resultado = ['mismas' => [], 'otro_deposito' => []];

        foreach ($tarjetas as $tarjeta) {

            $resultado[(string) $tarjeta->clave === (string) $clave ? 'mismas' : 'otro_deposito'][] = $tarjeta;
        }

        return $resultado;
    }

    /**
     * El modo de esta llamada: el `modo` que mandó el modelo (fijar | sumar) o, si no mandó ninguno, «sumar» si la
     * tarjeta anterior del mismo lote ya sumaba (la persona ya dijo «se suma»: la siguiente no pasa a cantidades
     * finales), y «fijar» en cualquier otro caso.
     *
     * @param  array  $input
     * @param  array  $mismas  Las tarjetas anteriores del mismo depósito.
     * @return string  MODO_FIJAR | MODO_SUMAR
     */
    protected static function modo_del_lote(array $input, array $mismas): string
    {
        $dicho = mb_strtolower(EntradaDeCargaIa::texto($input, 'modo'));

        if (in_array($dicho, [self::MODO_FIJAR, self::MODO_SUMAR], true)) {

            return $dicho;
        }

        foreach ($mismas as $anterior) {

            $datos = is_array($anterior->datos) ? $anterior->datos : [];

            if (isset($datos['modo']) && $datos['modo'] === self::MODO_SUMAR) {

                return self::MODO_SUMAR;
            }
        }

        return self::MODO_FIJAR;
    }

    /**
     * Un renglón de la tarjeta anterior vuelto a resolver contra el stock de hoy, con su propio modo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $esperado  El `esperado` guardado en la tarjeta anterior.
     * @param  \App\Models\Address|null  $deposito
     * @param  bool  $sin_depositos
     * @return array  Igual que resolver_renglon().
     */
    protected static function fila_heredada(ContextoDeCargaIa $contexto, array $esperado, $deposito, $sin_depositos): array
    {
        $modo = isset($esperado['modo']) && $esperado['modo'] === self::MODO_SUMAR ? self::MODO_SUMAR : self::MODO_FIJAR;

        return self::resolver_renglon(
            $contexto,
            ['articulo_id' => isset($esperado['article_id']) ? (int) $esperado['article_id'] : 0, 'cantidad' => isset($esperado['cantidad']) ? $esperado['cantidad'] : null],
            0,
            $deposito,
            $sin_depositos,
            $modo
        );
    }

    /**
     * Los artículos de las tarjetas de otro depósito, que no se heredan: [{articulo, motivo}].
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $tarjetas  Tarjetas de lote de otro depósito.
     * @return array
     */
    protected static function fuera_de_la_tarjeta(ContextoDeCargaIa $contexto, array $tarjetas): array
    {
        $fuera = [];

        foreach ($tarjetas as $tarjeta) {

            $datos = is_array($tarjeta->datos) ? $tarjeta->datos : [];

            foreach ((isset($datos['esperados']) && is_array($datos['esperados'])) ? $datos['esperados'] : [] as $esperado) {

                $fuera[] = [
                    'articulo' => self::nombre_para_el_resultado($contexto, isset($esperado['article_id']) ? (int) $esperado['article_id'] : 0),
                    'motivo'   => 'estaba en la tarjeta anterior de otro depósito',
                ];
            }
        }

        return $fuera;
    }

    /**
     * El modo de toda la tarjeta: «fijar», «sumar» o «mixto» (si hay renglones de los dos).
     *
     * @param  array  $esperados
     * @return string
     */
    protected static function modo_de_los_esperados(array $esperados): string
    {
        $que_suman = 0;

        foreach ($esperados as $esperado) {

            if ($esperado['modo'] === self::MODO_SUMAR) {

                $que_suman++;
            }
        }

        if ($que_suman === 0) {

            return self::MODO_FIJAR;
        }

        return $que_suman === count($esperados) ? self::MODO_SUMAR : 'mixto';
    }

    /**
     * El valor de la fila «Artículos» del encabezado: «N (queda el stock contado)», «N (se suma al stock)» o
     * «N (A se fijan, B se suman)».
     *
     * @param  array  $esperados
     * @return string
     */
    protected static function texto_de_los_modos(array $esperados): string
    {
        $modo = self::modo_de_los_esperados($esperados);

        if ($modo === self::MODO_FIJAR) {

            return count($esperados) . ' (queda el stock contado)';
        }

        if ($modo === self::MODO_SUMAR) {

            return count($esperados) . ' (se suma al stock)';
        }

        $que_suman = 0;

        foreach ($esperados as $esperado) {

            if ($esperado['modo'] === self::MODO_SUMAR) {

                $que_suman++;
            }
        }

        return count($esperados) . ' (' . (count($esperados) - $que_suman) . ' se fijan, ' . $que_suman . ' se suman)';
    }

    /**
     * Dónde se ajusta el lote: [depósito|null, sin_depositos] o la respuesta de negocio.
     *
     * Mismo criterio que proponer_stock_en_deposito: sin depósitos o con UNA sola sucursal va al total
     * del artículo (D12 de la misión sucursal-siempre: con una sola sucursal no hay nada que elegir,
     * aunque el modelo haya mandado un nombre); con dos o más, el depósito que dijo la persona o, si no
     * dijo ninguno, el de su ficha / el marcado por defecto (deposito_por_defecto()). Si no hay forma
     * de saberlo se pregunta, una sola vez para todo el lote.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $texto  El depósito que mandó el modelo.
     * @return array
     */
    protected static function lugar_del_ajuste(ContextoDeCargaIa $contexto, $texto)
    {
        $depositos = self::depositos_del_dueno($contexto->owner_id);

        if (count($depositos) <= 1) {

            if (!self::puede_tocar_el_total($contexto)) {

                return self::rechazo('tu usuario no tiene permiso para editar el stock de los artículos.');
            }

            return [null, true];
        }

        if (trim((string) $texto) !== '') {

            $deposito = self::resolver_deposito($contexto, $depositos, $texto, 'en qué depósito se hizo el conteo');

            if (RespuestaDeCargaIa::es_negativa($deposito)) {

                return $deposito;
            }

            return [$deposito, false];
        }

        $deposito = self::deposito_por_defecto($contexto, $depositos);

        if (is_null($deposito)) {

            $opciones = [];

            foreach ($depositos as $uno) {

                $opciones[] = ['nombre' => self::nombre_de_deposito($uno)];
            }

            return RespuestaDeCargaIa::faltan(['en qué depósito se hizo el conteo (todavía no se ajustó nada)'], ['depositos' => $opciones]);
        }

        return [$deposito, false];
    }

    /**
     * Resuelve UN renglón del lote: el artículo, la cantidad y lo que hay hoy.
     *
     * Devuelve ['afuera' => ['motivo' => ..., 'opciones' => ...]] si el renglón no entra a la tarjeta,
     * o los datos del renglón: article_id, nombre, final, sin_cambio, antes_texto y el `esperado` con la
     * forma de la tarjeta de un solo artículo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $renglon
     * @param  int  $numero
     * @param  \App\Models\Address|null  $deposito
     * @param  bool  $sin_depositos
     * @param  string  $modo  MODO_FIJAR (la cantidad es la final) o MODO_SUMAR (la cantidad se suma al stock de hoy).
     * @return array
     */
    protected static function resolver_renglon(ContextoDeCargaIa $contexto, array $renglon, $numero, $deposito, $sin_depositos, $modo = self::MODO_FIJAR)
    {
        $articulo = self::resolver_articulo($contexto, $renglon, 'qué artículo es el renglón ' . $numero);

        if (RespuestaDeCargaIa::es_negativa($articulo)) {

            return ['afuera' => self::motivo_de_la_respuesta($articulo)];
        }

        $cantidad = EntradaDeCargaIa::valor($renglon, 'cantidad');

        $cantidad = EntradaDeCargaIa::vacio($cantidad) ? null : self::cantidad_de_stock($cantidad);

        if (is_null($cantidad)) {

            return ['afuera' => ['motivo' => 'no vino la cantidad contada (o no es un número)']];
        }

        if ($cantidad < 0) {

            return ['afuera' => ['motivo' => 'la cantidad contada no puede ser negativa']];
        }

        // Para sumar, la cantidad es lo que se agrega: con 0 no habría nada que hacer.
        if ($modo === self::MODO_SUMAR && $cantidad <= 0) {

            return ['afuera' => ['motivo' => 'para sumar, la cantidad tiene que ser mayor que 0']];
        }

        $cantidad_dicha = round((float) $cantidad, 2);

        // En «fijar» la cantidad ya es el stock final; en «sumar» se calcula abajo sobre el stock de hoy.
        $final = $cantidad_dicha;

        $nombre = self::nombre_de_articulo($articulo);

        if ($sin_depositos) {

            $motivo = self::motivo_si_no_lleva_stock_total($articulo->id);

            if (!is_null($motivo)) {

                return ['afuera' => ['motivo' => $motivo]];
            }

            $crudo = DB::table('articles')->where('id', (int) $articulo->id)->value('stock');

            $actual = is_null($crudo) ? 0.0 : (float) $crudo;

            if ($modo === self::MODO_SUMAR) {

                // Para mostrar: lo que quedaría hoy. Al confirmar se recalcula sobre el stock de ese momento.
                $final = round($actual + $cantidad_dicha, 2);
            }

            return [
                'article_id'  => (int) $articulo->id,
                'nombre'      => $nombre,
                'final'       => $final,
                // Un artículo que no llevaba stock (null) y queda en 0 SÍ cambia: empieza a llevarlo.
                'sin_cambio'  => $modo === self::MODO_FIJAR && !is_null($crudo) && abs($final - $actual) < 0.01,
                'antes_texto' => is_null($crudo) ? 'sin stock cargado' : self::numero($actual),
                'esperado'    => [
                    'article_id'    => (int) $articulo->id,
                    'address_id'    => 0,
                    'sin_depositos' => true,
                    'modo'          => $modo,
                    'cantidad'      => $cantidad_dicha,
                    'final'         => $final,
                ],
            ];
        }

        $actual = self::amount_de(self::pivots_del_articulo($articulo->id), $deposito->id);

        if ($modo === self::MODO_SUMAR) {

            // Para mostrar: lo que quedaría hoy. Al confirmar se recalcula sobre el stock de ese momento.
            $final = round($actual + $cantidad_dicha, 2);
        }

        return [
            'article_id'  => (int) $articulo->id,
            'nombre'      => $nombre,
            'final'       => $final,
            'sin_cambio'  => $modo === self::MODO_FIJAR && abs($final - $actual) < 0.01,
            'antes_texto' => self::numero($actual),
            'esperado'    => [
                'article_id' => (int) $articulo->id,
                'address_id' => (int) $deposito->id,
                'modo'       => $modo,
                'cantidad'   => $cantidad_dicha,
                'final'      => $final,
            ],
        ];
    }

    /**
     * El motivo (y los candidatos, si los hay) de una respuesta negativa de resolver_articulo().
     * `opciones` puede venir como objeto vacío (RespuestaDeCargaIa::objeto): se mira solo si es array.
     *
     * @param  array  $respuesta
     * @return array  ['motivo' => string, 'opciones' => array?]
     */
    protected static function motivo_de_la_respuesta(array $respuesta)
    {
        $opciones = isset($respuesta['opciones']) && is_array($respuesta['opciones']) ? $respuesta['opciones'] : [];

        if (!empty($opciones['articulos'])) {

            return [
                'motivo'   => 'el nombre encaja con varios artículos: preguntá cuál',
                'opciones' => $opciones['articulos'],
            ];
        }

        if (isset($respuesta['error']) && trim((string) $respuesta['error']) !== '') {

            return ['motivo' => trim((string) $respuesta['error'])];
        }

        if (!empty($respuesta['faltan'])) {

            return ['motivo' => 'falta ' . implode(', ', (array) $respuesta['faltan'])];
        }

        return ['motivo' => 'no lo pude ubicar entre tus artículos'];
    }

    /**
     * Cómo nombró la persona el renglón, para devolvérselo igual en quedaron_afuera.
     *
     * @param  array  $renglon
     * @return string
     */
    protected static function como_lo_dijo(array $renglon)
    {
        $texto = EntradaDeCargaIa::texto($renglon, 'articulo');

        if ($texto !== '') {

            return $texto;
        }

        $id = EntradaDeCargaIa::valor($renglon, 'articulo_id');

        return is_numeric($id) ? 'artículo id ' . (int) $id : '(sin nombre)';
    }

    /**
     * La respuesta cuando ningún renglón entra a la tarjeta: lo dice con todo lo que quedó afuera, y
     * empieza con "No se cargó nada" para que el modelo no lo lea como un ajuste hecho.
     *
     * @param  array  $quedaron_afuera
     * @param  array  $sin_cambios
     * @return array
     */
    protected static function sin_nada_para_ajustar(array $quedaron_afuera, array $sin_cambios)
    {
        if (!count($quedaron_afuera)) {

            return self::rechazo('todos los artículos de la lista ya tienen ese stock, así que no hay nada para ajustar.', ['sin_cambios' => $sin_cambios]);
        }

        return RespuestaDeCargaIa::error(
            self::NO_SE_CARGO . 'no pude ubicar ninguno de los renglones de la lista. Preguntale a la persona por los de quedaron_afuera y volvé a llamar.',
            ['quedaron_afuera' => $quedaron_afuera, 'sin_cambios' => $sin_cambios]
        );
    }

    /**
     * El aviso de la tarjeta: lo que la persona tiene que saber ANTES de confirmar (qué renglones no
     * van y cuáles ya estaban bien). null si no hay nada.
     *
     * @param  array  $quedaron_afuera
     * @param  array  $sin_cambios
     * @return string|null
     */
    protected static function aviso_de_la_tarjeta(array $quedaron_afuera, array $sin_cambios)
    {
        $partes = [];

        if (count($quedaron_afuera)) {

            $nombres = [];

            foreach ($quedaron_afuera as $fila) {

                $nombres[] = $fila['articulo'];
            }

            $partes[] = 'No van en esta tarjeta (' . count($quedaron_afuera) . '): ' . implode(', ', $nombres) . '.';
        }

        if (count($sin_cambios)) {

            $partes[] = 'Ya estaban bien: ' . implode(', ', $sin_cambios) . '.';
        }

        return count($partes) ? implode(' ', $partes) : null;
    }

    /**
     * Identidad del lote para el reemplazo: un lote corregido en el mismo depósito reemplaza la tarjeta.
     *
     * @param  int  $address_id  0 = sobre el total del artículo.
     * @return string
     */
    public static function clave($address_id): string
    {
        return 'ajuste_stock_lote:' . (int) $address_id;
    }

    /**
     * Ejecuta el lote: cada renglón por ejecutar_stock_en_deposito() —el mismo camino que la tarjeta
     * de un solo artículo—, en su propio punto de guardado.
     *
     * 🔴 POR QUÉ UN DB::transaction POR RENGLÓN. Esto corre adentro de la transacción del ejecutor
     * (EjecutorAccionesIaHelper). Si un renglón lanza DESPUÉS de haber escrito (la relectura del pivot
     * no cierra), sin su propio punto de guardado ese renglón quedaría escrito a medias y además
     * contado como "no hecho". Con la transacción anidada (un SAVEPOINT en MySQL), lo que falla se
     * revierte solo, y lo que salió queda.
     *
     * Si no sale NINGUNO, es un 422 con los motivos: la tarjeta no queda como confirmada.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta, hechos, no_hechos}
     *
     * @throws AccionIaException
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        $datos = is_array($accion->datos) ? $accion->datos : [];

        $esperados = isset($datos['esperados']) && is_array($datos['esperados']) ? $datos['esperados'] : [];

        if (!count($esperados)) {

            throw new AccionIaException(422, 'La tarjeta del ajuste está vacía. Pedímela de nuevo.');
        }

        self::persona_autenticada($contexto, 'El ajuste de stock');

        $hechos = [];
        $no_hechos = [];

        foreach ($esperados as $esperado) {

            $esperado = is_array($esperado) ? $esperado : [];

            $article_id = isset($esperado['article_id']) ? (int) $esperado['article_id'] : 0;

            $nombre = self::nombre_para_el_resultado($contexto, $article_id);

            /* El nivel de transacción ANTES del renglón: ver el 🔴 del catch. */
            $nivel = DB::transactionLevel();

            try {

                $resultado = DB::transaction(function () use ($contexto, $esperado) {

                    /* Una tarjeta de un solo artículo "de mentira", sin guardar: el mismo camino, sin copiarlo. */
                    $una = new AiMessageAction();
                    $una->tipo = AiMessageAction::TIPO_STOCK_DEPOSITO;
                    $una->datos = ['esperado' => $esperado];

                    /* `static::` y no `self::`: deja que un test reemplace el renglón por un doble. */
                    return static::ejecutar_stock_en_deposito($contexto, $una);
                });

                $hechos[] = ['article_id' => $article_id, 'articulo' => $nombre, 'detalle' => (string) $resultado['texto']];

            } catch (\Exception $e) {

                /*
                 * 🔴 Revisión de la rama B, hallazgo 5. Seguir con el próximo renglón solo es seguro si
                 * la transacción de afuera (la del ejecutor) sigue entera. Si el nivel bajó (un deadlock
                 * adentro de una transacción anidada hace que Laravel la descuente y relance) o el error
                 * es de concurrencia (deadlock, espera de candado), los renglones siguientes correrían
                 * en autocommit, sin la transacción de la tarjeta: se relanza y no se ajusta nada.
                 */
                if (DB::transactionLevel() < $nivel || self::es_de_concurrencia($e)) {

                    throw $e;
                }

                if ($e instanceof AccionIaException) {

                    $no_hechos[] = ['article_id' => $article_id, 'articulo' => $nombre, 'motivo' => $e->getMessage()];

                    continue;
                }

                /* Un error técnico de UN renglón no voltea al resto: se registra y se cuenta como no hecho. */
                Log::warning('Ajuste de stock por lote: falló un renglón', ['article_id' => $article_id, 'error' => $e->getMessage()]);

                $no_hechos[] = ['article_id' => $article_id, 'articulo' => $nombre, 'motivo' => 'falló al guardarlo; probá de nuevo con ese artículo solo'];
            }
        }

        if (!count($hechos)) {

            $motivos = [];

            foreach ($no_hechos as $fila) {

                $motivos[] = $fila['articulo'] . ': ' . $fila['motivo'];
            }

            throw new AccionIaException(422, self::NO_SE_CARGO . 'no se ajustó ninguno de los artículos. ' . implode(' | ', $motivos));
        }

        return [
            'texto'     => self::texto_del_resultado($hechos, $no_hechos, $accion, $datos),
            'ruta'      => [
                'name'   => self::RUTA_LISTADO,
                'params' => new \stdClass(),
                'texto'  => 'Ver en el Listado',
            ],
            'hechos'    => $hechos,
            'no_hechos' => $no_hechos,
        ];
    }

    /**
     * true si la excepción es de concurrencia de la base (deadlock, espera de candado, serialización):
     * ésas no son "un renglón que no se pudo" sino la transacción entera en duda, y se relanzan.
     *
     * @param  \Exception  $e
     * @return bool
     */
    protected static function es_de_concurrencia(\Exception $e)
    {
        /* SQLSTATE 40001 (serialización / deadlock) o los errores 1205 y 1213 de MySQL. */
        if ($e instanceof \Illuminate\Database\QueryException
            && (in_array((string) $e->getCode(), ['40001'], true)
                || (isset($e->errorInfo[1]) && in_array((int) $e->errorInfo[1], [1205, 1213], true)))) {

            return true;
        }

        $mensaje = mb_strtolower($e->getMessage());

        foreach (['deadlock', 'lock wait timeout', 'serialization failure', '40001', 'try restarting transaction'] as $senal) {

            if (mb_strpos($mensaje, $senal) !== false) {

                return true;
            }
        }

        return false;
    }

    /**
     * El texto del resultado: cuántos se ajustaron y, si quedó alguno, cuáles y por qué. Es lo único
     * que lee el modelo, así que nunca dice "todo" si no fue todo.
     *
     * @param  array  $hechos
     * @param  array  $no_hechos
     * @param  \App\Models\AiMessageAction  $accion  La tarjeta (su id va en «Quedaron afuera de la tarjeta K»).
     * @param  array  $datos  Los datos de la tarjeta: `quedaron_afuera` y `fuera_de_la_tarjeta` se nombran al final.
     * @return string
     */
    protected static function texto_del_resultado(array $hechos, array $no_hechos, AiMessageAction $accion, array $datos)
    {
        $texto = 'Ajusté el stock de ' . count($hechos) . (count($hechos) === 1 ? ' artículo' : ' artículos') . '.';

        if (count($no_hechos)) {

            $partes = [];

            foreach ($no_hechos as $fila) {

                $partes[] = $fila['articulo'] . ' (' . $fila['motivo'] . ')';
            }

            $texto .= ' NO se ajustaron ' . count($no_hechos) . ': ' . implode('; ', $partes) . '.';
        }

        // Lo que ni entró a la tarjeta (no se pudo ubicar, o quedó en la tarjeta de otro depósito): se nombra siempre.
        $afuera = [];

        foreach (['quedaron_afuera', 'fuera_de_la_tarjeta'] as $clave_de_datos) {

            foreach ((isset($datos[$clave_de_datos]) && is_array($datos[$clave_de_datos])) ? $datos[$clave_de_datos] : [] as $fila) {

                $motivo = isset($fila['motivo']) ? rtrim(trim((string) $fila['motivo']), '.') : '';

                $afuera[] = (isset($fila['articulo']) ? (string) $fila['articulo'] : '(sin nombre)') . ($motivo === '' ? '' : ' (' . $motivo . ')');
            }
        }

        if (count($afuera)) {

            $texto .= ' Quedaron afuera de la tarjeta ' . (int) $accion->id . ': ' . implode('; ', $afuera) . '.';
        }

        return $texto;
    }

    /**
     * El nombre del artículo para el resultado (o su id, si ya no existe).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  int  $article_id
     * @return string
     */
    protected static function nombre_para_el_resultado(ContextoDeCargaIa $contexto, $article_id)
    {
        $articulo = Article::where('user_id', $contexto->owner_id)->where('id', (int) $article_id)->first(self::COLUMNAS);

        return is_null($articulo) ? 'artículo id ' . (int) $article_id : self::nombre_de_articulo($articulo);
    }
}
