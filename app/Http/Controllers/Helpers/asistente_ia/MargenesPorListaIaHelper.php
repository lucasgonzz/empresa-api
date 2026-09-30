<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Models\PriceType;
use Illuminate\Support\Facades\DB;

/**
 * EL MARGEN DE UN ARTÍCULO POR LISTA DE PRECIOS, en el alta y en la edición por el asistente
 * (misión alta-por-agente-margen-y-stock, 29/9/2026).
 *
 * 🔴 DE DÓNDE SALE. En demo3, el 29/9/2026, el dueño dictó por WhatsApp el alta de la Cera Nic
 * (artículo 17320) con "costo de 10.000 y un margen de ganancia del 30 % para la lista general".
 * El agente mandó `percentage_gain: 30` y `price_types: []`, la tarjeta se ejecutó sola (modo
 * directo) y el resultado dijo "creado" sin ningún campo que no quedara. Pero el negocio trabaja
 * con listas por artículo (`users.listas_de_precio = 1`) y las dos listas quedaron con margen 0 %
 * y precio 10.000 = el costo. `percentage_gain` solo mueve `articles.final_price`, un precio que
 * en esas cuentas la ficha ni siquiera muestra.
 *
 * 🔴 POR QUÉ `percentage_gain` NO LLEGA A LAS LISTAS. `ArticleHelper::setFinalPrice()` calcula
 * cada lista (`ArticlePricesHelper::aplicar_precios_segun_listas_de_precios()`) ANTES de sumar
 * `percentage_gain`, y una lista sin pivote toma `price_types.percentage` (NULL → 0). La ficha,
 * con listas, ESCONDE `percentage_gain` (`src/models/article.js`, `if_has_not_extencions`) y manda
 * `price_types` con `pivot.percentage` por lista (`model_functions.js::set_article_price_types`),
 * que `ArticlePriceTypeHelper::attach_price_types()` escribe ANTES de `setFinalPrice()`.
 *
 * Este helper hace exactamente eso desde el asistente, sin tocar nada de la pantalla: arma el
 * `price_types` con la forma que manda la ficha y deja que `ArticleController::store()` /
 * `update()` sigan su camino de siempre (attach_price_types → setFinalPrice). Después RELEE el
 * pivote para que el resultado diga el precio que quedó de verdad, no el que se pidió.
 *
 * Decisiones de Lucas (29/9/2026) que este archivo sostiene:
 *   1. Un margen sin lista en una cuenta con listas lo RECHAZA el sistema con un error que le dice
 *      al modelo que pregunte la lista (puede ofrecer "todas"): no depende de que el modelo se
 *      acuerde de una regla del prompt.
 *   2. También la edición: "cambiale el margen de la general a 35" mueve el pivote de verdad.
 *
 * PHP 7.4: sin match, sin str_contains, sin ?->, sin argumentos nombrados, sin union types.
 */
class MargenesPorListaIaHelper
{
    /** La clave con la que viaja en proponer_alta / proponer_edicion. */
    const CLAVE = 'margenes_por_lista';

    /**
     * La extensión con la que el negocio vende en dólares: ahí el margen de cada lista va por lista
     * Y por moneda (`price_type_monedas`, ArticlePriceTypeMonedaHelper), que este helper no arma.
     */
    const EXTENSION_DOLARES = 'ventas_en_dolares';

    /**
     * La extensión de los márgenes por CATEGORÍA: cada lista toma el margen de la categoría del
     * artículo (ArticlePricesHelper::aplicar_precios_segun_listas_de_precios_y_categorias), no de un
     * pivote del artículo. La ficha también esconde `percentage_gain` ahí.
     */
    const EXTENSION_POR_CATEGORIA = 'lista_de_precios_por_categoria';

    /** Lo que la persona dice para "a todas las listas", ya normalizado. */
    const TODAS = ['todas', 'todas las listas', 'todas las listas de precio', 'todas las listas de precios', 'todos', 'todas ellas'];

    /**
     * Palabras que la persona agrega alrededor del nombre de la lista y que no lo identifican
     * ("la lista general", "lista de precios mayorista"). Se sacan SOLO para la segunda pasada de la
     * búsqueda, nunca para la exacta: una lista que se llame literalmente "Lista 1" tiene que seguir
     * ganando por nombre completo.
     */
    const PALABRAS_DE_RELLENO = ['la', 'el', 'lista', 'listas', 'de', 'del', 'precio', 'precios'];

    /** Tolerancia para comparar porcentajes guardados en decimal(…,2). */
    const TOLERANCIA = 0.01;

    // =========================================================================================
    // ¿Aplica en este negocio?
    // =========================================================================================

