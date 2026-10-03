<?php

namespace Tests\Feature\Empleados;

use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use App\Models\PermissionEmpresa;
use App\Models\User;
use Database\Seeders\PermisosOrdenarYCompletarSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión empleados-duplicar-y-permisos (29/9/2026) — el catálogo de permisos.
 *
 * Lucas pidió "auditar todos los permisos de los empleados y acomodarlos donde deberían estar" (el
 * ejemplo: "Ver la CAJA" aparecía en el grupo de reportes). El catálogo (`PermisosCatalogoHelper`)
 * fija grupo, nombre y orden de cada permiso por slug.
 *
 * Lo que estos tests cuidan es lo que NO se puede romper al reordenar: los slugs (los compara
 * `can()`), los ids (van en los pivots de los empleados) y que correr la pasada dos veces no
 * duplique nada.
 *
 * @group empleados
 */
class Catalogo_de_permisos_Test extends EmpresaTestCase
{
    /**
     * Todos los slugs que el catálogo tiene que seguir teniendo. Si alguien saca uno, un empleado
     * con ese permiso lo perdería en silencio: el test lo frena. Para sumar un permiso se agrega
     * acá y en el catálogo; para sacar uno hace falta una decisión de producto, no un refactor.
     *
     * La lista está FIJA acá (no se arma desde el catálogo). Los 133 primeros slugs de
     * `PermissionSeeder` (versión anterior al catálogo) se contrastaron a mano contra este
     * archivo al crearlo; los cinco últimos son `article.edit_stock_only_sucursal`, los dos de
     * WhatsApp y los dos que el menú consulta y nadie sembraba (`cupon.index`,
     * `mercado_libre.orders`).
     */
    const SLUGS_QUE_NO_PUEDEN_FALTAR = [
        'sale.store',
        'article.vender.change_price',
        'article.vender.change_name',
        'vender.change_employee',
        'vender.create_article',
        'vender.article_discount',
        'sale.discount_surchage.aplicar',
        'sale.discount_surchage.crear',
        'vender.cambiar_address_id',
        'vender.limpiar_venta',
        'vender.discount_stock',
        'vender.iva_aplicado',
        'vender.prohibir_eliminar_articulos_de_venta',
        'vender.prohibir_camibar_lista_de_precios',
        'sale.index',
        'sale.update',
        'sale.delete',
        'sale.index.previus_days',
        'sale.index.total',
        'sale.index.addresses.all',
        'sale.index.addresses.only_your',
        'sale.index.employees.all',
        'sale.index.employees.only_your',
        'devolucion.store',
        'article.index',
        'article.store',
        'article.update',
        'article.delete',
        'article.excel.import',
        'article.excel.export',
        'article.export_excel_clients',
        'article.cost',
        'article.percentage_gain',
        'article.provider',
        'article.edit_stock',
        'article.edit_stock_only_sucursal',
        'article.stock_only_sucursal',
        'article.stock_min_max',
        // Misión movimientos-deposito-auditoria (3/10/2026): los dos permisos nuevos de los
        // movimientos de depósito. `deposit_movement.update` sigue más abajo (cambió de grupo,
        // no de slug).
        'deposit_movement.update_articles',
        'deposit_movement.move_stock',
        'deposit_movement.index.previus_days',
        'deposito_para_checkear',
        'deposito_checkeadas',
        'client.index',
        'client.store',
        'client.update',
        'client.delete',
        'client.excel.import',
        'client.excel.export',
        'payment_plan.store',
        'payment_plan.update',
        'provider.index',
        'provider.store',
        'provider.update',
        'provider.delete',
        'provider.excel.import',
        'provider.excel.export',
        'provider_order.index',
        'provider_order.store',
        'provider_order.update',
        'provider_order.delete',
        'provider_order.index.previus_days',
        'budget.index',
        'budget.store',
        'budget.update',
        'budget.delete',
        'caja.index',
        'movimiento_entre_caja.index',
        'movimiento_entre_caja.store',
        'movimiento_entre_caja.update',
        'movimiento_entre_caja.delete',
        'movimiento_entre_caja.index.previus_days',
        'expense.index',
        'expense.store',
        'expense.update',
        'expense.delete',
        'reportes.cheques',
        'reportes.index',
        'reportes.cards',
        'reportes.graficos',
        'reportes.articulos',
        'reportes.ingresos',
        'reportes.sucursales.index',
        'reportes.sucursales.index.all',
        'reportes.sucursales.index.only_your',
        'reportes.empleados.index',
        'reportes.empleados.index.all',
        'reportes.empleados.index.only_your',
        'reportes.gastos',
        'reportes.clientes',
        'order.index',
        'order.store',
        'order.update',
        'order.delete',
        'order.index.previus_days',
        'buyer.index',
        'buyer.store',
        'buyer.update',
        'buyer.delete',
        'cupon.index',
        'mercado_libre.orders',
        'alerts.provider_orders',
        'alerts.orders',
        'alerts.messages',
        'alerts.problemas_al_facturar',
        'alerts.recordatorio_cobro',
        'pending.index',
        'road_map.index',
        'road_map.store',
        'road_map.update',
        'road_map.delete',
        'road_map.terminadas.index',
        'produccion.index',
        'recipe.index',
        'recipe.store',
        'recipe.update',
        'recipe.delete',
        'order_production.index',
        'order_production.store',
        'order_production.update',
        'order_production.delete',
        'production_movement.index',
        'production_movement.store',
        'production_movement.update',
        'production_movement.delete',
        'abm',
        'caja.reports',
        'caja.charts',
        'reportes',
        'reportes.info_facturacion',
        'deposit_movement.index',
        'deposit_movement.store',
        'deposit_movement.update',
        'deposit_movement.delete',
        'road_map.terminadas.only_your',
        'road_map.terminadas.all',
        'whatsapp.see_owner_chats',
        'whatsapp.see_other_users_chats',
        'support.see_owner_chats',
        'support.see_other_users_chats',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // La base de testing del slot arranca con la tabla vacía, pero otra suite puede haber dejado
        // filas: se parte de cero (DatabaseTransactions lo revierte al terminar).
        DB::table('permission_empresa_user')->delete();
        PermissionEmpresa::query()->delete();
    }

