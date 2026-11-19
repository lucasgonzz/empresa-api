<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\article\RenombreEnMasivaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;

/**
 * RENOMBRE MASIVO DE ARTÍCULOS PROPUESTO POR EL ASISTENTE (misión asistente-diario-20261119, rama C,
 * A4, 19/11/2026): «cambiá de cada artículo ESMALTE AC. MULT. por ESMALTE ACCIÓN MÚLTIPLE».
 *
 * 🔴 EL CASO REAL (Ferre Total, conversación 72, mensajes 700-723). Pablo pidió el cambio sobre 145
 * esmaltes. El agente prometió hacerlo «como actualización masiva», le mostró ejemplos, Pablo confirmó
 * y recién ahí el agente le dijo que no había herramienta que renombre en masa: la actualización masiva
 * no toca el nombre. Ahora hay una tarjeta propia, con la cantidad y ejemplos «antes → después».
 *
 * CÓMO SE ARMA EL CONJUNTO. Los artículos ACTIVOS del dueño (FiltroDeArticulosIaHelper::query(), sin
 * Auth: siempre `user_id = owner_id`) que cumplen los filtros opcionales de la masiva (proveedor,
 * categoría, marca, nombre...), opcionalmente acotados a ids que devolvió otra herramienta, y cuyo
 * nombre TIENE el texto a reemplazar. El texto es el filtro que manda: sin él no hay conjunto. El
 * "tiene el texto" se decide en PHP con la misma función que después aplica la cola
 * (RenombreEnMasivaHelper::reemplazar()), no solo con el LIKE de MySQL: el LIKE ignora acentos
 * ("ACCION" encaja con "ACCIÓN") y la cuenta de la tarjeta tiene que ser la de los que de verdad
 * cambian.
 *
 * CÓMO SE EJECUTA. Por el MISMO camino que la actualización masiva: MasiveUpdateHelper::
 * encolar_actualizacion() con selección por ids (el filtro de dueño de esa función es la segunda
 * guarda), el job ProcessMasiveUpdateJob, el historial de actualizaciones masivas y su reversión. El
 * ítem del update_form es `reemplazo_de_texto` (RenombreEnMasivaHelper), que guarda el NOMBRE ENTERO de
 * antes en el historial: revertir desde el historial devuelve cada nombre como estaba.
 *
 * 🔴 NUNCA SE AUTO-CONFIRMA (HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES): reescribe el nombre de
 * hasta TOPE artículos de un saque, que es lo que se ve en el ticket, en la tienda y en las búsquedas.
 *
 * 🔴 EN ejecutar() SOLO ENTRAN LOS QUE LA PERSONA VIO. La tarjeta guarda los ids alcanzados al
 * proponer; al confirmar se vuelve a armar el conjunto con el mismo criterio y se encolan solo los que
 * están en los DOS (un artículo que entró después no se renombra sin que nadie lo haya visto). Si se
 * cayeron más de un 10 % (y más de 3), se corta y se pide de nuevo, como hace la masiva con su conteo.
 *
 * PHP 7.4: sin match, sin str_contains, sin nullsafe, sin argumentos nombrados, sin union types.
 */
class PropuestaRenombreMasivoIaHelper
{
    /** El mismo permiso que la actualización masiva ("Actualizar artículos" del listado). */
    const PERMISO = 'article.update';

    /**
     * Tope de artículos de un renombre. Más bajo que el de la masiva (3000) a propósito: el nombre es
     * lo que más se ve, y 2000 ya es un catálogo de proveedor entero. Para más, se acota el filtro.
     */
    const TOPE = 2000;

    /** Cuántos ejemplos «antes → después» van en la tarjeta y en la respuesta. */
    const MAX_EJEMPLOS = 5;

    /** Largo máximo del texto a buscar y del reemplazo (un nombre de artículo razonable). */
    const MAX_LARGO = 191;

    const TITULO = 'Renombre masivo de artículos';

    const AVISO = 'Cambia solo ese texto del nombre; el resto queda igual. Corre en segundo plano, queda en el historial de actualizaciones masivas y se puede revertir desde ahí (vuelve el nombre anterior de cada artículo).';

