<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper as Pantalla;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Escritura;
use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use Tests\EmpresaTestCase;

/**
 * Misión tiendas-demo-y-limpieza-tienda (14/10/2026), pedido de Lucas: la tienda dejó de ofrecer
 * cupones y el ingreso con Google, y el sistema sacó sus pantallas (Tienda Online > Cupones y el
 * grupo «Login con Google» de la configuración online). El asistente no puede seguir ofreciéndolos:
 *
 * - `cupon` salió del ABM genérico (proponer_alta / edicion / baja) y queda en EXCLUIDAS con motivo,
 *   así la guarda de revisión del test 34 sigue cerrada.
 * - 🔴 Sus rutas de recurso NO entran como acciones de pantalla. Sin la exclusión explícita, sacarla
 *   del ABM genérico las habría abierto por el otro camino: CuponController sigue existiendo por los
 *   pedidos viejos con cupón.
 * - El esquema de lectura deja de contestar `usar_cupones` y `google_login_enabled`: las columnas
 *   quedan (una tienda vieja las lee), pero ya no son algo que el dueño configure.
 *
 * @group chat-ia
 */
class Cupones_y_login_con_google_fuera_del_asistente_Test extends EmpresaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Escritura::olvidar();
        Pantalla::olvidar();
    }

    /**
     * @test
     */
    public function el_abm_generico_ya_no_carga_cupones()
    {
        $this->assertArrayNotHasKey('cupon', Escritura::entidades());
        $this->assertFalse(Escritura::existe('cupon'));
        $this->assertArrayHasKey('cupon', Escritura::EXCLUIDAS, 'Sin motivo en EXCLUIDAS, entidades_sin_revisar() la denuncia.');
        $this->assertNotContains('cupon', Escritura::entidades_sin_revisar());
    }

    /**
     * @test
     */
    public function las_rutas_de_cupones_ya_no_existen_y_la_guarda_queda_de_defensa()
    {
        // CuponController y su ruta se sacaron (misión sacar-cupones-y-login-google, 14/10/2026): sin ruta no hay
        // nada que el asistente pueda ofrecer. Antes este test recorría la ruta y exigía que estuviera excluida.
        foreach (app('router')->getRoutes() as $ruta) {
            $this->assertFalse(strpos($ruta->uri(), 'api/cupon') === 0, 'La ruta ' . $ruta->uri() . ' no debería existir: los cupones se sacaron del sistema.');
        }

        // Y si alguien vuelve a registrar una ruta de cupones, el asistente tampoco la ofrece: la guarda sigue en EXCLUIDAS.
        $this->assertArrayHasKey('#^[A-Z]+ api/cupon(/|$)#', Pantalla::EXCLUIDAS);
        $this->assertStringContainsString('cupones', Pantalla::EXCLUIDAS['#^[A-Z]+ api/cupon(/|$)#']);

        foreach (['GET api/cupon', 'POST api/cupon', 'GET api/cupon/{cupon}', 'PUT api/cupon/{cupon}', 'DELETE api/cupon/{cupon}'] as $ruta) {
            list($metodo, $uri) = explode(' ', $ruta, 2);

            $this->assertNull(Pantalla::declaracion($metodo, $uri), $ruta . ': no puede ofrecerse como acción de pantalla.');
            $this->assertSame(1, preg_match('#^[A-Z]+ api/cupon(/|$)#', $metodo . ' ' . $uri), $ruta . ': la guarda tiene que seguir cubriéndola.');
        }
    }

    /**
     * @test
     */
    public function la_configuracion_de_la_tienda_ya_no_contesta_cupones_ni_google()
    {
        EsquemaDeDatosIaHelper::olvidar();

        $declaracion = EsquemaDeDatosIaHelper::declaracion('online_configuration');

        $this->assertNotNull($declaracion);

        foreach (['usar_cupones', 'google_login_enabled'] as $columna) {
            $this->assertArrayNotHasKey($columna, $declaracion['campos'], $columna . ': el sistema ya no lo deja configurar.');
            $this->assertNotContains($columna, $declaracion['campos_por_defecto'], $columna);
        }

        // Lo que el dueño sí configura sigue estando.
        foreach (['pausar_tienda_online', 'mp_enabled', 'zippin_enabled'] as $columna) {
            $this->assertArrayHasKey($columna, $declaracion['campos'], $columna);
        }
    }
}
