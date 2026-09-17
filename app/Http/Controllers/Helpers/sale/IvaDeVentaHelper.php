<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\Afip\AfipWsHelper;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Criterio ÚNICO de "cuánto IVA declaró efectivamente una venta" (misión saneo-ganancia-ventas,
 * 17/9/2026).
 *
 * Existe porque tres consumidores necesitan el MISMO número y antes no existía ninguno:
 *
 * | Consumidor | Para qué |
 * |---|---|
 * | `SaleHelper::set_sale_ganancia()` | `sales.ganancia = total − total_cost − IVA declarado` |
 * | `app/Console/Commands/set_sales_ganancia.php` | el backfill de esa misma columna |
 * | `ContabilidadRepository::ventas_brutas()` | el renglón "Ventas netas" del Estado de Resultados |
 *
 * 🔴 EL IVA SALE DEL COMPROBANTE, NUNCA DE LA CONDICIÓN FISCAL DEL NEGOCIO.
 *
 * La solución que parece obvia —dividir el precio por (1 + alícuota) mirando
 * `condicion_iva_precios` / `usar_condicion_fiscal_en_costeo` / `aplicar_iva_al_costo`— es la
 * equivocada, y no por gusto: **no contempla la venta sin comprobante**, que es el 63 % de las
 * ventas de ferretotal y el 51 % de las de golonorte (medido el 17/9/2026 por SSH al VPS). En esas
 * el IVA cobrado se lo queda el negocio y la fórmula vieja (`total − total_cost`) ya era correcta.
 * Yendo por el comprobante caen solos todos los casos, sin una sola rama por condición fiscal:
 *
 * | Caso | IVA declarado | Por qué está bien |
 * |---|---|---|
 * | Venta facturada (RI) | el del comprobante | ese IVA va a ARCA, no es ganancia |
 * | Venta sin comprobante | 0 | no hay comprobante, no hay débito fiscal |
 * | Factura C (monotributista) | 0 | la C no discrimina IVA: `importe_iva` queda en 0 |
 * | **Factura E (exportación)** | **0** | **una exportación no tiene IVA (ver abajo)** |
 * | Facturación parcial | el de la parte facturada | es lo que dice el comprobante emitido |
 * | Alícuotas mixtas 21/10,5 | el total real del comprobante | no hay que reconstruir nada |
 * | Comprobante rechazado | 0 | `resultado != 'A'` no entra |
 *
 * ---------------------------------------------------------------------------------------------
 * 🔴 LA EXPORTACIÓN ES IVA 0, NO "SIN MEDIR" — y por qué se arregla en los DOS lados
 * ---------------------------------------------------------------------------------------------
 *
 * `AfipFexHelper::update_afip_ticket()` escribía `resultado` y no `importe_iva`, que quedaba en
 * NULL. Con la regla de más abajo (un autorizado sin `importe_iva` es un dato que falta), eso
 * dejaba la ganancia de toda venta exportada en NULL **en el mismo request que emitía la
 * factura**: `MakeAfipTicket::recalcular_ganancia_facturada()` corre inmediatamente después de
 * `AfipWsController::init()`. Y sin salida, porque el comando que mediría ese IVA
 * (`set_iva_debito`) está roto en `develop`.
 *
 * Pero una exportación **no tiene IVA: es 0, no un dato que falta**. WSFEX ni siquiera tiene campo
 * para declararlo, a diferencia de WSFE. El sistema ya lo sabía del otro lado —
 * `AfipNotaCreditoHelper` escribe `importe_iva => 0` para la NC de exportación y `SetIvaNotasCredito`
 * lo dice en su PHPDoc— y faltaba la misma regla del lado de las ventas.
 *
 * **La decisión fue arreglarlo en los dos lugares, y no son dos criterios:**
 *
 *   1. **Acá**, en el `CASE` de `subquery_por_venta()`: es la única capa que responde "cuánto IVA
 *      declaró esta venta", y es la que arregla los comprobantes **ya emitidos**, que son los que
 *      hoy tienen la ganancia en NULL. Si se arreglara sólo en la emisión, esas ventas quedarían
 *      rotas para siempre — no hay backfill que las salve.
 *   2. **En `AfipFexHelper`**, escribiendo el 0 al emitir: deja la columna con el dato correcto para
 *      cualquier otro lector directo de `afip_tickets.importe_iva` (por ejemplo
 *      `ContabilidadRepository::ventas_con_iva_sin_medir()`, que lee la columna y no pasa por acá).
 *
 * Lo que NO se duplica es el criterio: qué códigos son de exportación se escribe una sola vez, en
 * `AfipWsHelper::CBTE_TIPOS_EXPORTACION`, y los cuatro call sites lo leen de ahí.
 *
 * ---------------------------------------------------------------------------------------------
 * 🔴 LA INVARIANTE, Y POR QUÉ NO ALCANZA CON MIRAR `update_afip_ticket()`
 * ---------------------------------------------------------------------------------------------
 *
 * **La invariante es: todo lugar que escriba `afip_tickets.resultado` escribe `importe_iva` en el
 * MISMO `update()`.** Un `resultado = 'A'` con `importe_iva` en NULL deja la ganancia de esa venta
 * en NULL para siempre, por el encadenamiento de arriba.
 *
 * ⚠️ Hasta el 17/9/2026 este PHPDoc decía que "el camino de WSFE escribe las dos columnas juntas y
 * por eso no tiene esa ventana". **Era falso, y hay que decirlo porque invita a no mirar.**
 * `AfipWsfeHelper` escribe `resultado` en TRES lugares:
 *
 * | Dónde | Escribía `importe_iva` |
 * |---|---|
 * | `AfipWsfeHelper::update_afip_ticket()` — la emisión normal | sí, siempre |
 * | `AfipWsfeHelper::consultar_comprobante()` | **NO, hasta esta misión** |
 * | `AfipWsfeHelper::saveAfipTicket()` | no — pero es código muerto, nadie lo llama |
 *
 * Y a `consultar_comprobante()` no se llega por un camino raro: ante un error de RED al emitir,
 * `solicitar_cae()` lo dispara solo para recuperar el CAE que puede haber quedado autorizado en
 * ARCA, y si lo recupera trata la emisión como exitosa. También se llega a mano desde
 * `AfipTicketController::consultar_comprobante()`. Es el camino de cualquier Responsable Inscripto,
 * que es justamente donde el IVA no es cero. Se cerró en esta misma rama
 * (`AfipWsfeHelper::importe_iva_de_la_consulta()`), tomando el `ImpIVA` que devuelve ARCA y, si no
 * viniera, el snapshot `afip_tickets.imp_iva_enviado`.
 *
 * ✅ **Y eso convierte a `consultar_comprobante()` en la vía de recuperación de los comprobantes que
 * hoy tienen la columna en NULL**: volver a consultar uno le escribe el `importe_iva` que ARCA
 * informa, sin estimar nada y sin depender de `set_iva_debito`.
 *
 * El criterio de fondo (`resultado = 'A'` + `importe_iva`) es el mismo que ya usa
 * `ContabilidadRepository::query_iva_debito()` para el renglón fiscal; acá se agrega lo que aquel
 * no necesita: la atribución a la VENTA (no al período) y el prorrateo de las consolidaciones.
 *
 * 🔴 Soft deletes: `afip_tickets` los tiene desde la migración `2026_04_15_120000`. Las consultas
 * de esta clase van por `DB::table()` (no por el modelo), así que el scope global de `SoftDeletes`
 * NO aplica solo: el `whereNull('afip_tickets.deleted_at')` está escrito a mano y es obligatorio.
 * Sin él, un comprobante anulado seguiría descontando IVA de la ganancia para siempre.
 *
 * 🔴 Un comprobante autorizado SIN `importe_iva` medido NO es un cero. Es un dato que falta, y se
 * devuelve aparte (`sin_medir`) para que cada consumidor decida: la ganancia se persiste en null y
 * el backfill lo cuenta y lo denuncia. Tratarlo como 0 sería contar una venta facturada como si
 * hubiera sido en negro, que es exactamente el error que esta clase existe para no cometer. La
 * única excepción es la exportación, y no es una excepción a la regla sino a la premisa: ahí el 0
 * no se asume, se sabe.
 *
 * ⚠️ Recuperar ese `importe_iva` es una tarea aparte y hoy NO HAY COMANDO QUE LA HAGA —pero sí hay
 * camino, y es el de arriba: **volver a consultar el comprobante** contra ARCA
 * (`AfipTicketController::consultar_comprobante()`, o el botón que lo llama) ahora le escribe el
 * `ImpIVA` que el organismo informa. Es por comprobante y a mano, así que para los 61 medidos abajo
 * conviene un comando que los recorra, pero **ya no depende de rehacer `set_iva_debito`.** El que
 * existe, `php artisan set_iva_debito <company_name>`, está roto en `develop`: muere con
 * `Call to undefined method App\Models\Sale::afip_ticket()` —la relación se llama `afip_tickets`
 * desde hace versiones— y además le pasa la `Sale` a `AfipHelper` en el parámetro donde va el
 * `AfipTicket`, así que aunque se arreglara el nombre de la relación se quedaría sin venta adentro.
 * Verificado corriéndolo el 17/9/2026 en el slot s23. El molde para rehacerlo es
 * `SetIvaNotasCredito` (1/9/2026), que sólo escribe cuando puede REPRODUCIR el valor declarado y no
 * lo estima nunca. Volumen medido el 17/9/2026: 53 comprobantes así en ferretotal y 8 en golonorte.
 */
