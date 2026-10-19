<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El precio ORIGINAL de cada renglon, el de antes de los descuentos y los recargos de venta
 * (mision descuentos-aplicar-al-precio-del-articulo, 8/10/2026). Hermana de
 * `2026_09_28_100000_add_price_sin_recargos_de_venta_to_item_pivots`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  PARA QUE EXISTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La opcion de VENDER "aplicar los descuentos de esta venta directamente a los precios de los
 *  articulos" (`aplicar_descuentos_directo_a_items`) es el espejo de la de recargos: mete el
 *  descuento ADENTRO del `price` de cada renglon, en lugar de restarlo al total. Para poder
 *  prenderla y apagarla con la venta o el presupuesto ya guardados —y para mostrar en la tabla de
 *  VENDER el precio original entre parentesis— hace falta guardar el precio de antes. Con solo el
 *  `price` final el sistema no tiene forma de saber cuanto valia sin el descuento.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA SEMANTICA, QUE NO SE NEGOCIA: UNA PILA DE CAPAS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *      precio base ──► [capa DESCUENTOS de venta] ──► [capa RECARGOS de venta] ──► cuotas · IVA · moneda
 *
 *  Cada columna guarda el precio justo DEBAJO de su capa. Los descuentos son la capa de abajo, asi
 *  que esta columna guarda el precio sin los descuentos Y sin los recargos:
 *
 *      price_sin_descuentos_de_venta  NO NULL = el `price` del renglon TIENE ADENTRO los descuentos
 *                                     de venta; el valor es el precio ORIGINAL, sin los descuentos
 *                                     ni los recargos de venta (mismo contexto de IVA y moneda que
 *                                     `price`).
 *                                     NULL = el `price` NO tiene descuentos adentro.
 *
 *      price_sin_recargos_de_venta    (la de la mision del 28/9, NO cambia de significado) NO NULL =
 *                                     el `price` tiene los recargos adentro y el valor es el precio
 *                                     SIN ellos pero CON los descuentos, si los tiene.
 *
 *  El precio original de un renglon sale gratis de la pila: la columna de descuentos si no es NULL;
 *  si no, la de recargos si no es NULL; si no, el renglon no tiene ningun ajuste adentro y el
 *  `price` ya es el original.
 *
 *  🔴 NO SE REEMPLAZA UNA POR LA OTRA. Un renglon con descuento Y recargo adentro tiene las dos
 *  columnas llenas y valores distintos (original 100, descuento 10 %, recargo 10 %: descuentos =
 *  100, recargos = 90, price = 99). Sin la de recargos, apagar solo los recargos no tendria de
 *  donde recuperar el precio que quedaba con el descuento; sin la de descuentos, el original solo
 *  se podria reconstruir dividiendo por factores que dependen de que descuentos y recargos tenia
 *  elegidos el vendedor al guardar, y un precio adivinado es justo lo que estas columnas existen
 *  para evitar.
 *
 *  🔴 NULLABLE Y SIN DEFAULT A PROPOSITO. Un default 0, o rellenar la columna con `price` para las
 *  filas viejas, diria "este precio tiene el descuento adentro" sobre renglones donde nadie lo
 *  sabe. Como la opcion es nueva, ningun comprobante viejo la tiene prendida: NULL es exactamente
 *  la verdad para todo lo guardado antes de esta migracion.
 *
 *  DECIMAL(25,6), no (25,2), por el mismo motivo que la de recargos: la SPA manda la base sin
 *  redondear y apagar devuelve `round2(base)` = el precio que el renglon tenia antes de prender la
 *  opcion.
 *
 *  ⚠️ LO QUE NO PROMETE: lo mismo que la de recargos. "Volver a prender" da el mismo precio solo
 *  dentro de la misma edicion; y como con la opcion prendida el precio unitario se redondea a
 *  centavos, el TOTAL puede moverse centavos por unidad al prender o apagar.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LAS OCHO TABLAS, Y POR QUE CADA UNA CON SU GUARDA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Las cuatro de renglones de venta y sus cuatro gemelas de presupuesto, con `hasTable` +
 *  `!hasColumn` cada una: `budget_combo` nacio el 16/9/2026 y hay clientes con el gap historico
 *  entre los archivos de migracion y la tabla `migrations`, y re-ejecutar la migracion no puede
 *  fallar.
 *
 *  La columna va AL FINAL de la tabla, sin `after()`. `article_sale` es la tabla mas grande del
 *  sistema y una columna nullable agregada al final es la unica forma de ALTER que MySQL/MariaDB
 *  resuelven sin reconstruir la tabla entera en las versiones viejas.
 *
 * ⚠️ `tienda-api` comparte la base fisica del cliente y NO tiene migraciones: esta columna la crea
 * empresa-api para las dos. Es aditiva y nullable, asi que una tienda que no la conoce no se
 * entera (no escribe en estas tablas).
 *
 * 🔴 EL DEPLOY SUBE LOS ARCHIVOS ANTES DE MIGRAR (`DeploymentService::execute_steps()` de admin-api:
 * `upload_api` -> `sync_env_keys` -> `run_migrations`). Mientras esta migracion no corrio, ningun
 * `attach()` ni ningun `withPivot()` puede nombrar la columna: por eso todo pasa por
 * `App\Http\Controllers\Helpers\sale\DescuentosEnPreciosEsquemaHelper`.
 */
class AddPriceSinDescuentosDeVentaToItemPivots extends Migration
{
    /** Nombre de la columna nueva. Tiene que coincidir con `DescuentosEnPreciosEsquemaHelper::COLUMNA`. */
    const COLUMNA = 'price_sin_descuentos_de_venta';

    /**
     * Las ocho tablas de renglones: primero las de venta, despues las de presupuesto. Mismo orden
     * que `DescuentosEnPreciosEsquemaHelper::TABLAS`.
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
     * Agrega la columna en cada tabla que exista y no la tenga todavia.
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
                $table->decimal(self::COLUMNA, 25, 6)->nullable();
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