    /**
     * true si el negocio trabaja con listas POR ARTÍCULO y este helper puede cargar su margen:
     * `users.listas_de_precio` (UserHelper::uses_listas_de_precio, la misma pregunta que se hace
     * setFinalPrice) y SIN ventas en dólares (ver EXTENSION_DOLARES).
     *
     * @param  \App\Models\User|null  $owner
     * @return bool
     */
    public static function aplica($owner): bool
    {
        if (is_null($owner)) {

            return false;
        }

        return UserHelper::uses_listas_de_precio($owner) && !UserHelper::hasExtencion(self::EXTENSION_DOLARES, $owner);
    }

    /**
     * true cuando la ficha del artículo ESCONDE `percentage_gain`: con listas por artículo o con la
     * extensión de márgenes por categoría. Es el mismo criterio que `if_has_not_extencions` de
     * `src/models/article.js` (la SPA traduce el slug viejo de listas a `owner.listas_de_precio`).
     *
     * @param  \App\Models\User|null  $owner
     * @return bool
     */
    public static function margen_suelto_prohibido($owner): bool
    {
        if (is_null($owner)) {

            return false;
        }

        return UserHelper::uses_listas_de_precio($owner) || UserHelper::hasExtencion(self::EXTENSION_POR_CATEGORIA, $owner);
    }

    /**
     * Las listas de precio del dueño, en el orden en que las muestra la pantalla.
     *
     * @param  int  $owner_id
     * @return \Illuminate\Support\Collection
     */
    public static function listas_del_dueno($owner_id)
    {
        return PriceType::where('user_id', (int) $owner_id)
                        ->orderBy('position')
                        ->orderBy('id')
                        ->get();
    }

    /**
     * Por qué este negocio NO acepta margenes_por_lista, o null si lo acepta.
     *
     * @param  \App\Models\User|null  $owner
     * @return string|null
     */
    public static function motivo_si_no_aplica($owner)
    {
        if (self::aplica($owner)) {

            return null;
        }

        if (!is_null($owner) && UserHelper::uses_listas_de_precio($owner)) {

            return 'Este negocio vende en dólares: el margen de cada lista va por lista y por moneda, y eso se carga desde la ficha del artículo, no desde acá. '
                . 'Decile a la persona que el margen no queda cargado por el asistente.';
        }

        if (!is_null($owner) && UserHelper::hasExtencion(self::EXTENSION_POR_CATEGORIA, $owner)) {

            return 'En este negocio el margen de cada lista sale de la CATEGORÍA del artículo (se cambia en ABM > Categorías), no del artículo: no mandes margenes_por_lista. '
                . 'Decile a la persona que el margen de las listas lo pone la categoría.';
        }

        return 'Este negocio no trabaja con listas de precio: el margen del artículo va en percentage_gain (margen de ganancia), no en margenes_por_lista.';
    }

    // =========================================================================================
    // La guarda del margen suelto
    // =========================================================================================

    /**
     * 🔴 LA GUARDA QUE CIERRA EL CASO DE DEMO3: con listas, un `percentage_gain` (por cualquiera de
     * sus alias: llega acá ya traducido a la columna por validar_campos) NO se acepta, ni en el alta
     * ni en la edición. Devuelve la respuesta negativa, o null si se puede seguir.
     *
     * Por qué se RECHAZA en vez de convertirlo solo a "todas las listas": Lucas decidió (29/9/2026)
     * que un margen sin lista se pregunta. En un negocio con una lista mayorista y una minorista,
     * "margen 30" puede ser para una sola, y adivinar mueve precios que la persona no nombró.
     *
     * Un `percentage_gain` vacío (null) pasa: no mueve nada y es la forma de SACAR uno heredado de
     * una tarjeta vieja en una corrección.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $payload  El payload validado (columnas).
     * @return array|null
     */
    public static function guarda_del_margen_suelto(ContextoDeCargaIa $contexto, array $payload)
    {
        if (!array_key_exists('percentage_gain', $payload) || is_null($payload['percentage_gain'])) {

            return null;
        }

        $owner = $contexto->owner;

        if (!self::margen_suelto_prohibido($owner)) {

            return null;
        }

        if (!UserHelper::uses_listas_de_precio($owner)) {

            return RespuestaDeCargaIa::error(
                'En este negocio el margen de cada lista sale de la categoría del artículo (ABM > Categorías): el margen suelto del artículo (percentage_gain) '
                . 'no mueve ningún precio de lista y la ficha ni lo muestra. No lo mandes: si la persona quiere otro margen, se cambia en la categoría.'
            );
        }

        $nombres = self::nombres_de_las_listas($contexto->owner_id);

        $cuales = count($nombres) ? ' (' . implode(', ', $nombres) . ')' : '';

        if (!self::aplica($owner)) {

            return RespuestaDeCargaIa::error(
                'Este negocio trabaja con listas de precio' . $cuales . ' y vende en dólares: el margen va por lista y por moneda, y se carga desde la ficha del artículo. '
                . 'percentage_gain no mueve ningún precio de lista, así que no lo mandes. Decile a la persona que el margen lo tiene que cargar desde la ficha.'
            );
        }

        return RespuestaDeCargaIa::error(
            'Este negocio trabaja con listas de precio' . $cuales . ': el margen va POR LISTA, en margenes_por_lista '
            . '([{"lista": "<nombre de la lista>", "margen": 30}]). percentage_gain (margen de ganancia) no mueve ningún precio de lista, así que no lo mandes. '
            . 'Si la persona no dijo para qué lista es el margen, preguntale (podés ofrecerle "todas").',
            ['listas' => self::opciones($contexto->owner_id)]
        );
    }

