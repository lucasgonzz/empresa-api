<?php

namespace Tests\Feature\ResetDatosNegocio;

use App\Http\Controllers\Helpers\ResetDatosNegocio\CatalogoDeTablasDelResetHelper;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ResetDatosNegocio\Concerns\BaseDescartableParaElReset;
use Tests\TestCase;

/**
 * CA3, CA4 y CA5 — La ejecución del reset de datos de negocio (misión reset-datos-cliente).
 *
 * Después de simular y ejecutar, el sistema tiene que quedar "igual que cuando se ejecutó el user setup":
 * las tablas de negocio vacías con los ids otra vez desde 1, la configuración intacta, y los enlaces de las filas
 * que se conservan hacia datos que ya no existen, soltados.
 *
 * Verifica contra la base descartable (ver BaseDescartableParaElReset): TRUNCATE hace commit implícito, así que
 * estos tests no pueden usar transacciones ni la base del slot.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class EjecucionDelResetTest extends TestCase
{
    use BaseDescartableParaElReset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iniciar_entorno_del_reset();
    }

    protected function tearDown(): void
    {
        $this->terminar_entorno_del_reset();
        parent::tearDown();
    }

    /** @test */
    public function test_vacia_las_tablas_de_negocio_y_reinicia_los_ids()
    {
        $con_filas = $this->tablas_a_vaciar_con_filas();
        $this->assertSame(3, $con_filas['articles']);

        // Sanidad del fixture: antes del reset los ids ya avanzaron (si no, "reiniciado" no probaría nada).
        foreach (['articles', 'sales', 'clients', 'providers', 'current_acounts'] as $tabla) {
            $this->assertGreaterThan(1, $this->auto_increment_de($tabla), 'El fixture tiene que haber avanzado el AUTO_INCREMENT de ' . $tabla);
        }

        $respuesta = $this->simular_y_ejecutar_el_reset();

        // Contrato C1 de la ejecución.
        $respuesta->assertStatus(200);
        $respuesta->assertJson(['ok' => true, 'dry_run' => false, 'estado' => 'ok', 'base' => $this->base_descartable]);
        $this->assertIsInt($respuesta->json('registro_id'));
        foreach ($con_filas as $tabla => $filas) {
            $this->assertSame($filas, $respuesta->json('vaciadas.' . $tabla), 'vaciadas.' . $tabla);
        }
        $this->assertSame(array_sum($con_filas), $respuesta->json('total_filas'));
        $this->assertIsArray($respuesta->json('ajustes'));
        $this->assertIsArray($respuesta->json('ajustes_salteados'));
        $this->assertSame([], $respuesta->json('ajustes_salteados'), 'En una base completa ningún ajuste se saltea.');
        $this->assertArrayHasKey('ruta_relativa', $respuesta->json('respaldo'));
        $this->assertSame([], $respuesta->json('verificacion.tablas_con_filas'));
        $this->assertSame([], $respuesta->json('verificacion.auto_increment_sin_reiniciar'));
        $this->assertGreaterThanOrEqual(0, $respuesta->json('duracion_ms'));

        // Las 290 tablas (todas las de la lista, tengan o no filas) quedan sin filas y con el contador en 1.
        $catalogo = CatalogoDeTablasDelResetHelper::class;
        $con_filas_despues = [];
        $sin_reiniciar = [];
        foreach ($catalogo::TABLAS_A_VACIAR as $tabla) {
            if (DB::table($tabla)->count() > 0) {
                $con_filas_despues[] = $tabla;
            }
            $autoincremento = $this->auto_increment_de($tabla);
            if ($autoincremento !== null && $autoincremento > 1) {
                $sin_reiniciar[] = $tabla . '=' . $autoincremento;
            }
        }
        $this->assertSame([], $con_filas_despues, 'Tablas de negocio que siguen con filas.');
        $this->assertSame([], $sin_reiniciar, 'Tablas de negocio con el AUTO_INCREMENT sin reiniciar.');

        // Lo que Lucas pidió: el próximo registro nuevo es el id 1 (no sigue el del soft delete).
        $this->assertSame(1, (int) DB::table('articles')->insertGetId(['user_id' => $this->id_del_dueno, 'name' => 'Primer artículo después del reset']));
        $this->assertSame(1, (int) DB::table('clients')->insertGetId(['user_id' => $this->id_del_dueno, 'name' => 'Primer cliente']));
        $this->assertSame(1, (int) DB::table('providers')->insertGetId(['user_id' => $this->id_del_dueno, 'name' => 'Primer proveedor']));
        $this->assertSame(1, (int) DB::table('sales')->insertGetId(['user_id' => $this->id_del_dueno]));
        $this->assertSame(1, (int) DB::table('current_acounts')->insertGetId(['status' => 'sin_pagar', 'user_id' => $this->id_del_dueno]));
        $this->assertSame(1, (int) DB::table('stock_movements')->insertGetId(['user_id' => $this->id_del_dueno]));
    }

    /** @test */
    public function test_conserva_la_configuracion_y_deja_la_caja_en_cero()
    {
        // Foto de toda la configuración y el sistema, salvo lo que los ajustes tocan a propósito (ver el test
        // siguiente) y la tabla de registro, que crece con cada intento.
        $excluir = array_merge(CatalogoDeTablasDelResetHelper::TABLAS_A_VACIAR, ['addresses', 'discounts', 'personal_access_tokens', 'reset_datos_negocio_registros']);
        $antes = $this->foto_de_las_tablas($excluir);
        $dueno_antes = (array) DB::table('users')->where('id', $this->id_del_dueno)->first();
        $empleado_antes = (array) DB::table('users')->where('id', $this->id_del_empleado)->first();
        $sucursales_antes = DB::table('addresses')->whereNull('buyer_id')->count();
        $max_sucursal_antes = DB::table('addresses')->whereNull('buyer_id')->max('id');
        $this->assertGreaterThan(0, $sucursales_antes);

        $this->simular_y_ejecutar_el_reset()->assertStatus(200);

        // Mismas filas (cantidad y último id) en toda tabla de configuración y de sistema.
        $this->assertEquals($antes, $this->foto_de_las_tablas($excluir));

        // Las tablas que el plan nombra, una por una (mensaje legible si alguna falla).
        foreach (['users', 'price_types', 'afip_information', 'extencion_empresa_user', 'permission_empresa_user', 'online_configurations', 'pdf_column_profiles', 'cajas'] as $tabla) {
            $this->assertArrayHasKey($tabla, $antes, $tabla . ' tiene que ser de configuración.');
            $this->assertSame($antes[$tabla]['filas'], (int) DB::table($tabla)->count(), 'Filas de ' . $tabla);
            $this->assertSame($antes[$tabla]['max_id'], DB::table($tabla)->max('id') === null ? null : (int) DB::table($tabla)->max('id'), 'Último id de ' . $tabla);
        }
        $this->assertGreaterThan(0, $antes['pdf_column_profiles']['filas'], 'El fixture sembró un perfil de columnas.');

        // Las sucursales (addresses sin comprador) siguen todas.
        $this->assertSame($sucursales_antes, DB::table('addresses')->whereNull('buyer_id')->count());
        $this->assertEquals($max_sucursal_antes, DB::table('addresses')->whereNull('buyer_id')->max('id'));

        // La caja existe pero en cero y cerrada, sin apertura vigente.
        $cajas = DB::table('cajas')->orderBy('id')->get();
        $this->assertCount($antes['cajas']['filas'], $cajas);
        foreach ($cajas as $caja) {
            $this->assertEquals(0, (float) $caja->saldo, 'Saldo de la caja ' . $caja->id);
            $this->assertSame(0, (int) $caja->abierta);
            $this->assertNull($caja->abierta_at);
            $this->assertNull($caja->cerrada_at);
            $this->assertNull($caja->current_apertura_caja_id);
        }

        // Usuarios: todos con el aviso para que cada navegador borre su IndexedDB.
        $this->assertSame(0, DB::table('users')->where('eliminar_articulos_offline', '!=', 1)->count());

        // Cursores y marcas de generación del dueño: en cero / NULL. El empleado, que los tenía en NULL, sigue en NULL.
        $dueno = DB::table('users')->where('id', $this->id_del_dueno)->first();
        $this->assertSame(0, (int) $dueno->agente_aviso_stock_cursor);
        $this->assertSame(0, (int) $dueno->agente_aviso_pedidos_cursor);
        $this->assertNull($dueno->sugerencias_ultima_generacion_at);
        $this->assertNull($dueno->sugerencias_compras_ultima_generacion_at);
        $this->assertNull($dueno->ofertas_ultima_generacion_at);
        $empleado = DB::table('users')->where('id', $this->id_del_empleado)->first();
        $this->assertNull($empleado->agente_aviso_stock_cursor, 'El cursor en NULL se queda en NULL.');
        $this->assertNull($empleado->agente_aviso_pedidos_cursor, 'El cursor en NULL se queda en NULL.');

        // El resto de las columnas de los usuarios no se tocó (mail, clave, dueño...).
        $columnas_ajustadas = ['eliminar_articulos_offline', 'agente_aviso_stock_cursor', 'agente_aviso_pedidos_cursor',
            'sugerencias_ultima_generacion_at', 'sugerencias_compras_ultima_generacion_at', 'ofertas_ultima_generacion_at', 'updated_at'];
        $this->assertEquals(
            array_diff_key($dueno_antes, array_flip($columnas_ajustadas)),
            array_diff_key((array) $dueno, array_flip($columnas_ajustadas)),
            'El dueño conserva todo lo que no es un cursor.'
        );
        $this->assertEquals(
            array_diff_key($empleado_antes, array_flip($columnas_ajustadas)),
            array_diff_key((array) $empleado, array_flip($columnas_ajustadas)),
            'El empleado conserva todo lo que no es un cursor.'
        );
    }

    /** @test */
    public function test_borra_tokens_y_direcciones_de_compradores_y_descuentos_de_clientes()
    {
        $tokens_de_usuarios = DB::table('personal_access_tokens')->where('tokenable_type', 'not like', '%Buyer')->count();
        $mapeos = DB::table('column_positions')->count();
        $descuentos_generales = DB::table('discounts')->whereNull('client_id')->count();
        $this->assertGreaterThan(0, $descuentos_generales);
        $this->assertSame(1, DB::table('discounts')->whereNotNull('client_id')->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_type', 'like', '%Buyer')->count());
        $this->assertSame(1, $tokens_de_usuarios);
        $this->assertNotNull(DB::table('column_positions')->value('provider_id'));
        $this->assertNotNull(DB::table('balanzas')->value('article_id'));

        $this->simular_y_ejecutar_el_reset()->assertStatus(200);

        // Tokens: los de los compradores de la tienda se van (el id del comprador nuevo no puede heredarlos); los
        // de los usuarios del ERP siguen.
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_type', 'like', '%Buyer')->count());
        $this->assertSame($tokens_de_usuarios, DB::table('personal_access_tokens')->count());

        // Direcciones de compradores y descuentos atados a un cliente: borrados; el descuento general sigue.
        $this->assertSame(0, DB::table('addresses')->whereNotNull('buyer_id')->count());
        $this->assertSame(0, DB::table('discounts')->whereNotNull('client_id')->count());
        $this->assertSame($descuentos_generales, DB::table('discounts')->count(), 'Los descuentos sin cliente se conservan todos.');
        $this->assertSame(1, DB::table('discounts')->where('name', 'Descuento general')->count());

        // El mapeo de columnas de Excel se conserva (sirve para reimportar) pero sin el proveedor que ya no existe;
        // la balanza se conserva sin el artículo.
        $this->assertSame($mapeos, DB::table('column_positions')->count());
        $this->assertNull(DB::table('column_positions')->value('provider_id'));
        $this->assertSame(1, DB::table('balanzas')->count());
        $this->assertNull(DB::table('balanzas')->value('article_id'));
    }

    /** @test */
    public function test_la_ejecucion_queda_con_inicio_y_fin_en_el_registro()
    {
        $respuesta = $this->simular_y_ejecutar_el_reset();
        $respuesta->assertStatus(200);

        $registros = $this->getJson($this->ruta_del_reset . '/registros?limit=10', ['X-Admin-Api-Key' => $this->clave_de_admin_del_test])->json('registros');
        $ids = array_column($registros, 'id');
        $this->assertContains($respuesta->json('registro_id'), $ids);

        $fila = $registros[array_search($respuesta->json('registro_id'), $ids)];
        $this->assertSame('ok', $fila['estado']);
        $this->assertNotSame('simulacion', $fila['modo']);
        $this->assertNotEmpty($fila['started_at']);
        $this->assertNotEmpty($fila['finished_at'], 'Una ejecución terminada tiene finished_at (si no, quedó a medias).');
        $this->assertSame($respuesta->json('total_filas'), $fila['total_filas']);
        $this->assertGreaterThan(0, $fila['respaldo_bytes']);
        $this->assertNotEmpty($fila['respaldo_ruta']);
    }
}
