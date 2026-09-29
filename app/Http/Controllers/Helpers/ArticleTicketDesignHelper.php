<?php

namespace App\Http\Controllers\Helpers;

use App\Models\ArticleTicketDesign;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de los "Diseños de etiquetas de góndola" (misión disenos-etiquetas-gondola, 29/9/2026)
 * que comparten el controller (`ArticleTicketDesignController`), el seeder
 * (`ArticleTicketDesignSeeder`), el alta de listas de precios (`PriceTypeController@store`) y el PDF
 * (`Pdf\ArticleTicket\ArticleTicketDesignPdf`).
 *
 * Tres responsabilidades:
 *
 *   1. El catálogo de tipos de campo (§3.4 del plan). A diferencia de Vender, ACÁ la API sí conoce
 *      el catálogo: es la que imprime, así que es la fuente de verdad. El SPA lo espeja para el
 *      editor (`src/components/abm/disenos-de-etiquetas/catalogo.js`).
 *   2. La geometría y el "diseño de siempre" como datos (§3.3 y §3.7): la etiqueta que imprimía
 *      `ArticleTicketPdf` hasta hoy, con sus coordenadas exactas.
 *   3. La normalización del JSON que manda el SPA, y el alta idempotente de los diseños que genera
 *      el sistema (uno por lista de precios, o uno genérico).
 *
 * PHP 7.4 estricto: ninguna sintaxis de PHP 8 en ningún camino de este archivo.
 */
class ArticleTicketDesignHelper
{
    /** Versión del formato del JSON. */
    const VERSION_DEL_FORMATO = 1;

    /** Nombre del diseño genérico que se crea a un dueño que no trabaja con listas de precios. */
    const NOMBRE_GENERICO = 'Etiqueta de góndola';

    /*
     * ---------------------------------------------------------------------------------------
     *  Geometría fija de la hoja (§3.3)
     * ---------------------------------------------------------------------------------------
     */

    /** Hoja A4 vertical, en mm. */
    const ANCHO_HOJA = 210;
    const ALTO_HOJA = 297;

    /** Margen de la hoja, en mm (el mismo de `ArticleTicketPdf`). */
    const MARGEN = 5;

    /** Etiquetas a lo ancho. */
    const COLUMNAS_MINIMO = 1;
    const COLUMNAS_MAXIMO = 4;

    /** Etiquetas a lo alto (informativo: el alto real lo da `alto_mm`). */
    const FILAS_MINIMO = 1;
    const FILAS_MAXIMO = 20;

    /** Alto de la etiqueta, en mm. */
    const ALTO_MINIMO = 10;
    const ALTO_MAXIMO = 287;

    /** Tamaño de letra, en pt. */
    const TAMANO_MINIMO = 5;
    const TAMANO_MAXIMO = 120;

    /** Lado mínimo de un campo, en mm. */
    const LADO_MINIMO = 2;

    /** Máximo de campos por etiqueta. Los que sobran se descartan (se conservan los primeros). */
    const MAXIMO_DE_ELEMENTOS = 40;

    /** Largo máximo del texto de un `texto_fijo`. */
    const LARGO_MAXIMO_DEL_TEXTO = 200;

    /** Patrón del `id` de un campo. La `D` hace que `$` no acepte un salto de línea final. */
    const PATRON_ID = '/^[a-z0-9_]{1,40}$/D';

    /** Tolerancia para comparar milímetros ya redondeados a un decimal. */
    const EPSILON = 0.0001;

