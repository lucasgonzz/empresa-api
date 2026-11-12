<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Http\Controllers\Helpers\providerOrder\FacturaDeCompraHelper;
use App\Http\Controllers\SaleController;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use App\Models\Sale;
use App\Models\SemillaRegistro;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La marca de lo que sembraron el demo setup y `semilla:datos`, y su borrado (misión
 * prueba-experiencia-empresa, 3/11/2026).
 *
 * Hasta esta misión no había forma de distinguir un artículo de ejemplo de uno que el negocio cargó: ninguna
 * columna `is_demo`, ningún lote. `semilla:datos --reset` borraba TODO lo del dueño. La prueba gratuita
 * necesita lo contrario: al primer import se va lo de ejemplo y queda lo real.
 *
 * Cómo se marca: por LOTE DE IDS, sin tocar cada seeder. `abrir_lote()` fotografía el `MAX(id)` de cada tabla
 * de negocio antes de sembrar y `cerrar_lote()` registra en `semilla_registros` todo id que apareció en el
 * medio. El demo setup abre un lote antes de sus seeders y lo cierra después de `semilla:datos` y de la
 * trazabilidad del clip; `semilla:datos` suelto (local) abre el suyo si no hay uno abierto. Un lote adentro
 * de otro no existe: el de afuera cubre todo.
 *
 * Cómo se borra: `borrar_lo_sembrado()` es `SembrarDatosDePrueba::resetear()` acotado a los ids del lote y
 * con el catálogo incluido. Mismo orden de dependencia y mismo criterio: las operaciones de plata se deshacen
 * por su controlador (nunca un delete a lo bruto que descuadre una cuenta corriente o una caja); el catálogo
 * pasa por su controlador y después se saca de la papelera.
 *
 * 🔴 Política del chequeo independiente (3/11/2026): lo REAL que el negocio hizo con lo de ejemplo —vendió un
 * artículo de ejemplo, le cobró a un cliente de ejemplo, le compró a un proveedor de ejemplo— también se va,
 * con sus hijos; si quedara, quedaría colgado de ids que ya no existen. Lo que el negocio cargó con sus
 * propios datos (artículos, clientes y proveedores creados por él y sus operaciones) queda. El aviso previo
 * al import lo dice con todas las letras (`mixins/prueba.js` del SPA).
 *
 * Las sucursales, los depósitos, las cajas y la configuración NO entran en el lote: son la estructura con la
 * que el negocio sigue trabajando. Los empleados y vendedores de ejemplo SÍ se van (Lucas: «borra todo»); el
 * dueño nunca.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class SemillaRegistroHelper
{
    /**
     * Tablas que se fotografían en cada lote. El orden no importa acá (importa en borrar_lo_sembrado()).
     *
     * @var string[]
     */
    const TABLAS = [
        'articles',
        'descriptions',
        'images',
        'article_properties',
        'article_property_types',
        'article_property_values',
        'categories',
        'sub_categories',
        'brands',
        'providers',
        'provider_discounts',
        'provider_price_lists',
        'clients',
        'credit_accounts',
        'buyers',
        'orders',
        'carts',
        'sales',
        'afip_tickets',
        'current_acounts',
        'expenses',
        'provider_orders',
        'provider_order_afip_tickets',
        'cheques',
        'budgets',
        'pendings',
        'stock_movements',
        'deposit_movements',
        'movimiento_cajas',
        'apertura_cajas',
        'movimiento_entre_cajas',
        'company_performances',
        'buyer_tracking_events',
        'buyer_tracking_daily',
        'article_purchases',
        'users',
        'sellers',
    ];

    /** Filas por INSERT al cerrar un lote, y por DELETE al borrarlo. */
    const TAMANO_DE_INSERT = 500;

    /**
     * El lote abierto en este proceso, o null.
     *
     * @var array|null ['owner_id' => int, 'nombre' => string, 'maximos' => [tabla => int]]
     */
    private static $lote = null;

    /**
     * ¿Las tablas de la prueba existen en esta base? (una instancia vieja, o la suite antes de migrar).
     *
     * @return bool
     */
    public static function tabla_disponible()
    {
        try {
            return DB::connection()->getSchemaBuilder()->hasTable('semilla_registros');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * ¿Hay un lote abierto en este proceso?
     *
     * @return bool
     */
    public static function hay_lote_abierto()
    {
        return !is_null(self::$lote);
    }

    /**
     * Fotografía el `MAX(id)` de cada tabla de negocio. Devuelve false (y no abre nada) si ya hay un lote
     * abierto o si la base no tiene la tabla de marcas: el que llama sigue sembrando igual.
     *
     * @param int $owner_id
     * @param string $prefijo Prefijo del nombre del lote (`demo-setup`, `semilla-datos`).
     * @return bool
     */
    public static function abrir_lote($owner_id, $prefijo = 'semilla')
    {
        if (self::hay_lote_abierto() || !self::tabla_disponible()) {
            return false;
        }

        $schema = DB::connection()->getSchemaBuilder();
        $maximos = [];

        foreach (self::TABLAS as $tabla) {
            if (!$schema->hasTable($tabla)) {
                continue;
            }

            $maximos[$tabla] = (int) DB::table($tabla)->max('id');
        }

        self::$lote = [
            'owner_id' => (int) $owner_id,
            'nombre'   => $prefijo . '-' . Carbon::now()->format('Ymd-His'),
            'maximos'  => $maximos,
        ];

        return true;
    }

    /**
     * Registra en `semilla_registros` todo id que apareció desde `abrir_lote()`. Devuelve cuántas filas marcó
     * (0 si no había lote abierto).
     *
     * En `users` se marcan solo los empleados (`owner_id` no nulo): el dueño nace antes del lote, pero un
     * dueño nunca puede quedar marcado, pase lo que pase con el orden.
     *
     * @return int
     */
    public static function cerrar_lote()
    {
        if (!self::hay_lote_abierto()) {
            return 0;
        }

        $lote = self::$lote;
        self::$lote = null;

        $ahora = Carbon::now();
        $marcadas = 0;

        foreach ($lote['maximos'] as $tabla => $maximo) {
            $consulta = DB::table($tabla)->where('id', '>', $maximo);

            if ($tabla === 'users') {
                $consulta->whereNotNull('owner_id');
            }

            $ids = $consulta->orderBy('id')->pluck('id');

            foreach ($ids->chunk(self::TAMANO_DE_INSERT) as $parte) {
                $filas = [];

                foreach ($parte as $id) {
                    $filas[] = [
                        'owner_id'    => $lote['owner_id'],
                        'tabla'       => $tabla,
                        'registro_id' => (int) $id,
                        'lote'        => $lote['nombre'],
                        'created_at'  => $ahora,
                    ];
                }

                DB::table('semilla_registros')->insert($filas);
                $marcadas += count($filas);
            }
        }

        Log::info('SemillaRegistroHelper: lote ' . $lote['nombre'] . ' cerrado con ' . $marcadas . ' registros marcados.');

        return $marcadas;
    }

    /**
     * Descarta el lote abierto sin registrar nada (una siembra que falló a mitad de camino).
     *
     * @return void
     */
    public static function descartar_lote()
    {
        self::$lote = null;
    }

    /**
     * Marca UN registro como de ejemplo, fuera de un lote (misión prueba-primera-venta, 12/11/2026): la
     * apertura de caja con la que la prueba nace lista para vender se abre después de cerrar el lote del
     * demo setup. Sin marcarla, al primer import `borrar_caja_de_ejemplo()` cerraba la caja y la dejaba
     * huérfana (una apertura sin cierre en Tesorería) antes de abrir otra.
     *
     * Solo si el dueño todavía tiene datos de ejemplo: después del borrado lo nuevo es real. Con un lote
     * abierto no hace falta (lo marca `cerrar_lote()`) y no se marca dos veces.
     *
     * @param int $owner_id
     * @param string $tabla Una de TABLAS.
     * @param int $registro_id
     * @return bool si quedó marcado.
     */
    public static function marcar($owner_id, $tabla, $registro_id)
    {
        if (self::hay_lote_abierto() || !in_array($tabla, self::TABLAS, true) || !self::semilla_presente($owner_id)) {
            return false;
        }

        $ya = SemillaRegistro::where('owner_id', (int) $owner_id)
            ->where('tabla', $tabla)
            ->where('registro_id', (int) $registro_id)
            ->exists();

        if (!$ya) {
            SemillaRegistro::create([
                'owner_id'    => (int) $owner_id,
                'tabla'       => $tabla,
                'registro_id' => (int) $registro_id,
                'lote'        => 'prueba-primera-venta',
                'created_at'  => Carbon::now(),
            ]);
        }

        return true;
    }

    /**
     * Saca un registro de la marca: no es de ejemplo aunque haya nacido en el lote (el Client del propio
     * lead que crea el demo setup).
     *
     * @param string $tabla
     * @param int $registro_id
     * @return void
     */
    public static function excluir($tabla, $registro_id)
    {
        if (!self::tabla_disponible()) {
            return;
        }

        SemillaRegistro::where('tabla', $tabla)->where('registro_id', (int) $registro_id)->delete();
    }

    /**
     * Saca de la marca a un cliente y a sus cuentas corrientes: el Client del propio lead, que el demo setup
     * crea adentro del lote pero no es un dato de ejemplo.
     *
     * @param int $client_id
     * @return void
     */
    public static function excluir_cliente($client_id)
    {
        if (!self::tabla_disponible()) {
            return;
        }

        self::excluir('clients', $client_id);

        $cuentas = DB::table('credit_accounts')
            ->where('model_name', 'client')
            ->where('model_id', (int) $client_id)
            ->pluck('id');

        foreach ($cuentas as $cuenta_id) {
            self::excluir('credit_accounts', $cuenta_id);
        }
    }

    /**
     * ¿El dueño todavía tiene datos de ejemplo marcados?
     *
     * @param int $owner_id
     * @return bool
     */
    public static function semilla_presente($owner_id)
    {
        if (!self::tabla_disponible()) {
            return false;
        }

        return SemillaRegistro::where('owner_id', (int) $owner_id)->exists();
    }

    /**
     * Ids marcados de una tabla, para un dueño.
     *
     * @param int $owner_id
     * @param string $tabla
     * @return int[]
     */
    public static function ids($owner_id, $tabla)
    {
        return SemillaRegistro::where('owner_id', (int) $owner_id)
            ->where('tabla', $tabla)
            ->pluck('registro_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }

    /**
     * Borra las marcas de un dueño (lo usa `semilla:datos --reset`, que borra todo lo suyo por su cuenta).
     *
     * @param int $owner_id
     * @return void
     */
    public static function olvidar($owner_id)
    {
        if (!self::tabla_disponible()) {
            return;
        }

        SemillaRegistro::where('owner_id', (int) $owner_id)->delete();
    }

    /**
     * Las operaciones REALES del dueño que apuntan a algo del lote (vendió un artículo de ejemplo, le cobró
     * a un cliente de ejemplo, le compró a un proveedor de ejemplo...). No están marcadas, pero se van con
     * lo sembrado: si quedaran, quedarían colgadas de ids que ya no existen.
     *
     * Se calcula ANTES de borrar nada, mirando las claves lógicas (el repo no tiene FK): el cliente, el
     * proveedor, la cuenta corriente y el comprador de la cabecera, y el artículo de los renglones.
     *
     * @param int $owner_id
     * @return array<string,int[]> tabla => ids (solo los que NO están en el lote)
     */
    public static function operaciones_reales_colgadas($owner_id)
    {
        $owner_id = (int) $owner_id;
        $articulos = self::ids($owner_id, 'articles');
        $clientes = self::ids($owner_id, 'clients');
        $proveedores = self::ids($owner_id, 'providers');
        $cuentas = self::ids($owner_id, 'credit_accounts');
        $compradores = self::ids($owner_id, 'buyers');

        $colgadas = [
            'sales'           => [],
            'budgets'         => [],
            'current_acounts' => [],
            'provider_orders' => [],
            'cheques'         => [],
            'orders'          => [],
            'stock_movements' => [],
        ];

        $propios = function ($tabla, $ids) use ($owner_id) {
            if (!count($ids)) {
                return [];
            }

            $del_lote = self::ids($owner_id, $tabla);

            return array_values(array_diff(array_unique(array_map('intval', $ids)), $del_lote));
        };

        $por_cabecera = function ($tabla, $columna, $valores) use ($owner_id) {
            if (!count($valores) || !self::columna_existe($tabla, $columna)) {
                return [];
            }

            return DB::table($tabla)->where('user_id', $owner_id)->whereIn($columna, $valores)->pluck('id')->all();
        };

        $por_renglon = function ($pivote, $columna_padre, $articulos) {
            if (!count($articulos) || !self::tabla_existe($pivote)) {
                return [];
            }

            return DB::table($pivote)->whereIn('article_id', $articulos)->pluck($columna_padre)->all();
        };

        $colgadas['sales'] = $propios('sales', array_merge(
            $por_cabecera('sales', 'client_id', $clientes),
            $por_renglon('article_sale', 'sale_id', $articulos)
        ));
        $colgadas['budgets'] = $propios('budgets', array_merge(
            $por_cabecera('budgets', 'client_id', $clientes),
            $por_renglon('article_budget', 'budget_id', $articulos)
        ));
        $colgadas['current_acounts'] = $propios('current_acounts', array_merge(
            $por_cabecera('current_acounts', 'client_id', $clientes),
            $por_cabecera('current_acounts', 'provider_id', $proveedores),
            $por_cabecera('current_acounts', 'credit_account_id', $cuentas)
        ));
        $colgadas['provider_orders'] = $propios('provider_orders', array_merge(
            $por_cabecera('provider_orders', 'provider_id', $proveedores),
            $por_renglon('article_provider_order', 'provider_order_id', $articulos)
        ));
        $colgadas['cheques'] = $propios('cheques', array_merge(
            $por_cabecera('cheques', 'client_id', $clientes),
            $por_cabecera('cheques', 'provider_id', $proveedores)
        ));
        $colgadas['orders'] = $propios('orders', array_merge(
            $por_cabecera('orders', 'buyer_id', $compradores),
            $por_renglon('article_order', 'order_id', $articulos)
        ));
        $colgadas['stock_movements'] = $propios('stock_movements', $por_cabecera('stock_movements', 'article_id', $articulos));

        return $colgadas;
    }

    /**
     * Borra TODO lo sembrado de un dueño, en orden de dependencia, más lo real que lo referencia. Lo que el
     * negocio creó con sus propios datos queda.
     *
     * Es `SembrarDatosDePrueba::resetear()` acotado a los ids del lote, más el catálogo (artículos,
     * categorías, proveedores, clientes), los compradores y pedidos de la tienda, los empleados y vendedores
     * de ejemplo y la caja de ejemplo. Deja las cajas con el saldo recalculado y vacía `semilla_registros` del
     * dueño al terminar. Idempotente: un id que ya no existe se saltea, así que una corrida que se cortó a
     * mitad se retoma volviendo a llamar. Un controlador que se niega o tira sobre UN registro no frena el
     * resto: se loguea y la fila se saca igual al final del paso.
     *
     * 🔴 Necesita contexto de autenticación (los controladores leen Auth()->user()): lo arma acá con
     * `DeleteModelsHelper::setup_auth_context()` por el guard `web`, igual que la semilla. Corre en un job o en
     * un import sincrónico, nunca en el camino caliente de un request.
     *
     * @param int $owner_id
     * @return array<string,int> Resumen por tabla de lo borrado.
     */
    public static function borrar_lo_sembrado($owner_id)
    {
        $owner_id = (int) $owner_id;
        $resumen = [];

        if (!self::semilla_presente($owner_id)) {
            return $resumen;
        }

        // Los controladores leen Auth()->user(): se entra como el dueño por el guard de sesión. `shouldUse('web')`
        // porque dentro de un request de la API el guard activo es `sanctum`, que no tiene loginUsingId().
        Auth::shouldUse('web');
        DeleteModelsHelper::setup_auth_context($owner_id);

        $request = Request::create('/');
        config(['app.suppress_delete_notifications' => true]);

        try {
            // 0. Lo real que apunta a lo sembrado se suma a cada lista, ANTES de borrar nada.
            $colgadas = self::operaciones_reales_colgadas($owner_id);
            $resumen['reales_colgadas'] = array_sum(array_map('count', $colgadas));

            $sale_ids = self::unir(self::ids($owner_id, 'sales'), $colgadas['sales']);
            $budget_ids = self::unir(self::ids($owner_id, 'budgets'), $colgadas['budgets']);
            $cc_ids = self::unir(self::ids($owner_id, 'current_acounts'), $colgadas['current_acounts']);
            $provider_order_ids = self::unir(self::ids($owner_id, 'provider_orders'), $colgadas['provider_orders']);
            $cheque_ids = self::unir(self::ids($owner_id, 'cheques'), $colgadas['cheques']);
            $order_ids = self::unir(self::ids($owner_id, 'orders'), $colgadas['orders']);
            $stock_movement_ids = self::unir(self::ids($owner_id, 'stock_movements'), $colgadas['stock_movements']);

            /*
             * 1. Comprobantes ANTES que las ventas: SaleController@destroy rechaza con 422 la venta con un
             *    AfipTicket vivo (mismo motivo que en resetear()). Los de las ventas reales colgadas también.
             */
            $afip_ids = self::unir(
                self::ids($owner_id, 'afip_tickets'),
                count($sale_ids) ? DB::table('afip_tickets')->whereIn('sale_id', $sale_ids)->pluck('id')->all() : []
            );
            $resumen['afip_tickets'] = self::borrar_por_id('afip_tickets', $afip_ids);

            // 2. Ventas, por el controlador (devuelve stock, deshace caja y cuenta corriente), y sus hijos.
            $sale_controller = new SaleController();
            foreach (Sale::whereIn('id', $sale_ids)->pluck('id') as $sale_id) {
                self::intentar('venta ' . $sale_id, function () use ($sale_controller, $request, $sale_id) {
                    $sale_controller->destroy($request, $sale_id);
                });
            }
            foreach (['article_sale', 'discount_sale', 'sale_surchage', 'current_acount_payment_method_sale', 'commissioner_sale'] as $tabla) {
                self::borrar_colgadas($tabla, 'sale_id', $sale_ids);
            }
            $resumen['sales'] = self::borrar_por_id('sales', $sale_ids);

            // 3. Notas de crédito a proveedor atadas a una compra, ANTES que las compras (422 si quedan).
            $current_acount_controller = new CurrentAcountController();
            $notas_credito_de_compras = CurrentAcount::whereIn('id', $cc_ids)
                ->whereNotNull('devolucion_provider_order_id')
                ->whereNotNull('credit_account_id')
                ->orderBy('created_at', 'DESC')
                ->get();
            foreach ($notas_credito_de_compras as $nota) {
                self::intentar('nota de crédito de compra ' . $nota->id, function () use ($current_acount_controller, $request, $nota) {
                    $current_acount_controller->delete($request, 'provider', $nota->id);
                });
            }

            // 4. Compras, por el controlador (devuelve el stock que sumaron).
            $compras_vivas = DB::table('provider_orders')->whereIn('id', $provider_order_ids)->pluck('id')->all();
            if (count($compras_vivas)) {
                self::intentar('compras', function () use ($compras_vivas) {
                    DeleteModelsHelper::process_delete('provider_order', $compras_vivas, true);
                });
                config(['app.suppress_delete_notifications' => true]);
            }
            self::borrar_colgadas('article_provider_order', 'provider_order_id', $provider_order_ids);
            $resumen['provider_orders'] = self::borrar_por_id('provider_orders', $provider_order_ids);

            // 5. Cheques ANTES que los gastos y los movimientos de cuenta corriente (422 si ya se cobraron).
            $resumen['cheques'] = self::borrar_por_id('cheques', $cheque_ids);

            // 6. Gastos, por el controlador (deshace caja).
            $expense_controller = new ExpenseController();
            $expense_ids = self::ids($owner_id, 'expenses');
            foreach (Expense::whereIn('id', $expense_ids)->pluck('id') as $expense_id) {
                self::intentar('gasto ' . $expense_id, function () use ($expense_controller, $request, $expense_id) {
                    $expense_controller->destroy($request, $expense_id);
                });
            }
            $resumen['expenses'] = self::borrar_por_id('expenses', $expense_ids);

            // 7. El resto de la cuenta corriente (cobros y pagos), del más nuevo al más viejo.
            $current_acounts = CurrentAcount::whereIn('id', $cc_ids)->orderBy('created_at', 'DESC')->get();
            foreach ($current_acounts as $current_acount) {
                $model_name = !is_null($current_acount->client_id) ? 'client' : 'provider';
                self::intentar('cuenta corriente ' . $current_acount->id, function () use ($current_acount_controller, $request, $model_name, $current_acount) {
                    $current_acount_controller->delete($request, $model_name, $current_acount->id);
                });
            }
            $resumen['current_acounts'] = self::borrar_por_id('current_acounts', $cc_ids);

            // 8. Presupuestos, pendientes y facturas de compra: sin riesgo de descuadrar nada, van directo.
            self::borrar_colgadas('article_budget', 'budget_id', $budget_ids);
            $resumen['budgets'] = self::borrar_por_id('budgets', $budget_ids);
            $resumen['pendings'] = self::borrar_por_id('pendings', self::ids($owner_id, 'pendings'));

            $facturas_de_compra = self::ids($owner_id, 'provider_order_afip_tickets');
            if (count($facturas_de_compra)) {
                FacturaDeCompraHelper::borrar_facturas($facturas_de_compra);
            }
            $resumen['provider_order_afip_tickets'] = count($facturas_de_compra);

            // 9. La performance sembrada (la que calculó la semilla); la del negocio la recalcula el cron.
            $resumen['company_performances'] = self::borrar_por_id('company_performances', self::ids($owner_id, 'company_performances'));

            // 10. Caja: al final, después de los controladores, que leen y escriben movimientos al deshacer.
            $resumen['caja'] = self::borrar_caja_de_ejemplo($owner_id);

            // 11. Movimientos de stock y de depósito, ANTES que los artículos.
            $resumen['stock_movements'] = self::borrar_por_id('stock_movements', $stock_movement_ids);
            $deposit_movement_ids = self::ids($owner_id, 'deposit_movements');
            self::borrar_colgadas('article_deposit_movement', 'deposit_movement_id', $deposit_movement_ids);
            $resumen['deposit_movements'] = self::borrar_por_id('deposit_movements', $deposit_movement_ids);

            // 12. Catálogo: por el controlador (combos, recetas, Tienda Nube, espejos) y después fuera de la papelera.
            $resumen['articles'] = self::borrar_articulos($owner_id);
            $resumen['descriptions'] = self::borrar_por_id('descriptions', self::ids($owner_id, 'descriptions'));
            $resumen['images'] = self::borrar_por_id('images', self::ids($owner_id, 'images'));
            $resumen['article_properties'] = self::borrar_por_id('article_properties', self::ids($owner_id, 'article_properties'));
            $resumen['article_property_values'] = self::borrar_por_id('article_property_values', self::ids($owner_id, 'article_property_values'));
            $resumen['article_property_types'] = self::borrar_por_id('article_property_types', self::ids($owner_id, 'article_property_types'));
            $resumen['sub_categories'] = self::borrar_por_id('sub_categories', self::ids($owner_id, 'sub_categories'));
            $resumen['categories'] = self::borrar_por_id('categories', self::ids($owner_id, 'categories'));
            $resumen['brands'] = self::borrar_por_id('brands', self::ids($owner_id, 'brands'));

            // 13. Clientes y proveedores, por el controlador y después fuera de la papelera.
            $resumen['clients'] = self::borrar_modelo_con_papelera('client', 'clients', $owner_id);
            $resumen['providers'] = self::borrar_modelo_con_papelera('provider', 'providers', $owner_id);
            $resumen['provider_discounts'] = self::borrar_por_id('provider_discounts', self::ids($owner_id, 'provider_discounts'));
            $resumen['provider_price_lists'] = self::borrar_por_id('provider_price_lists', self::ids($owner_id, 'provider_price_lists'));
            $resumen['credit_accounts'] = self::borrar_por_id('credit_accounts', self::ids($owner_id, 'credit_accounts'));

            // 14. Tienda: pedidos, carritos, compradores y su actividad.
            self::borrar_colgadas('article_order', 'order_id', $order_ids);
            $resumen['orders'] = self::borrar_por_id('orders', $order_ids);
            $cart_ids = self::ids($owner_id, 'carts');
            self::borrar_colgadas('article_cart', 'cart_id', $cart_ids);
            $resumen['carts'] = self::borrar_por_id('carts', $cart_ids);
            $resumen['buyers'] = self::borrar_por_id('buyers', self::ids($owner_id, 'buyers'));
            $resumen['buyer_tracking_events'] = self::borrar_por_id('buyer_tracking_events', self::ids($owner_id, 'buyer_tracking_events'));
            $resumen['buyer_tracking_daily'] = self::borrar_por_id('buyer_tracking_daily', self::ids($owner_id, 'buyer_tracking_daily'));
            $resumen['article_purchases'] = self::borrar_por_id('article_purchases', self::ids($owner_id, 'article_purchases'));

            // 15. Empleados y vendedores de ejemplo. El dueño nunca: solo filas con owner_id.
            $empleados = self::ids($owner_id, 'users');
            $resumen['users'] = count($empleados)
                ? DB::table('users')->whereIn('id', $empleados)->whereNotNull('owner_id')->where('id', '<>', $owner_id)->delete()
                : 0;
            $resumen['sellers'] = self::borrar_por_id('sellers', self::ids($owner_id, 'sellers'));

            // 16. Sin marcas: `semilla_presente()` pasa a false.
            self::olvidar($owner_id);
        } finally {
            config(['app.suppress_delete_notifications' => false]);
        }

        Log::info('SemillaRegistroHelper: datos de ejemplo del dueño ' . $owner_id . ' borrados.', $resumen);

        return $resumen;
    }

    /**
     * Corre un paso que pasa por un controlador; si tira, lo anota y sigue (la fila se saca igual después).
     *
     * @param string $que
     * @param callable $paso
     * @return void
     */
    private static function intentar($que, callable $paso)
    {
        try {
            $paso();
        } catch (\Throwable $e) {
            Log::warning('SemillaRegistroHelper: el controlador no pudo deshacer ' . $que . ' (se borra igual): ' . $e->getMessage());
        }
    }

    /**
     * Unión de dos listas de ids, sin repetidos.
     *
     * @param int[] $a
     * @param int[] $b
     * @return int[]
     */
    private static function unir(array $a, array $b)
    {
        return array_values(array_unique(array_map('intval', array_merge($a, $b))));
    }

    /**
     * @param string $tabla
     * @return bool
     */
    private static function tabla_existe($tabla)
    {
        return DB::connection()->getSchemaBuilder()->hasTable($tabla);
    }

    /**
     * @param string $tabla
     * @param string $columna
     * @return bool
     */
    private static function columna_existe($tabla, $columna)
    {
        return self::tabla_existe($tabla) && DB::connection()->getSchemaBuilder()->hasColumn($tabla, $columna);
    }

    /**
     * Borra por id, directo en la tabla (salta los soft deletes a propósito: esto es sacar de la base, no
     * mandar a la papelera). Devuelve cuántas filas se fueron.
     *
     * @param string $tabla
     * @param int[] $ids
     * @return int
     */
    private static function borrar_por_id($tabla, array $ids)
    {
        if (!count($ids) || !self::tabla_existe($tabla)) {
            return 0;
        }

        $borradas = 0;

        foreach (array_chunk($ids, self::TAMANO_DE_INSERT) as $parte) {
            $borradas += DB::table($tabla)->whereIn('id', $parte)->delete();
        }

        return $borradas;
    }

    /**
     * Artículos: por el controlador (combos, recetas, Tienda Nube, espejos de inventory linkage) y después
     * fuera de la papelera, con sus tablas colgadas.
     *
     * @param int $owner_id
     * @return int
     */
    private static function borrar_articulos($owner_id)
    {
        $ids = self::ids($owner_id, 'articles');

        if (!count($ids)) {
            return 0;
        }

        $vivos = DB::table('articles')->whereIn('id', $ids)->whereNull('deleted_at')->pluck('id')->all();
        if (count($vivos)) {
            self::intentar('artículos', function () use ($vivos) {
                DeleteModelsHelper::process_delete('article', $vivos, true);
            });
            config(['app.suppress_delete_notifications' => true]);
        }

        foreach ([
            'address_article',
            'article_price_type',
            'article_price_type_monedas',
            'article_provider',
            'article_deposit',
            'article_discounts',
            'article_variants',
            'article_compact_embeddings',
            'article_properties',
        ] as $tabla) {
            self::borrar_colgadas($tabla, 'article_id', $ids);
        }

        DB::table('images')
            ->whereIn('imageable_id', $ids)
            ->where('imageable_type', 'like', '%Article')
            ->delete();

        return self::borrar_por_id('articles', $ids);
    }

    /**
     * Clientes o proveedores: por el controlador y después fuera de la papelera.
     *
     * @param string $model_name `client` | `provider`
     * @param string $tabla
     * @param int $owner_id
     * @return int
     */
    private static function borrar_modelo_con_papelera($model_name, $tabla, $owner_id)
    {
        $ids = self::ids($owner_id, $tabla);

        if (!count($ids)) {
            return 0;
        }

        $vivos = DB::table($tabla)->whereIn('id', $ids)->whereNull('deleted_at')->pluck('id')->all();
        if (count($vivos)) {
            self::intentar($tabla, function () use ($model_name, $vivos) {
                DeleteModelsHelper::process_delete($model_name, $vivos, true);
            });
            config(['app.suppress_delete_notifications' => true]);
        }

        return self::borrar_por_id($tabla, $ids);
    }

    /**
     * Borra las filas de una tabla colgada (pivote o hija) por su columna, si la tabla existe.
     *
     * @param string $tabla
     * @param string $columna
     * @param int[] $ids
     * @return void
     */
    private static function borrar_colgadas($tabla, $columna, array $ids)
    {
        if (!count($ids) || !self::tabla_existe($tabla)) {
            return;
        }

        foreach (array_chunk($ids, self::TAMANO_DE_INSERT) as $parte) {
            DB::table($tabla)->whereIn($columna, $parte)->delete();
        }
    }

    /**
     * La caja de ejemplo: los movimientos y las aperturas del lote. Las cajas quedan (son estructura).
     *
     * Saldos, con la política del chequeo (3/11/2026): una caja que después del borrado NO tiene ningún
     * movimiento (todo lo suyo era del lote) queda en cero y cerrada; una caja que conserva movimientos
     * reales conserva su saldo. Una apertura del lote que todavía tiene movimientos reales se queda; si la
     * apertura abierta de una caja se fue, `current_apertura_caja_id` se limpia (colgado, hace tirar al
     * próximo cierre); si sigue viva, se recalcula su saldo.
     *
     * @param int $owner_id
     * @return int Movimientos borrados.
     */
    private static function borrar_caja_de_ejemplo($owner_id)
    {
        $movimientos = self::borrar_por_id('movimiento_cajas', self::ids($owner_id, 'movimiento_cajas'));
        self::borrar_por_id('movimiento_entre_cajas', self::ids($owner_id, 'movimiento_entre_cajas'));

        $aperturas = self::ids($owner_id, 'apertura_cajas');
        $con_movimientos = count($aperturas)
            ? MovimientoCaja::whereIn('apertura_caja_id', $aperturas)->distinct()->pluck('apertura_caja_id')->all()
            : [];
        $aperturas_a_borrar = array_values(array_diff($aperturas, $con_movimientos));
        self::borrar_por_id('apertura_cajas', $aperturas_a_borrar);

        foreach (Caja::where('user_id', $owner_id)->get() as $caja) {
            $tiene_movimientos = MovimientoCaja::where('caja_id', $caja->id)->exists();
            $apertura_viva = !is_null($caja->current_apertura_caja_id)
                && AperturaCaja::where('id', $caja->current_apertura_caja_id)->exists();

            if (!$tiene_movimientos) {
                // Todo lo suyo era de ejemplo: vuelve a cero y cerrada.
                $caja->saldo = 0;
                $caja->abierta = 0;
                $caja->current_apertura_caja_id = null;
                $caja->save();
                continue;
            }

            if (!$apertura_viva) {
                // Conserva su saldo real; solo se limpia la apertura colgada.
                if (!is_null($caja->current_apertura_caja_id)) {
                    $caja->abierta = 0;
                    $caja->current_apertura_caja_id = null;
                    $caja->save();
                }
                continue;
            }

            MovimientoCajaHelper::recalcular_saldos(null, $caja->id);
        }

        return $movimientos;
    }
}
