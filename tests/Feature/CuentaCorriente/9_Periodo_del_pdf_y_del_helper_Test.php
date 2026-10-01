<?php

namespace Tests\Feature\CuentaCorriente;

use App\Http\Controllers\Helpers\currentAcount\CuentaCorrientePeriodoHelper;
use Tests\EmpresaTestCase;

/**
 * Misión cuenta-corriente-periodo (1/10/2026) — el PDF de la cuenta corriente con período y el
 * helper que comparte con el listado.
 *
 * 🔴 Lo que NO se puede probar por la ruta: el camino feliz del PDF. `CurrentAcountPdf` termina
 * con `$this->Output(); exit;` en el constructor, y un `exit` adentro del proceso de PHPUnit lo
 * mata. Por eso por la ruta real (`GET current-acount/pdf/{id}/{cantidad}/{type?}`) se prueban los
 * caminos que cortan ANTES del PDF (período vacío y cuenta inexistente => 404), y la selección de
 * movimientos —que es lo único que el PDF hace distinto del listado— se prueba sobre el helper, que
 * es exactamente lo que el PDF llama.
 *
 * @group cuenta-corriente
 */
class Periodo_del_pdf_y_del_helper_Test extends EmpresaTestCase
{
    use ArmaCadenas;

    /** @var \App\Models\CreditAccount */
    protected $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        list($cliente, $this->cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Período PDF');
    }

    /**
     * @param  string  $fecha_hora
     * @return \App\Models\CurrentAcount
     */
    protected function mov($fecha_hora)
    {
        return $this->movimiento($this->cuenta, ['debe' => 100, 'created_at' => $fecha_hora]);
    }

    /**
     * @test
     */
    public function el_pdf_de_un_periodo_sin_movimientos_responde_404_con_mensaje_legible()
    {
        $this->mov('2026-09-10 10:00:00');

        foreach (['simple', 'details'] as $tipo) {

            $respuesta = $this->get('current-acount/pdf/'.$this->cuenta->id.'/10/'.$tipo.'?desde=2026-07-01&hasta=2026-07-31');

            $respuesta->assertStatus(404);
            $this->assertSame('No hay movimientos en el período seleccionado.', $respuesta->exception->getMessage(), $tipo);
        }
    }

    /**
     * @test
     */
    public function el_pdf_de_un_periodo_de_una_cuenta_inexistente_responde_404()
    {
        $this->get('current-acount/pdf/999999999/10?desde=2026-07-01')->assertStatus(404);
    }

    /**
     * La fecha del período es estricta; todo lo demás cuenta como "no vino".
     *
     * @test
     */
    public function el_helper_parsea_fechas_y_minimos_estrictos()
    {
        $this->assertSame('2026-09-01', CuentaCorrientePeriodoHelper::fecha('2026-09-01'));
        $this->assertSame('2024-02-29', CuentaCorrientePeriodoHelper::fecha('2024-02-29'));

        foreach (['2026-02-29', '2026-9-1', '01/09/2026', '2026-09-01 10:00:00', '', null, ['2026-09-01'], 20260901] as $invalida) {
            $this->assertNull(CuentaCorrientePeriodoHelper::fecha($invalida), json_encode($invalida));
        }

        $this->assertSame(10, CuentaCorrientePeriodoHelper::minimo('10'));
        $this->assertSame(1, CuentaCorrientePeriodoHelper::minimo(1));

        foreach (['0', '-1', 'abc', '', null, ['5']] as $invalido) {
            $this->assertNull(CuentaCorrientePeriodoHelper::minimo($invalido), json_encode($invalido));
        }
    }

    /**
     * Es la llamada que hace el PDF: sin `minimo`, con `hasta` null o inclusivo, en orden DESC.
     *
     * @test
     */
    public function el_helper_sin_minimo_devuelve_exactamente_el_periodo_del_mas_nuevo_al_mas_viejo()
    {
        $this->mov('2026-08-31 23:59:59');
        $a = $this->mov('2026-09-01 00:00:00')->id;
        $b = $this->mov('2026-09-30 23:30:00')->id;
        $this->mov('2026-10-01 00:00:00');

        $resultado = CuentaCorrientePeriodoHelper::consultar($this->cuenta->id, '2026-09-01', '2026-09-30');

        $this->assertSame([$b, $a], $resultado['models']->pluck('id')->all());
        $this->assertSame(['desde' => '2026-09-01', 'hasta' => '2026-09-30', 'ampliado' => false, 'cantidad' => 2], $resultado['periodo']);

        // Sin hasta: todo desde `desde`.
        $sin_tope = CuentaCorrientePeriodoHelper::consultar($this->cuenta->id, '2026-09-01');

        $this->assertCount(3, $sin_tope['models']);
        $this->assertNull($sin_tope['periodo']['hasta']);
    }

    /**
     * @test
     */
    public function el_helper_carga_las_relaciones_que_se_le_piden()
    {
        $this->mov('2026-09-10 10:00:00');

        $resultado = CuentaCorrientePeriodoHelper::consultar($this->cuenta->id, '2026-09-01', null, null, ['articles', 'sale.articles']);

        $this->assertTrue($resultado['models'][0]->relationLoaded('articles'));
        $this->assertTrue($resultado['models'][0]->relationLoaded('sale'));
    }
}
