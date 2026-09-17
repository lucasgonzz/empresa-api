<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Criterio ÚNICO de "cuánto IVA CRÉDITO trae adentro el costo de una venta" (misión
 * saneo-ganancia-ventas, 17/9/2026).
 *
 * Es la contraparte de `IvaDeVentaHelper`, que mide el IVA DÉBITO. Los dos juntos completan la
 * fórmula de `SaleHelper::calcular_ganancia()`:
 *
 *     sales.ganancia = total − (total_cost − crédito fiscal contenido en el costo) − IVA declarado
 *
 * ---------------------------------------------------------------------------------------------
 * POR QUÉ HACE FALTA: el número nuevo era PEOR que el viejo en las cuentas legacy
 * ---------------------------------------------------------------------------------------------
 *
 * La fórmula que estrenó esta misión (`total − total_cost − IVA declarado`) da por sentado que
 * `sales.total_cost` es NETO. Para una cuenta **legacy** —`usar_condicion_fiscal_en_costeo = 0`
 * con la tilde vieja `aplicar_iva_al_costo = 1`— no lo es:
 * `ArticleHelper::aplicar_descuentos_e_iva()` le suma el IVA al costo ANTES del margen, así que
 * `articles.costo_real` queda BRUTO y `SaleHelper::getCost()` lo copia tal cual al pivot. Ese IVA
 * de compra es **crédito fiscal recuperable**, no costo: restarle además el IVA débito completo lo
 * descuenta dos veces.
 *
 * | Margen 40 % | Cuenta migrada (RI) | Cuenta legacy con la tilde prendida |
 * |---|---|---|
 * | `total_cost` | 100 (neto) | **121 (bruto)** |
 * | Precio final | 169,40 | 169,40 |
 * | `importe_iva` del comprobante | 29,40 | 29,40 |
 * | Sin este helper | 40,00 ✅ | **19,00** ❌ |
 * | Ganancia real | 40,00 | **40,00** |
 *
 * El error es el 21 % del costo neto, constante en pesos: como porcentaje crece cuando el margen
 * baja, y con margen ≤ 21 % **informa ganancia negativa a un negocio que gana plata** — que es el
 * síntoma con el que arrancó esta misión. Ferretotal está exactamente en ese caso (medido el
 * 17/9/2026: `condicion_iva_precios = 'RRII'`, `aplicar_iva_al_costo = 1`,
 * `usar_condicion_fiscal_en_costeo = 0`).
 *
 * ---------------------------------------------------------------------------------------------
 * 🔴 EL PREDICADO NO SE REESCRIBE ACÁ: SE COMPONE DE LOS DOS QUE YA EXISTEN
 * ---------------------------------------------------------------------------------------------
 *
 * `ArticlePricesHelper::iva_va_al_costo()` responde DÓNDE se suma el IVA y
 * `ArticlePricesHelper::el_iva_participa_del_precio()` responde SI se suma. Con esos dos, más
 * `es_monotributista_para_costeo()` (que es el que decide si ese IVA se recupera), las cinco
 * combinaciones vivas caen solas:
 *
 * | Cuenta | `iva_va_al_costo` | `el_iva_participa_del_precio` | Costo | ¿Netear? |
 * |---|---|---|---|---|
 * | Legacy RI, tilde prendida | true | true | BRUTO | **SÍ** |
 * | Legacy RI, tilde apagada | false | true | neto | no |
 * | Legacy MT, tilde prendida | true | true | BRUTO | **NO** (ver abajo) |
 * | Migrada RI | false | true | neto | no |
 * | Migrada MT | false | **false** | BRUTO | **NO** (ver abajo) |
 *
 * 🔴 Los dos Monotributistas tienen el costo bruto y NO se netean, y no es un olvido: el MT **no
 * recupera** el IVA de sus compras. Lo que le factura el proveedor es su costo real, punto. Netearlo
 * le inventaría un 21 % de ganancia que nunca existió. Y su ganancia hoy ya da bien sin tocar nada:
 * su Factura C declara `importe_iva = 0`, así que `total − 121 − 0` es exactamente el número
 * correcto. Por eso el MT migrado aparece con el costo BRUTO y `¿Netear?` en no: las dos cosas son
 * ciertas a la vez.
 *
 * El MT migrado, además, es el único que tiene el costo bruto con `iva_va_al_costo()` en false —su
 * costo se guarda tal cual lo carga, sin back-out, desde la misión `iva-fuera-del-costeo-monotributista`
 * (21/8/2026)—, y por eso `el_costo_es_bruto()` mira los dos predicados y no uno solo.
 *
 * ---------------------------------------------------------------------------------------------
 * 🔴 LÍNEA POR LÍNEA, NO POR VENTA: `articles.aplicar_iva` manda
 * ---------------------------------------------------------------------------------------------
 *
 * Que la CUENTA tenga el costo bruto no significa que TODAS sus líneas lo tengan.
 * `ArticlePricesHelper::aplicar_iva()` solo le suma el IVA al costo si el artículo tiene
 * `aplicar_iva` prendido y una alícuota real (`hasIva()`: ni null, ni '0', ni 'Exento', ni
 * 'No Gravado'). Un artículo exento de la misma cuenta tiene el costo NETO, y netearlo otra vez le
 * sacaría un 21 % que nunca tuvo. Este helper reproduce esa misma condición, línea por línea: es el
 * inverso exacto del cálculo, no una aproximación por venta.
 *
 * La alícuota sale de `article_sale.iva_percentage` —la del MOMENTO de la venta, persistida desde el
 * 11/6/2026— y recién si falta se cae a la del artículo (`ivas.percentage` vía `articles.iva_id`).
 * La columna es `VARCHAR(20)` desde `2026_07_30_120000` porque guarda también etiquetas fiscales,
 * así que todo valor no numérico se trata como "sin alícuota utilizable".
 *
 * ⚠️ Lo que NO se puede reconstruir: `articles.aplicar_iva` y `articles.iva_id` son el estado de
 * HOY, no el del día de la venta (a diferencia de la alícuota, que sí quedó persistida en el pivot).
 * Para una línea cuyo artículo cambió de condición después de venderse, este helper usa lo actual
 * porque es lo único que hay. Es la misma limitación que tiene el resto del sistema.
 *
 * ---------------------------------------------------------------------------------------------
 * Lo que este helper deliberadamente NO netea
 * ---------------------------------------------------------------------------------------------
 *
 * Devuelve el CRÉDITO contenido en el costo (un número a restarle a `total_cost`), y no un
 * `total_cost` recalculado. Es a propósito: `SaleTotalesHelper::set_total_cost()` suma, además de
 * las líneas de `article_sale`, el costo de las `promocion_vinotecas`, que no tienen alícuota
 * propia. Devolviendo el crédito, lo que no se puede atribuir simplemente no se netea y
 * `total_cost` sigue mandando; recalculando el total, esas promociones desaparecerían del costo.
 * Invariante que se sostiene sí o sí: **el crédito nunca puede ser mayor que el costo**.
 */