    /**
     * Herramienta proponer_renombre_masivo.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  buscar*, reemplazo*, filtros, articulo_ids, reemplaza_a
     * @return array  La respuesta de la herramienta.
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_permiso('actualizar artículos'));
        }

        $criterio = self::criterio_del_input($contexto, $input);

        if (RespuestaDeCargaIa::es_negativa($criterio)) {

            return $criterio;
        }

        $conjunto = self::conjunto($contexto->owner_id, $criterio);

        if (RespuestaDeCargaIa::es_negativa($conjunto)) {

            return $conjunto;
        }

        $total = count($conjunto['ids']);

        if ($total === 0) {

            return RespuestaDeCargaIa::error(
                'Ningún artículo activo tiene «' . $criterio['buscar'] . '» en el nombre'
                . (count($criterio['legibles']) ? ' con esos filtros (' . implode('; ', $criterio['legibles']) . ')' : '')
                . '. Revisá con la persona cómo está escrito exactamente (puntos, espacios, abreviaturas): podés buscar con contar_articulos_por_filtro por nombre contiene.'
            );
        }

        $renglones = [
            ['etiqueta' => 'Artículos alcanzados', 'valor' => (string) $total],
        ];

        foreach ($criterio['renglones'] as $renglon) {

            $renglones[] = $renglon;
        }

        $renglones[] = ['etiqueta' => 'Cambio', 'valor' => self::texto_del_cambio($criterio['buscar'], $criterio['reemplazo'])];

        foreach ($conjunto['ejemplos'] as $ejemplo) {

            $renglones[] = ['etiqueta' => 'Ejemplo', 'valor' => $ejemplo['antes'] . ' → ' . $ejemplo['despues']];
        }

        /*
         * Revisión independiente (19/11/2026): si el texto también está pegado a otra palabra, se dice en
         * la tarjeta y al modelo (el reemplazo es literal y ahí también cambia).
         */
        $aviso_dentro = '';

        if ($conjunto['dentro_de_otra_palabra'] > 0) {

            $aviso_dentro = 'Ojo: en ' . $conjunto['dentro_de_otra_palabra'] . ' de ellos «' . $criterio['buscar']
                . '» está pegado a otra palabra y también cambia (el primer ejemplo es uno de esos).';

            $renglones[] = ['etiqueta' => 'Atención', 'valor' => $aviso_dentro];
        }

        $datos = [
            'buscar'         => $criterio['buscar'],
            'reemplazo'      => $criterio['reemplazo'],
            'filtros'        => $criterio['filtros'],
            'filter_form'    => $criterio['filter_form'],
            'imagen'         => $criterio['imagen'],
            'articulo_ids'   => $criterio['articulo_ids'],
            // Los que vio la persona: en ejecutar() solo entran éstos (ver el 🔴 del docblock).
            'ids'            => $conjunto['ids'],
            'total_estimado' => $total,
            'ejemplos'       => $conjunto['ejemplos'],
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_RENOMBRE_MASIVO,
            self::clave($criterio),
            $datos,
            ['titulo' => self::TITULO, 'renglones' => $renglones, 'aviso' => $aviso_dentro === '' ? self::AVISO : self::AVISO . ' ' . $aviso_dentro],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        return AccionesIaHelper::respuesta_de_propuesta(
            $creada,
            'Renombre de ' . $total . ' artículos: ' . self::texto_del_cambio($criterio['buscar'], $criterio['reemplazo']),
            [
                'articulos_alcanzados' => $total,
                'filtros_legibles'     => $criterio['legibles'],
                'ejemplos'             => self::lineas_de_ejemplos($conjunto['ejemplos']),
                'como_contarlo'        => 'Antes de pedir el sí, decile a la persona cuántos artículos alcanza y mostrale dos o tres ejemplos de «ejemplos» (nombre de antes → nombre de después) TAL CUAL vienen. No inventes otros ejemplos.'
                    . ($aviso_dentro === '' ? '' : ' 🔴 ' . $aviso_dentro . ' Mostrale ese ejemplo primero y preguntale si está bien; si no, acotá el texto (por ejemplo con el espacio o el punto de al lado) y volvé a llamar.'),
                'dentro_de_otra_palabra' => $conjunto['dentro_de_otra_palabra'],
            ]
        );
    }