    // =========================================================================================
    // Resolver lo que pidió la persona
    // =========================================================================================

    /**
     * Resuelve `margenes_por_lista` contra las listas del dueño.
     *
     * Cada ítem es `{lista, margen}`. La lista va por NOMBRE: igual normalizado gana; si no, la que
     * contiene (o está contenida en) lo que dijo la persona, si es UNA sola; varias → `faltan` con
     * las opciones; ninguna → `error` que nombra las listas que existen. "todas" expande a todas, y
     * una lista nombrada explícitamente le gana a "todas" ("30 a todas y 40 a la minorista").
     *
     * Un ítem puede traer además `price_type_id`: no está en el esquema de la herramienta, lo pone
     * la HERENCIA de una corrección (AltaDeArticuloConFotoIaHelper::heredar) con la lista ya resuelta
     * en la tarjeta anterior. Se valida contra el dueño igual que el nombre.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $crudo
     * @return array  [['price_type_id' => int, 'nombre' => string, 'margen' => float], ...] en el orden
     *                de las listas (vacío = la persona pidió sacar los márgenes), o la respuesta negativa.
     */
    public static function resolver(ContextoDeCargaIa $contexto, $crudo)
    {
        $motivo = self::motivo_si_no_aplica($contexto->owner);

        if (!is_null($motivo)) {

            return RespuestaDeCargaIa::error($motivo);
        }

        $items = self::como_lista($crudo);

        if (is_null($items)) {

            return RespuestaDeCargaIa::error(self::mensaje_de_formato());
        }

        if (!count($items)) {

            return [];
        }

        $listas = self::listas_del_dueno($contexto->owner_id);

        if (!count($listas)) {

            return RespuestaDeCargaIa::error('Este negocio trabaja con listas de precio pero no tiene ninguna cargada: se cargan en ABM > Listas de precio.');
        }

        /** El margen que vino para "todas", si vino. */
        $de_todas = null;

        /** price_type_id => margen, de las listas nombradas una por una. */
        $explicitas = [];

        foreach ($items as $item) {

            $item = self::como_array($item);

            if (is_null($item)) {

                return RespuestaDeCargaIa::error(self::mensaje_de_formato());
            }

            $texto_de_la_lista = self::texto_de($item, ['lista', 'nombre', 'price_type', 'lista_de_precios']);

            $crudo_del_margen = self::valor_de($item, ['margen', 'porcentaje', 'percentage']);

            $margen = self::a_margen($crudo_del_margen);

            $para = $texto_de_la_lista === '' ? 'esa lista' : 'la lista "' . $texto_de_la_lista . '"';

            if (is_null($margen)) {

                return RespuestaDeCargaIa::error('El margen de ' . $para . ' tiene que ser un número (30 es 30 %).');
            }

            if ($margen <= -100) {

                return RespuestaDeCargaIa::error('El margen de ' . $para . ' no puede ser -100 % o menos: el precio quedaría en cero o negativo.');
            }

            $por_id = isset($item['price_type_id']) && is_numeric($item['price_type_id']) ? (int) $item['price_type_id'] : 0;

            if ($por_id > 0) {

                $lista = $listas->firstWhere('id', $por_id);

                if (is_null($lista)) {

                    return RespuestaDeCargaIa::error(
                        'La lista de precios de la tarjeta anterior ya no existe. Las listas de este negocio son: ' . implode(', ', self::nombres($listas)) . '.',
                        ['listas' => self::opciones_de($listas)]
                    );
                }

                if (array_key_exists((int) $lista->id, $explicitas)) {

                    return self::repetida($lista);
                }

                $explicitas[(int) $lista->id] = $margen;

                continue;
            }

            if ($texto_de_la_lista === '') {

                return RespuestaDeCargaIa::faltan(
                    ['para qué lista de precios es el margen de ' . Catalogo::numero($margen) . ' % (puede ser "todas")'],
                    ['listas' => self::opciones_de($listas)]
                );
            }

            if (in_array(self::normalizar($texto_de_la_lista), self::TODAS, true)) {

                if (!is_null($de_todas)) {

                    return RespuestaDeCargaIa::error('"todas" vino dos veces en margenes_por_lista: mandalo una sola vez, con el margen que va.');
                }

                $de_todas = $margen;

                continue;
            }

            $lista = self::buscar_lista($listas, $texto_de_la_lista);

            if (RespuestaDeCargaIa::es_negativa($lista)) {

                return $lista;
            }

            if (array_key_exists((int) $lista->id, $explicitas)) {

                return self::repetida($lista);
            }

            $explicitas[(int) $lista->id] = $margen;
        }

        $resueltos = [];

        foreach ($listas as $lista) {

            $id = (int) $lista->id;

            if (array_key_exists($id, $explicitas)) {

                $resueltos[] = ['price_type_id' => $id, 'nombre' => (string) $lista->name, 'margen' => $explicitas[$id]];

            } elseif (!is_null($de_todas)) {

                $resueltos[] = ['price_type_id' => $id, 'nombre' => (string) $lista->name, 'margen' => $de_todas];
            }
        }

        return $resueltos;
    }

