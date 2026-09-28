<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El precio de cada renglon SIN los recargos de venta (mision recargos-en-precios-editable,
 * 28/9/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  PARA QUE EXISTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La opcion de VENDER "aplicar los recargos de esta venta directamente a los precios de los
 *  articulos" (`aplicar_recargos_directo_a_items`) mete el recargo ADENTRO del `price` de cada
 *  renglon. Hasta hoy el renglon guardaba solo ese precio final, asi que el sistema no tenia forma
 *  de saber cuanto valia sin el recargo: por eso la SPA congelaba la opcion al editar. Lucas pidio
 *  poder prenderla y apagarla editando una venta o un presupuesto ya creado, con cada precio
 *  volviendo EXACTAMENTE a como estaba. Para eso hace falta guardar el precio de antes.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA SEMANTICA, QUE NO SE NEGOCIA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *      NO NULL  = el `price` de ese renglon TIENE ADENTRO los recargos de venta; el valor es el
 *                 precio unitario SIN ellos (mismo contexto de IVA y moneda que `price`).
 *      NULL     = el `price` NO los tiene (opcion apagada, servicio sin "recargos en servicios",
 *                 o renglon viejo guardado antes de esta migracion).
 *
 *  🔴 NULLABLE Y SIN DEFAULT A PROPOSITO. Un default 0, o rellenar la columna con `price` para
 *  las filas viejas, mentiria: diria "este precio tiene el recargo adentro y sin el vale X" sobre
 *  renglones donde nadie sabe si lo tiene. Un comprobante viejo con la opcion prendida tiene el
 *  recargo adentro y NO se puede reconstruir el precio de antes (el redondeo a 2 decimales ya se
 *  comio la informacion): la SPA lo detecta por el NULL y lo deja bloqueado, que es el modo de
 *  falla seguro — bloquear, nunca adivinar un precio.
 *
 *  DECIMAL(25,6), no (25,2): la SPA manda la base sin redondear, y con seis decimales "apagar"
 *  devuelve `round2(base)` = el precio que el renglon tenia antes de prender la opcion. Esa es la
 *  unica promesa exacta de la columna.
 *
 *  ⚠️ LO QUE NO PROMETE, para que nadie lo lea de mas:
 *
 *  - "Volver a prender" da el mismo precio SOLO dentro de la misma edicion, mientras la base
 *    sigue en memoria. Si se GUARDA con la opcion apagada, la base pasa a NULL y el precio queda en
 *    `round2(base)`; prenderla en otra edicion parte de ese numero ya redondeado y da
 *    `round2(round2(base) × factor)`, que puede correrse un centavo: base 93,457 con 10 % da 102,80
 *    la primera vez, pero 93,46 × 1,10 da 102,81.
 *  - El TOTAL puede moverse al prender o apagar. Desde el 28/9/2026 (decision de Lucas) la SPA
 *    redondea a centavos el precio UNITARIO cuando lleva los recargos adentro, para que renglones,
 *    total y factura de ARCA coincidan (antes el total salia del precio sin redondear y la suma de
 *    los renglones daba menos: con 10.000 unidades de 0,35 y 10 % + 5 %, mas de cuarenta pesos de
 *    diferencia, y los presupuestos grandes rebotaban con el 500 del margen de 3). Con el recargo
 *    al pie ese redondeo por unidad no existe, asi que entre las dos formas el total difiere en
 *    hasta medio centavo por unidad, multiplicado por la cantidad de cada renglon.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LAS OCHO TABLAS, Y POR QUE CADA UNA CON SU GUARDA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Las cuatro de renglones de venta y sus cuatro gemelas de presupuesto. Cada una va con
 *  `hasTable` + `!hasColumn`: `budget_combo` nacio el 16/9/2026 y hay clientes con el gap
 *  historico entre los archivos de migracion y la tabla `migrations`, asi que no se puede dar por
 *  sentado que todas existan; y re-ejecutar la migracion no puede fallar.
 *
 * ⚠️ `tienda-api` comparte la base fisica del cliente y NO tiene migraciones: esta columna la crea
 * empresa-api para las dos. Es aditiva y nullable, asi que una tienda que no la conoce no se
 * entera (no escribe en estas tablas).
 *
 * 🔴 EL DEPLOY SUBE LOS ARCHIVOS ANTES DE MIGRAR (`DeploymentService::execute_steps()` de admin-api:
 * `upload_api` -> `sync_env_keys` -> `run_migrations`). Mientras esta migracion no corrio, ningun
 * `attach()` ni ningun `withPivot()` puede nombrar la columna: por eso todo pasa por
 * `App\Http\Controllers\Helpers\sale\RecargosEnPreciosEsquemaHelper`.
 */
class AddPriceSinRecargosDeVentaToItemPivots extends Migration
{
    /** Nombre de la columna nueva. Tiene que coincidir con `RecargosEnPreciosEsquemaHelper::COLUMNA`. */
    const COLUMNA = 'price_sin_recargos_de_venta';

    /**
     * Las ocho tablas de renglones: primero las de venta, despues las de presupuesto.
     *
     * @var array<int,string>
     */
    protected $tablas = [
        'article_sale',
        'sale_service',
        'combo_sale',
        'promocion_vinoteca_sale',
        'article_budget',
        'budget_service',
        'budget_combo',
        'budget_promocion_vinoteca',
    ];

    /**
     * Agrega la columna, despues de `price`, en cada tabla que exista y no la tenga todavia.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->tablas as $tabla) {

            if (!Schema::hasTable($tabla) || Schema::hasColumn($tabla, self::COLUMNA)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->decimal(self::COLUMNA, 25, 6)->nullable()->after('price');
            });
        }
    }

    /**
     * Saca la columna de cada tabla donde este. Simetrico a `up()`.
     *
     * @return void
     */
    public function down()
    {
        foreach ($this->tablas as $tabla) {

            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, self::COLUMNA)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn(self::COLUMNA);
            });
        }
    }
}