class CostoDeVentaHelper
{
    /**
     * Alias del join a `articles` que usa la expresión SQL. Va con alias propio (y no como
     * `articles` a secas) para no chocar con un join que el caller ya tuviera hecho por su cuenta.
     */
    const ALIAS_ARTICULO = 'articulo_del_costo';

    /** Alias del join a `ivas`, por el mismo motivo. */
    const ALIAS_IVA = 'iva_del_costo';

    /**
     * ¿El costo guardado en `article_sale.cost` de esta cuenta trae el IVA adentro?
     *
     * No inventa un criterio nuevo: compone los dos que ya resuelven el pipeline de precios. El
     * costo es bruto por dos caminos distintos, y hay que mirar los dos —ver la tabla del PHPDoc de
     * la clase.
     *
     * @param  \App\Models\User|null $user
     * @return bool
     */
    public static function el_costo_es_bruto($user)
    {
        if (ArticlePricesHelper::iva_va_al_costo($user)) {
            // Cuenta legacy con la tilde prendida: el IVA se suma al costo antes del margen.
            return true;
        }

        if (!ArticlePricesHelper::el_iva_participa_del_precio($user)) {
            // Monotributista migrado: el costo se guarda tal cual lo carga, con el IVA adentro.
            return true;
        }

        return false;
    }

    /**
     * ¿Ese IVA del costo es crédito fiscal recuperable, o sea, hay algo que netear?
     *
     * Las dos preguntas son distintas y separarlas es lo que hace que el Monotributista no se rompa:
     * él tiene el costo bruto y NO recupera nada.
     *
     * @param  \App\Models\User|null $user
     * @return bool
     */
    public static function hay_credito_fiscal_en_el_costo($user)
    {
        if (!self::el_costo_es_bruto($user)) {
            return false;
        }

        return !ArticlePricesHelper::es_monotributista_para_costeo($user);
    }