    /**
     * La lista que nombró la persona, o la respuesta negativa.
     *
     * @param  \Illuminate\Support\Collection  $listas
     * @param  string  $texto
     * @return \App\Models\PriceType|array
     */
    protected static function buscar_lista($listas, $texto)
    {
        $buscado = self::normalizar($texto);

        $exactas = [];

        foreach ($listas as $lista) {

            if (self::normalizar((string) $lista->name) === $buscado) {

                $exactas[] = $lista;
            }
        }

        if (count($exactas) === 1) {

            return $exactas[0];
        }

        if (count($exactas) > 1) {

            return self::ambigua($texto, $exactas);
        }

        /*
         * Segunda pasada, sin las palabras de relleno ("la general" contra "LISTA GENERAL"): igual
         * gana, y si no, contiene / está contenida. Un texto que queda vacío sin el relleno ("la
         * lista") no identifica nada y no se busca.
         */
        $buscado_sin_relleno = self::sin_relleno($buscado);

        if ($buscado_sin_relleno === '') {

            return self::no_existe($texto, $listas);
        }

        $iguales = [];
        $parecidas = [];

        foreach ($listas as $lista) {

            $nombre = self::sin_relleno(self::normalizar((string) $lista->name));

            if ($nombre === '') {

                continue;
            }

            if ($nombre === $buscado_sin_relleno) {

                $iguales[] = $lista;

            } elseif (strpos($nombre, $buscado_sin_relleno) !== false || strpos($buscado_sin_relleno, $nombre) !== false) {

                $parecidas[] = $lista;
            }
        }

        $encontradas = count($iguales) ? $iguales : $parecidas;

        if (count($encontradas) === 1) {

            return $encontradas[0];
        }

        if (count($encontradas) > 1) {

            return self::ambigua($texto, $encontradas);
        }

        return self::no_existe($texto, $listas);
    }

    // =========================================================================================
    // Lo que va al controller
    // =========================================================================================