    /**
     * @return void
     */
    public function test_el_catalogo_no_repite_slugs_ni_nombres_y_no_perdio_ninguno()
    {
        $catalogo = PermisosCatalogoHelper::catalogo();
        $filas = PermisosCatalogoHelper::filas();

        $total_en_grupos = 0;
        foreach ($catalogo as $grupo => $permisos) {
            $this->assertNotEmpty($permisos, 'El grupo "'.$grupo.'" está vacío');
            $total_en_grupos += count($permisos);
        }

        // Si un slug estuviera en dos grupos, `filas()` lo pisaría en silencio y los conteos no darían.
        $this->assertEquals($total_en_grupos, count($filas), 'Hay un slug repetido entre grupos');

        $nombres = array_map(function ($fila) {
            return $fila['nombre'];
        }, $filas);
        $this->assertEquals(
            count($nombres),
            count(array_unique($nombres)),
            'Dos permisos no pueden llamarse igual: el asistente resuelve el permiso por nombre'
        );

        foreach (self::SLUGS_QUE_NO_PUEDEN_FALTAR as $slug) {
            $this->assertArrayHasKey($slug, $filas, 'Falta el permiso "'.$slug.'" en el catálogo');
        }
        $this->assertCount(count(self::SLUGS_QUE_NO_PUEDEN_FALTAR), $filas, 'Hay permisos en el catálogo que no están en la lista de arriba');
    }