    /*
     * ---------------------------------------------------------------------------------------
     *  Catálogo de tipos (§3.4)
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Cada tipo con lo que toma al agregarse (o cuando el SPA manda un campo sin ese dato):
     * `w`, `h` en mm, `tamano` en pt, `negrita`, `saltos_de_linea`, `alineacion`.
     *
     * 🔴 No se incluyen costos ni márgenes: una etiqueta de góndola la ve el cliente final.
     *
     * @var array
     */
    const CATALOGO = array(
        'nombre'               => array('w' => 60, 'h' => 15, 'tamano' => 12, 'negrita' => true,  'saltos_de_linea' => true,  'alineacion' => 'L'),
        'precio_final'         => array('w' => 60, 'h' => 13, 'tamano' => 33, 'negrita' => true,  'saltos_de_linea' => false, 'alineacion' => 'R'),
        'precio_lista'         => array('w' => 60, 'h' => 10, 'tamano' => 24, 'negrita' => true,  'saltos_de_linea' => false, 'alineacion' => 'R'),
        'codigo_barras_imagen' => array('w' => 50, 'h' => 6,  'tamano' => 8,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'L'),
        'codigo_barras_texto'  => array('w' => 50, 'h' => 5,  'tamano' => 8,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'C'),
        'codigo_proveedor'     => array('w' => 40, 'h' => 5,  'tamano' => 9,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'L'),
        'codigo_interno'       => array('w' => 30, 'h' => 5,  'tamano' => 9,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'L'),
        'categoria'            => array('w' => 40, 'h' => 5,  'tamano' => 9,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'L'),
        'sub_categoria'        => array('w' => 40, 'h' => 5,  'tamano' => 9,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'L'),
        'marca'                => array('w' => 40, 'h' => 5,  'tamano' => 9,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'L'),
        'proveedor'            => array('w' => 40, 'h' => 5,  'tamano' => 9,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'L'),
        'descripcion'          => array('w' => 60, 'h' => 10, 'tamano' => 8,  'negrita' => false, 'saltos_de_linea' => true,  'alineacion' => 'L'),
        'unidad_medida'        => array('w' => 20, 'h' => 5,  'tamano' => 9,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'L'),
        'stock'                => array('w' => 20, 'h' => 5,  'tamano' => 9,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'L'),
        'imagen'               => array('w' => 20, 'h' => 20, 'tamano' => 8,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'C'),
        'fecha_impresion'      => array('w' => 15, 'h' => 5,  'tamano' => 8,  'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'C'),
        'texto_fijo'           => array('w' => 30, 'h' => 6,  'tamano' => 10, 'negrita' => true,  'saltos_de_linea' => false, 'alineacion' => 'L'),
    );

    /** Tipos que llevan `rotulo` (anteponer el nombre de la lista, o "Precio: "). */
    const TIPOS_CON_ROTULO = array('precio_final', 'precio_lista');

    /** Alineaciones válidas. */
    const ALINEACIONES = array('L', 'C', 'R');

    /*
     * ---------------------------------------------------------------------------------------
     *  Geometría
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Ancho de una etiqueta, en mm, sin redondear: `(210 - 10) / columnas`. Es el que usa el PDF
     * para ubicar cada etiqueta en la hoja.
     *
     * @param  int  $columnas
     * @return float
     */
    static function ancho_etiqueta($columnas)
    {
        $columnas = max(self::COLUMNAS_MINIMO, min(self::COLUMNAS_MAXIMO, (int) $columnas));

        return (self::ANCHO_HOJA - (self::MARGEN * 2)) / $columnas;
    }

    /**
     * Ancho de una etiqueta redondeado a un decimal: contra este se acotan los campos del diseño
     * (el diseño de siempre, de 3 columnas, mide 66,7).
     *
     * @param  int  $columnas
     * @return float
     */
    static function ancho_etiqueta_redondeado($columnas)
    {
        return round(self::ancho_etiqueta($columnas), 1);
    }

    /**
     * Cuántas filas de etiquetas entran en la hoja con ese alto: `floor((297 - 10) / alto_mm)`,
     * nunca menos de 1.
     *
     * @param  float  $alto_mm
     * @return int
     */
    static function filas_por_hoja($alto_mm)
    {
        $alto_mm = (float) $alto_mm;

        if ($alto_mm <= 0) {
            return 1;
        }

        /* El + EPSILON evita que 287 / 41 = 6,99999... por coma flotante dé una fila de menos. */
        $filas = (int) floor(((self::ALTO_HOJA - (self::MARGEN * 2)) / $alto_mm) + self::EPSILON);

        return max(1, $filas);
    }

    /**
     * El alto sugerido al elegir cuántas filas entran: `floor(((297 - 10) / filas) * 10) / 10`.
     *
     * @param  int  $filas
     * @return float
     */
    static function alto_para_filas($filas)
    {
        $filas = max(self::FILAS_MINIMO, min(self::FILAS_MAXIMO, (int) $filas));

        return floor(((self::ALTO_HOJA - (self::MARGEN * 2)) / $filas) * 10) / 10;
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  El diseño de siempre (§3.7)
     * ---------------------------------------------------------------------------------------
     */

    /**
     * La etiqueta que imprimía `ArticleTicketPdf` hasta el 29/9/2026, como datos: A4, 3 por ancho,
     * 40 mm de alto (7 filas), con marco, y sus cinco campos en las coordenadas exactas de ese PDF
     * (el ancho de 66,67 queda en 66,7 por el redondeo a un decimal).
     *
     * Con `$price_type_id` el precio es el de esa lista (`precio_lista`); sin él, `precio_final`.
     *
     * 🔴 Es la constante ÚNICA del diseño de siempre en la API. El SPA tiene su espejo en
     * `diseno_actual.js` para "Nuevo diseño" y "Restablecer el diseño de siempre".
     *
     * @param  int|null  $price_type_id
     * @return array
     */
    static function diseno_actual($price_type_id = null)
    {
        $ancho = self::ancho_etiqueta_redondeado(3);

        /* El ancho de la imagen del código de barras en ArticleTicketPdf: ticket_w - 15. */
        $ancho_codigo = round(self::ancho_etiqueta(3) - 15, 1);

        if (is_null($price_type_id)) {
            $precio = array(
                'id' => 'e1', 'tipo' => 'precio_final',
                'x' => 0, 'y' => 0, 'w' => $ancho, 'h' => 13,
                'tamano' => 33, 'negrita' => true, 'saltos_de_linea' => false, 'alineacion' => 'R',
                'rotulo' => false,
            );
        } else {
            $precio = array(
                'id' => 'e1', 'tipo' => 'precio_lista',
                'x' => 0, 'y' => 0, 'w' => $ancho, 'h' => 13,
                'tamano' => 33, 'negrita' => true, 'saltos_de_linea' => false, 'alineacion' => 'R',
                'price_type_id' => (int) $price_type_id,
                'rotulo' => false,
            );
        }

        return array(
            'version'   => self::VERSION_DEL_FORMATO,
            'columnas'  => 3,
            'filas'     => 7,
            'alto_mm'   => 40,
            'marco'     => true,
            'elementos' => array(
                $precio,
                array(
                    'id' => 'e2', 'tipo' => 'nombre',
                    'x' => 0, 'y' => 13, 'w' => $ancho, 'h' => 15,
                    'tamano' => 12, 'negrita' => true, 'saltos_de_linea' => true, 'alineacion' => 'L',
                ),
                array(
                    'id' => 'e3', 'tipo' => 'codigo_barras_imagen',
                    'x' => 1, 'y' => 28, 'w' => $ancho_codigo, 'h' => 6,
                    'tamano' => 8, 'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'L',
                ),
                array(
                    'id' => 'e4', 'tipo' => 'codigo_barras_texto',
                    'x' => 0, 'y' => 34, 'w' => $ancho_codigo, 'h' => 5,
                    'tamano' => 8, 'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'C',
                ),
                array(
                    'id' => 'e5', 'tipo' => 'fecha_impresion',
                    'x' => $ancho_codigo, 'y' => 34, 'w' => 15, 'h' => 5,
                    'tamano' => 8, 'negrita' => false, 'saltos_de_linea' => false, 'alineacion' => 'C',
                ),
            ),
        );
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Normalización (§3.3)
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Normaliza el JSON de un diseño para que no entre basura a la base ni al PDF.
     *
     *   - Números fuera de rango se acotan; lo que no es número toma el valor por defecto.
     *   - Tipos desconocidos se descartan. Tope de 40 campos.
     *   - `x, y >= 0`, `w, h >= 2`, y el campo se recorta para no salirse de la etiqueta.
     *   - `id` inválido o repetido se regenera.
     *   - `precio_lista` sin una lista DEL DUEÑO se descarta.
     *   - Claves extra se ignoran. Los textos se recortan pero no se escapan (el PDF no interpreta
     *     HTML, y el SPA escapa al mostrar).
     *
     * Devuelve `[bool $valido, array|null $diseno]`: inválido solo si no es un objeto/array.
     *
     * @param  mixed  $diseno
     * @param  int    $owner_id  El dueño, para validar las listas de `precio_lista`.
     * @return array
     */
    static function normalizar_diseno($diseno, $owner_id)
    {
        if (!is_array($diseno)) {
            return array(false, null);
        }

        $columnas = self::entero_acotado(self::valor($diseno, 'columnas'), self::COLUMNAS_MINIMO, self::COLUMNAS_MAXIMO, 3);
        $filas = self::entero_acotado(self::valor($diseno, 'filas'), self::FILAS_MINIMO, self::FILAS_MAXIMO, 7);
        $alto = self::decimal_acotado(self::valor($diseno, 'alto_mm'), self::ALTO_MINIMO, self::ALTO_MAXIMO, 40);
        $marco = self::booleano(self::valor($diseno, 'marco'), true);

        $ancho = self::ancho_etiqueta_redondeado($columnas);

        $listas_del_dueno = self::ids_de_listas_del_dueno($owner_id);

        $elementos = array();
        $ids_usados = array();

        $crudos = self::valor($diseno, 'elementos');

        if (is_array($crudos)) {
            foreach ($crudos as $crudo) {

                if (count($elementos) >= self::MAXIMO_DE_ELEMENTOS) {
                    break;
                }

                $elemento = self::normalizar_elemento($crudo, $ancho, $alto, $listas_del_dueno);

                if (is_null($elemento)) {
                    continue;
                }

                if (!is_string($elemento['id']) || !preg_match(self::PATRON_ID, $elemento['id']) || isset($ids_usados[$elemento['id']])) {
                    $elemento['id'] = self::id_libre($ids_usados);
                }

                $ids_usados[$elemento['id']] = true;

                $elementos[] = $elemento;
            }
        }

        return array(true, array(
            'version'   => self::VERSION_DEL_FORMATO,
            'columnas'  => $columnas,
            'filas'     => $filas,
            'alto_mm'   => $alto,
            'marco'     => $marco,
            'elementos' => $elementos,
        ));
    }

    /**
     * Un campo normalizado, o null si hay que descartarlo.
     *
     * @param  mixed  $crudo
     * @param  float  $ancho             Ancho de la etiqueta (redondeado a un decimal).
     * @param  float  $alto              Alto de la etiqueta.
     * @param  array  $listas_del_dueno  `[id => true]` de las listas del dueño.
     * @return array|null
     */
    private static function normalizar_elemento($crudo, $ancho, $alto, array $listas_del_dueno)
    {
        if (!is_array($crudo)) {
            return null;
        }

        $tipo = self::valor($crudo, 'tipo');

        if (!is_string($tipo) || !array_key_exists($tipo, self::CATALOGO)) {
            return null;
        }

        $defecto = self::CATALOGO[$tipo];

        $price_type_id = null;

        if ($tipo === 'precio_lista') {
            $crudo_lista = self::valor($crudo, 'price_type_id');

            if (!self::es_entero_positivo($crudo_lista) || !isset($listas_del_dueno[(int) $crudo_lista])) {
                return null;
            }

            $price_type_id = (int) $crudo_lista;
        }

        list($x, $w) = self::acotar_en_eje(self::valor($crudo, 'x'), self::valor($crudo, 'w'), $defecto['w'], $ancho);
        list($y, $h) = self::acotar_en_eje(self::valor($crudo, 'y'), self::valor($crudo, 'h'), $defecto['h'], $alto);

        $alineacion = self::valor($crudo, 'alineacion');

        if (!is_string($alineacion) || !in_array(strtoupper($alineacion), self::ALINEACIONES, true)) {
            $alineacion = $defecto['alineacion'];
        }

        $elemento = array(
            'id'              => self::valor($crudo, 'id'),
            'tipo'            => $tipo,
            'x'               => $x,
            'y'               => $y,
            'w'               => $w,
            'h'               => $h,
            'tamano'          => self::decimal_acotado(self::valor($crudo, 'tamano'), self::TAMANO_MINIMO, self::TAMANO_MAXIMO, $defecto['tamano']),
            'negrita'         => self::booleano(self::valor($crudo, 'negrita'), $defecto['negrita']),
            'saltos_de_linea' => self::booleano(self::valor($crudo, 'saltos_de_linea'), $defecto['saltos_de_linea']),
            'alineacion'      => strtoupper($alineacion),
        );

        if ($tipo === 'precio_lista') {
            $elemento['price_type_id'] = $price_type_id;
        }

        if (in_array($tipo, self::TIPOS_CON_ROTULO, true)) {
            $elemento['rotulo'] = self::booleano(self::valor($crudo, 'rotulo'), false);
        }

        if ($tipo === 'texto_fijo') {
            $texto = self::valor($crudo, 'texto');

            if (!is_string($texto) && !is_int($texto) && !is_float($texto)) {
                $texto = '';
            }

            $elemento['texto'] = mb_substr(trim((string) $texto), 0, self::LARGO_MAXIMO_DEL_TEXTO, 'UTF-8');
        }

        return $elemento;
    }

    /**
     * Acota posición y largo de un campo sobre un eje: `pos >= 0`, `largo >= 2`, `pos + largo <=
     * limite`. Si no entra, primero se achica el largo y, si aun así no entra (la posición quedó
     * pegada al borde), se corre la posición. Todo redondeado a un decimal.
     *
     * @param  mixed  $pos
     * @param  mixed  $largo
     * @param  float  $largo_por_defecto
     * @param  float  $limite
     * @return array  `[pos, largo]`
     */
    private static function acotar_en_eje($pos, $largo, $largo_por_defecto, $limite)
    {
        $limite = round((float) $limite, 1);

        $pos = is_numeric($pos) ? round((float) $pos, 1) : 0.0;
        $largo = is_numeric($largo) ? round((float) $largo, 1) : (float) $largo_por_defecto;

        if ($pos < 0) {
            $pos = 0.0;
        }

        if ($largo < self::LADO_MINIMO) {
            $largo = (float) self::LADO_MINIMO;
        }

        if ($largo > $limite) {
            $largo = $limite;
        }

        if ($pos > $limite - self::LADO_MINIMO + self::EPSILON) {
            $pos = round($limite - self::LADO_MINIMO, 1);
        }

        if ($pos + $largo > $limite + self::EPSILON) {
            $largo = round($limite - $pos, 1);
        }

        return array(self::numero_limpio($pos), self::numero_limpio($largo));
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Diseños que genera el sistema (§3.8)
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Los diseños que el sistema le genera a un dueño. Lo usan el seeder y los setups.
     *
     *   - Trabaja con listas de precios y tiene al menos una -> un diseño por lista (nombre y
     *     posición de la lista), si no tiene ya uno de esa lista.
     *   - Si no -> un diseño "Etiqueta de góndola" con `precio_final`, solo si no tiene NINGUNO.
     *
     * Con el candado sobre la fila del dueño en `users` (el mismo recurso que usa
     * `VenderLayoutHelper`): el seeder, el alta de una lista y otro seeder no pueden duplicar.
     * Si el id no existe o es de un empleado no crea nada.
     *
     * @param  int  $owner_id
     * @return int  Cuántos diseños creó.
     */
    static function crear_disenos_del_sistema($owner_id)
    {
        return DB::transaction(function () use ($owner_id) {

            $dueno = self::bloquear_dueno($owner_id);

            if (is_null($dueno) || !is_null($dueno->owner_id)) {
                return 0;
            }

            $listas = array();

            if (UserHelper::uses_listas_de_precio($dueno)) {
                $listas = PriceType::where('user_id', $owner_id)
                                    ->orderBy('position')
                                    ->orderBy('id')
                                    ->get();
            }

            if (count($listas) == 0) {
                if (ArticleTicketDesign::where('user_id', $owner_id)->exists()) {
                    return 0;
                }

                ArticleTicketDesign::create(array(
                    'user_id'       => $owner_id,
                    'name'          => self::NOMBRE_GENERICO,
                    'price_type_id' => null,
                    'position'      => 0,
                    'diseno'        => self::diseno_actual(null),
                ));

                return 1;
            }

            $creados = 0;

            foreach ($listas as $lista) {
                if (self::crear_diseno_de_lista_sin_candado($owner_id, $lista)) {
                    $creados++;
                }
            }

            return $creados;
        });
    }

    /**
     * El diseño de una lista de precios recién creada (`PriceTypeController@store`), si el dueño
     * trabaja con listas y todavía no tiene uno de esa lista. Mismo candado que el seeder.
     *
     * @param  \App\Models\PriceType  $lista
     * @return bool  Si lo creó.
     */
    static function crear_diseno_de_lista($lista)
    {
        $owner_id = $lista->user_id;

        return DB::transaction(function () use ($owner_id, $lista) {

            $dueno = self::bloquear_dueno($owner_id);

            if (is_null($dueno) || !is_null($dueno->owner_id)) {
                return false;
            }

            if (!UserHelper::uses_listas_de_precio($dueno)) {
                return false;
            }

            return self::crear_diseno_de_lista_sin_candado($owner_id, $lista);
        });
    }

    /**
     * Crea el diseño de la lista si el dueño no tiene uno de esa lista. Se llama con el candado
     * del dueño ya tomado.
     *
     * @param  int                    $owner_id
     * @param  \App\Models\PriceType  $lista
     * @return bool
     */
    private static function crear_diseno_de_lista_sin_candado($owner_id, $lista)
    {
        $ya_tiene = ArticleTicketDesign::where('user_id', $owner_id)
                                        ->where('price_type_id', $lista->id)
                                        ->exists();

        if ($ya_tiene) {
            return false;
        }

        $nombre = trim((string) $lista->name);

        if ($nombre === '') {
            $nombre = self::NOMBRE_GENERICO;
        }

        ArticleTicketDesign::create(array(
            'user_id'       => $owner_id,
            'name'          => mb_substr($nombre, 0, 120, 'UTF-8'),
            'price_type_id' => $lista->id,
            'position'      => (int) $lista->position,
            'diseno'        => self::diseno_actual($lista->id),
        ));

        return true;
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Lecturas por dueño
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El diseño pedido, solo si es de este dueño. El filtro por `user_id` va en la consulta: es lo
     * único que separa los diseños de un negocio de los de otro en una base compartida.
     *
     * @param  mixed  $id
     * @param  int    $owner_id
     * @return \App\Models\ArticleTicketDesign|null
     */
    static function diseno_del_dueno($id, $owner_id)
    {
        if (!self::es_entero_positivo($id)) {
            return null;
        }

        return ArticleTicketDesign::where('id', (int) $id)
                                    ->where('user_id', $owner_id)
                                    ->first();
    }

    /**
     * Candado sobre la fila del dueño en `users` (ver `VenderLayoutHelper::bloquear_dueno()`).
     *
     * @param  int  $owner_id
     * @return \App\Models\User|null
     */
    static function bloquear_dueno($owner_id)
    {
        return User::where('id', $owner_id)->lockForUpdate()->first();
    }

    /**
     * `[id => true]` de las listas de precios del dueño.
     *
     * @param  int  $owner_id
     * @return array
     */
    static function ids_de_listas_del_dueno($owner_id)
    {
        $ids = PriceType::where('user_id', $owner_id)->pluck('id')->all();

        $resultado = array();

        foreach ($ids as $id) {
            $resultado[(int) $id] = true;
        }

        return $resultado;
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Privados
     * ---------------------------------------------------------------------------------------
     */

    /**
     * @param  array   $arreglo
     * @param  string  $clave
     * @return mixed
     */
    private static function valor(array $arreglo, $clave)
    {
        return array_key_exists($clave, $arreglo) ? $arreglo[$clave] : null;
    }

    /**
     * @param  mixed  $valor
     * @return bool
     */
    private static function es_entero_positivo($valor)
    {
        if (is_int($valor)) {
            return $valor > 0;
        }

        if (is_string($valor) && ctype_digit($valor)) {
            return (int) $valor > 0;
        }

        if (is_float($valor) && floor($valor) == $valor) {
            return $valor > 0;
        }

        return false;
    }

    /**
     * @param  mixed  $valor
     * @param  int    $minimo
     * @param  int    $maximo
     * @param  int    $defecto
     * @return int
     */
    private static function entero_acotado($valor, $minimo, $maximo, $defecto)
    {
        if (!is_numeric($valor)) {
            return $defecto;
        }

        return max($minimo, min($maximo, (int) round((float) $valor)));
    }

    /**
     * Un decimal acotado y redondeado a un decimal (entero si no tiene decimales).
     *
     * @param  mixed  $valor
     * @param  float  $minimo
     * @param  float  $maximo
     * @param  float  $defecto
     * @return int|float
     */
    private static function decimal_acotado($valor, $minimo, $maximo, $defecto)
    {
        if (!is_numeric($valor)) {
            return self::numero_limpio((float) $defecto);
        }

        $valor = round((float) $valor, 1);

        return self::numero_limpio(max($minimo, min($maximo, $valor)));
    }

    /**
     * 40.0 -> 40 (entero) y 66.7 -> 66.7: así el JSON guarda `40` y no `40.0`.
     *
     * @param  float  $numero
     * @return int|float
     */
    private static function numero_limpio($numero)
    {
        $numero = round((float) $numero, 1);

        if (floor($numero) == $numero) {
            return (int) $numero;
        }

        return $numero;
    }

    /**
     * Un booleano del JSON. El SPA manda `true`/`false`; `1`, `'1'`, `'true'` también valen.
     *
     * @param  mixed  $valor
     * @param  bool   $defecto
     * @return bool
     */
    private static function booleano($valor, $defecto)
    {
        if (is_null($valor)) {
            return (bool) $defecto;
        }

        if (is_bool($valor)) {
            return $valor;
        }

        $resultado = filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_null($resultado) ? (bool) $defecto : $resultado;
    }

    /**
     * El primer `eN` libre.
     *
     * @param  array  $ids_usados
     * @return string
     */
    private static function id_libre(array $ids_usados)
    {
        $n = 1;

        while (isset($ids_usados['e'.$n])) {
            $n++;
        }

        return 'e'.$n;
    }
}
