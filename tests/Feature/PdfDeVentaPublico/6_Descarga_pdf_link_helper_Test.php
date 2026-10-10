<?php

namespace Tests\Feature\PdfDeVentaPublico;

use App\Http\Controllers\Helpers\PdfLinkHelper;
use App\Models\PdfLink;
use Carbon\Carbon;

/**
 * PdfLinkHelper (misión pdf-de-venta-publico, 10/10/2026): el reuso del token, los tipos que lo
 * admiten (contrato con tienda-api) y cómo se suma el `t=` a una URL.
 */
class Descarga_pdf_link_helper_Test extends DescargaTestCase
{
    /**
     * Los tipos que admiten token son exactamente los del contrato con tienda-api.
     *
     * @test
     */
    public function los_tipos_con_token_son_los_del_contrato()
    {
        $this->assertSame(['sale', 'budget', 'current_acount', 'credit_account', 'order'], PdfLinkHelper::TIPOS_CON_TOKEN);

        $this->assertFalse(PdfLinkHelper::admite_token('road_map'));
        $this->assertFalse(PdfLinkHelper::admite_token('afip_ticket'));
        $this->assertFalse(PdfLinkHelper::admite_token(null));
    }

    /**
     * Un tipo que no admite token o un id inválido no emiten nada: tiran, para que un armador mal
     * escrito se note en el momento y no mande un link que no abre.
     *
     * @test
     */
    public function un_tipo_sin_token_o_un_id_invalido_no_emiten()
    {
        $antes = PdfLink::count();

        foreach ([['road_map', 5], ['sale', 0], ['sale', 'abc'], ['sale', '12abc']] as $caso) {

            try {
                PdfLinkHelper::token_para($caso[0], $caso[1], $this->dueno->id);
                $this->fail('Tenía que tirar con ' . json_encode($caso));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame($antes, PdfLink::count());
    }

    /**
     * Se reutiliza la fila vigente; revocada, la próxima vez se emite un token nuevo y el viejo deja
     * de abrir.
     *
     * @test
     */
    public function reutiliza_el_vigente_y_emite_otro_si_se_revoco()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $primero = PdfLinkHelper::token_para('sale', $venta_id, $this->dueno->id);
        $this->assertSame($primero, PdfLinkHelper::token_para('sale', $venta_id, $this->dueno->id));
        $this->assertSame(48, strlen($primero));

        PdfLink::where('token', $primero)->update(['revoked_at' => Carbon::now()]);

        $segundo = PdfLinkHelper::token_para('sale', $venta_id, $this->dueno->id);

        $this->assertNotSame($primero, $segundo);
        $this->assertFalse(PdfLinkHelper::token_valido('sale', $venta_id, $primero));
        $this->assertTrue(PdfLinkHelper::token_valido('sale', $venta_id, $segundo));
    }

    /**
     * `con_token()` usa `?` si la URL no tiene query, `&` si ya tiene, y deja el `#fragmento` al
     * final.
     *
     * @test
     */
    public function con_token_suma_el_parametro_con_el_separador_que_corresponde()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $token = PdfLinkHelper::token_para('sale', $venta_id, $this->dueno->id);

        $base = 'https://api-x.comerciocity.com/sale/pdf/' . $venta_id;

        $this->assertSame($base . '?t=' . $token, PdfLinkHelper::con_token($base, 'sale', $venta_id, $this->dueno->id));
        $this->assertSame($base . '?a=1&t=' . $token, PdfLinkHelper::con_token($base . '?a=1', 'sale', $venta_id, $this->dueno->id));
        $this->assertSame($base . '?t=' . $token, PdfLinkHelper::con_token($base . '?', 'sale', $venta_id, $this->dueno->id));
        $this->assertSame($base . '?a=1&t=' . $token . '#p2', PdfLinkHelper::con_token($base . '?a=1#p2', 'sale', $venta_id, $this->dueno->id));
    }

    /**
     * Los dueños de cada recurso: null si no existe; los padres para los tipos sin `user_id`.
     *
     * @test
     */
    public function resuelve_el_dueno_o_null_si_no_existe()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $this->assertSame([$this->dueno->id], PdfLinkHelper::duenios_del_recurso('sale', $venta_id));
        $this->assertSame([$this->dueno->id], PdfLinkHelper::duenios_del_recurso('sale', (string) $venta_id));
        $this->assertNull(PdfLinkHelper::duenios_del_recurso('sale', 999999999));
        $this->assertNull(PdfLinkHelper::duenios_del_recurso('sale', $venta_id . 'abc'));
        $this->assertNull(PdfLinkHelper::duenios_del_recurso('tipo_inventado', $venta_id));
        $this->assertNull(PdfLinkHelper::duenios_del_recurso('articles', ''));
        $this->assertNull(PdfLinkHelper::duenios_del_recurso('articles', '999999998-999999999'));
    }
}
