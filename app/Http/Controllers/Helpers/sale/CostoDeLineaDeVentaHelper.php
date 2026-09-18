<?php

namespace App\Http\Controllers\Helpers\sale;

use Illuminate\Support\Facades\DB;

/**
 * Criterio ÚNICO de "¿esta línea de venta tiene el costo roto, y en cuánto hay que corregirlo?"
 * (misión saneo-ganancia-ventas, 17/9/2026).
 *
 * ─── Por qué existe ───────────────────────────────────────────────────────────────────────────
 *
 * 🔴 Dos comandos necesitan responder la MISMA pregunta y tienen que responderla IGUAL:
 *
 * | Quién | Para qué |
 * |---|---|
 * | `sale:sanear-costo-de-linea` | decidir qué líneas corrige y con qué valor |
 * | `set_costo_ventas` | negarse a correr si queda histórico roto sin sanear (su guarda de orden) |
 *
 * Y no es una coincidencia estética: si la guarda frenara por líneas que el saneo después **se
 * niega a tocar**, el upgrade de ese cliente queda trabado para siempre y sus dos únicas salidas son
 * malas — `--force`, que destruye el histórico que todavía era reparable, o quedarse trabado.
 *
 * Hasta el 17/9/2026 el criterio estaba escrito DOS veces: en PHP adentro del saneo y en SQL adentro
 * de la guarda. El PHPDoc de la guarda afirmaba que eran "exactamente el mismo" y era falso: la
 * guarda era una condición **necesaria** y el saneo aplicaba descartes adicionales después. Tres
 * divergencias medidas, cada una un cliente trabado sin salida:
 *
 * | # | La guarda contaba | El saneo descartaba |
 * |---|---|---|
 * | a | `price` no null, sin exigir `price > 0` | `price <= 0` → `firma_del_comando_sin_precio_para_acotar_k` |
 * | b | sólo joineaba `sales` | INNER JOIN a `articles`: la línea huérfana ni se mira |
 * | c | no verificaba que existiera `k` | `buscar_k` null → `firma_del_comando_sin_k_coherente` |
 *
 * (y una cuarta que el chequeo no había listado: `pivot_con_unidades_individuales_historicas_distintas`).
 *
 * Por eso el criterio vive acá, en un solo lugar, y los dos comandos lo LLAMAN. La paridad deja de
 * ser una promesa de un comentario y pasa a ser una propiedad del código — verificada además por
 * `tests/Feature/Sales/30_Paridad_Guarda_Y_Saneo_Test`.
 *
 * ─── La convención que se repara ──────────────────────────────────────────────────────────────
 *
 * 🔴 `article_sale.cost` es el costo UNITARIO y `article_sale.ganancia` es el total de la línea. Lo
 * fijan tres lugares que ya están en producción y que multiplican por la cantidad DESPUÉS de leer el
 * costo: `SaleHelper::attachArticle()`, `SaleTotalesHelper::set_total_cost()` y
 * `ContabilidadRepository::costo_mercaderia_vendida()`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class CostoDeLineaDeVentaHelper
{
    /**
     * Umbral de incoherencia entre costo y precio de una línea: por encima de este múltiplo del
     * precio, la pérdida de la línea supera su propia facturación. Es el mismo criterio de
     * `herramientas/sql/costo_sin_dividir_unidades_individuales.sql`.
     */
    const FACTOR_COSTO_INCOHERENTE = 2.0;

    /**
     * Tope de divisiones por la cantidad que se prueban en la causa B. Es el default de
     * `sale:sanear-costo-de-linea --k_max`, y por lo tanto el que la guarda tiene que asumir: es el
     * comando que la guarda misma le imprime al operador.
     */
    const K_MAX_POR_DEFECTO = 4;

    /**
     * Query base de las líneas de venta de UN cliente, con las columnas que necesita `analizar()`.
     *
     * 🔴 El `join` a `articles` NO es decorativo y es una de las tres divergencias que se cerraron:
     * `article_sale` no tiene foreign keys, así que una línea cuyo artículo se borró en duro existe
     * de verdad. Con el INNER JOIN esa línea no entra — ni al saneo ni a la guarda —, que es lo
     * correcto: sin el artículo no hay `unidades_individuales` con qué corregirla, y una guarda que
     * la contara dejaría al cliente trabado sin ninguna forma de destrabarlo.
     *
     * Devuelve un builder NUEVO en cada llamada: quien lo reciba le agrega sus propios filtros
     * (rango de fechas, venta puntual, desde qué id) sin ensuciarle el criterio al otro.
     *
     * @param  int $user_id
     * @return \Illuminate\Database\Query\Builder
     */
    public static function query_de_lineas($user_id)
    {
        return DB::table('article_sale')
            ->join('sales', 'sales.id', '=', 'article_sale.sale_id')
            ->join('articles', 'articles.id', '=', 'article_sale.article_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.user_id', $user_id)
            ->select(
                'article_sale.id as linea_id',
                'article_sale.sale_id',
                'article_sale.article_id',
                'article_sale.amount',
                'article_sale.price',
                'article_sale.cost',
                'article_sale.ganancia',
                /*
                 * Las dos columnas se llaman igual y hay que distinguirlas: la del articulo es el
                 * valor de HOY, la del pivot —si tuviera algo— seria el valor historico exacto.
                 */
                'articles.unidades_individuales as unidades_del_articulo',
                'article_sale.unidades_individuales as unidades_de_la_linea',
                'sales.num as venta_num',
                'sales.created_at as venta_fecha',
                'sales.to_check as venta_to_check',
                'sales.checked as venta_checked'
            );
    }

    /**
     * Acota un query de `query_de_lineas()` a las líneas que PODRÍAN ser corregibles por la causa B.
     *
     * 🔴 Es un PREFILTRO, no el criterio. Su única razón de ser es no traerse a PHP el histórico
     * entero de un cliente con 109.000 líneas cuando las candidatas son una fracción. Todas sus
     * condiciones son **necesarias** para que `analizar()` devuelva una corrección por causa B:
     *
     *   - `cost > 0`, `amount > 1`, `price` no null y `ganancia` no null → `tiene_firma_del_comando()`
     *     devuelve false sin alguna de ellas (con `amount = 1` las dos firmas son la misma expresión
     *     y la fila no se puede atribuir; con `amount < 1` el saneo descarta por
     *     `cantidad_fraccionada_no_evaluable`);
     *   - `price > 0` → si no, descarte `firma_del_comando_sin_precio_para_acotar_k`;
     *   - `cost > price` → si no, descarte `firma_del_comando_con_costo_plausible_como_unitario`
     *     (la negación exacta de `es_plausible_como_costo_unitario()`);
     *   - las dos firmas → `tiene_firma_del_comando()`, con la misma tolerancia.
     *
     * Lo que NO se decide acá —el `k` coherente, la división por unidades individuales, el costo
     * corregido positivo, las unidades históricas del pivot y la venta en depósito— lo decide
     * `analizar()`, en PHP, que es el único criterio. Quien use este prefiltro tiene que pasar cada
     * fila por `analizar()` antes de contarla.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @return \Illuminate\Database\Query\Builder
     */
    public static function acotar_a_candidatas_de_causa_b($query)
    {
        return $query
            ->whereNotNull('article_sale.price')
            ->whereNotNull('article_sale.ganancia')
            ->where('article_sale.price', '>', 0)
            ->where('article_sale.cost', '>', 0)
            ->where('article_sale.amount', '>', 1)
            ->whereColumn('article_sale.cost', '>', 'article_sale.price')
            // Cumple la firma que dejaba el comando viejo...
            ->whereRaw('ABS(article_sale.ganancia - (article_sale.price * article_sale.amount - article_sale.cost)) <= 0.02 * GREATEST(1, ABS(article_sale.amount)) + 0.05')
            // ...y NO cumple la firma sana, que es lo que las vuelve mutuamente excluyentes.
            ->whereRaw('ABS(article_sale.ganancia - ((article_sale.price - article_sale.cost) * article_sale.amount)) > 0.02 * GREATEST(1, ABS(article_sale.amount)) + 0.05');
    }

    /**
     * Clasifica una línea y, si corresponde, calcula el costo unitario correcto.
     *
     * Devuelve siempre un array con:
     *
     *   - `accion`: `'ninguna'` | `'corregir'` | `'descartar'`
     *   - `cost_final`: el costo unitario que habría que persistir (sólo con `corregir`)
     *   - `ganancia_final`: la ganancia TOTAL de la línea con ese costo (sólo con `corregir`)
     *   - `causas`: subconjunto de `['A', 'B']`
     *   - `k`: cuántas veces se dividió por la cantidad en la causa B
     *   - `motivo`: el descarte, cuando `accion` es `'descartar'`
     *   - `contador`: nombre del contador informativo que le toca, o null
     *   - `tiene_unidades_en_el_pivot`: si la línea trae `article_sale.unidades_individuales`
     *
     * @param  object $fila Fila de `query_de_lineas()`.
     * @param  bool $hacer_a
     * @param  bool $hacer_b
     * @param  int $k_max
     * @return array
     */
    public static function analizar($fila, $hacer_a, $hacer_b, $k_max)
    {
        $sana = [
            'accion' => 'ninguna',
            'cost_final' => null,
            'causas' => [],
            'k' => null,
            'motivo' => null,
            'contador' => null,
            'tiene_unidades_en_el_pivot' => !is_null($fila->unidades_de_la_linea),
        ];

        if (is_null($fila->cost)) {
            $sana['contador'] = 'lineas_sin_costo';

            return $sana;
        }

        $cost = (float) $fila->cost;
        $price = is_null($fila->price) ? null : (float) $fila->price;
        $amount = (float) $fila->amount;
        $ganancia = is_null($fila->ganancia) ? null : (float) $fila->ganancia;
        $unidades = is_null($fila->unidades_del_articulo) ? null : (float) $fila->unidades_del_articulo;

        $sana['cost_final'] = $cost;

        /*
         * Una linea con cantidad fraccionada que paso por set_costo_ventas quedo con el costo
         * DIVIDIDO, no multiplicado, y no hay criterio que la distinga de una sana. Se informa.
         */
        if ($amount < 1 && $hacer_b && self::tiene_firma_del_comando($cost, $price, $amount, $ganancia)) {
            return self::descartar($sana, 'cantidad_fraccionada_no_evaluable');
        }

        $causas = [];
        $k = null;
        $cost_final = $cost;
        $unidades = (!is_null($unidades) && $unidades > 1) ? $unidades : null;

        /* ── Causa B ─────────────────────────────────────────────────────────────────────── */

        if ($hacer_b && self::tiene_firma_del_comando($cost, $price, $amount, $ganancia)) {
            if (is_null($price) || $price <= 0) {
                return self::descartar($sana, 'firma_del_comando_sin_precio_para_acotar_k');
            }

            /*
             * 🔴 La firma sola NO alcanza: hay un camino en vivo que la produce sobre una linea
             * PERFECTAMENTE SANA. Ver `es_plausible_como_costo_unitario()`.
             */
            if (self::es_plausible_como_costo_unitario($cost, $price)) {
                return self::descartar($sana, 'firma_del_comando_con_costo_plausible_como_unitario');
            }

            /*
             * Si el articulo tiene unidades individuales, el costo que el comando multiplico
             * puede ser el del bulto entero (o sea las dos causas encima de la misma linea).
             * En ese caso el techo de coherencia de esta etapa sube por ese factor, o si no el
             * buscador de k se comeria la division de las unidades individuales y devolveria un
             * costo partido de mas. Lo que sobre lo divide despues la causa A.
             */
            $techo = $price * self::FACTOR_COSTO_INCOHERENTE * (is_null($unidades) ? 1 : $unidades);

            $k = self::buscar_k($cost, $techo, $amount, $k_max);

            if (is_null($k)) {
                return self::descartar($sana, 'firma_del_comando_sin_k_coherente');
            }

            $cost_final = $cost / pow($amount, $k);
            $causas[] = 'B';
        }

        /* ── Causa A ─────────────────────────────────────────────────────────────────────── */

        $es_incoherente = !is_null($price)
            && $price > 0
            && $cost_final > $price * self::FACTOR_COSTO_INCOHERENTE;

        $tiene_unidades_individuales = !is_null($unidades);

        if ($es_incoherente && $tiene_unidades_individuales && $hacer_a) {
            $candidato = $cost_final / $unidades;

            if ($candidato > 0 && $candidato <= $price * self::FACTOR_COSTO_INCOHERENTE) {
                $cost_final = $candidato;
                $causas[] = 'A';
            } else {
                return self::descartar($sana, 'dividir_por_unidades_individuales_no_alcanza');
            }
        } elseif ($es_incoherente && $tiene_unidades_individuales) {
            return self::descartar($sana, 'causa_a_no_pedida_en_esta_corrida');
        } elseif ($es_incoherente) {
            /*
             * El costo supera el doble del precio pero no hay ninguna causa identificada: sin la
             * firma del comando y sin unidades individuales, puede ser perfectamente una venta
             * legitima a perdida. No se toca: se lista.
             */
            return self::descartar($sana, 'costo_incoherente_sin_causa_identificada');
        }

        if (count($causas) === 0) {
            $sana['contador'] = 'lineas_sanas';

            return $sana;
        }

        $cost_final = round($cost_final, 2);

        if ($cost_final <= 0) {
            return self::descartar($sana, 'costo_corregido_no_positivo');
        }

        /*
         * El pivot tiene su propia columna `unidades_individuales`. Si trae un valor distinto del
         * que tiene el articulo hoy, ese es el valor historico y la division de la causa A —que se
         * hizo con el de hoy— estaria corrigiendo con el numero equivocado. Nadie midio nunca que
         * guarda esa columna, asi que no se adivina: se deja la linea sin tocar y se denuncia.
         */
        if (!is_null($fila->unidades_de_la_linea)) {
            $de_la_linea = (float) $fila->unidades_de_la_linea;
            $del_articulo = is_null($fila->unidades_del_articulo) ? null : (float) $fila->unidades_del_articulo;

            if (is_null($del_articulo) || abs($de_la_linea - $del_articulo) > 0.005) {
                return self::descartar($sana, 'pivot_con_unidades_individuales_historicas_distintas');
            }
        }

        /*
         * 🔴 Venta en deposito (`to_check` o `checked`): `SaleTotalesHelper::set_total_cost()`
         * devuelve NULL para estas ventas a proposito. Corregir una sola de sus lineas obliga a
         * recalcular la venta, y ese recalculo le BLANQUEA el `total_cost` a la venta entera. Se
         * gana un costo de linea y se pierde el total de la venta: no vale la pena.
         */
        if ((int) $fila->venta_to_check === 1 || (int) $fila->venta_checked === 1) {
            return self::descartar($sana, 'venta_en_deposito_el_recalculo_blanquearia_su_total_cost');
        }

        return [
            'accion' => 'corregir',
            'cost_final' => $cost_final,
            'ganancia_final' => round((is_null($price) ? 0 : $price - $cost_final) * $amount, 2),
            'causas' => $causas,
            'k' => $k,
            'motivo' => null,
            'contador' => null,
            'tiene_unidades_en_el_pivot' => $sana['tiene_unidades_en_el_pivot'],
        ];
    }

    /**
     * ¿El análisis termina en una corrección que involucra a la causa B?
     *
     * Es lo que la guarda de orden de `set_costo_ventas` cuenta, y no "cualquier corrección": lo que
     * ese comando destruye al correr antes de tiempo es la FIRMA de la causa B, que es la única marca
     * que vuelve reparable a esa línea. Una línea de causa A se detecta por `cost > price × 2` más
     * `unidades_individuales`, que el recálculo de la ganancia no toca — sigue siendo reparable
     * después, así que no hay por qué trabar el upgrade por ella.
     *
     * @param  array $analisis
     * @return bool
     */
    public static function corrige_por_causa_b($analisis)
    {
        return $analisis['accion'] === 'corregir' && in_array('B', $analisis['causas'], true);
    }

    /**
     * ¿La fila la escribio `set_costo_ventas` con el bug del costo total?
     *
     * Firma: ganancia = price × amount − cost, que es lo que persistia el comando. La linea sana
     * cumple ganancia = (price − cost) × amount. Con amount > 1 y cost > 0 las dos no pueden
     * cumplirse a la vez.
     *
     * 🔴 LA FIRMA SOLA NO ALCANZA PARA ESCRIBIR. Una linea sana a la que le editaron el precio
     * despues de la venta (`SaleHelper::updateItemsPrices()`) cumple esta misma firma sin estar
     * rota. Todo lo que cumpla la firma tiene que pasar ademas por
     * `es_plausible_como_costo_unitario()`, que es donde vive esa guarda y donde esta el caso
     * medido.
     *
     * @param  float $cost
     * @param  float|null $price
     * @param  float $amount
     * @param  float|null $ganancia
     * @return bool
     */
    private static function tiene_firma_del_comando($cost, $price, $amount, $ganancia)
    {
        /*
         * Con amount = 1 las dos firmas son la misma expresion (y el bug no inflaba nada), asi
         * que la fila no se puede atribuir. Con amount = 0 tampoco hay nada que dividir.
         */
        if (is_null($ganancia) || is_null($price) || $cost <= 0 || $amount <= 0 || $amount == 1.0) {
            return false;
        }

        $tolerancia = self::tolerancia($amount);

        $firma_del_comando = abs($ganancia - ($price * $amount - $cost)) <= $tolerancia;
        $firma_sana = abs($ganancia - (($price - $cost) * $amount)) <= $tolerancia;

        return $firma_del_comando && !$firma_sana;
    }

    /**
     * 🔴 El costo guardado, ¿todavia sirve como costo unitario de esa misma linea?
     *
     * Esta es la guarda que separa una linea ROTA de una linea SANA a la que alguien le edito el
     * precio, porque las dos pueden cumplir la firma de la causa B.
     *
     * El camino del falso positivo: `SaleHelper::updateItemsPrices()` (llamado desde
     * `SaleController::updatePrices()`) reescribe `price` y `price_sin_iva` de la linea y NO
     * recalcula `ganancia`. La linea queda con la ganancia del precio VIEJO y el precio NUEVO, y
     * eso cae justo sobre la firma cada vez que `price_viejo − price_nuevo = cost × (amount−1) / amount`:
     *
     *     cost 100, amount 2, precio 300 editado a 250  →  ganancia = (300 − 100) × 2 = 400
     *     firma de la causa B:  250 × 2 − 100 = 400  ✓   (y la linea esta perfectamente sana)
     *
     * Sin esta guarda el saneo le escribia `cost = 50`: el costo de un negocio real partido al
     * medio. El mismo vector abre `HelperController::set_sales_cost()`, que pisa `cost` sin tocar
     * `ganancia`.
     *
     * Lo que los separa es que en la linea rota el valor guardado es un TOTAL metido en una columna
     * unitaria —o sea `costo_unitario × cantidad`, con cantidad > 1—, mientras que en el falso
     * positivo el valor guardado sigue siendo el costo unitario de verdad. Un costo unitario que no
     * llega al precio unitario de su propia linea es un costo unitario plausible, y sobre eso no se
     * escribe.
     *
     * Lo que esta guarda deja afuera, y se acepta: las lineas genuinamente rotas de margen alto,
     * donde el costo inflado igual quedo por debajo del precio (`costo_unitario × cantidad ≤ price`,
     * por ejemplo costo 100, cantidad 2 y precio 1000). Salen listadas con el motivo
     * `firma_del_comando_con_costo_plausible_como_unitario` y se pueden mirar a mano. Corregir de
     * menos se arregla despues; corregir de mas ya rompio el dato.
     *
     * ⚠️ El otro criterio que se evaluo —descartar las lineas cuyo `updated_at` no coincida con el
     * `created_at`— NO SIRVE EN ESTE REPO: la relacion `Sale::articles()` no declara
     * `withTimestamps()`, asi que ni `attach()` ni `updateExistingPivot()` escriben
     * `article_sale.updated_at`, y `set_costo_ventas` escribe con `DB::table()->update()`, que
     * tampoco lo toca. Medido el 17/9/2026 sobre `empresa_testing_s23`: 24 filas, 24 con
     * `created_at` y 0 con `updated_at`. La columna esta siempre en NULL, asi que el criterio
     * habria descartado todo o nada segun como se lo escribiera.
     *
     * @param  float $cost
     * @param  float $price
     * @return bool
     */
    private static function es_plausible_como_costo_unitario($cost, $price)
    {
        return $cost <= $price;
    }

    /**
     * Margen de comparacion. Las columnas son decimal(x,2), asi que precio y costo ya vienen
     * redondeados: multiplicar por la cantidad arrastra ese redondeo.
     *
     * 🔴 La misma expresión está escrita en SQL en `acotar_a_candidatas_de_causa_b()`, que es un
     * prefiltro del mismo criterio. Si esta cambia, aquélla cambia.
     *
     * @param  float $amount
     * @return float
     */
    private static function tolerancia($amount)
    {
        return 0.02 * max(1.0, abs($amount)) + 0.05;
    }

    /**
     * Menor cantidad de divisiones por la cantidad que deja el costo por debajo del techo de
     * coherencia. Arranca en 1 porque la firma ya probo que el costo se multiplico al menos una
     * vez, y devuelve el MENOR k que entra: la correccion minima, para sub-corregir antes que
     * sobre-corregir.
     *
     * @param  float $cost
     * @param  float $techo
     * @param  float $amount
     * @param  int $k_max
     * @return int|null
     */
    private static function buscar_k($cost, $techo, $amount, $k_max)
    {
        for ($k = 1; $k <= $k_max; $k++) {
            $candidato = $cost / pow($amount, $k);

            if ($candidato > 0 && $candidato <= $techo) {
                return $k;
            }
        }

        return null;
    }

    /**
     * @param  array $sana
     * @param  string $motivo
     * @return array
     */
    private static function descartar($sana, $motivo)
    {
        $sana['accion'] = 'descartar';
        $sana['motivo'] = $motivo;

        return $sana;
    }
}