    /**
     * El `price_types` que va al controller, con la forma que manda la ficha: para cada lista
     * resuelta, `['id' => X, 'pivot' => ['percentage' => M, 'setear_precio_final' => 0,
     * 'final_price' => null, 'incluir_en_excel_para_clientes' => ...]]`.
     *
     * Las listas que ya venían en `$actuales` (la edición manda el modelo entero de withAll(), con
     * sus pivotes) se conservan TAL CUAL; solo se pisan las nombradas. En el alta `$actuales` es el
     * `[]` que la pantalla manda siempre.
     *
     * 🔴 `setear_precio_final = 0` ES A PROPÓSITO. Con 1, `aplicar_precios_segun_listas_de_precios()`
     * toma `pivot.final_price` (el precio fijo) e IGNORA el porcentaje: si la lista está marcada como
     * "precio fijo" (por defecto o en el pivote del artículo), mandar el margen con el 1 de la lista
     * dejaría la tarjeta diciendo "30 %" y el precio sin moverse. Si la persona dicta un margen para
     * esa lista, quiere margen, no precio fijo. Y `final_price = null` por lo mismo: con el 0, el
     * precio lo vuelve a calcular setFinalPrice desde el margen.
     *
     * 🔴 SE VERIFICA AL EJECUTAR que cada lista siga existiendo y sea del dueño: entre la tarjeta y
     * el clic pueden pasar horas, y `attach_price_types` hace `syncWithoutDetaching` con el id que le
     * llegue, sin mirar de quién es. Esto corre ANTES de llamar al controller, así que un 422 acá no
     * deja nada escrito.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $margenes  Lo que devolvió resolver() y quedó guardado en la tarjeta.
     * @param  mixed  $actuales  El `price_types` que ya iba en el payload.
     * @return array
     *
     * @throws AccionIaException
     */
    public static function price_types_para_el_payload(ContextoDeCargaIa $contexto, array $margenes, $actuales = [])
    {
        $por_id = [];

        foreach ($margenes as $margen) {

            $por_id[(int) $margen['price_type_id']] = $margen;
        }

        $listas = PriceType::where('user_id', $contexto->owner_id)
                            ->whereIn('id', array_keys($por_id))
                            ->get()
                            ->keyBy('id');

        foreach ($por_id as $id => $margen) {

            if (!isset($listas[$id])) {

                throw new AccionIaException(422, 'La lista de precios "' . $margen['nombre'] . '" de la tarjeta ya no existe entre las tuyas. Pedímelo de nuevo.');
            }
        }

        $resultado = [];

        $pisadas = [];

        foreach ((is_array($actuales) ? $actuales : []) as $actual) {

            $actual = self::como_array($actual);

            if (is_null($actual) || !isset($actual['id'])) {

                continue;
            }

            $id = (int) $actual['id'];

            if (isset($por_id[$id])) {

                $resultado[] = self::fila_de_pantalla($listas[$id], (float) $por_id[$id]['margen'], $actual);

                $pisadas[$id] = true;

                continue;
            }

            $resultado[] = $actual;
        }

        foreach ($por_id as $id => $margen) {

            if (!isset($pisadas[$id])) {

                $resultado[] = self::fila_de_pantalla($listas[$id], (float) $margen['margen'], null);
            }
        }

        return $resultado;
    }

    /**
     * Una fila de `price_types` como la manda la ficha para una lista con margen tipeado.
     *
     * `incluir_en_excel_para_clientes`: el del pivote si el artículo ya lo tenía (edición), si no
     * el de la lista, que es lo que pone `set_article_price_types` de la SPA al abrir la ficha.
     *
     * @param  \App\Models\PriceType  $lista
     * @param  float  $margen
     * @param  array|null  $actual
     * @return array
     */
    protected static function fila_de_pantalla(PriceType $lista, $margen, $actual)
    {
        $incluir = !is_null($actual) && isset($actual['pivot']) && is_array($actual['pivot']) && array_key_exists('incluir_en_excel_para_clientes', $actual['pivot'])
            ? $actual['pivot']['incluir_en_excel_para_clientes']
            : $lista->incluir_en_lista_de_precios_de_excel;

        return [
            'id'    => (int) $lista->id,
            'pivot' => [
                'percentage'                     => $margen,
                'setear_precio_final'            => 0,
                'final_price'                    => null,
                'incluir_en_excel_para_clientes' => $incluir,
            ],
        ];
    }

    // =========================================================================================
    // Lo que ve la persona
    // =========================================================================================

    /**
     * Los renglones del alta: uno por lista nombrada ("Margen Minorista" → "30 %").
     *
     * @param  array  $margenes
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    public static function renglones_del_alta(array $margenes)
    {
        $renglones = [];

        foreach ($margenes as $margen) {

            $renglones[] = [
                'etiqueta' => 'Margen ' . $margen['nombre'],
                'valor'    => self::porcentaje($margen['margen']),
            ];
        }

        return $renglones;
    }

    /**
     * El aviso de la tarjeta del alta con las listas que NO se nombraron y el margen con el que
     * quedan, o null si no hay ninguna.
     *
     * 🔴 EN UN ALTA DE ARTÍCULO CON LISTAS, ESTE AVISO VA SIEMPRE, aunque no venga
     * margenes_por_lista: la tarjeta tiene que decir con qué margen queda cada lista. En demo3 la
     * tarjeta decía "Margen de ganancia: 30 %" y las dos listas quedaron en 0 % sin que nada lo
     * mostrara; con este aviso, la persona lee "quedan con su margen por defecto: 0 %" ANTES de
     * confirmar.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $margenes
     * @return string|null
     */
    public static function aviso_del_alta(ContextoDeCargaIa $contexto, array $margenes)
    {
        if (!self::aplica($contexto->owner)) {

            return null;
        }

        $nombradas = [];

        foreach ($margenes as $margen) {

            $nombradas[(int) $margen['price_type_id']] = true;
        }

        $demas = [];

        foreach (self::listas_del_dueno($contexto->owner_id) as $lista) {

            if (isset($nombradas[(int) $lista->id])) {

                continue;
            }

            $demas[] = $lista->name . ' ' . self::porcentaje(is_null($lista->percentage) ? 0 : (float) $lista->percentage);
        }

        if (!count($demas)) {

            return null;
        }

        return (count($nombradas) ? 'Las demás listas quedan' : 'Cada lista queda') . ' con su margen por defecto: ' . implode(', ', $demas) . '.';
    }

