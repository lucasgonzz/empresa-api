<?php

namespace Tests\Feature\PdfDeVentaPublico;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Los reportes por nombre de comercio (`reportes/inventario|clientes/{company_name}/{periodo}` y
 * `reportes/excel-articulos/{company_name}/{mes}`), sumados a la misión pdf-de-venta-publico en la
 * verificación del 10/10/2026: hasta ahí cualquiera los bajaba escribiendo el nombre en la URL.
 *
 * El dueño se resuelve por `company_name` con la misma consulta que ReportePdf y ArticleSalesExport.
 * Regla: sesión de ese comercio, o su ventana de transición abierta; si no, o si el nombre no es de
 * nadie, 404. Sin token (no se comparten).
 */
class Descarga_reportes_por_comercio_Test extends DescargaTestCase
{
    /**
     * Las tres rutas, con el nombre del comercio ya puesto.
     *
     * @param  string  $company_name
     * @return string[]
     */
    protected function rutas($company_name)
    {
        $nombre = rawurlencode($company_name);

        return [
            'reportes/inventario/' . $nombre . '/mes',
            'reportes/clientes/' . $nombre . '/mes',
            'reportes/excel-articulos/' . $nombre . '/2026-09',
        ];
    }

    /**
     * Sin sesión y con la ventana cerrada, los tres dan 404, el mismo que un nombre que no es de
     * nadie.
     *
     * @test
     */
    public function sin_sesion_dan_el_mismo_404_que_un_comercio_inexistente()
    {
        $this->sin_sesion();

        $no_existe = $this->get('reportes/inventario/' . rawurlencode('Comercio que no existe ' . uniqid()) . '/mes');
        $no_existe->assertStatus(404);

        foreach ($this->rutas($this->dueno->company_name) as $ruta) {

            $respuesta = $this->get($ruta);

            $respuesta->assertStatus(404);
            $this->assertSame($no_existe->getContent(), $respuesta->getContent(), $ruta);
        }
    }

    /**
     * La sesión del dueño y la de su empleado los abren.
     *
     * @test
     */
    public function la_sesion_del_comercio_los_abre()
    {
        foreach ($this->rutas($this->dueno->company_name) as $ruta) {
            $this->get($ruta)->assertStatus(200)->assertSee(self::SERVIDA);
        }

        $this->sin_sesion();
        $this->actingAs($this->crear_empleado_del_dueno(), 'web');

        foreach ($this->rutas($this->dueno->company_name) as $ruta) {
            $this->get($ruta)->assertStatus(200)->assertSee(self::SERVIDA);
        }
    }

    /**
     * La sesión de otro comercio no abre los reportes del primero (y sí los suyos).
     *
     * @test
     */
    public function la_sesion_de_otro_comercio_no_los_abre()
    {
        $otro = $this->crear_otro_comercio();

        $this->sin_sesion();
        $this->actingAs($otro, 'web');

        foreach ($this->rutas($this->dueno->company_name) as $ruta) {
            $this->get($ruta)->assertStatus(404);
        }

        foreach ($this->rutas($otro->company_name) as $ruta) {
            $this->get($ruta)->assertStatus(200)->assertSee(self::SERVIDA);
        }
    }

    /**
     * Con la ventana del dueño abierta se sirven sin sesión, como antes, y queda registrado.
     *
     * @test
     */
    public function con_la_ventana_abierta_se_sirven_y_quedan_registrados()
    {
        $this->abrir_ventana($this->dueno->id);

        $this->sin_sesion();

        Log::spy();

        foreach ($this->rutas($this->dueno->company_name) as $ruta) {
            $this->get($ruta)->assertStatus(200)->assertSee(self::SERVIDA);
        }

        $dueno_id = $this->dueno->id;

        Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto = []) use ($dueno_id) {
            return strpos($mensaje, 'ventana de transición') !== false
                && $contexto['tipo'] === 'comercio'
                && $contexto['duenios'] === [$dueno_id]
                && $contexto['con_sesion'] === false;
        })->times(3);
    }

    /**
     * La ventana de OTRO comercio no abre los reportes del primero.
     *
     * @test
     */
    public function la_ventana_de_otro_comercio_no_los_abre()
    {
        $otro = $this->crear_otro_comercio();

        $this->abrir_ventana($otro->id);

        $this->sin_sesion();

        foreach ($this->rutas($this->dueno->company_name) as $ruta) {
            $this->get($ruta)->assertStatus(404);
        }
    }

    /**
     * Si el primer usuario con ese nombre es un empleado, el comercio es el de su dueño (el
     * controlador toma ese mismo primer usuario).
     *
     * @test
     */
    public function un_nombre_que_lleva_un_empleado_es_del_comercio_de_su_dueno()
    {
        $otro = $this->crear_otro_comercio();

        $empleado_del_otro = DB::table('users')->insertGetId([
            'name'         => 'Empleado con nombre de comercio',
            'company_name' => 'Nombre solo del empleado ' . uniqid(),
            'email'        => 'pdf-publico-empleado-nombre-' . uniqid() . '@test.local',
            'password'     => 'x',
            'status'       => 'commerce',
            'owner_id'     => $otro->id,
        ]);

        $nombre = DB::table('users')->where('id', $empleado_del_otro)->value('company_name');

        // El dueño del fixture no abre ese nombre; el dueño del empleado, sí.
        foreach ($this->rutas($nombre) as $ruta) {
            $this->get($ruta)->assertStatus(404);
        }

        $this->sin_sesion();
        $this->actingAs($otro, 'web');

        foreach ($this->rutas($nombre) as $ruta) {
            $this->get($ruta)->assertStatus(200)->assertSee(self::SERVIDA);
        }
    }
}
