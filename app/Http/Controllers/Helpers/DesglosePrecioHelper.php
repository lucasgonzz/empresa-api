<?php

namespace App\Http\Controllers\Helpers;

/**
 * Forma de UNA entrada del desglose del precio de un articulo.
 *
 * Hasta esta mision el desglose era un array de strings planos: cada renglon se armaba concatenando
 * texto y numeros ('Mas IVA de 21% = $1.210,00'), y el front decidia que un renglon era titulo
 * comparando `des === des.toUpperCase()`. Esa heuristica ya fallo una vez con las listas de nombre
 * acentuado (hallazgo 20260805-desglose-por-lista-margen-propio-y-acentos, punto 2), y ademas hacia
 * imposible pintar cada componente del precio con su color y su icono: para saber si un renglon era
 * IVA o descuento habia que adivinarlo del texto.
 *
 * Ahora cada renglon es un array asociativo con seis claves. La sexta, `texto`, es EXACTAMENTE el
 * string que se emitia antes, y de ahi sale la clave `description` de la respuesta con
 * Self::solo_textos(). O sea: una sola emision, dos vistas de la misma cosa.
 *
 * 🔴 Por que `description` sigue existiendo y sigue siendo un array de strings: `empresa-spa` es una
 * PWA con su propio flujo de actualizacion. Entre que se despliega la API y que cada una de las ~40
 * cuentas recarga su bundle hay una ventana en la que el front viejo recibe la respuesta nueva. Si
 * `description` cambiara de tipo, ese front pintaria '[object Object]' en cada renglon. Los
 * desgloses YA GUARDADOS en sales.price_description y provider_orders.price_description tambien son
 * arrays de strings, y el modal los sigue mostrando por el mismo camino.
 *
 * PHP 7.4: sin tipos de retorno union, sin promocion en constructor, sin enum, sin atributos.
 */
class DesglosePrecioHelper {

    /*
        Catalogo CERRADO de tipos. El front tiene un icono y un par de tokens de color por cada uno,
        y un default neutro para el tipo que no conozca -- asi, agregar un tipo aca no rompe la
        pantalla de nadie mientras se despliega el front.
    */
    const SECCION       = 'seccion';
    const COSTO         = 'costo';
    const MARGEN        = 'margen';
    const IVA           = 'iva';
    const DESCUENTO     = 'descuento';
    const RECARGO       = 'recargo';
    /*
        Lo que RESTA del precio sin ser un descuento comercial: hoy, los price_type_surchages
        de una lista, que se llaman "recargos" en el modelo pero el codigo les hace -= (es
        intencional, decision de Lucas del 4/7). Tienen tipo propio y no RECARGO porque el
        front dibuja RECARGO con un signo mas: usarlo aca pondria el icono diciendo lo
        contrario de lo que hace la cuenta.
    */
    const DEDUCCION     = 'deduccion';
    const IMPUESTO      = 'impuesto';
    const COTIZACION    = 'cotizacion';
    const UNIDADES      = 'unidades';
    const REDONDEO      = 'redondeo';
    const PRECIO_MANUAL = 'precio_manual';
    const NOTA          = 'nota';
    const TOTAL         = 'total';

    /*
        Identificadores estables de las secciones. Existen para que el codigo pueda preguntar "esta
        es la seccion del precio final unico?" sin comparar prosa: ArticleHelper::quitar_seccion_del_precio_final_unico()
        cortaba buscando el string 'CALCULO DEL PRECIO FINAL', y el titulo lo emiten TRES sitios
        distintos de ArticleHelper. Con la clave, cambiar el texto visible deja de ser un cambio de
        comportamiento.
    */
    const CLAVE_COSTO_REAL   = 'costo_real';
    const CLAVE_PRECIO_FINAL = 'precio_final';
    const CLAVE_LISTA        = 'lista';

    /**
     * Los catorce tipos validos. Lo usa el test que verifica que ningun sitio invento un tipo suelto:
     * un tipo desconocido no explota en el front (cae en neutro), asi que sin este test se pintaria
     * gris para siempre sin que nadie se entere.
     *
     * @return array
     */
    static function tipos() {
        return [
            Self::SECCION,
            Self::COSTO,
            Self::MARGEN,
            Self::IVA,
            Self::DESCUENTO,
            Self::RECARGO,
            Self::DEDUCCION,
            Self::IMPUESTO,
            Self::COTIZACION,
            Self::UNIDADES,
            Self::REDONDEO,
            Self::PRECIO_MANUAL,
            Self::NOTA,
            Self::TOTAL,
        ];
    }

