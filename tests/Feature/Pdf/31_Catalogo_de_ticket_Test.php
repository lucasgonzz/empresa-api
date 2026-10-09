<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfLayout\CamposDeTicketPdf;
use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDerivadoPdf;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;
use Tests\Feature\Pdf\Concerns\ComprobantesConDisenoDePagina;
use Tests\Feature\Pdf\Concerns\PerfilesDeTicketDeComandera;

/**
 * El catálogo del diseñador para un TICKET DE COMANDERA (misión diseno-ticket-comandera,
 * 9/10/2026, contrato §3.3 a §3.5 y §5 del plan): las claves nuevas, la categoría y los campos
 * `negocio_*`, los tres fijos de ARCA a lo ancho, el derivado equivalente al Ticket 2.0 de siempre,
 * de dónde sale la clase (el `sheet_type_id` del formulario o el del perfil), el parámetro
 * `sin_comprobante_de_prueba` y que cada campo del catálogo de ticket se resuelve (el equivalente
 * del test 27 para el catálogo de ticket).
 *
 * Que el catálogo de HOJA siga saliendo igual lo cuida el test 22 (con las tres claves nuevas
 * declaradas al final).
 *
 * @group pdf-ticket-comandera
 */
class Catalogo_de_ticket_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;
    use ComprobantesConDisenoDePagina;
    use PerfilesDeTicketDeComandera;

    const URL = 'api/pdf-column-profiles/page-layout-catalog';

    /** Las claves de una hoja (las nueve de siempre y las tres nuevas de todos los casos). */
    const CLAVES_DE_HOJA = [
        'model_name', 'es_fiscal', 'categorias', 'campos', 'fijos', 'formatos_de_hoja', 'limites',
        'diseno_derivado', 'comprobante_de_prueba', 'es_ticket', 'grilla_de_tabla', 'columnas_sugeridas',
    ];

    /** Las que suma un ticket, al final. */
    const CLAVES_DE_TICKET = ['ancho_mm', 'caracteres_por_renglon', 'tamanos_de_ticket'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_dueno_para_pdf(false);
        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        $this->borrar_archivos_de_prueba();

        parent::tearDown();
    }

    /**
     * GET al catálogo.
     *
     * @param array $query
     * @return array
     */
    private function catalogo(array $query)
    {
        return $this->getJson(self::URL.'?'.http_build_query($query))->assertStatus(200)->json();
    }

    /**
     * Ids (o keys de los fijos) de una zona del diseño, en orden.
     *
     * @param array $zona
     * @return array<int, string>
     */
    private function ids($zona)
    {
        $ids = [];
        foreach ($zona as $item) {
            $ids[] = $item['tipo'] === 'fijo' ? 'fijo:'.$item['key'] : $item['id'];
        }

        return $ids;
    }

    /**
     * El campo de una caja del derivado.
     *
     * @param array  $diseno
     * @param string $key
     * @return array|null
     */
    private function campo_del_derivado($diseno, $key)
    {
        foreach (['superior', 'pie'] as $zona) {
            foreach ($diseno[$zona] as $item) {
                foreach (isset($item['campos']) ? $item['campos'] : [] as $campo) {
                    if ($campo['key'] === $key) {
                        return $campo;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @test
     */
    public function un_rollo_de_comandera_devuelve_el_catalogo_de_ticket()
    {
        $json = $this->catalogo(['model_name' => 'sale', 'sheet_type_id' => $this->rollo_del_sistema(80)->id]);

        $this->assertSame(array_merge(self::CLAVES_DE_HOJA, self::CLAVES_DE_TICKET), array_keys($json));
        $this->assertTrue($json['es_ticket']);
        $this->assertSame(24, $json['grilla_de_tabla']);
        $this->assertSame(['item_name', 'item_amount', 'item_price', 'item_subtotal'], $json['columnas_sugeridas']);
        $this->assertSame(80, $json['ancho_mm']);
        $this->assertSame(48, $json['caracteres_por_renglon']);
        $this->assertSame([
            ['tamano' => 9, 'nombre' => 'Normal'],
            ['tamano' => 12, 'nombre' => 'Alto doble'],
            ['tamano' => 18, 'nombre' => 'Grande'],
        ], $json['tamanos_de_ticket']);
        $this->assertSame([], $json['formatos_de_hoja'], 'Un rollo no tiene formatos de hoja.');
        $this->assertSame([], $json['fijos'], 'Sin factura no hay fijos.');

        /** "Negocio" primero, y después las categorías de venta de siempre. */
        $this->assertSame(['key' => 'negocio', 'nombre' => 'Negocio', 'icono' => 'bi-shop'], $json['categorias'][0]);
        $this->assertSame(CatalogoDeCamposPdf::categorias('sale'), array_slice($json['categorias'], 1));

        /** Los negocio_* primero (el logo, de tipo imagen) y después TODOS los campos de venta. */
        $this->assertSame(CatalogoDeCamposPdf::campos('sale', true), $json['campos']);
        $keys = array_column($json['campos'], 'key');
        $this->assertSame([
            'negocio_logo', 'negocio_nombre', 'negocio_razon_social', 'negocio_cuit', 'negocio_condicion_iva',
            'negocio_domicilio', 'negocio_ingresos_brutos', 'negocio_inicio_actividades', 'negocio_telefono',
            'negocio_email', 'negocio_web',
        ], array_slice($keys, 0, 11));
        $this->assertSame(CatalogoDeCamposPdf::keys('sale'), array_slice($keys, 11));
        $this->assertSame('imagen', $json['campos'][0]['tipo']);
        $this->assertNull($json['campos'][0]['ejemplo']);

        /** Un rollo de 55 mm: 33 caracteres. */
        $json = $this->catalogo(['model_name' => 'sale', 'sheet_type_id' => $this->rollo_del_sistema(55)->id]);
        $this->assertSame(55, $json['ancho_mm']);
        $this->assertSame(33, $json['caracteres_por_renglon']);
    }

    /**
     * @test
     */
    public function el_derivado_del_ticket_remito_es_el_ticket_de_siempre()
    {
        $json = $this->catalogo(['model_name' => 'sale', 'sheet_type_id' => $this->rollo_del_sistema(80)->id]);
        $derivado = $json['diseno_derivado'];

        $this->assertSame(['caja_logo', 'caja_negocio', 'caja_venta', 'caja_cliente'], $this->ids($derivado['superior']));
        $this->assertSame(['caja_totales'], $this->ids($derivado['pie']));

        $this->assertSame('Venta Número', $this->campo_del_derivado($derivado, 'venta_numero')['etiqueta']);
        $this->assertSame(12, $this->campo_del_derivado($derivado, 'cliente_nombre')['tamano'], 'El cliente en alto doble.');

        $total = $this->campo_del_derivado($derivado, 'tot_total');
        $this->assertSame('TOTAL A PAGAR', $total['etiqueta']);
        $this->assertTrue($total['negrita']);
        $this->assertSame('izquierda', $total['alineacion']);
    }

    /**
     * @test
     */
    public function la_factura_en_ticket_tiene_los_tres_fijos_a_lo_ancho()
    {
        $json = $this->catalogo(['model_name' => 'sale', 'is_afip_ticket' => 1, 'sheet_type_id' => $this->rollo_del_sistema(80)->id]);

        $this->assertTrue($json['es_fiscal']);
        $this->assertSame(['afip_emisor', 'afip_receptor', 'afip_pie'], array_column($json['fijos'], 'key'));
        $this->assertSame(['superior', 'superior', 'pie'], array_column($json['fijos'], 'zona'));
        foreach ($json['fijos'] as $fijo) {
            $this->assertFalse($fijo['redimensionable'], $fijo['key'].' va siempre a lo ancho del rollo.');
            $this->assertSame(12, $fijo['cols_min']);
        }

        $derivado = $json['diseno_derivado'];
        $this->assertSame(
            ['caja_logo', 'fijo:afip_emisor', 'caja_negocio', 'caja_venta', 'caja_cliente', 'fijo:afip_receptor'],
            $this->ids($derivado['superior'])
        );
        $this->assertSame(['caja_totales', 'fijo:afip_pie'], $this->ids($derivado['pie']));
        $this->assertSame(12, $derivado['superior'][5]['cols']);

        /** La factura de HOJA no cambia: dos fijos, el del cliente redimensionable desde 6. */
        $hoja = $this->catalogo(['model_name' => 'sale', 'is_afip_ticket' => 1]);
        $this->assertSame(CatalogoDeCamposPdf::fijos('sale', true), $hoja['fijos']);
        $this->assertSame(['afip_receptor', 'afip_pie'], array_column($hoja['fijos'], 'key'));
    }

    /**
     * @test
     */
    public function la_clase_sale_del_formulario_o_del_perfil_y_solo_la_venta_es_ticket()
    {
        $ticket_55 = $this->perfil_de_ticket($this->dueno->id, false, [], 55);
        $hoja = $this->perfil_de_hoja($this->dueno->id, false);

        /** Sin sheet_type_id: el del perfil guardado. */
        $json = $this->catalogo(['model_name' => 'sale', 'profile_id' => $ticket_55->id]);
        $this->assertTrue($json['es_ticket']);
        $this->assertSame(33, $json['caracteres_por_renglon']);

        /** El del formulario manda sobre el guardado (en los dos sentidos). */
        $json = $this->catalogo(['model_name' => 'sale', 'profile_id' => $hoja->id, 'sheet_type_id' => $this->rollo_del_sistema(80)->id]);
        $this->assertTrue($json['es_ticket']);
        $this->assertSame(48, $json['caracteres_por_renglon']);

        $json = $this->catalogo(['model_name' => 'sale', 'profile_id' => $ticket_55->id, 'sheet_type_id' => $this->hoja_a4()->id]);
        $this->assertFalse($json['es_ticket']);
        $this->assertSame(self::CLAVES_DE_HOJA, array_keys($json));

        /** Un rollo propio de OTRO dueño no vale: sin perfil, hoja. */
        $otro = $this->crear_dueno('Otro dueno ticket');
        $json = $this->catalogo(['model_name' => 'sale', 'sheet_type_id' => $this->rollo_propio($otro->id, 72)->id]);
        $this->assertFalse($json['es_ticket']);

        /** El rollo propio del dueño, sí. */
        $json = $this->catalogo(['model_name' => 'sale', 'sheet_type_id' => $this->rollo_propio($this->dueno->id, 72)->id]);
        $this->assertTrue($json['es_ticket']);
        $this->assertSame(72, $json['ancho_mm']);
        $this->assertSame(43, $json['caracteres_por_renglon']);

        /** Un presupuesto con un rollo sigue siendo hoja, con sus columnas sugeridas. */
        $json = $this->catalogo(['model_name' => 'budget', 'sheet_type_id' => $this->rollo_del_sistema(80)->id]);
        $this->assertFalse($json['es_ticket']);
        $this->assertSame(self::CLAVES_DE_HOJA, array_keys($json));
        $this->assertSame(['document_item_name', 'document_item_amount', 'document_item_price', 'document_item_subtotal'], $json['columnas_sugeridas']);
        $this->assertSame(CatalogoDeCamposPdf::campos('budget'), $json['campos']);
    }

    /**
     * @test
     */
    public function sin_comprobante_de_prueba_no_lo_busca()
    {
        $venta = $this->crear_venta_completa();
        Budget::create(['num' => 77, 'user_id' => $this->dueno->id, 'client_id' => $venta->client_id, 'budget_status_id' => 1, 'total' => 0, 'moneda_id' => 1]);

        /** Sin el parámetro, igual que siempre. */
        $this->assertSame($venta->id, $this->catalogo(['model_name' => 'sale'])['comprobante_de_prueba']['id']);
        $this->assertNotNull($this->catalogo(['model_name' => 'budget'])['comprobante_de_prueba']);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $json = $this->catalogo(['model_name' => 'budget', 'sin_comprobante_de_prueba' => 1]);

        $consultas = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        $this->assertArrayHasKey('comprobante_de_prueba', $json, 'La clave sigue estando.');
        $this->assertNull($json['comprobante_de_prueba']);
        $this->assertStringNotContainsString('`budgets`', $consultas, 'No recorre la tabla de presupuestos.');

        $this->assertNull($this->catalogo(['model_name' => 'sale', 'sin_comprobante_de_prueba' => 1])['comprobante_de_prueba']);
        $this->assertSame($venta->id, $this->catalogo(['model_name' => 'sale', 'sin_comprobante_de_prueba' => 0])['comprobante_de_prueba']['id']);
    }

    /**
     * @test
     */
    public function asegurar_fijos_pone_el_emisor_solo_en_el_ticket_fiscal()
    {
        $con_emisor = DisenoDePaginaPdf::normalizar([
            'superior' => [['tipo' => 'fijo', 'key' => 'afip_emisor'], ['tipo' => 'fijo', 'key' => 'afip_receptor', 'cols' => 6]],
            'pie' => [['tipo' => 'fijo', 'key' => 'afip_emisor']],
        ]);

        /** normalizar(): el emisor vale solo en "superior". */
        $this->assertSame(['fijo:afip_emisor', 'fijo:afip_receptor'], $this->ids($con_emisor['superior']));
        $this->assertSame([], $con_emisor['pie']);

        /** Hoja fiscal: el emisor se saca; el cliente conserva su ancho. */
        $hoja = DisenoDePaginaPdf::asegurar_fijos($con_emisor, true);
        $this->assertSame(['fijo:afip_receptor'], $this->ids($hoja['superior']));
        $this->assertSame(6, $hoja['superior'][0]['cols']);
        $this->assertSame(['fijo:afip_pie'], $this->ids($hoja['pie']));

        /** Ticket fiscal: los tres, el cliente a lo ancho. */
        $ticket = DisenoDePaginaPdf::asegurar_fijos($con_emisor, true, true);
        $this->assertSame(['fijo:afip_emisor', 'fijo:afip_receptor'], $this->ids($ticket['superior']));
        $this->assertSame(12, $ticket['superior'][1]['cols']);
        $this->assertSame(['fijo:afip_pie'], $this->ids($ticket['pie']));

        /** Ticket fiscal vacío: emisor al principio, cliente al final de arriba, pie al final. */
        $vacio = DisenoDePaginaPdf::asegurar_fijos(['version' => 1, 'superior' => [['tipo' => 'caja', 'id' => 'c', 'cols' => 12, 'titulo' => '', 'estilo' => 'borde', 'campos' => []]], 'pie' => []], true, true);
        $this->assertSame(['fijo:afip_emisor', 'c', 'fijo:afip_receptor'], $this->ids($vacio['superior']));
        $this->assertSame(['fijo:afip_pie'], $this->ids($vacio['pie']));

        /** Ticket no fiscal: ningún fijo. */
        $remito = DisenoDePaginaPdf::asegurar_fijos($con_emisor, false, true);
        $this->assertSame([], $remito['superior']);
    }

    /**
     * @test
     */
    public function cada_campo_del_catalogo_de_ticket_se_resuelve()
    {
        $this->dueno->phone = '341 555-1234';
        $this->dueno->online = 'https://mitienda.com.ar';
        $this->dueno->image_url = 'https://logo.test/logo.png';
        $this->dueno->save();
        $dueno = User::find($this->dueno->id);

        $venta = $this->completar_venta_con_origen_factura_y_envio($this->crear_venta_completa());
        $factura = $venta->afip_tickets()->first();

        foreach ([[false, null], [true, $factura]] as $caso) {
            $fuente = new CamposDeTicketPdf($venta, $dueno, false, 'descriptivo', $caso[0], $caso[1]);
            $valores = [];

            foreach (CatalogoDeCamposPdf::campos('sale', true) as $definicion) {
                $key = $definicion['key'];
                $campo = $this->campo_de_caja($key, $key === CatalogoDeCamposPdf::KEY_TEXTO_LIBRE ? ['id' => 'texto_1', 'texto' => 'Gracias'] : []);
                $valor = $fuente->valor($key, $campo);

                if (is_array($valor)) {
                    $this->assertNotEmpty($valor, $key.': una lista vacía tiene que ser null.');
                    foreach ($valor as $elemento) {
                        $this->assertIsString($elemento, $key);
                    }
                } elseif (! is_null($valor)) {
                    $this->assertIsString($valor, $key);
                    $this->assertNotSame('', trim($valor), $key.': un valor vacío tiene que ser null.');
                }

                $valores[$key] = $valor;
            }

            $this->assertSame(CatalogoDeCamposPdf::keys('sale', true), array_keys($valores));

            /** Los datos del negocio: los del encabezado del PDF de siempre. */
            $this->assertSame('https://logo.test/logo.png', $valores['negocio_logo']);
            $this->assertSame($dueno->company_name, $valores['negocio_nombre']);
            $this->assertSame('Razon Social PDF SA', $valores['negocio_razon_social']);
            $this->assertSame('30111111118', $valores['negocio_cuit']);
            $this->assertSame('Responsable inscripto', $valores['negocio_condicion_iva']);
            $this->assertSame('Calle Falsa 123', $valores['negocio_domicilio']);
            $this->assertSame('30111111118', $valores['negocio_ingresos_brutos']);
            $this->assertSame('01/01/2020', $valores['negocio_inicio_actividades']);
            $this->assertSame('341 555-1234', $valores['negocio_telefono']);
            $this->assertSame($dueno->email, $valores['negocio_email']);
            $this->assertSame('mitienda.com.ar', $valores['negocio_web'], 'La web sin el protocolo, como el encabezado de siempre.');

            /** Lo de la venta lo resuelve la fuente de siempre. */
            $this->assertSame('Juan Perez Test', $valores['cliente_nombre']);
            $this->assertSame('1520', $valores['venta_numero']);
        }

        /** Una key que no es del catálogo de ticket tira (un campo sin su resolver da rojo). */
        $this->expectException(\InvalidArgumentException::class);
        (new CamposDeTicketPdf($venta, $dueno, false, 'descriptivo'))->valor('negocio_que_no_existe', []);
    }
}