    /**
     * Encola el renombre. Corre en el request del clic (o con la persona puesta por el canal de
     * WhatsApp), adentro de la transacción de EjecutorAccionesIaHelper.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  AiMessageAction  $accion
     * @return array  resultado {texto, ruta, ejemplos}
     *
     * @throws AccionIaException  422 si ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::puede($contexto->persona, self::PERMISO)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso('actualizar artículos'));
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $buscar = isset($datos['buscar']) ? (string) $datos['buscar'] : '';
        $reemplazo = isset($datos['reemplazo']) ? (string) $datos['reemplazo'] : '';
        $vistos = isset($datos['ids']) && is_array($datos['ids']) ? array_map('intval', $datos['ids']) : [];

        if ($buscar === '' || !count($vistos)) {

            throw new AccionIaException(422, 'Esta tarjeta no tiene ningún renombre para aplicar. Pedímelo de nuevo.');
        }

        $criterio = [
            'buscar'       => $buscar,
            'reemplazo'    => $reemplazo,
            'filter_form'  => isset($datos['filter_form']) && is_array($datos['filter_form']) ? $datos['filter_form'] : [],
            'imagen'       => isset($datos['imagen']) ? $datos['imagen'] : null,
            'articulo_ids' => isset($datos['articulo_ids']) && is_array($datos['articulo_ids']) ? $datos['articulo_ids'] : [],
        ];

        $fresco = self::conjunto($contexto->owner_id, $criterio);

        if (RespuestaDeCargaIa::es_negativa($fresco)) {

            throw new AccionIaException(422, (string) $fresco['error']);
        }

        // Solo los que vio la persona Y siguen cumpliendo (del dueño, activos y con el texto en el nombre).
        $ids = array_values(array_intersect($vistos, $fresco['ids']));

        $estimado = isset($datos['total_estimado']) ? (int) $datos['total_estimado'] : count($vistos);

        if (!count($ids)) {

            throw new AccionIaException(422, 'Ninguno de los artículos de la tarjeta tiene ya «' . $buscar . '» en el nombre: no hay nada que renombrar.');
        }

        if (abs($estimado - count($ids)) > max(3, (int) ceil($estimado * 0.10))) {

            throw new AccionIaException(422, 'Cambió la cantidad de artículos para renombrar (eran ' . $estimado . ', ahora son ' . count($ids) . '): pedímelo de nuevo para ver la cantidad actual.');
        }

        $persona = $contexto->persona;

        $auth_user_id = !is_null($persona) ? (int) $persona->id : (int) $contexto->conversation->auth_user_id;

        $resultado = MasiveUpdateHelper::encolar_actualizacion(
            'article',
            false,
            [],
            [RenombreEnMasivaHelper::item_del_formulario($buscar, $reemplazo)],
            $ids,
            $contexto->owner_id,
            $auth_user_id,
            null,
            // El criterio legible del historial, en lugar de "Seleccion manual".
            [['key' => 'Renombre: ' . self::texto_del_cambio($buscar, $reemplazo)]]
        );

        if ((int) $resultado['status'] !== 200) {

            $mensaje = isset($resultado['body']['message']) ? (string) $resultado['body']['message'] : 'No se pudo encolar el renombre.';

            throw new AccionIaException(422, $mensaje);
        }

        $encolados = isset($resultado['body']['queued_count']) ? (int) $resultado['body']['queued_count'] : count($ids);

        $resultado_de_la_tarjeta = [
            'texto' => 'Renombre encolado: ' . $encolados . ' artículos (' . self::texto_del_cambio($buscar, $reemplazo) . '). Te va a aparecer en el sistema cuando termine.',
            'ruta'  => [
                'name'   => 'article',
                'params' => new \stdClass(),
                'texto'  => 'Ver el listado',
            ],
        ];

        if (isset($datos['ejemplos']) && is_array($datos['ejemplos']) && count($datos['ejemplos'])) {

            $resultado_de_la_tarjeta['ejemplos'] = self::lineas_de_ejemplos($datos['ejemplos']);
        }

        return $resultado_de_la_tarjeta;
    }

    /**
     * Lee y valida el input de la IA: el texto a buscar, el reemplazo, los filtros (traducidos por
     * FiltroDeArticulosIaHelper, los mismos de la masiva) y los ids opcionales.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $input
     * @return array  El criterio, o la respuesta negativa.
     */
    protected static function criterio_del_input(ContextoDeCargaIa $contexto, array $input)
    {
        $buscar_crudo = EntradaDeCargaIa::valor($input, 'buscar');
        $reemplazo_crudo = EntradaDeCargaIa::valor($input, 'reemplazo');

        $buscar = is_scalar($buscar_crudo) ? trim((string) $buscar_crudo) : '';

        if ($buscar === '') {

            return RespuestaDeCargaIa::faltan(['qué texto del nombre hay que reemplazar (tal cual está escrito en los artículos)']);
        }

        // El reemplazo puede ser vacío ("sacale «OFERTA» al nombre"), pero tiene que venir.
        if (is_null($reemplazo_crudo) || !is_scalar($reemplazo_crudo)) {

            return RespuestaDeCargaIa::faltan(['por qué texto hay que reemplazarlo (vacío para sacarlo)']);
        }

        $reemplazo = trim((string) $reemplazo_crudo);

        if (mb_strlen($buscar) > self::MAX_LARGO || mb_strlen($reemplazo) > self::MAX_LARGO) {

            return RespuestaDeCargaIa::error('El texto a buscar y el reemplazo pueden tener hasta ' . self::MAX_LARGO . ' caracteres.');
        }

        if ($buscar === $reemplazo) {

            return RespuestaDeCargaIa::error('El texto a buscar y el reemplazo son iguales: no hay nada que cambiar.');
        }

        $filtros = EntradaDeCargaIa::valor($input, 'filtros');
        $filtros = is_array($filtros) ? array_values($filtros) : [];

        $traducido = FiltroDeArticulosIaHelper::traducir($filtros, $contexto->owner_id);

        if (RespuestaDeCargaIa::es_negativa($traducido)) {

            return $traducido;
        }

        $articulo_ids = self::ids_del_input(EntradaDeCargaIa::valor($input, 'articulo_ids'));

        $renglones = $traducido['renglones'];
        $legibles = $traducido['legibles'];

        if (count($articulo_ids)) {

            $renglones[] = ['etiqueta' => 'Artículos elegidos', 'valor' => (string) count($articulo_ids)];
            $legibles[] = count($articulo_ids) . ' artículos elegidos';
        }

        return [
            'buscar'       => $buscar,
            'reemplazo'    => $reemplazo,
            'filtros'      => $filtros,
            'filter_form'  => $traducido['filter_form'],
            'imagen'       => $traducido['imagen'],
            'articulo_ids' => $articulo_ids,
            'renglones'    => $renglones,
            'legibles'     => $legibles,
        ];
    }