    /**
     * La edición: qué listas cambian de verdad y el renglón "antes → después" de cada una.
     *
     * El "antes" es lo que hoy usa el cálculo para ESE artículo: el pivote si existe y no es precio
     * fijo (con porcentaje vacío, el de la lista); "precio fijo $ X" si el pivote está marcado como
     * precio fijo; y si el artículo no tiene pivote con esa lista, el margen por defecto de la lista.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  int  $article_id
     * @param  array  $margenes
     * @return array  ['renglones' => [...], 'cambian' => [margenes que cambian]]
     */
    public static function cambios_de_edicion(ContextoDeCargaIa $contexto, $article_id, array $margenes)
    {
        $pivotes = self::pivotes($article_id, $margenes);

        $listas = self::listas_del_dueno($contexto->owner_id)->keyBy('id');

        $renglones = [];
        $cambian = [];

        foreach ($margenes as $margen) {

            $id = (int) $margen['price_type_id'];

            $pivote = isset($pivotes[$id]) ? $pivotes[$id] : null;

            $por_defecto = isset($listas[$id]) && !is_null($listas[$id]->percentage) ? (float) $listas[$id]->percentage : 0.0;

            $es_precio_fijo = !is_null($pivote) && !empty($pivote->setear_precio_final);

            if ($es_precio_fijo) {

                $antes = 'precio fijo ' . (is_null($pivote->final_price) ? '(sin precio)' : FormatoIaHelper::monto($pivote->final_price));

            } else {

                $efectivo = !is_null($pivote) && !is_null($pivote->percentage) ? (float) $pivote->percentage : $por_defecto;

                if (abs($efectivo - (float) $margen['margen']) < self::TOLERANCIA) {

                    continue;
                }

                $antes = self::porcentaje($efectivo);
            }

            $cambian[] = $margen;

            $renglones[] = [
                'etiqueta' => 'Margen ' . $margen['nombre'],
                'valor'    => $antes . ' → ' . self::porcentaje($margen['margen']),
            ];
        }

        return ['renglones' => $renglones, 'cambian' => $cambian];
    }

    // =========================================================================================
    // Lo que quedó de verdad
    // =========================================================================================

    /**
     * RELEE el pivote después del controller y dice, lista por lista, qué quedó.
     *
     * 🔴 POR QUÉ SE RELEE Y NO SE CONFÍA EN EL 201. El defecto de demo3 fue exactamente un "creado"
     * con `campos_que_no_quedaron: []` y las listas en 0 %: el controller no falla cuando el margen
     * no llega, simplemente calcula con otro. Lo único que prueba que el margen quedó es el pivote
     * con ESE porcentaje y sin precio fijo, y lo único que el modelo tiene que repetir es el precio
     * que quedó escrito ahí.
     *
     * @param  int  $article_id
     * @param  array  $margenes
     * @return array  ['hechos' => string[], 'fallas' => string[]]
     */
    public static function verificar($article_id, array $margenes)
    {
        $pivotes = self::pivotes($article_id, $margenes);

        $hechos = [];
        $fallas = [];

        foreach ($margenes as $margen) {

            $id = (int) $margen['price_type_id'];

            $pivote = isset($pivotes[$id]) ? $pivotes[$id] : null;

            if (is_null($pivote)) {

                $fallas[] = 'el margen de ' . $margen['nombre'] . ' no quedó cargado (la lista no quedó asociada al artículo)';

                continue;
            }

            if (!empty($pivote->setear_precio_final)
                || is_null($pivote->percentage)
                || abs((float) $pivote->percentage - (float) $margen['margen']) >= self::TOLERANCIA) {

                $quedo = !empty($pivote->setear_precio_final)
                    ? 'precio fijo'
                    : (is_null($pivote->percentage) ? 'sin margen' : self::porcentaje($pivote->percentage));

                $fallas[] = 'el margen de ' . $margen['nombre'] . ' quedó en ' . $quedo . ' y no en ' . self::porcentaje($margen['margen']);

                continue;
            }

            $precio = is_null($pivote->final_price) || (float) $pivote->final_price == 0.0
                ? 'sin precio: el artículo no tiene costo'
                : 'precio ' . FormatoIaHelper::monto($pivote->final_price);

            $hechos[] = 'margen ' . self::porcentaje($margen['margen']) . ' en ' . $margen['nombre'] . ' (' . $precio . ')';
        }

        return ['hechos' => $hechos, 'fallas' => $fallas];
    }

