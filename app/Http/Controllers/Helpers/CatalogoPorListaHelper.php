<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\PriceType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Catálogo de la tienda por lista de precios (misión catalogo-por-lista-tienda, 5/10/2026).
 *
 * EL PEDIDO: Ferretotal vende en su e-commerce a minoristas y a mayoristas, cada uno con su lista
 * de precios, y hay artículos que solo quiere vender minorista. Todos los artículos tienen precio
 * en las dos listas (el mayorista de un artículo "solo minorista" queda a costo), así que "tiene
 * precio en la lista" NO sirve para decidir qué se muestra: hace falta una decisión explícita.
 *
 * EL DISEÑO, en dos columnas aditivas y NULL por defecto (migraciones del 5/10/2026):
 *   - `price_types.catalogo_restringido_en_tienda`: el interruptor de la LISTA. `= 1` → la lista
 *     está restringida: el comprador cuya lista es ésta ve en la tienda SOLO los artículos
 *     habilitados para ella.
 *   - `article_price_type.visible_en_tienda`: por ARTÍCULO y por LISTA. `= 1` → habilitado.
 *
 * Este repo (el ERP) es donde el comerciante DECIDE; tienda-api es donde se APLICA, leyendo las
 * mismas dos columnas de la base compartida. Por eso acá solo se escribe y se cuenta.
 *
 * 🔴 SIEMPRE `= 1`, NUNCA `!= 0`. NULL y 0 significan lo mismo ("no habilitado" / "sin
 * restricción"): una lista nueva nace sin restricción y un artículo nace sin habilitar (decisión
 * de Lucas). Comparar con `!= 0` haría que el NULL de los artículos que nadie tocó se lea como
 * "habilitado" en algunos lados y como "no habilitado" en otros.
 *
 * 🔴 EL PIVOTE NO TIENE ÍNDICE ÚNICO por (article_id, price_type_id): un artículo puede tener la
 * misma lista atada dos veces. Por eso todo lo de acá pregunta "¿existe una fila habilitada?"
 * (EXISTS) y escribe sobre TODAS las filas del par, nunca sobre "la" fila.
 *
 * Lo que vive acá:
 *   - sanear_booleano(): el saneo a 0/1 de lo que llega del request (ficha, lista, masiva).
 *   - interruptor_a_escribir_en_update(): qué hay que escribir (o no) del interruptor de la lista
 *     en el PUT de PriceTypeController.
 *   - interpretar_si_no(): el Sí/No de una celda del Excel de la importación (1, 0 o no informado).
 *   - contar_habilitados(): el contador "X habilitados de Y" del ABM de la lista.
 *   - aplicar_en_masiva() / revertir_en_masiva(): la clave `visible_en_tienda_lista_{id}` de la
 *     actualización masiva de artículos y su reversión.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class CatalogoPorListaHelper
{
    /**
     * Clave del interruptor en el payload de la lista (`price_types.catalogo_restringido_en_tienda`).
     *
     * @var string
     */
    const CLAVE_DEL_INTERRUPTOR = 'catalogo_restringido_en_tienda';

    /**
     * Lo que vale como un "Sí" en una celda del Excel, ya en minúsculas (ver interpretar_si_no()).
     * Es la misma lista que reconoce el parser de las columnas Sí/No del importador
     * (ProcessRow::get_boolean_column()).
     *
     * @var string[]
     */
    const VALORES_SI = ['si', 'sí', 's', '1', 'yes', 'y', 'true', 'verdadero'];

    /**
     * Lo que vale como un "No" en una celda del Excel, ya en minúsculas (ver interpretar_si_no()).
     *
     * @var string[]
     */
    const VALORES_NO = ['no', 'n', '0', 'false', 'falso'];

    /**
     * Prefijo de la clave de la actualización masiva: `visible_en_tienda_lista_{price_type_id}`.
     * Es el contrato con empresa-spa (`opciones-filtrados-seleccion/Update.vue` arma una tarjeta
     * por cada lista restringida con esta clave). No se cambia sin cambiar los dos lados.
     *
     * @var string
     */
    const PREFIJO_CLAVE_DE_MASIVA = 'visible_en_tienda_lista_';

    /**
     * Expresión de la clave completa de la masiva; el grupo 1 es el id de la lista.
     *
     * @var string
     */
    const REGEX_CLAVE_DE_MASIVA = '/^visible_en_tienda_lista_(\d+)$/';

    /**
     * Sanea un valor booleano tal como puede llegar en un request (JSON, form-data o la cola de
     * la masiva) a 1, 0 o null.
     *
     * null significa "no es un valor válido": quien llama NO escribe nada. Eso incluye '' — que es
     * lo que el SPA manda en el "No modificar" de la masiva y lo que en MySQL estricto revienta al
     * guardarse en un tinyint — y cualquier texto que no sea un sí o un no reconocible.
     *
     * @param  mixed $valor
     * @return int|null  1, 0 o null si no es un valor válido.
     */
    public static function sanear_booleano($valor)
    {
        if ($valor === true || $valor === 1 || $valor === '1' || $valor === 'true') {
            return 1;
        }

        if ($valor === false || $valor === 0 || $valor === '0' || $valor === 'false') {
            return 0;
        }

        return null;
    }

    /**
     * El interruptor "Catálogo restringido en la tienda" de una lista tal como llega en el request
     * de PriceTypeController (store/update), saneado a 1 o 0: 1 solo con un sí explícito (1, '1',
     * true, 'true'); cualquier otra cosa —0, null, '', un texto— es 0. A diferencia de
     * sanear_booleano() acá no hay "no escribir": quien llama ya decidió escribir porque el request
     * trae la clave, y una lista solo puede quedar restringida o no.
     *
     * @param  mixed $valor
     * @return int  1 o 0.
     */
    public static function interruptor_de_lista($valor)
    {
        return self::sanear_booleano($valor) === 1 ? 1 : 0;
    }

    /**
     * Qué hay que escribir del interruptor de la lista en el PUT de PriceTypeController@update:
     * 1 o 0, o null = NO escribir nada (el valor guardado se conserva).
     *
     *  - Sin la clave en el request (un SPA viejo, cacheado en la PWA, que no conoce el
     *    interruptor): no se escribe. Si se escribiera, le APAGARÍA la restricción a un comercio
     *    que la prendió desde otra pestaña ya actualizada.
     *  - Con un valor explícito (0, 1, true, false, '1', 'true', ...): se escribe saneado a 1 o 0.
     *  - 🔴 Con la clave en `null`: NO se escribe (M2 de la revisión independiente, 6/10/2026). El ABM
     *    de la SPA reenvía `{...this.model}` entero al guardar, y el JSON de una lista cuyo
     *    interruptor todavía es NULL —o que se cargó antes de que el dueño lo prendiera desde otra
     *    pestaña— lleva la clave en `null`: eso es un eco de lo que se cargó, no un "apagalo". Antes
     *    escribía 0 y los mayoristas volvían a ver todo el catálogo sin que nadie lo notara.
     *
     * ⚠️ Un `''` explícito sigue apagando (lo fija 1_Interruptor_de_la_lista_Test), pero llega acá
     * como `null`: el middleware global `ConvertEmptyStringsToNull` (Kernel.php:23) lo convierte
     * antes de que el request llegue al controlador, también adentro de `$request->json()`, así
     * que con `$request->input()` ya no se lo puede distinguir del eco de un NULL. Por eso, cuando
     * llega `null`, se mira el cuerpo JSON CRUDO (mismo recurso que usa
     * PdfColumnProfileController::page_layout_from_request()): si ahí venía `""`, es un vacío
     * explícito y apaga; si venía `null`, es el eco y no se escribe. En un request que no es JSON no
     * hay cuerpo crudo que mirar y el `null` cuenta siempre como "no informado". No "simplificar"
     * esto a `!is_null($request->input(...))`: rompe el caso del vacío.
     *
     * @param  \Illuminate\Http\Request $request
     * @return int|null  1, 0 o null = no escribir.
     */
    public static function interruptor_a_escribir_en_update(Request $request)
    {
        $clave = self::CLAVE_DEL_INTERRUPTOR;

        if (!$request->has($clave)) {
            return null;
        }

        $valor = $request->input($clave);

        if (!is_null($valor)) {
            return self::interruptor_de_lista($valor);
        }

        return self::la_clave_vino_vacia_en_el_cuerpo($request, $clave) ? 0 : null;
    }

    /**
     * Si el cuerpo JSON CRUDO del request trae la clave con `""` (y no con `null`), antes de que
     * `ConvertEmptyStringsToNull` la convirtiera. Ver interruptor_a_escribir_en_update().
     *
     * @param  \Illuminate\Http\Request $request
     * @param  string                   $clave
     * @return bool
     */
    protected static function la_clave_vino_vacia_en_el_cuerpo(Request $request, $clave)
    {
        if (!$request->isJson()) {
            return false;
        }

        $cuerpo = json_decode((string) $request->getContent(), true);

        return is_array($cuerpo) && array_key_exists($clave, $cuerpo) && $cuerpo[$clave] === '';
    }

    /**
     * Lo que dice una celda Sí/No del Excel: 1, 0 o null = no informado. Es el parser de la columna
     * `visible_en_tienda_<lista>` de la importación (ProcessRow::leer_visible_en_tienda()).
     *
     *  - Un sí reconocible (VALORES_SI: si, sí, s, 1, yes, y, true, verdadero) → 1.
     *  - Un no reconocible (VALORES_NO: no, n, 0, false, falso) → 0.
     *  - 🔴 TODO LO DEMÁS → null, y quien llama NO escribe nada: la celda vacía, un typo ("Sii"), una
     *    "x", un número cualquiera, el texto de una columna mal mapeada. Antes lo que no era un sí
     *    valía 0 (como la columna `setear_precio_final_<lista>`), y reimportar una planilla con un
     *    typo DESHABILITABA artículos de la tienda sin avisar (B1 de la revisión independiente,
     *    6/10/2026). Acá no se puede imitar a `setear_precio_final_<lista>`: ese 0 es "no fijar el
     *    precio" y sobre el margen no hace daño; este 0 le saca un artículo a un comprador.
     *
     * Sin importar mayúsculas ("SÍ" con la tilde en mayúscula también es un sí: por eso
     * mb_strtolower y no strtolower, que en PHP 7.4 no toca las letras acentuadas) y con los
     * espacios de alrededor recortados, incluido el espacio de no separación (NBSP, U+00A0) que
     * trae lo copiado de una web y que trim() no recorta. Una celda ya numérica (1, 0, 1.0) vale
     * como su texto.
     *
     * No se reusa ProcessRow::get_boolean_column(): devuelve int (no puede devolver null), usa
     * strtolower y no recorta el NBSP — con "SÍ" no andaría.
     *
     * @param  mixed $valor  El texto o número de la celda, o null si la columna no está mapeada.
     * @return int|null  1, 0 o null = no informado.
     */
    public static function interpretar_si_no($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? 1 : 0;
        }

        if (!is_scalar($valor)) {
            return null;
        }

        // \p{Z} son los espacios Unicode (NBSP incluido); \s los de siempre (tab, salto de línea).
        $texto = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', (string) $valor);

        // preg_replace da null con un texto que no es UTF-8 válido: no se puede leer, no se informa.
        if (is_null($texto)) {
            return null;
        }

        $normalizado = mb_strtolower($texto, 'UTF-8');

        if (in_array($normalizado, self::VALORES_SI, true)) {
            return 1;
        }

        if (in_array($normalizado, self::VALORES_NO, true)) {
            return 0;
        }

        return null;
    }

    /**
     * El contador "X habilitados de Y" del ABM de la lista (contrato C2,
     * `GET api/price-type/{id}/habilitados-en-tienda`).
     *
     *  - `total`: los artículos vivos (sin soft delete) del dueño de la lista.
     *  - `habilitados`: de ésos, los que tienen al menos UNA fila de pivote con esta lista y
     *    `visible_en_tienda = 1`. Con EXISTS y no con un JOIN + COUNT: el pivote puede tener la
     *    misma lista atada dos veces, y un artículo se cuenta una sola vez (igual que la tienda
     *    lo muestra una sola vez).
     *
     * Quien llama ya verificó que la lista es del dueño logueado (si no, es 404 y no se cuenta
     * nada). Los dos conteos van por el dueño DE LA LISTA (`price_types.user_id`), que es a nombre
     * de quien están los artículos.
     *
     * @param  \App\Models\PriceType $price_type
     * @return array{habilitados: int, total: int}
     */
    public static function contar_habilitados($price_type)
    {
        // Id de la lista, para el closure del EXISTS.
        $price_type_id = (int) $price_type->id;

        // Article usa SoftDeletes: el builder ya deja afuera los borrados.
        $total = Article::where('user_id', $price_type->user_id)->count();

        $habilitados = Article::where('user_id', $price_type->user_id)
                                ->whereExists(function ($sub) use ($price_type_id) {
                                    $sub->select(DB::raw(1))
                                        ->from('article_price_type')
                                        ->whereColumn('article_price_type.article_id', 'articles.id')
                                        ->where('article_price_type.price_type_id', $price_type_id)
                                        ->where('article_price_type.visible_en_tienda', 1);
                                })
                                ->count();

        return [
            'habilitados' => (int) $habilitados,
            'total'       => (int) $total,
        ];
    }

    /**
     * Si una clave del formulario de la masiva (o del historial de una masiva, al revertir) es la
     * de "visible en la tienda para la lista X".
     *
     * @param  mixed $clave
     * @return bool
     */
    public static function es_clave_de_masiva($clave)
    {
        return is_string($clave) && preg_match(self::REGEX_CLAVE_DE_MASIVA, $clave) === 1;
    }

    /**
     * El id de lista que trae una clave `visible_en_tienda_lista_{id}`, o null si la clave no
     * tiene esa forma.
     *
     * @param  mixed $clave
     * @return int|null
     */
    public static function lista_de_la_clave_de_masiva($clave)
    {
        if (!is_string($clave) || preg_match(self::REGEX_CLAVE_DE_MASIVA, $clave, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Aplica la clave `visible_en_tienda_lista_{id}` de una actualización masiva a UN artículo, y
     * devuelve el cambio con la misma forma que MasiveUpdateHelper::apply_form_change() (o null si
     * no hubo cambio o no corresponde aplicar).
     *
     * Reglas:
     *  - Solo con valor explícito 0 o 1 (el "No modificar" del SPA no llega, y si llegara como ''
     *    no se aplica).
     *  - La lista tiene que ser del dueño de la masiva. 🔴 En la cola NO hay sesión: el dueño es el
     *    `$owner` que ya resolvió process_update() (`User::find($masive_update->user_id)`), nunca
     *    `UserHelper::userId()`, que en el worker no tiene a quién mirar. Una lista ajena o que ya
     *    no existe se ignora y queda en el log: la masiva sigue con el resto.
     *  - El artículo también tiene que ser de ese dueño: la selección manual de la masiva resuelve
     *    los artículos por id sin mirar el dueño, y acá se escribe un pivote.
     *  - "Cambió" se mide en VISIBILIDAD, no en el valor crudo: NULL y 0 son los dos "no
     *    habilitado", así que un "No" sobre un artículo que nunca se habilitó no escribe nada ni
     *    aparece en el historial. Lo que sí se escribe, se registra con el valor viejo EXACTO
     *    (NULL, 0 o 1) para que la reversión deje la fila como estaba.
     *  - Se escribe con syncWithoutDetaching + updateExistingPivot: si el artículo no tenía la lista
     *    atada, se ata (con el resto de las columnas en NULL = margen por defecto de la lista,
     *    igual que el alta de una lista desde la ficha); si la tenía atada dos veces, se escriben
     *    las dos filas.
     *
     * @param  mixed                  $model  El artículo (cualquier otro modelo se ignora).
     * @param  array                  $form   ['type', 'key', 'value'] tal como viene del SPA.
     * @param  \App\Models\User|null  $owner  Dueño de la masiva, resuelto una vez por corrida.
     * @return array|null  ['prop_key', 'old_value', 'new_value', 'operation', 'form_key'] o null.
     */
    public static function aplicar_en_masiva($model, array $form, $owner = null)
    {
        $price_type_id = self::lista_de_la_clave_de_masiva(isset($form['key']) ? $form['key'] : null);

        if (is_null($price_type_id) || !($model instanceof Article)) {
            return null;
        }

        /*
         * Mismo criterio que checkbox_value_means_modify() de la masiva: solo 0 o 1 explícitos
         * (entero o string). Un booleano de JSON también se acepta: es la misma intención.
         */
        $nuevo = self::sanear_booleano(isset($form['value']) ? $form['value'] : null);

        if (is_null($nuevo)) {
            return null;
        }

        // Dueño de la masiva; sin él (llamada fuera de process_update) se toma el del artículo.
        $owner_id = !is_null($owner) ? (int) $owner->id : (int) $model->user_id;

        if ((int) $model->user_id !== $owner_id) {
            Log::warning('CatalogoPorListaHelper: masiva sobre el articulo '.$model->id.' de otro dueño; no se toca su visibilidad en la tienda.');
            return null;
        }

        if (!self::lista_es_del_dueno($price_type_id, $owner_id)) {
            Log::warning('CatalogoPorListaHelper: la lista '.$price_type_id.' no existe o no es del dueño '.$owner_id.'; se ignora la clave de la masiva.');
            return null;
        }

        // Valor viejo EXACTO del par (puede no haber fila): es lo que la reversión va a restaurar.
        $viejo = self::visible_actual($model->id, $price_type_id);

        // Cambio medido en visibilidad: NULL y 0 son lo mismo para la tienda.
        if (($viejo === 1) === ($nuevo === 1)) {
            return null;
        }

        self::escribir_visible($model, $price_type_id, $nuevo);

        $clave = $form['key'];

        return [
            'prop_key'  => $clave,
            'old_value' => $viejo,
            'new_value' => $nuevo,
            'operation' => 'set',
            'form_key'  => $clave,
        ];
    }

    /**
     * La rama espejo de aplicar_en_masiva() para la reversión: vuelve a escribir en el pivote el
     * valor que el artículo tenía antes de la masiva (NULL, 0 o 1).
     *
     * 🔴 Sin esta rama, revert_article_pivot_changes() hacía `$model->visible_en_tienda_lista_5 =
     * ...` y `$model->save()`: "Unknown column" y la reversión entera en fallo.
     *
     * Si la lista ya no existe (se borró después de la masiva, y con ella sus filas de pivote) o
     * dejó de ser del dueño, no hay nada que restaurar: se saltea y queda en el log.
     *
     * @param  \App\Models\Article    $model     El artículo, ya acotado al dueño de la masiva.
     * @param  string                 $prop_key  `visible_en_tienda_lista_{id}`.
     * @param  mixed                  $viejo     El `old` guardado en el historial de la masiva.
     * @param  \App\Models\User|null  $owner     Dueño de la masiva.
     * @return array|null  ['old', 'new', 'operation' => 'revert'] para el historial, o null.
     */
    public static function revertir_en_masiva($model, $prop_key, $viejo, $owner = null)
    {
        $price_type_id = self::lista_de_la_clave_de_masiva($prop_key);

        if (is_null($price_type_id) || !($model instanceof Article)) {
            return null;
        }

        $owner_id = !is_null($owner) ? (int) $owner->id : (int) $model->user_id;

        if (!self::lista_es_del_dueno($price_type_id, $owner_id)) {
            Log::warning('CatalogoPorListaHelper: no se revierte la visibilidad del articulo '.$model->id.' en la lista '.$price_type_id.': la lista ya no existe o no es del dueño.');
            return null;
        }

        // El old del historial, saneado: NULL se restaura como NULL (nunca como 0).
        $a_restaurar = is_null($viejo) ? null : self::sanear_booleano($viejo);

        if (!is_null($viejo) && is_null($a_restaurar)) {
            // Un old ilegible en el historial no se escribe: mejor no tocar que inventar.
            return null;
        }

        $antes_de_revertir = self::visible_actual($model->id, $price_type_id);

        self::escribir_visible($model, $price_type_id, $a_restaurar);

        return [
            'old'       => $antes_de_revertir,
            'new'       => $a_restaurar,
            'operation' => 'revert',
        ];
    }

    /**
     * Si la lista existe y es del dueño indicado.
     *
     * @param  int $price_type_id
     * @param  int $owner_id
     * @return bool
     */
    protected static function lista_es_del_dueno($price_type_id, $owner_id)
    {
        return PriceType::where('id', $price_type_id)
                        ->where('user_id', $owner_id)
                        ->exists();
    }

    /**
     * El valor de `visible_en_tienda` del par (artículo, lista), resumido para un pivote que puede
     * tener filas duplicadas: 1 si ALGUNA fila está habilitada (es lo que la tienda mira), si no 0
     * si alguna tiene 0, si no null (sin fila, o todas en NULL).
     *
     * @param  int $article_id
     * @param  int $price_type_id
     * @return int|null
     */
    protected static function visible_actual($article_id, $price_type_id)
    {
        $valores = DB::table('article_price_type')
                        ->where('article_id', $article_id)
                        ->where('price_type_id', $price_type_id)
                        ->pluck('visible_en_tienda')
                        ->all();

        $hay_cero = false;

        foreach ($valores as $valor) {

            if (!is_null($valor) && (int) $valor === 1) {
                return 1;
            }

            if (!is_null($valor) && (int) $valor === 0) {
                $hay_cero = true;
            }
        }

        return $hay_cero ? 0 : null;
    }

    /**
     * Escribe `visible_en_tienda` en el pivote (artículo, lista): ata la lista si no estaba y
     * actualiza TODAS las filas del par (updateExistingPivot no distingue duplicados).
     *
     * @param  \App\Models\Article $model
     * @param  int                 $price_type_id
     * @param  int|null            $valor  1, 0 o null.
     * @return void
     */
    protected static function escribir_visible($model, $price_type_id, $valor)
    {
        $model->price_types()->syncWithoutDetaching([$price_type_id]);

        $model->price_types()->updateExistingPivot($price_type_id, [
            'visible_en_tienda' => $valor,
        ]);
    }
}