    /**
     * Los artículos que cambian con el criterio: ids (en orden) y hasta MAX_EJEMPLOS ejemplos.
     *
     * Primero un LIKE en SQL sobre los activos del dueño (con los filtros y los ids) y después la
     * confirmación en PHP con la misma función que aplica la cola. Con más de TOPE candidatos en SQL
     * se corta antes de traerlos (el LIKE es un superconjunto de los que cambian).
     *
     * @param  int  $owner_id
     * @param  array  $criterio  buscar, reemplazo, filter_form, imagen, articulo_ids
     * @return array  ['ids' => int[], 'ejemplos' => [{article_id, antes, despues}]] o la respuesta negativa.
     */
    protected static function conjunto($owner_id, array $criterio)
    {
        $query = FiltroDeArticulosIaHelper::query((int) $owner_id, $criterio['filter_form'], $criterio['imagen'])
            ->where('articles.name', 'LIKE', '%' . addcslashes($criterio['buscar'], '%_\\') . '%');

        if (count($criterio['articulo_ids'])) {

            $query->whereIn('articles.id', array_map('intval', $criterio['articulo_ids']));
        }

        $candidatos = (int) (clone $query)->count();

        if ($candidatos > self::TOPE) {

            return RespuestaDeCargaIa::error(
                'Son ' . $candidatos . ' artículos con «' . $criterio['buscar'] . '» en el nombre y el tope de un renombre masivo es ' . self::TOPE . ': acotá con un filtro (proveedor, categoría, marca).'
            );
        }

        $ids = [];
        $ejemplos = [];

        /*
         * Revisión independiente (19/11/2026): el reemplazo es literal y también cambia el texto pegado a
         * otra palabra («MULT» dentro de «MULTIUSO»). Se cuenta cuántos son y el PRIMER ejemplo de esos va
         * adelante en la tarjeta, para que la persona lo vea antes del sí.
         */
        $dentro_de_otra_palabra = 0;
        $ejemplo_dentro = null;

        foreach ($query->orderBy('articles.id')->get(['articles.id', 'articles.name']) as $articulo) {

            $antes = (string) $articulo->name;
            $despues = RenombreEnMasivaHelper::reemplazar($antes, $criterio['buscar'], $criterio['reemplazo']);

            if ($despues === $antes || trim($despues) === '') {

                continue;
            }

            $ids[] = (int) $articulo->id;

            $ejemplo = ['article_id' => (int) $articulo->id, 'antes' => $antes, 'despues' => $despues];

            if (RenombreEnMasivaHelper::queda_dentro_de_otra_palabra($antes, $criterio['buscar'])) {

                $dentro_de_otra_palabra++;

                if (is_null($ejemplo_dentro)) {

                    $ejemplo_dentro = $ejemplo;
                }
            }

            if (count($ejemplos) < self::MAX_EJEMPLOS) {

                $ejemplos[] = $ejemplo;
            }
        }

        // El ejemplo «dentro de otra palabra» primero; si no estaba entre los mostrados, desplaza al último.
        if (!is_null($ejemplo_dentro)) {

            $resto = array_values(array_filter($ejemplos, function ($ejemplo) use ($ejemplo_dentro) {
                return $ejemplo['article_id'] !== $ejemplo_dentro['article_id'];
            }));

            $ejemplos = array_slice(array_merge([$ejemplo_dentro], $resto), 0, self::MAX_EJEMPLOS);
        }

        return ['ids' => $ids, 'ejemplos' => $ejemplos, 'dentro_de_otra_palabra' => $dentro_de_otra_palabra];
    }

