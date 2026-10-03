<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\User;

/**
 * ArticleVariantBarCodeHelper
 *
 * Toda la logica del codigo de barras PROPIO de una variante (`article_variants.bar_code`):
 *
 *  - Escanear (contrato C2 de la mision "codigo-de-barras-de-variantes"): resolver el codigo que
 *    llega a `VenderController::search_bar_code` a una variante disponible del comercio.
 *  - Guardar (contrato C1): validar y normalizar el codigo que el comerciante escribe en la grilla
 *    del modal de Variantes (`ArticleVariantController::update`).
 *
 * Por que las dos mitades viven juntas: son las dos puntas de la misma regla. Lo que se acepta al
 * guardar tiene que ser lo que despues se puede escanear sin ambiguedad. Si el guardado dejara
 * repetir un codigo, el escaneo devolveria "la primera" variante que matchee y el comerciante
 * venderia la equivocada sin ningun aviso.
 *
 * Contexto que conviene tener presente:
 *  - `article_variants` NO tiene `user_id`. Las variantes se acotan al comercio por el articulo
 *    (`articles.user_id`), y varios comercios comparten una misma base (ver "Base compartida" del
 *    contexto), asi que dos comercios pueden tener cargado el mismo codigo personalizado sin que
 *    sea un error. Toda consulta de este helper pasa por el articulo del duenio.
 *  - `Article` usa SoftDeletes: un articulo dado de baja no se puede escanear, asi que ni sus
 *    variantes ni su codigo cuentan (el `whereHas('article')` ya los deja afuera).
 *  - El generador (`ArticleVariantGeneratorHelper`) le pone '0' + id a cada variante nueva. Es el
 *    codigo por defecto y al que se vuelve cuando el comerciante vacia el campo.
 *
 * Compatible con PHP 7.4 (sin match, str_contains, nullsafe, argumentos nombrados, etc.).
 */
class ArticleVariantBarCodeHelper
{
    /**
     * Largo maximo del codigo: el de la columna `article_variants.bar_code` (varchar(20)).
     * Sin este tope, MySQL (en modo estricto) tiraria un error 500 en vez de un aviso entendible.
     */
    const LONGITUD_MAXIMA = 20;

    /**
     * Busca la variante DISPONIBLE del comercio cuyo codigo de barras es `$code`.
     *
     * Devuelve `null` (y el llamador sigue con la cadena normal de articulo) si:
     *  - el usuario no tiene la extension `article_variants`: sin ella el comercio no trabaja con
     *    variantes y un codigo de variante olvidado en la base no tiene que resolverse como tal;
     *  - ninguna variante matchea.
     *
     * Orden de busqueda (contrato C2):
     *  1. `bar_code` exacto (el codigo propio de la variante, sea el por defecto o uno cargado).
     *  2. Con la extension `codigos_de_barra_basados_en_numero_interno`, un codigo '0' + id: se
     *     busca la variante por su id. Es el formato con el que se imprimen las etiquetas de las
     *     variantes en los comercios que usan numero interno.
     *
     * En los dos pasos la variante tiene que estar disponible (`oculta = false`, igual que la
     * busqueda por nombre, que no ofrece las ocultas) y ser de un articulo del comercio.
     *
     * La extension se consulta sobre el usuario autenticado (su duenio), igual que el resto de
     * Vender; `$user_id` solo acota los articulos. El controlador pasa siempre el duenio, que es el
     * mismo usuario.
     *
     * @param string|int $code    Codigo escaneado, tal cual llego (sin trim: un lector no agrega espacios).
     * @param int        $user_id Id del duenio de los articulos (`articles.user_id`).
     * @return \App\Models\ArticleVariant|null Variante pelada, sin relaciones cargadas.
     */
    public static function find_available_by_code($code, $user_id)
    {
        // Usuario (su duenio) una sola vez: `hasExtencion` sin usuario lo vuelve a buscar en la base
        // en cada llamada, y este camino es el del escaneo, que se usa en cada venta. Pasandole el
        // mismo modelo, las extensiones se cargan una sola vez para las dos preguntas de abajo.
        $user = UserHelper::user();

        if (!UserHelper::hasExtencion('article_variants', $user)) {
            return null;
        }

        $code = (string) $code;

        // Una variante sin codigo tiene `bar_code` null o vacio: un codigo vacio no puede matchearla.
        if ($code === '') {
            return null;
        }

        // Paso 1: coincidencia exacta con el codigo de la variante. Se ordena por id para que, si
        // hubiera dos variantes del mismo comercio con el mismo codigo (datos cargados antes de que
        // el guardado validara), el resultado sea siempre el mismo y no dependa del motor.
        $variant = self::available_variants_of($user_id)
                        ->where('bar_code', $code)
                        ->orderBy('id')
                        ->first();

        if (!is_null($variant)) {
            return $variant;
        }

        // Paso 2: '0' + id, solo con la extension de numero interno.
        if (UserHelper::hasExtencion('codigos_de_barra_basados_en_numero_interno', $user)) {

            $variant_id = self::variant_id_from_internal_code($code);

            if (!is_null($variant_id)) {
                return self::available_variants_of($user_id)
                            ->where('id', $variant_id)
                            ->first();
            }
        }

        return null;
    }

