<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Http\Controllers\Helpers\providerOrder\FacturaDeCompraHelper;
use App\Http\Controllers\SaleController;
use App\Models\AfipTicket;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use App\Models\Sale;
use App\Models\SemillaRegistro;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
 * pasa por su controlador y después se saca de la papelera. Lo que el negocio creó después no está en el lote
 * y no se toca.
 *
 * Las sucursales, los depósitos, las cajas, los empleados, los vendedores y la configuración NO entran en el
 * lote: no son "información de prueba", son la estructura con la que el negocio sigue trabajando.
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
    ];

    /** Filas por INSERT al cerrar un lote. */
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
            $ids = DB::table($tabla)->where('id', '>', $maximo)->orderBy('id')->pluck('id');

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
     * Borra TODO lo sembrado de un dueño, en orden de dependencia, sin tocar lo que el negocio creó después.
     *
     * Es `SembrarDatosDePrueba::resetear()` acotado a los ids del lote, más el catálogo (artículos,
     * categorías, proveedores, clientes), los compradores y pedidos de la tienda y la caja de ejemplo. Deja
     * las cajas con el saldo recalculado y vacía `semilla_registros` del dueño al terminar. Idempotente: un id
     * que ya no existe se saltea, así que una corrida que se cortó a mitad se retoma volviendo a llamar.
     *
     * 🔴 Necesita contexto de autenticación (los controladores leen Auth()->user()): lo arma acá con
     * `DeleteModelsHelper::setup_auth_context()`, igual que la semilla. Corre en un job, nunca en un request.
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

        DeleteModelsHelper::setup_auth_context($owner_id);

        $request = Request::create('/');
        config(['app.suppress_delete_notifications' => true]);

        try {
            /*
             * 1. Comprobantes ANTES que las ventas: SaleController@destroy rechaza con 422 la venta con un
             *    AfipTicket vivo (mismo motivo que en resetear()).
             */
            $resumen['afip_tickets'] = self::borrar_por_id('afip_tickets', self::ids($owner_id, 'afip_tickets'));

            // 2. Ventas, por el controlador (devuelve stock, deshace caja y cuenta corriente).
            $sale_controller = new SaleController();
            $sale_ids = Sale::withTrashed()->whereIn('id', self::ids($owner_id, 'sales'))->pluck('id')->all();
            foreach ($sale_ids as $sale_id) {
                if (Sale::where('id', $sale_id)->exists()) {
                    $sale_controller->destroy($request, $sale_id);
                }
            }
            DB::table('article_sale')->whereIn('sale_id', $sale_ids)->delete();
            $resumen['sales'] = self::borrar_por_id('sales', $sale_ids);

            // 3. Notas de crédito a proveedor atadas a una compra, ANTES que las compras (422 si quedan).
            $current_acount_controller = new CurrentAcountController();
            $cc_ids = self::ids($owner_id, 'current_acounts');
            $notas_credito_de_compras = CurrentAcount::whereIn('id', $cc_ids)
                ->whereNotNull('devolucion_provider_order_id')
                ->whereNotNull('credit_account_id')
                ->orderBy('created_at', 'DESC')
                ->get();
            foreach ($notas_credito_de_compras as $nota) {
                $current_acount_controller->delete($request, 'provider', $nota->id);
            }

            // 4. Compras, por el controlador (devuelve el stock que sumaron).
            $provider_order_ids = self::ids($owner_id, 'provider_orders');
            $compras_vivas = DB::table('provider_orders')->whereIn('id', $provider_order_ids)->pluck('id')->all();
            if (count($compras_vivas)) {
                DeleteModelsHelper::process_delete('provider_order', $compras_vivas, true);
                config(['app.suppress_delete_notifications' => true]);
            }
            DB::table('article_provider_order')->whereIn('provider_order_id', $provider_order_ids)->delete();
            $resumen['provider_orders'] = self::borrar_por_id('provider_orders', $provider_order_ids);

            // 5. Cheques ANTES que los gastos y los movimientos de cuenta corriente (422 si ya se cobraron).
            $resumen['cheques'] = self::borrar_por_id('cheques', self::ids($owner_id, 'cheques'));

            // 6. Gastos, por el controlador (deshace caja).
            $expense_controller = new ExpenseController();
            $expense_ids = self::ids($owner_id, 'expenses');
            foreach (Expense::whereIn('id', $expense_ids)->pluck('id') as $expense_id) {
                $expense_controller->destroy($request, $expense_id);
            }
            $resumen['expenses'] = self::borrar_por_id('expenses', $expense_ids);

            // 7. El resto de la cuenta corriente (cobros y pagos), del más nuevo al más viejo.
            $current_acounts = CurrentAcount::whereIn('id', $cc_ids)->orderBy('created_at', 'DESC')->get();
            foreach ($current_acounts as $current_acount) {
                $model_name = !is_null($current_acount->client_id) ? 'client' : 'provider';
                $current_acount_controller->delete($request, $model_name, $current_acount->id);
            }
            $resumen['current_acounts'] = self::borrar_por_id('current_acounts', $cc_ids);

            // 8. Presupuestos, pendientes y facturas de compra: sin riesgo de descuadrar nada, van directo.
            $budget_ids = self::ids($owner_id, 'budgets');
            DB::table('article_budget')->whereIn('budget_id', $budget_ids)->delete();
            $resumen['budgets'] = self::borrar_por_id('budgets', $budget_ids);
            $resumen['pendings'] = self::borrar_por_id('pendings', self::ids($owner_id, 'pendings'));

            $facturas_de_compra = self::ids($owner_id, 'provider_order_afip_tickets');
            if (count($facturas_de_compra)) {
                FacturaDeCompraHelper::borrar_facturas($facturas_de_compra);
            }
            $resumen['provider_order_afip_tickets'] = count($facturas_de_compra);

            // 9. La performance es derivada de las ventas: se va entera y la recalcula el cron.
            $resumen['company_performances'] = DB::table('company_performances')->where('user_id', $owner_id)->delete();

            // 10. Caja: al final, después de los controladores, que leen y escriben movimientos al deshacer.
            $resumen['caja'] = self::borrar_caja_de_ejemplo($owner_id);

            // 11. Movimientos de stock y de depósito, ANTES que los artículos.
            $resumen['stock_movements'] = self::borrar_por_id('stock_movements', self::ids($owner_id, 'stock_movements'));
            $deposit_movement_ids = self::ids($owner_id, 'deposit_movements');
            DB::table('article_deposit_movement')->whereIn('deposit_movement_id', $deposit_movement_ids)->delete();
            $resumen['deposit_movements'] = self::borrar_por_id('deposit_movements', $deposit_movement_ids);

            // 12. Catálogo: por el controlador (combos, recetas, Tienda Nube, espejos) y después fuera de la papelera.
            $resumen['articles'] = self::borrar_articulos($owner_id);
            $resumen['descriptions'] = self::borrar_por_id('descriptions', self::ids($owner_id, 'descriptions'));
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
            $order_ids = self::ids($owner_id, 'orders');
            DB::table('article_order')->whereIn('order_id', $order_ids)->delete();
            $resumen['orders'] = self::borrar_por_id('orders', $order_ids);
            $cart_ids = self::ids($owner_id, 'carts');
            DB::table('article_cart')->whereIn('cart_id', $cart_ids)->delete();
            $resumen['carts'] = self::borrar_por_id('carts', $cart_ids);
            $resumen['buyers'] = self::borrar_por_id('buyers', self::ids($owner_id, 'buyers'));
            $resumen['buyer_tracking_events'] = self::borrar_por_id('buyer_tracking_events', self::ids($owner_id, 'buyer_tracking_events'));
            $resumen['buyer_tracking_daily'] = self::borrar_por_id('buyer_tracking_daily', self::ids($owner_id, 'buyer_tracking_daily'));
            $resumen['article_purchases'] = self::borrar_por_id('article_purchases', self::ids($owner_id, 'article_purchases'));

            // 15. Sin marcas: `semilla_presente()` pasa a false.
            self::olvidar($owner_id);
        } finally {
            config(['app.suppress_delete_notifications' => false]);
        }

        Log::info('SemillaRegistroHelper: datos de ejemplo del dueño ' . $owner_id . ' borrados.', $resumen);

        return $resumen;
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
        if (!count($ids)) {
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
            DeleteModelsHelper::process_delete('article', $vivos, true);
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
            DeleteModelsHelper::process_delete($model_name, $vivos, true);
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
        if (!DB::connection()->getSchemaBuilder()->hasTable($tabla)) {
            return;
        }

        foreach (array_chunk($ids, self::TAMANO_DE_INSERT) as $parte) {
            DB::table($tabla)->whereIn($columna, $parte)->delete();
        }
    }

    /**
     * La caja de ejemplo: los movimientos y las aperturas del lote. Las cajas quedan (son estructura) con el
     * saldo recalculado; si su apertura abierta era del lote y quedó sin movimientos, se cierra en cero
     * (mismo criterio que resetear(): un `current_apertura_caja_id` colgado hace tirar al próximo cierre).
     *
     * @param int $owner_id
     * @return int Movimientos borrados.
     */
    private static function borrar_caja_de_ejemplo($owner_id)
    {
        $movimientos = self::borrar_por_id('movimiento_cajas', self::ids($owner_id, 'movimiento_cajas'));
        self::borrar_por_id('movimiento_entre_cajas', self::ids($owner_id, 'movimiento_entre_cajas'));

        // Una apertura del lote que todavía tiene movimientos reales del negocio se queda.
        $aperturas = self::ids($owner_id, 'apertura_cajas');
        $con_movimientos = count($aperturas)
            ? MovimientoCaja::whereIn('apertura_caja_id', $aperturas)->distinct()->pluck('apertura_caja_id')->all()
            : [];
        $aperturas_a_borrar = array_values(array_diff($aperturas, $con_movimientos));
        self::borrar_por_id('apertura_cajas', $aperturas_a_borrar);

        foreach (Caja::where('user_id', $owner_id)->get() as $caja) {
            $apertura_viva = !is_null($caja->current_apertura_caja_id)
                && AperturaCaja::where('id', $caja->current_apertura_caja_id)->exists();

            if (!$apertura_viva) {
                $caja->saldo = 0;
                $caja->abierta = 0;
                $caja->current_apertura_caja_id = null;
                $caja->save();
                continue;
            }

            MovimientoCajaHelper::recalcular_saldos(null, $caja->id);
        }

        return $movimientos;
    }
}
