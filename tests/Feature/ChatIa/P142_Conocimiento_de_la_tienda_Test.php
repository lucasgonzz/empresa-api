<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), P9 — lo que el asistente sabe de la configuración de la tienda.
 *
 * 🔴 LOS CASOS REALES (Pesca Mayorista, 13/11/2026). «necesito activar que los productos sin stock aparezca el
 * cartel» → «No tengo una opción para activar ese cartel» (V15): la opción es `ignorar_stock`, que el esquema no
 * conocía. «DONDE CONFIGURO QUE DATOS PEDIRLE A UN CLIENTE… TIENEN QUE CARGAR» → «No hay ninguna pantalla»
 * (V20): está `pedir_barrio_al_registrarse`. Desde P9 los dos se leen por defecto y la descripción cuenta cómo se
 * combinan y dónde se cambian.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types, readonly ni enum.
 *
 * @group chat-ia
 */
class P142_Conocimiento_de_la_tienda_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @test
     */
    public function la_configuracion_de_la_tienda_dice_el_cartel_de_agotado_y_los_datos_del_registro()
    {
        EsquemaDeDatosIaHelper::olvidar();

        $declaracion = EsquemaDeDatosIaHelper::declaracion('online_configuration');

        foreach (['ignorar_stock', 'permitir_pedidos_sin_stock', 'mostrar_stock_disponible', 'pedir_barrio_al_registrarse', 'logear_cliente_al_registrar'] as $campo) {
            $this->assertArrayHasKey($campo, $declaracion['campos'], $campo);
            $this->assertContains($campo, $declaracion['campos_por_defecto'], $campo . ': tiene que viajar sin pedirlo.');
        }

        /* Los de antes siguen, y en su orden: las claves nuevas van al final. */
        $por_defecto = $declaracion['campos_por_defecto'];
        $this->assertLessThan(array_search('ignorar_stock', $por_defecto, true), array_search('show_articles_without_stock', $por_defecto, true));

        $descripcion = $declaracion['descripcion'];
        $this->assertStringContainsString('ignorar_stock', $descripcion);
        $this->assertStringContainsString('agotado', $descripcion);
        $this->assertStringContainsString('pedir_barrio_al_registrarse', $descripcion);
        $this->assertStringContainsString('configuracion de la Tienda Online', $descripcion);
    }

    /**
     * La consulta real devuelve los flags tal como están en la base.
     *
     * @test
     */
    public function la_consulta_devuelve_ignorar_stock_y_el_barrio()
    {
        EsquemaDeDatosIaHelper::olvidar();

        $comercio = User::create([
            'name'         => 'Comercio tienda P115',
            'company_name' => 'Pesca P115',
            'email'        => 'tienda-p115-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        DB::table('online_configurations')->where('user_id', $comercio->id)->delete();
        DB::table('online_configurations')->insert([
            'user_id'                     => $comercio->id,
            'ignorar_stock'               => 1,
            'pedir_barrio_al_registrarse' => 1,
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        $resultado = CatalogoDeDatosIaHelper::consultar_datos($comercio->id, 'online_configuration', []);

        $this->assertArrayNotHasKey('error', $resultado, json_encode($resultado));
        $this->assertSame(1, $resultado['registros_encontrados']);

        $fila = $resultado['registros'][0];
        $this->assertArrayHasKey('ignorar_stock', $fila);
        $this->assertEquals(1, $fila['ignorar_stock']);
        $this->assertEquals(1, $fila['pedir_barrio_al_registrarse']);
    }
}