    /**
     * Valida y normaliza el codigo que el comerciante escribio para una variante.
     *
     * Se llama ANTES de modificar nada: si devuelve error, el controlador responde 422 sin guardar
     * ni el codigo ni el precio ni `oculta`.
     *
     * Reglas, en este orden (contrato C1):
     *  1. trim.
     *  2. Vacio -> se restituye el codigo por defecto '0' + id de la variante.
     *  3. Mas de 20 caracteres -> error.
     *  4. Igual al codigo de OTRA variante del mismo duenio (las ocultas cuentan) -> error.
     *  5. Igual al `bar_code` de un ARTICULO del mismo duenio -> error.
     *  6. Con la extension `codigos_de_barra_basados_en_numero_interno` del duenio: el codigo es el
     *     `num` (entero canonico) de un articulo del duenio -> error.
     *  7. Con la extension `codigo_proveedor_en_vender` del duenio: el codigo es el `provider_code` de
     *     un articulo del duenio, sin distinguir mayusculas -> error.
     *
     * Las reglas 6 y 7 son las de la cadena de escaneo de articulos de `VenderController::search_bar_code`
     * (que campo identifica a un articulo depende de las extensiones). Sin esas extensiones solo
     * cuenta la 5. Cada error dice QUE campo del articulo choca.
     *
     * Las reglas 4 a 7 corren tambien sobre el codigo por defecto restituido: es un codigo como
     * cualquier otro y, aunque es raro, otra variante puede haberlo cargado a mano.
     *
     * El propio codigo de la variante no cuenta como repetido (se excluye por id), asi que volver a
     * guardar el mismo codigo es valido.
     *
     * Por que las ocultas cuentan en la regla 4: el generador crea cada combinacion nueva oculta y
     * el comerciante las habilita despues. Si una oculta pudiera repetir un codigo, al habilitarla
     * quedarian dos variantes disponibles con el mismo codigo.
     *
     * Por que la regla 5 incluye al articulo de la propia variante: el lector resuelve primero la
     * variante, asi que un codigo igual al de su articulo dejaria al articulo sin poder escanearse.
     *
     * El duenio es el del articulo de la variante (no el usuario logueado): es el comercio al que
     * pertenecen los datos contra los que se compara.
     *
     * @param \App\Models\ArticleVariant $variant  Variante que se esta guardando.
     * @param mixed                      $bar_code Valor recibido en el request (texto, numero o null).
     * @return array `['bar_code' => <codigo final>]` o `['error' => <mensaje para el comerciante>]`.
     */
    public static function validate_bar_code_for_update($variant, $bar_code)
    {
        // Solo texto o numero: un arreglo o un objeto no son un codigo y trim() reventaria con un 500.
        if (!is_null($bar_code) && !is_string($bar_code) && !is_int($bar_code) && !is_float($bar_code)) {
            return ['error' => 'El código de barras no es válido: tiene que ser un texto o un número.'];
        }

        // 1. trim. Los middlewares de Laravel ya recortan los strings del request, pero el helper no
        // puede depender de eso (se lo puede llamar desde otro lado).
        $bar_code = trim((string) $bar_code);

        // 2. Vacio: se vuelve al codigo por defecto, el mismo que le pone el generador.
        if ($bar_code === '') {
            $bar_code = '0' . $variant->id;
        }

        // 3. Largo. mb_strlen porque el tope de la columna se cuenta en caracteres, no en bytes.
        if (mb_strlen($bar_code, 'UTF-8') > self::LONGITUD_MAXIMA) {
            return ['error' => 'El código de barras no puede tener más de ' . self::LONGITUD_MAXIMA . ' caracteres.'];
        }

        // Duenio de los datos. withTrashed: el articulo de la variante puede estar dado de baja y
        // igual hay que saber de quien es.
        $article = Article::withTrashed()->find($variant->article_id);

        // Una variante huerfana (sin articulo) no la puede escanear nadie: no hay contra que comparar.
        if (is_null($article)) {
            return ['bar_code' => $bar_code];
        }

        // 4. Otra variante del mismo duenio con el mismo codigo, ocultas incluidas.
        $other_variant = ArticleVariant::where('bar_code', $bar_code)
                                        ->where('id', '<>', $variant->id)
                                        ->whereHas('article', function ($article_query) use ($article) {
                                            $article_query->where('user_id', $article->user_id);
                                        })
                                        ->with('article')
                                        ->orderBy('id')
                                        ->first();

        if (!is_null($other_variant)) {
            return ['error' => 'Ese código de barras ya lo usa la variante "'
                . trim($other_variant->article->name . ' ' . $other_variant->variant_description)
                . '". Cada variante necesita un código propio para poder escanearla.'];
        }

        // 5. Un articulo del mismo duenio con ese codigo (Article ya excluye los dados de baja).
        $article_with_code = Article::where('user_id', $article->user_id)
                                    ->where('bar_code', $bar_code)
                                    ->orderBy('id')
                                    ->first();

        if (!is_null($article_with_code)) {
            return ['error' => 'Ese código de barras ya es el código de barras del artículo "'
                . $article_with_code->name
                . '". Cada variante necesita un código propio para poder escanearla.'];
        }

        // 6 y 7. El campo con el que se escanean los articulos depende de las extensiones del DUENIO
        // (la misma cadena de `VenderController::search_bar_code`): con numero interno, el `num`; con
        // codigo de proveedor, el `provider_code`. Si una variante tuviera ese mismo codigo, el lector
        // la resolveria primero y el articulo dejaria de poder escanearse, igual que con `bar_code`.
        // Se mira al duenio de los articulos (no al usuario logueado), como en el resto de la validacion.
        // Sin ninguna de las dos extensiones solo cuenta `bar_code`, como hasta ahora.
        $owner = User::find($article->user_id);

        if (is_null($owner)) {
            return ['bar_code' => $bar_code];
        }

        // 6. Numero interno: el codigo es todo digitos y es EL numero de un articulo del duenio.
        if (UserHelper::hasExtencion('codigos_de_barra_basados_en_numero_interno', $owner)) {

            $internal_number = self::canonical_integer($bar_code);

            if (!is_null($internal_number)) {
                // Comparacion numerica exacta con un entero, no con el texto: `articles.num` es un
                // entero y MySQL compara '0555' = 555 como verdadero. Un codigo '0555' no es el
                // numero 555 tal como se escanea (ese articulo sigue escaneandose por '555'), asi
                // que solo la forma canonica ('555') choca. No pasar `$bar_code` directo al where.
                $article_with_num = Article::where('user_id', $article->user_id)
                                            ->where('num', $internal_number)
                                            ->orderBy('id')
                                            ->first();

                if (!is_null($article_with_num)) {
                    return ['error' => 'Ese código coincide con el número interno del artículo "'
                        . $article_with_num->name
                        . '". Como en este comercio los artículos se escanean por número interno, ese artículo dejaría de poder escanearse. Elegí otro código para la variante.'];
                }
            }
        }

        // 7. Codigo de proveedor: igual al `provider_code` de un articulo del duenio, sin distinguir
        // mayusculas (asi lo compara el scanner de la SPA). LOWER de los dos lados y no el `=` de
        // MySQL: que sea insensible a mayusculas depende de la collation de la columna, y aca no se
        // puede confiar en eso. Es una consulta de guardado, no del camino caliente del escaneo.
        if (UserHelper::hasExtencion('codigo_proveedor_en_vender', $owner)) {

            $article_with_provider_code = Article::where('user_id', $article->user_id)
                                                    ->whereRaw('LOWER(provider_code) = ?', [mb_strtolower($bar_code, 'UTF-8')])
                                                    ->orderBy('id')
                                                    ->first();

            if (!is_null($article_with_provider_code)) {
                return ['error' => 'Ese código coincide con el código de proveedor del artículo "'
                    . $article_with_provider_code->name
                    . '". Como en este comercio los artículos se escanean por código de proveedor, ese artículo dejaría de poder escanearse. Elegí otro código para la variante.'];
            }
        }

        return ['bar_code' => $bar_code];
    }