    /**
     * @return void
     */
    public function test_ningun_grupo_de_la_pantalla_se_repite_con_otra_capitalizacion()
    {
        $grupos = array_keys(PermisosCatalogoHelper::catalogo());
        $normalizados = array_map('mb_strtolower', $grupos);

        $this->assertEquals(count($normalizados), count(array_unique($normalizados)));
    }

    /**
     * En una base vacía crea todo, en el orden del catálogo, y volver a correr no cambia nada.
     *
     * @return void
     */
    public function test_el_seeder_crea_todo_una_sola_vez()
    {
        $this->seed(PermissionSeeder::class);

        $total = count(PermisosCatalogoHelper::filas());
        $this->assertEquals($total, PermissionEmpresa::count());

        // Los ids siguen el orden del catálogo: la primera fila es la primera de "Vender".
        $this->assertEquals('sale.store', PermissionEmpresa::orderBy('id')->first()->slug);

        $this->seed(PermissionSeeder::class);
        $this->seed(PermisosOrdenarYCompletarSeeder::class);

        $this->assertEquals($total, PermissionEmpresa::count(), 'Correr el seeder de nuevo duplicó permisos');
        $this->assertEquals(
            $total,
            PermissionEmpresa::distinct('slug')->count('slug'),
            'Hay slugs repetidos'
        );
    }

    /**
     * El caso real de producción: una base vieja con grupos y nombres viejos y con permisos
     * faltantes, y un empleado que ya tiene permisos asignados. La pasada tiene que reordenar y
     * completar SIN tocar ids ni pivots, o el empleado perdería lo que tenía.
     *
     * @return void
     */
    public function test_pone_al_dia_una_base_vieja_sin_tocar_ids_ni_permisos_de_los_empleados()
    {
        // Como estaban: "Ver la CAJA" en el grupo de reportes, nombre viejo, minúsculas.
        $caja_reports = PermissionEmpresa::forceCreate(['slug' => 'caja.reports', 'name' => 'Ver la CAJA', 'model_name' => 'Estadisticas']);
        $caja_index = PermissionEmpresa::forceCreate(['slug' => 'caja.index', 'name' => 'Ver Cajas', 'model_name' => 'Estadisticas']);
        $ventas = PermissionEmpresa::forceCreate(['slug' => 'sale.index', 'name' => 'Listar ventas', 'model_name' => 'ventas']);
        // Un permiso propio de un cliente, que el catálogo no conoce: no se toca.
        $propio = PermissionEmpresa::forceCreate(['slug' => 'cliente.especial', 'name' => 'Algo propio', 'model_name' => 'Propios']);

        $owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $empleado = User::create([
            'name'         => 'Empleado con permisos',
            'company_name' => 'Ferreteria permisos',
            'email'        => 'perm-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
            'owner_id'     => $owner->id,
        ]);
        $empleado->permissions()->sync([$caja_index->id, $ventas->id, $propio->id]);

        $this->seed(PermisosOrdenarYCompletarSeeder::class);

        // Mismos ids, mismo slug, grupo y nombre nuevos.
        $this->assertEquals($caja_index->id, PermissionEmpresa::where('slug', 'caja.index')->value('id'));
        $this->assertEquals('Cajas y tesorería', PermissionEmpresa::find($caja_index->id)->model_name);
        $this->assertEquals('Entrar a Tesorería (ver las cajas)', PermissionEmpresa::find($caja_index->id)->name);
        $this->assertEquals('Sin efecto por ahora', PermissionEmpresa::find($caja_reports->id)->model_name);
        $this->assertEquals('Ventas', PermissionEmpresa::find($ventas->id)->model_name);
        $this->assertEquals('Ver el listado de ventas', PermissionEmpresa::find($ventas->id)->name);

        // Lo propio del cliente queda como estaba.
        $this->assertEquals('Propios', PermissionEmpresa::find($propio->id)->model_name);
        $this->assertEquals('Algo propio', PermissionEmpresa::find($propio->id)->name);

        // Se completó lo que faltaba: ahora está todo el catálogo (más el propio).
        $this->assertEquals(count(PermisosCatalogoHelper::filas()) + 1, PermissionEmpresa::count());
        $this->assertEquals(1, PermissionEmpresa::where('slug', 'alerts.recordatorio_cobro')->count());

        // El empleado conserva exactamente lo que tenía.
        $this->assertEquals(
            collect([$caja_index->id, $ventas->id, $propio->id])->sort()->values()->all(),
            $empleado->permissions()->pluck('permission_empresas.id')->sort()->values()->all()
        );

        // Y una segunda pasada no cambia nada.
        $filas_antes = PermissionEmpresa::orderBy('id')->get(['id', 'slug', 'name', 'model_name'])->toArray();
        $resultado = PermisosCatalogoHelper::aplicar();
        $this->assertEquals(0, $resultado['creados']);
        $this->assertEquals(0, $resultado['actualizados']);
        $this->assertEquals($filas_antes, PermissionEmpresa::orderBy('id')->get(['id', 'slug', 'name', 'model_name'])->toArray());
    }