    /**
     * Mide el crédito fiscal contenido en el costo de un lote de ventas, en UNA query (o en
     * ninguna, si la cuenta no tiene el costo bruto).
     *
     * @param  iterable $ventas Modelos `Sale` (o cualquier objeto con `id`).
     * @param  \App\Models\User|null $user Dueño de las ventas. Si viene null se resuelve solo.
     * @return array<int,float> Crédito por id de venta, siempre con una entrada por venta pedida.
     */
    public static function medir_ventas($ventas, $user = null)
    {
        /** Crédito por venta, inicializado en 0 para todas las pedidas. */
        $credito = [];

        foreach ($ventas as $venta) {
            $credito[(int) $venta->id] = 0.0;
        }

        if (count($credito) === 0) {
            return [];
        }

        if (is_null($user)) {
            $user = self::user_de_la_venta(self::primera($ventas));
        }

        if (!self::hay_credito_fiscal_en_el_costo($user)) {
            return $credito;
        }

        $filas = DB::table('article_sale')
            ->leftJoin('articles', 'articles.id', '=', 'article_sale.article_id')
            ->leftJoin('ivas', 'ivas.id', '=', 'articles.iva_id')
            ->whereIn('article_sale.sale_id', array_keys($credito))
            ->select([
                'article_sale.sale_id',
                'article_sale.cost',
                'article_sale.amount',
                'article_sale.iva_percentage',
                'articles.aplicar_iva',
                DB::raw('ivas.percentage as alicuota_del_articulo'),
            ])
            ->get();

        foreach ($filas as $fila) {

            $sale_id = (int) $fila->sale_id;

            if (!isset($credito[$sale_id])) {
                continue;
            }

            $credito[$sale_id] += self::credito_de_la_linea($fila);
        }

        foreach ($credito as $sale_id => $valor) {
            $credito[$sale_id] = round($valor, 2);
        }

        return $credito;
    }

    /**
     * Mide el crédito fiscal contenido en el costo de UNA venta. Es el camino del guardado en vivo
     * (`SaleHelper::set_sale_ganancia()`) y llama a `medir_ventas()` para que no pueda divergir del
     * backfill.
     *
     * @param  \App\Models\Sale $sale
     * @return float
     */
    public static function medir_venta($sale)
    {
        $medicion = self::medir_ventas([$sale]);

        $id = (int) $sale->id;

        return isset($medicion[$id]) ? $medicion[$id] : 0.0;
    }

    /**
     * Crédito fiscal contenido en el costo de UNA línea de venta.
     *
     * Reproduce la condición exacta de `ArticlePricesHelper::aplicar_iva()`: si al costo de ese
     * artículo nunca se le sumó IVA, acá no hay nada que sacar y la línea aporta 0. Aportar 0 es
     * siempre el lado seguro: deja el costo como estaba, que es lo que el sistema venía informando.
     *
     * @param  mixed $fila Fila cruda de la query de `medir_ventas()`.
     * @return float
     */
    private static function credito_de_la_linea($fila)
    {
        if (is_null($fila->cost) || is_null($fila->amount)) {
            return 0.0;
        }

        /*
         * `aplicar_iva` apagado: ese costo se guardó NETO aunque la cuenta tenga la tilde puesta
         * (ver ArticlePricesHelper::aplicar_iva(), que arranca con ese if). Un null acá es un
         * artículo que ya no existe, y sin poder confirmar la condición tampoco se netea.
         */
        if (is_null($fila->aplicar_iva) || !((int) $fila->aplicar_iva)) {
            return 0.0;
        }

        $alicuota = self::alicuota_de_la_linea($fila);

        if (is_null($alicuota) || $alicuota <= 0) {
            // Exento, No Gravado, 0 % o sin alícuota utilizable: hasIva() lo habría cortado también.
            return 0.0;
        }

        /** Costo BRUTO de la línea entera, con la misma cuenta que hace set_total_cost(). */
        $costo_bruto = (float) $fila->cost * (float) $fila->amount;

        return $costo_bruto - ($costo_bruto / (1 + ($alicuota / 100)));
    }