    /**
     * Consulta base de las variantes que se pueden vender de un comercio: disponibles
     * (`oculta = false`) y de un articulo del duenio.
     *
     * Por que `whereHas('article')` y no un `whereIn('article_id', ...)` con ids precalculados: es
     * un EXISTS correlacionado contra `article_variants.article_id` (indexado) y hereda gratis el
     * SoftDeletes del articulo. No simplificar a una consulta directa sobre `article_variants`:
     * esa tabla no tiene `user_id` y mezclaria variantes de otros comercios de la misma base.
     *
     * @param int $user_id Id del duenio de los articulos.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function available_variants_of($user_id)
    {
        return ArticleVariant::where('oculta', false)
                                ->whereHas('article', function ($article_query) use ($user_id) {
                                    $article_query->where('user_id', $user_id);
                                });
    }

    /**
     * Si `$code` es el codigo '0' + id de una variante, devuelve ese id; si no, `null`.
     *
     * Solo se acepta la forma canonica: '0' seguido del id sin ceros de relleno ('012' -> 12). Un
     * codigo como '0012' no es el '0' + id de ninguna variante, y la comparacion numerica de MySQL
     * (`id = '012'` da true para el id 12) lo habria resuelto igual: un codigo cualquiera que
     * empieza con 0 podia terminar devolviendo una variante que no era la escaneada. Tampoco se
     * aceptan letras ni espacios por la misma razon.
     *
     * @param string $code Codigo escaneado.
     * @return int|null
     */
    protected static function variant_id_from_internal_code($code)
    {
        // Solo digitos y que empiece con 0 (ctype_digit rechaza vacio, signos, puntos y letras).
        if (!ctype_digit($code) || substr($code, 0, 1) !== '0') {
            return null;
        }

        // Lo que sigue al 0 es el id candidato.
        $candidate = substr($code, 1);

        // Canonico: sin ceros de relleno y mayor a cero.
        $id = self::canonical_integer($candidate);

        if (is_null($id) || $id < 1) {
            return null;
        }

        return $id;
    }

    /**
     * Si `$text` es un entero escrito en su forma canonica (solo digitos, sin ceros de relleno: '555',
     * no '0555'), devuelve ese entero; si no, `null`.
     *
     * Lo usan las dos comparaciones numericas de este helper (el id de '0' + id y el `num` del numero
     * interno) para no depender del cast flojo de MySQL, que da `id = '012'` y `num = '0555'` como
     * verdaderos. Un texto gigante no entra en un entero: el cast se satura y deja de coincidir con
     * el texto, asi que tambien devuelve `null` (no hay un `num` ni un id tan grande).
     *
     * @param string $text Texto a evaluar.
     * @return int|null
     */
    protected static function canonical_integer($text)
    {
        // ctype_digit rechaza vacio, signos, puntos, espacios y letras.
        if (!ctype_digit($text) || $text !== (string) (int) $text) {
            return null;
        }

        return (int) $text;
    }
}