class IvaDeVentaHelper
{
    /**
     * Subquery agregada por venta: el IVA autorizado de cada `sales.id` y cuántos de sus
     * comprobantes autorizados todavía no tienen el IVA medido.
     *
     * Devuelve un builder NUEVO en cada llamada (nunca uno clonado): un clon arrastraría el
     * `groupBy` hacia los `count()` del caller, que es el error que `ContabilidadRepository`
     * documenta en su regla 05.
     *
     * Las notas de crédito no entran acá: sus `afip_tickets` tienen `sale_id` en null (llevan
     * `nota_credito_id` + `sale_nota_credito_id`, ver `AfipNotaCreditoHelper::create_afip_ticket()`),
     * así que el `whereNotNull('sale_id')` las deja afuera por estructura, no por una lista de
     * `cbte_tipo` que pueda quedar vieja.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public static function subquery_por_venta()
    {
        /** Codigos de exportacion, tipados a int para poder interpolarlos sin riesgo en el SQL. */
        $exportacion = implode(',', array_map('intval', AfipWsHelper::codigos_de_exportacion()));

        return DB::table('afip_tickets')
            ->select([
                'afip_tickets.sale_id',
                DB::raw(
                    'SUM(CASE WHEN afip_tickets.cbte_tipo IN ('.$exportacion.') THEN 0'
                    .' ELSE COALESCE(afip_tickets.importe_iva, 0) END) as iva_declarado'
                ),
                DB::raw(
                    'SUM(CASE WHEN afip_tickets.cbte_tipo IN ('.$exportacion.') THEN 0'
                    .' WHEN afip_tickets.importe_iva IS NULL THEN 1 ELSE 0 END) as comprobantes_sin_medir'
                ),
            ])
            ->whereNotNull('afip_tickets.sale_id')
            ->whereNull('afip_tickets.deleted_at')
            ->where('afip_tickets.resultado', 'A')
            ->groupBy('afip_tickets.sale_id');
    }

    /**
     * Mide el IVA declarado de un lote de ventas de una sola pasada (dos queries en total, sin
     * importar cuántas ventas vengan): es el camino que usa el backfill, que corre sobre bases con
     * decenas de miles de ventas.
     *
     * Cada venta tiene que traer al menos `id`, `total` y `consolidacion_facturacion_id`. Si el
     * caller selecciona columnas a mano y se olvida de `consolidacion_facturacion_id`, las ventas
     * consolidadas quedarían medidas en 0 — por eso el backfill la selecciona explícitamente.
     *
     * @param  iterable $ventas Modelos `Sale` (o cualquier objeto con esas tres propiedades).
     * @return array<int,array{iva: float, sin_medir: int}> Indexado por id de venta.
     */
    public static function medir_ventas($ventas)
    {
        /** Ids de las ventas pedidas. */
        $ids = [];

        /** Ids de las ventas contenedoras de facturación involucradas. */
        $ids_consolidacion = [];

        foreach ($ventas as $venta) {
            $ids[] = (int) $venta->id;

            $consolidacion_id = self::consolidacion_id_de($venta);

            if (!is_null($consolidacion_id)) {
                $ids_consolidacion[] = $consolidacion_id;
            }
        }

        if (count($ids) === 0) {
            return [];
        }

        $ids_consolidacion = array_values(array_unique($ids_consolidacion));

        /** Medición cruda por sale_id (propias + contenedoras, en una sola query). */
        $filas = self::subquery_por_venta()
            ->whereIn('afip_tickets.sale_id', array_values(array_unique(array_merge($ids, $ids_consolidacion))))
            ->get();

        $medido_por_sale_id = [];

        foreach ($filas as $fila) {
            $medido_por_sale_id[(int) $fila->sale_id] = [
                'iva'       => (float) $fila->iva_declarado,
                'sin_medir' => (int) $fila->comprobantes_sin_medir,
            ];
        }

        /** Totales de las contenedoras, para prorratear (ver sumar_consolidacion()). */
        $total_por_consolidacion = [];

        if (count($ids_consolidacion) >= 1) {
            $total_por_consolidacion = Sale::whereIn('id', $ids_consolidacion)
                ->pluck('total', 'id')
                ->all();
        }

        $medicion = [];

        foreach ($ventas as $venta) {
            $id = (int) $venta->id;

            $propio = isset($medido_por_sale_id[$id]) ? $medido_por_sale_id[$id] : ['iva' => 0.0, 'sin_medir' => 0];

            $medicion[$id] = self::sumar_consolidacion($venta, $propio, $medido_por_sale_id, $total_por_consolidacion);
        }

        return $medicion;
    }

    /**
     * Mide el IVA declarado de UNA venta. Es el camino del guardado en vivo
     * (`SaleHelper::set_sale_ganancia()`), y por definición tiene que dar exactamente lo mismo que
     * `medir_ventas()` para esa misma venta — de hecho lo llama, para que no haya dos criterios
     * que puedan divergir.
     *
     * @param  \App\Models\Sale $sale
     * @return array{iva: float, sin_medir: int}
     */
    public static function medir_venta($sale)
    {
        $medicion = self::medir_ventas([$sale]);

        $id = (int) $sale->id;

        return isset($medicion[$id]) ? $medicion[$id] : ['iva' => 0.0, 'sin_medir' => 0];
    }

    /**
     * Suma a la medición propia de una venta la parte que le toca del comprobante de su
     * consolidación de facturación, si es que está adentro de una.
     *
     * 🔴 Por qué hace falta: cuando varias ventas se consolidan para emitir UN solo comprobante
     * (`ConsolidarFacturacionHelper`), el `afip_ticket` cuelga de la venta CONTENEDORA y las
     * originales quedan sin comprobante propio. Sin este prorrateo, cada venta original mediría
     * IVA 0 y se contaría como si hubiera sido en negro — justo lo que este archivo existe para
     * evitar. El prorrateo es exacto porque la contenedora se crea con
     * `total = suma de los total de las originales` (ver `ConsolidarFacturacionHelper::consolidar()`).
     *
     * Si la contenedora tiene total 0 (o desapareció), no hay proporción posible: en vez de
     * inventar una, el comprobante se cuenta como NO medido y la ganancia queda en null.
     *
     * @param  mixed $venta
     * @param  array{iva: float, sin_medir: int} $propio
     * @param  array<int,array{iva: float, sin_medir: int}> $medido_por_sale_id
     * @param  array<int,mixed> $total_por_consolidacion
     * @return array{iva: float, sin_medir: int}
     */
    private static function sumar_consolidacion($venta, $propio, $medido_por_sale_id, $total_por_consolidacion)
    {
        $consolidacion_id = self::consolidacion_id_de($venta);

        if (is_null($consolidacion_id) || !isset($medido_por_sale_id[$consolidacion_id])) {
            return $propio;
        }

        $medido_consolidacion = $medido_por_sale_id[$consolidacion_id];

        $total_consolidacion = isset($total_por_consolidacion[$consolidacion_id])
            ? (float) $total_por_consolidacion[$consolidacion_id]
            : 0.0;

        $total_venta = is_null($venta->total) ? 0.0 : (float) $venta->total;

        if ($total_consolidacion == 0.0) {
            return [
                'iva'       => $propio['iva'],
                'sin_medir' => $propio['sin_medir'] + $medido_consolidacion['sin_medir'] + ($medido_consolidacion['iva'] > 0 ? 1 : 0),
            ];
        }

        $proporcion = $total_venta / $total_consolidacion;

        return [
            'iva'       => $propio['iva'] + round($medido_consolidacion['iva'] * $proporcion, 2),
            'sin_medir' => $propio['sin_medir'] + $medido_consolidacion['sin_medir'],
        ];
    }

    /**
     * Id de la venta contenedora de facturación, o null si la venta no está consolidada.
     *
     * Se lee con `isset()` sobre el atributo y no con `$venta->consolidacion_facturacion_id` a
     * secas porque un modelo cargado con `select()` acotado no tiene la columna, y en ese caso
     * Eloquent devuelve null igual: lo que se quiere evitar es depender de esa coincidencia.
     *
     * @param  mixed $venta
     * @return int|null
     */
    private static function consolidacion_id_de($venta)
    {
        if (!isset($venta->consolidacion_facturacion_id) || is_null($venta->consolidacion_facturacion_id)) {
            return null;
        }

        return (int) $venta->consolidacion_facturacion_id;
    }

    /**
     * Agrega a un query de `sales` los joins necesarios para poder netear el IVA declarado de cada
     * venta en SQL (sin traerse una fila a PHP). Lo usa `ContabilidadRepository::ventas_brutas()` y
     * su detalle paginado.
     *
     * 🔴 Ninguno de los tres joins puede multiplicar filas: las dos subqueries vienen agrupadas por
     * `sale_id` (una fila por venta como máximo) y el join a `sales as venta_consolidacion` es
     * contra la PK. Eso es lo que permite que `ventas_brutas_detalle()` siga haciendo `count()`
     * sobre esta misma base sin contar de más.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query Query sobre la tabla `sales`.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function aplicar_joins_de_iva($query)
    {
        return $query
            ->leftJoinSub(self::subquery_por_venta(), 'iva_venta', function ($join) {
                $join->on('iva_venta.sale_id', '=', 'sales.id');
            })
            ->leftJoin('sales as venta_consolidacion', 'venta_consolidacion.id', '=', 'sales.consolidacion_facturacion_id')
            ->leftJoinSub(self::subquery_por_venta(), 'iva_consolidacion', function ($join) {
                $join->on('iva_consolidacion.sale_id', '=', 'sales.consolidacion_facturacion_id');
            });
    }

    /**
     * Expresión SQL del total de una venta YA NETO del IVA que esa venta declaró. Requiere que el
     * query haya pasado antes por `aplicar_joins_de_iva()`.
     *
     * Un comprobante autorizado sin `importe_iva` medido aporta 0 acá (el `COALESCE`), o sea que
     * esa venta queda SIN netear: en un reporte es preferible un renglón que sigue estando
     * sobrevaluado —y que `ContabilidadRepository::ventas_con_iva_sin_medir()` denuncia— a un
     * renglón que desaparece o que se netea con un número inventado.
     *
     * @return string
     */
    public static function expresion_total_neto_de_iva()
    {
        return '(sales.total'
            .' - COALESCE(iva_venta.iva_declarado, 0)'
            .' - COALESCE(iva_consolidacion.iva_declarado * (sales.total / NULLIF(venta_consolidacion.total, 0)), 0))';
    }
}