    /**
     * La clave de la tarjeta: el mismo pedido arma la misma clave (y una segunda llamada reemplaza a
     * la primera en lugar de dejar dos tarjetas vivas que renombren dos veces).
     *
     * @param  array  $criterio
     * @return string
     */
    public static function clave(array $criterio)
    {
        return 'renombre_masivo:' . sha1((string) json_encode([
            $criterio['buscar'], $criterio['reemplazo'], $criterio['filtros'], $criterio['articulo_ids'],
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * «AC. MULT.» → «ACCIÓN MÚLTIPLE», o «OFERTA» → (se saca) con el reemplazo vacío.
     *
     * @param  string  $buscar
     * @param  string  $reemplazo
     * @return string
     */
    protected static function texto_del_cambio($buscar, $reemplazo)
    {
        return '«' . $buscar . '» → ' . ($reemplazo === '' ? '(se saca del nombre)' : '«' . $reemplazo . '»');
    }

    /**
     * Los ejemplos como líneas «antes → después» para el modelo.
     *
     * @param  array  $ejemplos
     * @return array<int, string>
     */
    protected static function lineas_de_ejemplos(array $ejemplos)
    {
        $lineas = [];

        foreach ($ejemplos as $ejemplo) {

            if (is_array($ejemplo) && isset($ejemplo['antes'], $ejemplo['despues'])) {

                $lineas[] = $ejemplo['antes'] . ' → ' . $ejemplo['despues'];
            }
        }

        return $lineas;
    }

    /**
     * Los ids que mandó el modelo (enteros positivos, sin repetir), o [] si no mandó.
     *
     * @param  mixed  $valor
     * @return array<int, int>
     */
    protected static function ids_del_input($valor)
    {
        if (!is_array($valor)) {

            return [];
        }

        $ids = [];

        foreach ($valor as $id) {

            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {

                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }
}