    /**
     * Suma lo que quedó de los márgenes al resultado de una EDICIÓN (el alta lo suma
     * AltaDeArticuloConFotoIaHelper::completar(), junto con la foto y el stock).
     *
     * @param  array  $resultado
     * @param  int  $article_id
     * @param  array  $margenes
     * @return array
     */
    public static function completar_resultado(array $resultado, $article_id, array $margenes)
    {
        $verificado = self::verificar($article_id, $margenes);

        $texto = isset($resultado['texto']) ? (string) $resultado['texto'] : '';

        if (count($verificado['hechos'])) {

            $texto .= ', con ' . self::enumerar($verificado['hechos']);
        }

        if (count($verificado['fallas'])) {

            $texto .= ', pero ' . implode('; y ', $verificado['fallas']);
        }

        $resultado['texto'] = $texto;
        $resultado['margenes_por_lista'] = $verificado;

        return $resultado;
    }

    // =========================================================================================
    // que_puedo_cargar
    // =========================================================================================

    /**
     * Lo que que_puedo_cargar de `article` le cuenta al modelo sobre las listas de ESTE negocio:
     * si trabaja con listas, cuáles, su margen por defecto y dónde va el margen. Sin esto el modelo
     * no tiene cómo saber, antes de proponer, si "margen 30" va en percentage_gain o por lista.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @return array
     */
    public static function para_que_puedo_cargar(ContextoDeCargaIa $contexto)
    {
        $owner = $contexto->owner;

        if (self::aplica($owner)) {

            $listas = [];

            foreach (self::listas_del_dueno($contexto->owner_id) as $lista) {

                $listas[] = [
                    'nombre'             => (string) $lista->name,
                    'margen_por_defecto' => self::porcentaje(is_null($lista->percentage) ? 0 : (float) $lista->percentage),
                ];
            }

            return [
                'trabaja_con_listas' => true,
                'el_margen_va'       => 'POR LISTA, en margenes_por_lista de proponer_alta y proponer_edicion. percentage_gain se rechaza: no mueve ningún precio de lista. Si la persona no dijo la lista, preguntala (podés ofrecer "todas").',
                'listas'             => $listas,
            ];
        }

        $motivo = self::motivo_si_no_aplica($owner);

        return [
            'trabaja_con_listas' => !is_null($owner) && UserHelper::uses_listas_de_precio($owner),
            'el_margen_va'       => self::margen_suelto_prohibido($owner) ? $motivo : 'en percentage_gain (margen de ganancia): este negocio no trabaja con listas de precio por artículo.',
        ];
    }

    // =========================================================================================
    // Utilidades
    // =========================================================================================

    /**
     * Los pivotes del artículo con las listas pedidas, leídos por query builder (no por la
     * relación: una relación ya cargada devolvería lo de antes del controller).
     *
     * @param  int  $article_id
     * @param  array  $margenes
     * @return array<int, object>  price_type_id => fila
     */
    protected static function pivotes($article_id, array $margenes)
    {
        $ids = [];

        foreach ($margenes as $margen) {

            $ids[] = (int) $margen['price_type_id'];
        }

        if (!count($ids)) {

            return [];
        }

        $filas = DB::table('article_price_type')
                    ->where('article_id', (int) $article_id)
                    ->whereIn('price_type_id', $ids)
                    ->get(['price_type_id', 'percentage', 'final_price', 'setear_precio_final']);

        $pivotes = [];

        foreach ($filas as $fila) {

            $pivotes[(int) $fila->price_type_id] = $fila;
        }

        return $pivotes;
    }

    /**
     * "30 %", "30,5 %".
     *
     * @param  float|int|string  $valor
     * @return string
     */
    public static function porcentaje($valor)
    {
        return Catalogo::numero((float) $valor) . ' %';
    }

    /**
     * "a", "a y b", "a, b y c".
     *
     * @param  array<int, string>  $partes
     * @return string
     */
    public static function enumerar(array $partes)
    {
        $partes = array_values($partes);

        if (count($partes) <= 2) {

            return implode(' y ', $partes);
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes) . ' y ' . $ultima;
    }

    /**
     * Un margen a partir de lo que mandó el modelo (30, "30", "30%", "30,5 %"), o null.
     *
     * @param  mixed  $valor
     * @return float|null
     */
    public static function a_margen($valor)
    {
        if (is_int($valor) || is_float($valor)) {

            return round((float) $valor, 2);
        }

        if (!is_string($valor)) {

            return null;
        }

        $texto = trim(str_replace(['%', ' '], '', $valor));

        $texto = str_replace(',', '.', $texto);

        if ($texto === '' || !is_numeric($texto)) {

            return null;
        }

        return round((float) $texto, 2);
    }