    /**
     * Alícuota de una línea: la persistida en el pivot al momento de la venta y, sólo si la columna
     * está VACÍA, la que tiene hoy el artículo.
     *
     * 🔴 "Vacía" es null o cadena vacía, y no incluye 'Exento' ni 'No Gravado': esas son alícuotas
     * fiscales reales, y son justamente la respuesta de que esa línea no lleva IVA. Cayéndose a la
     * del artículo, una línea vendida como exenta cuyo artículo hoy tiene 21 % se netearía un 21 %
     * que nunca pagó. Es, además, la misma regla que la expresión SQL (`COALESCE` sobre `NULLIF`),
     * y que las dos coincidan es lo que hace que la tarjeta del reporte y `sales.ganancia` no puedan
     * divergir.
     *
     * @param  mixed $fila
     * @return float|null Null cuando no hay una alícuota numérica utilizable.
     */
    private static function alicuota_de_la_linea($fila)
    {
        if (!self::esta_vacio($fila->iva_percentage)) {
            return self::alicuota_numerica($fila->iva_percentage);
        }

        return self::alicuota_numerica($fila->alicuota_del_articulo);
    }

    /**
     * ¿Esta columna de texto no trae ningún valor? (null o cadena vacía).
     *
     * @param  mixed $valor
     * @return bool
     */
    private static function esta_vacio($valor)
    {
        return is_null($valor) || trim((string) $valor) === '';
    }

    /**
     * Convierte a float un valor de `iva_percentage` / `ivas.percentage`, que son columnas de TEXTO
     * y pueden traer etiquetas fiscales ('Exento', 'No Gravado') además de números.
     *
     * @param  mixed $valor
     * @return float|null
     */
    private static function alicuota_numerica($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        if ($valor === '' || !is_numeric($valor)) {
            return null;
        }

        return (float) $valor;
    }

    // =========================================================================================
    // LA MISMA CUENTA, PERO EN SQL (Estado de Resultados)
    // =========================================================================================

    /**
     * Expresión SQL del costo NETO de una línea de pivot, y —si hace falta— los joins que esa
     * expresión necesita, agregados al `$query` que se le pasa.
     *
     * 🔴 POR QUÉ EXISTE, aparte de los métodos de arriba. El Estado de Resultados suma el costo de
     * decenas de miles de líneas y además lo pagina: traerlas a PHP no es una opción. Pero el
     * criterio no puede vivir en dos lugares, así que lo que se duplica es la sintaxis, no la regla:
     * esta expresión y `credito_de_la_linea()` preguntan exactamente lo mismo —`aplicar_iva`
     * prendido y alícuota numérica mayor a cero, con la del pivot primero y la del artículo sólo si
     * la columna está vacía— y el test que las compara sobre la misma venta es lo que impide que se
     * separen.
     *
     * 🔴 LOS JOINS NO PUEDEN MULTIPLICAR FILAS, y eso es obligatorio: los dos van contra una PK
     * (`article_sale.article_id → articles.id` y `articles.iva_id → ivas.id`), así que cada línea
     * sigue dando una fila. Es lo que permite que el `count()` del drill-down siga contando lo
     * mismo. No se filtra por `articles.deleted_at` a propósito: la relación `Sale::articles()` usa
     * `withTrashed()`, o sea que un artículo borrado sigue aportando su costo, y filtrarlo acá haría
     * que la tarjeta dejara de coincidir con `sales.total_cost`.
     *
     * ⚠️ Cuando la cuenta NO tiene el costo bruto —casi todas— devuelve el `cost * amount` de
     * siempre y no agrega un solo join: la query queda idéntica a la de antes de esta misión.
     *
     * @param  mixed $query Builder (Eloquent o Query) sobre el que se van a agregar los joins.
     * @param  string $tabla Nombre del pivot: `article_sale` o `article_current_acount`.
     * @param  \App\Models\User|null $user Dueño de los datos.
     * @return string Expresión SQL lista para meter en un `SUM()` o en un `select`.
     */
    public static function expresion_costo_neto_de_linea($query, $tabla, $user)
    {
        /** Costo BRUTO de la línea entera: la misma cuenta que hacen set_total_cost() y el CMV. */
        $costo_bruto = '('.$tabla.'.cost * '.$tabla.'.amount)';

        if (!self::hay_credito_fiscal_en_el_costo($user)) {
            return $costo_bruto;
        }

        self::aplicar_joins_de_alicuota($query, $tabla);

        /**
         * Alícuota de la línea. El `NULLIF` es lo que hace que 'Exento' NO caiga a la del artículo
         * (ver alicuota_de_la_linea()): sólo se cae cuando la columna está vacía de verdad.
         *
         * El `CAST` reemplaza a un `REGEXP` a propósito: MySQL convierte 'Exento' y 'No Gravado' a 0
         * —con warning, no con error—, que es exactamente la respuesta que se necesita, y así no hay
         * que pelear con el doble escapado de la barra invertida entre PHP, la cadena de MySQL y el
         * motor de expresiones regulares.
         */
        $alicuota = 'CAST(COALESCE(NULLIF('.$tabla.".iva_percentage, ''), ".self::ALIAS_IVA.'.percentage) AS DECIMAL(10,4))';

        /** Mismo guard que ArticlePricesHelper::aplicar_iva(): sin la tilde no se sumó IVA alguno. */
        $linea_es_bruta = '('.self::ALIAS_ARTICULO.'.aplicar_iva = 1 AND '.$alicuota.' > 0)';

        return '(CASE WHEN '.$linea_es_bruta
            .' THEN '.$costo_bruto.' / (1 + ('.$alicuota.' / 100))'
            .' ELSE '.$costo_bruto.' END)';
    }

