<?php

namespace Tests\Feature\PdfDeVentaPublico;

use App\Http\Controllers\Helpers\PdfLinkHelper;
use App\Models\PdfLink;

/**
 * `GET api/pdf-link/{tipo}/{id}` (misión pdf-de-venta-publico, 10/10/2026): el token que el botón de
 * WhatsApp de la SPA suma al link de la venta o del presupuesto.
 */
class Descarga_endpoint_pdf_link_Test extends DescargaTestCase
{
    /**
     * El dueño recibe un token que abre ESA venta, y el segundo pedido devuelve el mismo token (no
     * una fila nueva por cada vez que alguien aprieta el botón).
     *
     * @test
     */
    public function el_dueno_recibe_el_token_y_se_reutiliza()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $primera = $this->getJson('api/pdf-link/sale/' . $venta_id)->assertStatus(200)->json('token');

        $this->assertIsString($primera);
        $this->assertSame(PdfLinkHelper::LARGO_DEL_TOKEN, strlen($primera));
        $this->assertTrue(PdfLinkHelper::token_valido('sale', $venta_id, $primera));

        $segunda = $this->getJson('api/pdf-link/sale/' . $venta_id)->assertStatus(200)->json('token');

        $this->assertSame($primera, $segunda);
        $this->assertSame(1, PdfLink::where('tipo', 'sale')->where('model_id', $venta_id)->count());

        $fila = PdfLink::where('tipo', 'sale')->where('model_id', $venta_id)->first();
        $this->assertSame($this->dueno->id, $fila->user_id);
    }

    /**
     * Un empleado del comercio también lo pide (comparte desde la pantalla de Ventas), y le toca
     * el mismo token que al dueño.
     *
     * @test
     */
    public function el_empleado_recibe_el_mismo_token()
    {
        $presupuesto_id = $this->presupuesto_de($this->dueno->id);

        $del_dueno = $this->getJson('api/pdf-link/budget/' . $presupuesto_id)->assertStatus(200)->json('token');

        $this->sin_sesion();
        $this->actingAs($this->crear_empleado_del_dueno(), 'web');

        $this->getJson('api/pdf-link/budget/' . $presupuesto_id)->assertStatus(200)->assertJson(['token' => $del_dueno]);
    }

    /**
     * Un recurso de otro comercio, uno que no existe, un tipo que no admite token y un id que no es
     * un id dan el mismo 404, y no dejan ninguna fila.
     *
     * @test
     */
    public function lo_ajeno_lo_inexistente_y_lo_no_admitido_dan_404()
    {
        $venta_ajena_id = $this->venta_de($this->crear_otro_comercio()->id);

        $antes = PdfLink::count();

        $ajena = $this->getJson('api/pdf-link/sale/' . $venta_ajena_id);
        $inexistente = $this->getJson('api/pdf-link/sale/999999999');
        $tipo_no_admitido = $this->getJson('api/pdf-link/road_map/' . $venta_ajena_id);
        $tipo_inventado = $this->getJson('api/pdf-link/users/500');
        $id_raro = $this->getJson('api/pdf-link/sale/' . $venta_ajena_id . 'abc');

        foreach ([$ajena, $inexistente, $tipo_no_admitido, $tipo_inventado, $id_raro] as $respuesta) {
            $respuesta->assertStatus(404);
            $this->assertSame($ajena->getContent(), $respuesta->getContent());
        }

        $this->assertSame($antes, PdfLink::count(), 'Un 404 no puede emitir ningún token.');
    }

    /**
     * Sin sesión no hay token: la ruta está en el grupo auth:sanctum.
     *
     * @test
     */
    public function sin_sesion_no_hay_token()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $this->sin_sesion();

        $this->getJson('api/pdf-link/sale/' . $venta_id)->assertStatus(401);
    }
}