    /**
     * El API entrega la lista ya ordenada, sin importar en qué orden de `id` están las filas: es
     * lo que hace que todas las bases muestren los grupos igual.
     *
     * @return void
     */
    public function test_el_endpoint_devuelve_los_permisos_en_el_orden_del_catalogo()
    {
        // Se crean "al revés" a propósito: primero un permiso de Reportes, después uno de Vender.
        PermissionEmpresa::forceCreate(['slug' => 'reportes.index', 'name' => 'x', 'model_name' => 'x']);
        PermissionEmpresa::forceCreate(['slug' => 'cliente.especial', 'name' => 'Algo propio', 'model_name' => 'Propios']);
        PermissionEmpresa::forceCreate(['slug' => 'sale.store', 'name' => 'x', 'model_name' => 'x']);
        PermissionEmpresa::forceCreate(['slug' => 'vender.limpiar_venta', 'name' => 'x', 'model_name' => 'x']);
        PermissionEmpresa::forceCreate(['slug' => 'sale.index', 'name' => 'x', 'model_name' => 'x']);

        $response = $this->getJson('api/permission');

        $response->assertStatus(200);

        $slugs = collect($response->json('models'))->pluck('slug')->all();

        $this->assertEquals(
            ['sale.store', 'vender.limpiar_venta', 'sale.index', 'reportes.index', 'cliente.especial'],
            $slugs,
            'Tiene que ir Vender, después Ventas, después Reportes, y lo que no está en el catálogo al final'
        );
    }

    /**
     * El buscador de la pantalla de Empleados suma estas palabras al nombre y al grupo: sin ellas
     * "plata" no encuentra las cajas ni "borrar" los permisos de eliminar.
     *
     * @return void
     */
    public function test_el_endpoint_manda_palabras_clave_para_el_buscador()
    {
        PermissionEmpresa::forceCreate(['slug' => 'caja.index', 'name' => 'x', 'model_name' => 'x']);
        PermissionEmpresa::forceCreate(['slug' => 'client.delete', 'name' => 'x', 'model_name' => 'x']);
        PermissionEmpresa::forceCreate(['slug' => 'cliente.especial', 'name' => 'Algo propio', 'model_name' => 'Propios']);

        $response = $this->getJson('api/permission');
        $response->assertStatus(200);

        $por_slug = collect($response->json('models'))->keyBy('slug');

        $this->assertStringContainsString('plata', $por_slug['caja.index']['palabras_clave']);
        $this->assertStringContainsString('borrar', $por_slug['client.delete']['palabras_clave']);
        $this->assertStringContainsString('cobrar', $por_slug['client.delete']['palabras_clave']);
        // Un permiso que el catalogo no conoce no rompe: llega con el campo vacio.
        $this->assertSame('', $por_slug['cliente.especial']['palabras_clave']);

        // Las palabras clave son solo para la respuesta: no se guardan en la base.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('permission_empresas', 'palabras_clave'));
    }
}