    /**
     * Un renglon comun del desglose.
     *
     * @param string      $tipo     Uno de los del catalogo de arriba. Elige icono y color.
     * @param string      $etiqueta Titulo corto del renglon, en castellano y ya capitalizado.
     * @param string|null $detalle  La aclaracion chica ('21%', 'del proveedor', '3 unidades'). Sin
     *                              punto final. Null si el renglon no tiene nada que aclarar.
     * @param string|null $valor    El numero que cierra el renglon, YA FORMATEADO. Casi siempre es
     *                              plata, con Numbers::price($x, true): el front no formatea plata,
     *                              porque Numbers::price() es el formateador del sistema y dos
     *                              implementaciones divergirian. En los pocos renglones cuyo
     *                              resultado ES un porcentaje --el margen implicito de un precio
     *                              cargado a mano, y el margen de una lista-- va el porcentaje, que
     *                              es lo que la persona fue a leer ahi. Null si el renglon no cierra
     *                              en ningun numero (las notas).
     * @param string      $texto    El renglon tal como se emitia antes de esta mision. De aca sale
     *                              la clave `description` de la respuesta.
     * @return array
     */
    static function linea($tipo, $etiqueta, $detalle, $valor, $texto) {
        return [
            'tipo'     => $tipo,
            'clave'    => null,
            'etiqueta' => $etiqueta,
            'detalle'  => $detalle,
            'valor'    => $valor,
            'texto'    => $texto,
        ];
    }

    /**
     * Un encabezado de seccion. Lleva `clave` y no lleva `valor`.
     *
     * @param string $clave    Una de las CLAVE_* de arriba.
     * @param string $etiqueta El titulo que se muestra ('Calculo del costo real').
     * @param string $texto    El titulo historico, en MAYUSCULAS ('CALCULO DEL COSTO REAL').
     * @return array
     */
    static function seccion($clave, $etiqueta, $texto) {
        return [
            'tipo'     => Self::SECCION,
            'clave'    => $clave,
            'etiqueta' => $etiqueta,
            'detalle'  => null,
            'valor'    => null,
            'texto'    => $texto,
        ];
    }

    /**
     * Desglose de un articulo que no tiene nada que explicar todavia.
     *
     * ArticleHelper::setFinalPrice() tiene un early return al principio: si el articulo no tiene ni
     * costo ni precio (y la cuenta no usa listas ni ventas en dolares), no hay cadena que recorrer y
     * devuelve el MODELO, no un array -- aunque se le haya pedido la descripcion. Eso siempre fue
     * asi, pero antes casi no se notaba: el modal recien se abria cuando llegaba la respuesta.
     * Desde que abre al instante, el usuario se come un cuadro vacio despues del spinner.
     *
     * Una nota que diga que falta cargar el costo es la respuesta correcta a "por que este precio",
     * y es mejor que un modal en blanco o que un error que no lo es.
     *
     * @return array
     */
    static function sin_calculo() {
        return [
            Self::linea(
                Self::NOTA,
                'Todavia no hay calculo para mostrar',
                'Cargale un costo o un precio al articulo y el desglose aparece solo',
                null,
                'Todavia no hay calculo para mostrar: cargale un costo o un precio al articulo'
            ),
        ];
    }

    /**
     * El desglose visto como el array de strings de siempre.
     *
     * Tolera que entre una entrada que ya sea string: si algun sitio de emision quedara sin
     * convertir, este metodo no explota y el renglon viaja tal cual. El test
     * DesgloseEstructuradoTest es el que denuncia esa mezcla; aca no se la tapa, solo se evita que
     * un olvido se transforme en un 500.
     *
     * @param array $detalle
     * @return array
     */
    static function solo_textos($detalle) {

        if (!is_array($detalle)) {
            return $detalle;
        }

        $textos = [];

        foreach ($detalle as $linea) {

            if (is_array($linea) && array_key_exists('texto', $linea)) {
                $textos[] = $linea['texto'];
            } else if (is_string($linea)) {
                $textos[] = $linea;
            }
        }

        return $textos;
    }

    /**
     * Si esta entrada es el encabezado de la seccion pedida.
     *
     * @param mixed  $linea
     * @param string $clave Una de las CLAVE_* de arriba.
     * @return bool
     */
    static function es_seccion($linea, $clave) {

        if (!is_array($linea)) {
            return false;
        }

        if (!array_key_exists('tipo', $linea) || $linea['tipo'] !== Self::SECCION) {
            return false;
        }

        return array_key_exists('clave', $linea) && $linea['clave'] === $clave;
    }
}