    /**
     * Minúsculas, sin tildes y con los espacios colapsados.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar($texto)
    {
        $texto = mb_strtolower(trim((string) $texto));

        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /**
     * El texto normalizado sin las PALABRAS_DE_RELLENO.
     *
     * @param  string  $normalizado
     * @return string
     */
    protected static function sin_relleno($normalizado)
    {
        $palabras = [];

        foreach (explode(' ', (string) $normalizado) as $palabra) {

            if ($palabra !== '' && !in_array($palabra, self::PALABRAS_DE_RELLENO, true)) {

                $palabras[] = $palabra;
            }
        }

        return implode(' ', $palabras);
    }

    /**
     * El valor crudo como lista de ítems, o null si no es una lista. Un solo objeto suelto
     * ({lista, margen}) se acepta como lista de uno: el modelo a veces lo manda así.
     *
     * @param  mixed  $crudo
     * @return array|null
     */
    protected static function como_lista($crudo)
    {
        if ($crudo instanceof \stdClass) {

            $crudo = json_decode(json_encode($crudo), true);
        }

        if (!is_array($crudo)) {

            return null;
        }

        if (!count($crudo)) {

            return [];
        }

        $es_lista = array_keys($crudo) === range(0, count($crudo) - 1);

        return $es_lista ? $crudo : [$crudo];
    }

    /**
     * Un ítem como array asociativo, o null.
     *
     * @param  mixed  $item
     * @return array|null
     */
    protected static function como_array($item)
    {
        if ($item instanceof \stdClass) {

            $item = json_decode(json_encode($item), true);
        }

        return is_array($item) ? $item : null;
    }

    /**
     * El primer valor escalar no vacío de esas claves, como texto recortado.
     *
     * @param  array  $item
     * @param  array<int, string>  $claves
     * @return string
     */
    protected static function texto_de(array $item, array $claves)
    {
        foreach ($claves as $clave) {

            if (isset($item[$clave]) && is_scalar($item[$clave]) && trim((string) $item[$clave]) !== '') {

                return trim((string) $item[$clave]);
            }
        }

        return '';
    }

    /**
     * El primer valor presente de esas claves, o null.
     *
     * @param  array  $item
     * @param  array<int, string>  $claves
     * @return mixed
     */
    protected static function valor_de(array $item, array $claves)
    {
        foreach ($claves as $clave) {

            if (array_key_exists($clave, $item) && !is_null($item[$clave])) {

                return $item[$clave];
            }
        }

        return null;
    }

    /**
     * @param  int  $owner_id
     * @return array<int, string>
     */
    protected static function nombres_de_las_listas($owner_id)
    {
        return self::nombres(self::listas_del_dueno($owner_id));
    }

    /**
     * @param  \Illuminate\Support\Collection  $listas
     * @return array<int, string>
     */
    protected static function nombres($listas)
    {
        $nombres = [];

        foreach ($listas as $lista) {

            $nombres[] = (string) $lista->name;
        }

        return $nombres;
    }

    /**
     * @param  int  $owner_id
     * @return array<int, array{nombre: string}>
     */
    protected static function opciones($owner_id)
    {
        return self::opciones_de(self::listas_del_dueno($owner_id));
    }

    /**
     * @param  iterable  $listas
     * @return array<int, array{nombre: string}>
     */
    protected static function opciones_de($listas)
    {
        $opciones = [];

        foreach ($listas as $lista) {

            $opciones[] = ['nombre' => (string) $lista->name];
        }

        return $opciones;
    }

    /**
     * @param  string  $texto
     * @param  array  $candidatas
     * @return array
     */
    protected static function ambigua($texto, array $candidatas)
    {
        return RespuestaDeCargaIa::faltan(
            ['cuál de estas listas de precio es (hay ' . count($candidatas) . ' que encajan con "' . $texto . '")'],
            ['listas' => self::opciones_de($candidatas)]
        );
    }

    /**
     * @param  string  $texto
     * @param  \Illuminate\Support\Collection  $listas
     * @return array
     */
    protected static function no_existe($texto, $listas)
    {
        return RespuestaDeCargaIa::error(
            'No hay ninguna lista de precios que se llame "' . $texto . '". Las listas de este negocio son: ' . implode(', ', self::nombres($listas)) . '.',
            ['listas' => self::opciones_de($listas)]
        );
    }

    /**
     * @param  \App\Models\PriceType  $lista
     * @return array
     */
    protected static function repetida(PriceType $lista)
    {
        return RespuestaDeCargaIa::error('La lista "' . $lista->name . '" vino dos veces en margenes_por_lista: mandala una sola vez, con el margen que va.');
    }

    /**
     * @return string
     */
    protected static function mensaje_de_formato()
    {
        return 'margenes_por_lista tiene que ser una lista de {"lista": "<nombre de la lista>", "margen": <número>}, por ejemplo [{"lista": "Minorista", "margen": 30}].';
    }
}