    /**
     * Agrega al query los dos joins que necesita `expresion_costo_neto_de_linea()`, una sola vez.
     *
     * Van con alias propios para no chocar con un join a `articles` que el caller ya tuviera hecho
     * por su cuenta, y el guard de reentrada es porque la tarjeta y su drill-down comparten la misma
     * query base.
     *
     * @param  mixed $query
     * @param  string $tabla
     * @return void
     */
    private static function aplicar_joins_de_alicuota($query, $tabla)
    {
        if (self::ya_tiene_los_joins($query)) {
            return;
        }

        $query->leftJoin('articles as '.self::ALIAS_ARTICULO, self::ALIAS_ARTICULO.'.id', '=', $tabla.'.article_id')
              ->leftJoin('ivas as '.self::ALIAS_IVA, self::ALIAS_IVA.'.id', '=', self::ALIAS_ARTICULO.'.iva_id');
    }

    /**
     * ¿Este query ya tiene puestos los joins de alícuota?
     *
     * @param  mixed $query
     * @return bool
     */
    private static function ya_tiene_los_joins($query)
    {
        $base = method_exists($query, 'getQuery') ? $query->getQuery() : $query;

        if (!isset($base->joins) || !is_array($base->joins)) {
            return false;
        }

        foreach ($base->joins as $join) {

            if (isset($join->table) && $join->table === 'articles as '.self::ALIAS_ARTICULO) {
                return true;
            }
        }

        return false;
    }

    /**
     * Usuario dueño de un conjunto de datos, resuelto por id.
     *
     * Es el camino que usa `ContabilidadRepository`, que recibe el `$user_id` como parámetro:
     * `EstadoResultadosHelper` tiene prohibido `auth()` (el cron `SetCompanyPerformances` lo llama
     * sin sesión HTTP) y tiene prohibido consultar la base por fuera del repository.
     *
     * A propósito NO cachea: los tests cambian las tildes fiscales del usuario en medio de una
     * corrida, y una caché estática les devolvería la configuración vieja.
     *
     * @param  int|null $user_id
     * @return \App\Models\User|null
     */
    public static function user_por_id($user_id)
    {
        if (is_null($user_id)) {
            return null;
        }

        return User::find($user_id);
    }

    /**
     * Usuario dueño de una venta.
     *
     * Se prefiere la relación del modelo (Eloquent la cachea en la instancia, así que el guardado en
     * vivo no paga una query extra por venta) y se cae a `config('app.USER_ID')` cuando la venta vino
     * de un `select()` acotado que no trajo `user_id` — que es exactamente el caso del backfill, y
     * ahí ese id es el mismo por el que el comando filtra.
     *
     * @param  mixed $venta
     * @return \App\Models\User|null
     */
    public static function user_de_la_venta($venta)
    {
        if (!is_null($venta) && isset($venta->user_id) && !is_null($venta->user_id)) {

            if (method_exists($venta, 'user')) {
                return $venta->user;
            }

            return User::find((int) $venta->user_id);
        }

        $user_id = config('app.USER_ID');

        if (is_null($user_id)) {
            return null;
        }

        return User::find($user_id);
    }

    /**
     * Primer elemento de un iterable, sin asumir que es un array indexado.
     *
     * @param  iterable $ventas
     * @return mixed|null
     */
    private static function primera($ventas)
    {
        foreach ($ventas as $venta) {
            return $venta;
        }

        return null;
    }
}
