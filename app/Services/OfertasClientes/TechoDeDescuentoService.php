<?php

namespace App\Services\OfertasClientes;

use App\Http\Controllers\Helpers\CriterioDePrecioHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;

/**
 * El techo de descuento de un artículo para un cliente: hasta cuánto se le puede bajar el precio
 * sin vender por debajo del costo. Determinista, en código, sin IA de por medio: la IA recibe el
 * techo ya calculado y solo elige un número ADENTRO del rango (§A.7 del plan).
 *
 * 🔴 LA DEMOSTRACIÓN, TEXTUAL, PORQUE ES EXACTAMENTE LO QUE ALGUIEN "SIMPLIFICA" DE VUELTA
 *
 * El margen del sistema es markup SOBRE EL COSTO, no sobre el precio:
 *   ArticlePricesHelper.php:468  ->  $final_price = $cost + ($cost * $percentage / 100);
 *   ArticlePricesHelper.php:423  ->  $percentage  = ($base_del_margen - $cost) / $cost * 100;
 *
 * Entonces, con margen `m` sobre costo, el descuento que deja el precio EXACTAMENTE en el costo
 * NO es `m`, es:
 *
 *     techo_% = m / (100 + m) * 100
 *
 *     | Margen del artículo | Techo real |
 *     |                 30% |     23,08% |
 *     |                 50% |     33,33% |
 *     |                100% |        50% |
 *
 * 🔴 TOMAR EL MARGEN COMO DESCUENTO DIRECTO VENDE BAJO COSTO. Costo 100, margen 50%, precio 150,
 * menos 50% = 75: son 25 de pérdida por unidad. Si esto se "simplifica" a `techo = margen`, la
 * primera oferta que se active le cobra de menos a un cliente real. Validado el 15/8/2026 contra
 * cinco artículos de empresa_testing_s2: aplicando el techo, el precio neto dio idéntico al
 * costo_real, al centavo, en todos.
 *
 * LA CADENA, con los métodos exactos (§A.4):
 *   1. modo         = CriterioDePrecioHelper::desde_articulo($article)
 *   2. exclusiones borde (§A.4.1, cada una comentada abajo)
 *   3. precio_bruto = ArticlePricesHelper::resolver_precio_de_venta($article, $user, $price_type_id)
 *   4. precio_neto  = ArticlePricesHelper::quitar_iva_y_sale_taxes($article, $precio_bruto, $user)
 *   5. costo        = (float) $article->costo_real
 *   6. margen       = ($precio_neto - $costo) / $costo * 100
 *   7. techo_crudo  = margen / (100 + margen) * 100
 *   8. techo        = min(techo_crudo, MAX_DESCUENTO_ABSOLUTO)
 *   9. techo        = techo * factor_credito      (el factor NUNCA es > 1)
 *  10. techo_final  = (int) floor(techo)          (floor, nunca round)
 *  11. si techo_final < MIN_DESCUENTO -> la línea NO se sugiere
 *
 * 🔴 NUNCA leer $article->final_price directo (docblock ArticlePricesHelper.php:48-54): en una
 * cuenta con listas ese número no se le cobra a nadie. 🔴 NUNCA llamar a
 * ArticleHelper::setFinalPrice() para simular: no es puro — ArticleHelper.php:321 tiene un save()
 * que borra articles.price sin mirar la bandera $guardar_cambios. Acá se leen los valores ya
 * persistidos.
 *
 * 🔴 El margen SIEMPRE se deriva del precio vigente resuelto contra costo_real (§A.4.2): no se lee
 * articles.percentage_gain ni article_price_type.percentage. Hay cinco capas de margen (usuario,
 * unidades individuales, proveedor, categoría, artículo — ArticleHelper:144-177 y :186-209) y
 * percentage_gain es solo la última; derivar del precio resuelto cubre las capas de MARGEN, el precio
 * manual y setear_precio_final con UNA sola fórmula.
 *
 * ⚠️ CON UNA EXCEPCIÓN, Y NO ES LA QUE ESTE DOCBLOCK AFIRMABA HASTA EL 15/8/2026: `unidades_individuales`
 * NO QUEDA CUBIERTA. No es una capa de margen: es un cambio de unidad. calcular_base_antes_de_listas()
 * divide el costo por $article->unidades_individuales (ArticleHelper.php:155-158), así que el precio
 * resultante es POR UNIDAD SUELTA mientras que costo_real sigue siendo POR BULTO. La resta del paso 6
 * compara dos cosas distintas y da un margen negativo, con lo cual la línea sale por
 * EXCLUIDO_MARGEN_NO_POSITIVO. 🔴 O sea: un artículo fraccionado NO SE OFERTA, y se excluye en
 * silencio, sin que el motivo diga "unidades individuales". Es el lado seguro (nunca se sugiere un
 * descuento sobre una comparación que no cierra) pero es una limitación conocida, no un descuido, y va
 * declarada en el informe. Arreglarla es dividir también el costo, y eso se decide con Lucas: no se
 * "emparcha" acá.
 *
 * Este servicio no escribe nada en la base. PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class TechoDeDescuentoService
{
    /** Un 3% no mueve a nadie y no vale el mail: por debajo de esto la línea no se sugiere. */
    const MIN_DESCUENTO = 5;

    /** Más de 60% en un ecommerce es un error de carga, no una oferta. */
    const MAX_DESCUENTO_ABSOLUTO = 60;

    /*
     * 🔴 NO HAY NINGUNA CONSTANTE DE "MAL PAGADOR" ACÁ, Y NO ES UN OLVIDO. El plan original tenía
     * un FACTOR_MAL_PAGADOR = 0.5 que le partía el techo al medio; la decisión que tomó Lucas el
     * 15/8/2026 es otra: "al que debe hace mucho no se le ofrece nada". Se EXCLUYE entero y antes
     * de calcular, en CriteriosDeOfertaService, así que jamás llega hasta acá. Convertirlo de
     * vuelta en un factor es deshacer una decisión de negocio, no optimizar.
     */

    /** costo_real null, vacío o <= 0: sin costo no hay techo posible. */
    const EXCLUIDO_SIN_COSTO = 'sin_costo';

    /**
     * El precio persistido pasó por ArticleHelper::cotizar() y el costo no: el precio quedó en pesos
     * y costo_real siguió en dólares, así que no son comparables. Se llama por lo que efectivamente
     * pasa (el costo quedó sin cotizar) y NO "dolares_con_listas": las listas de precio no tienen
     * nada que ver, ver el guard del paso 2.
     */
    const EXCLUIDO_COSTO_EN_DOLARES_SIN_COTIZAR = 'costo_en_dolares_sin_cotizar';

    /** El resolvedor no devolvió precio (artículo sin precio en ninguna punta). */
    const EXCLUIDO_SIN_PRECIO = 'sin_precio';

    /** El precio neto no supera al costo: no hay margen del que sacar un descuento. */
    const EXCLUIDO_MARGEN_NO_POSITIVO = 'margen_no_positivo';

    /** El techo quedó por debajo de MIN_DESCUENTO: no valdría el mail. */
    const EXCLUIDO_TECHO_MENOR_AL_MINIMO = 'techo_menor_al_minimo';

    /**
     * 🔴 La firma congelada del plan (§D), la que llama A4 para re-validar el techo con los datos
     * de HOY cuando el comerciante activa una oferta. Devuelve null si el artículo queda excluido.
     *
     * @param  mixed $article Artículo con provider y price_types cargados (o cargables).
     * @param  mixed $client  Cliente destinatario, por su lista de precios. Puede ser null.
     * @param  mixed $user    Dueño del comercio (condición fiscal, listas, sale_taxes).
     * @param  float $factor_credito Modificador que solo puede BAJAR el techo (ver evaluar()).
     * @return array|null ['techo','piso','margen','precio_neto','costo','excluido_por' => null, ...]
     */
    public static function calcular($article, $client, $user, $factor_credito = 1.0)
    {
        $resultado = self::evaluar($article, $client, $user, $factor_credito);

        return is_null($resultado['excluido_por']) ? $resultado : null;
    }

    /**
     * Igual que calcular(), pero devuelve SIEMPRE el array, con 'excluido_por' cargado cuando el
     * artículo no entra. Existe porque el pipeline necesita contar y explicar las exclusiones (van
     * a la vista y al informe), y un null no explica nada.
     *
     * @param  mixed $article
     * @param  mixed $client
     * @param  mixed $user
     * @param  float $factor_credito
     * @return array
     */
    public static function evaluar($article, $client, $user, $factor_credito = 1.0)
    {
        /*
         * 🔴 EL FACTOR NUNCA PUEDE SER MAYOR QUE 1, y este clamp es el invariante que blinda todo
         * el servicio: con techo_paso_9 <= techo_paso_8 <= MAX_DESCUENTO_ABSOLUTO, ninguna
         * combinación de señales (crédito, vendibilidad, lo que devuelva la IA) puede empujar el
         * descuento por encima del margen del artículo. Dejarlo pasar de 1 rompe el techo.
         */
        $factor_credito = (float) $factor_credito;
        $factor_credito = $factor_credito > 1 ? 1.0 : $factor_credito;
        $factor_credito = $factor_credito < 0 ? 0.0 : $factor_credito;

        $resultado = [
            'techo'                => null,
            'piso'                 => self::MIN_DESCUENTO,
            'margen'               => null,
            'precio_neto'          => null,
            'precio_vigente'       => null,
            'costo'                => null,
            'price_type_id'        => null,
            'factor_credito'       => $factor_credito,
            'modo_precio'          => null,
            'marca_monotributista' => false,
            'excluido_por'         => null,
        ];

        /*
         * Paso 1. Borde "precio manual": el modo es informativo y PRECIO_MANUAL / NINGUNO NO
         * excluyen. El margen se deriva igual del precio contra el costo, que es exactamente lo que
         * hace el sistema en ArticlePricesHelper.php:423. Lo único que no se puede con precio manual
         * es leer un percentage_gain que no está — y acá no se lee nunca (§A.4.2).
         */
        $resultado['modo_precio'] = CriterioDePrecioHelper::desde_articulo($article);

        /*
         * Paso 2, borde "sin costo": EXCLUYE. Sin costo no hay techo, y sugerir un descuento sin
         * techo es justo lo que este servicio viene a impedir. Se mira costo_real y no cost:
         * costo_real ya tiene descuentos, recargos y —si la cuenta es Monotributista o legacy con
         * aplicar_iva_al_costo— el IVA adentro, que es la magnitud en la que queda el precio después
         * de quitar_iva_y_sale_taxes(). Mezclar cost crudo con precio bruto rompe la comparación.
         * es_positivo() descarta null, '' y 0 en todas sus formas ("0", "0.00").
         */
        if (!CriterioDePrecioHelper::es_positivo($article->costo_real)) {
            $resultado['excluido_por'] = self::EXCLUIDO_SIN_COSTO;
            return $resultado;
        }

        /*
         * Paso 2, borde "el costo quedó en dólares y el precio no": EXCLUYE.
         *
         * 🔴 LO QUE DESALINEA COSTO Y PRECIO NO ES USAR LISTAS DE PRECIO: ES QUE CORRA
         * ArticleHelper::cotizar() (:640-659). costo_real sale de aplicar_descuentos_e_iva($article->cost)
         * (ArticleHelper.php:282-292) y NUNCA pasa por cotizar(): queda en la moneda en la que se cargó
         * el costo, siempre. El precio SÍ se cotiza —calcular_base_antes_de_listas() llama a cotizar()
         * en el else de :162-171, y la rama price_from_cost_mas_iva en :365-372 con la misma condición
         * escrita por De Morgan— y queda en pesos. Restar uno del otro es una cuenta sin sentido y de
         * esa resta sale un margen inventado. La condición exacta está en
         * el_precio_se_cotiza_y_el_costo_no(), abajo.
         *
         * MEDIDO el 15/8/2026 sobre la cuenta 500 (cotizar_precios_en_dolares = 1, listas_de_precio = 0):
         * artículo de 10 USD con 20% de margen y el dólar a 1000 -> final_price 14.520 (pesos) contra
         * costo_real 10 (dólares) -> margen 112,226% -> techo 60%, el tope absoluto. El comerciante
         * terminaría vendiendo a ~5.800 algo que le costó 10.000. En la base `prueba` hay 409 de 695
         * artículos con cost_in_dollars = 1: no es un caso de laboratorio.
         *
         * 🔴 Y PARA EL OTRO LADO: cuando cotizar() NO corre, NO se excluye. Sin cotización el precio se
         * deriva del costo sin cambiar de moneda, los dos quedan del mismo lado y el margen es
         * correcto; excluir ahí sería tirar líneas buenas a la basura. El guard viejo miraba
         * uses_listas_de_precio() y se equivocaba en las dos puntas a la vez: dejaba pasar el caso roto
         * (sin listas, cotizando) y excluía el sano (con listas + ventas_en_dolares, que es justo la
         * combinación donde cotizar() no corre).
         */
        if (self::el_precio_se_cotiza_y_el_costo_no($article, $user)) {
            $resultado['excluido_por'] = self::EXCLUIDO_COSTO_EN_DOLARES_SIN_COTIZAR;
            return $resultado;
        }

        /*
         * Paso 3. Se le pasa la lista del cliente: en una cuenta con listas, el precio que se le
         * cobra a ESTE cliente es el de SU lista, no el final_price del artículo.
         */
        $price_type_id = is_null($client) ? null : $client->price_type_id;

        $precio = ArticlePricesHelper::resolver_precio_de_venta($article, $user, $price_type_id);

        $resultado['price_type_id']  = $precio['price_type_id'];
        $resultado['precio_vigente'] = is_null($precio['final_price']) ? null : (float) $precio['final_price'];

        if (is_null($resultado['precio_vigente']) || $resultado['precio_vigente'] <= 0) {
            $resultado['excluido_por'] = self::EXCLUIDO_SIN_PRECIO;
            return $resultado;
        }

        /*
         * Paso 4. El espejo acoplado de aplicar_sale_taxes() + aplicar_iva(): baja el precio de
         * etiqueta a la misma magnitud en la que está el costo. Sin esto el margen sale inflado por
         * el IVA y por los impuestos sobre ventas, que son plata de AFIP y de la provincia, no
         * ganancia de nadie.
         */
        $resultado['precio_neto'] = (float) ArticlePricesHelper::quitar_iva_y_sale_taxes(
            $article,
            $resultado['precio_vigente'],
            $user
        );

        // Paso 5.
        $resultado['costo'] = (float) $article->costo_real;

        /*
         * Paso 6, borde "cuenta Monotributista": NO excluye, se MARCA. 🔴 Riesgo medido: con costo
         * importado el IVA se cuenta DOS VECES. ProcessRow.php:306-312 nunca hace el back-out
         * (precios_incluyen_iva queda siempre en false porque ningún llamador pasa la clave) mientras
         * que la compra manual sí lo hace en NewProviderOrderHelper.php:1038-1039. Entonces
         * articles.cost queda BRUTO —con el IVA del Excel del proveedor adentro— y
         * aplicar_descuentos_e_iva() le suma el 21% otra vez, porque para MT iva_va_al_costo() da true
         * (ArticlePricesHelper.php:754-772): costo_real queda ~21% INFLADO.
         *
         * 🔴 LA DIRECCIÓN DEL ERROR, QUE ES AL REVÉS DE LO QUE DECÍA ESTE COMENTARIO HASTA EL
         * 15/8/2026: con el costo inflado, margen = (precio_neto - costo) / costo baja, y el techo
         * m/(100+m) baja con él. O sea que el techo sale MÁS BAJO, no más alto: MÁS conservador. Si el
         * precio se deriva de ese mismo costo inflado, los dos suben juntos y el margen porcentual ni
         * se mueve; la distorsión aparece cuando el precio NO viene de ahí (precio manual, o precio de
         * antes de la reimportación del costo), y siempre para abajo.
         *
         * 🔴 Por eso EL RIESGO ACÁ ES COMERCIAL, NO DE PLATA: se le ofrece al cliente menos descuento
         * del que el margen real aguantaba, y en el borde la línea ni se sugiere (cae por debajo de
         * MIN_DESCUENTO). NUNCA se vende bajo costo por esta vía. No "compensar" el 21% subiendo el
         * techo acá: el que hay que arreglar es el back-out del import, que está fuera del alcance de
         * esta misión y va declarado en el informe.
         */
        $resultado['marca_monotributista'] = ArticlePricesHelper::es_monotributista_para_costeo($user);

        $resultado['margen'] = ($resultado['precio_neto'] - $resultado['costo']) / $resultado['costo'] * 100;

        if ($resultado['margen'] <= 0) {
            $resultado['excluido_por'] = self::EXCLUIDO_MARGEN_NO_POSITIVO;
            return $resultado;
        }

        // Pasos 7 y 8.
        $techo = min(self::techo_crudo($resultado['margen']), (float) self::MAX_DESCUENTO_ABSOLUTO);

        // Paso 9. Ver el clamp del principio: el factor solo puede bajar.
        $techo = $techo * $factor_credito;

        /*
         * Paso 10. 🔴 floor, NUNCA round: round(23.6) = 24 > 23,08 = techo real -> vende bajo costo.
         * Un solo punto de más alcanza para perder plata en cada unidad. Además el entero hacia
         * abajo es un colchón gratis contra las cinco reglas acumulables de ArticleHelper::redondear()
         * (:696-730) y contra el margen inflado del Monotributista del paso 6, y es lo que un
         * comerciante entiende y comunica.
         *
         * 🔴 ACÁ QUEDA FIJADO EL PORCENTAJE, QUE ES LO QUE SE GUARDA Y LO QUE SE APLICA SOBRE EL
         * PRECIO DE ETIQUETA, SIN NINGUNA RECONVERSIÓN. La demostración, para que nadie agregue una
         * conversión que no hace falta: el IVA (*(1+i)) y los sale_taxes (/(1-t)) son factores
         * MULTIPLICATIVOS, así que precio_bruto = precio_neto * K con K constante para el artículo, y
         *
         *     precio_bruto * (1 - d/100)  ->  neteado  ->  precio_neto * (1 - d/100)
         *
         * o sea que el porcentaje de descuento se conserva EXACTO entre neto y bruto. Por eso el
         * margen se calcula neteado y el porcentaje viaja tal cual hasta la tienda (§A.6), que
         * aplica * (1 - porcentaje/100) sobre el precio que ya resolvió y no necesita conocer ni
         * costos ni márgenes. Ese es el punto del diseño.
         */
        $resultado['techo'] = (int) floor($techo);

        // Paso 11.
        if ($resultado['techo'] < self::MIN_DESCUENTO) {
            $resultado['excluido_por'] = self::EXCLUIDO_TECHO_MENOR_AL_MINIMO;
        }

        return $resultado;
    }

    /**
     * true cuando el precio persistido del artículo pasó por ArticleHelper::cotizar() y el costo no,
     * o sea cuando los dos quedaron en monedas distintas y compararlos no significa nada.
     *
     * Es la conjunción de DOS condiciones que viven en ArticleHelper y que hay que espejar juntas:
     *
     *   1. cotizar() CONVIERTE   <=>  $article->cost_in_dollars && $user->cotizar_precios_en_dolares
     *                                 (el if de ArticleHelper.php:642-645; adentro multiplica por el
     *                                 dólar del proveedor o por el global, pero eso ya no cambia el
     *                                 diagnóstico: cambió de moneda).
     *   2. cotizar() se LLAMA    <=>  !( UserHelper::uses_listas_de_precio($user)
     *                                    && UserHelper::hasExtencion('ventas_en_dolares', $user) )
     *                                 Los dos llamadores usan la misma condición: el else de
     *                                 calcular_base_antes_de_listas() (:162-171) y el if de la rama
     *                                 price_from_cost_mas_iva (:365-372), que es la misma escrita con
     *                                 De Morgan.
     *
     * 🔴 Vive en un método propio y no inline en el guard por dos razones: el día que alguien cambie
     * el ruteo de cotizar() en ArticleHelper tiene UN solo lugar acá que espejar, y el test la afirma
     * sola, sin tener que construir el artículo entero.
     *
     * @param  mixed $article
     * @param  mixed $user
     * @return bool
     */
    public static function el_precio_se_cotiza_y_el_costo_no($article, $user)
    {
        if (!$article->cost_in_dollars || !$user->cotizar_precios_en_dolares) {
            return false;
        }

        return !(
            UserHelper::uses_listas_de_precio($user)
            && UserHelper::hasExtencion('ventas_en_dolares', $user)
        );
    }

    /**
     * El techo crudo (sin tope absoluto, sin factor y sin floor) que corresponde a un margen. Está
     * aparte para que la fórmula tenga UN solo lugar donde vive y se pueda probar sola contra la
     * tabla de la verdad (30 -> 23,08 · 50 -> 33,33 · 100 -> 50).
     *
     * @param  float $margen Margen porcentual sobre el costo.
     * @return float
     */
    public static function techo_crudo($margen)
    {
        $margen = (float) $margen;

        return $margen <= 0 ? 0.0 : $margen / (100 + $margen) * 100;
    }
}
