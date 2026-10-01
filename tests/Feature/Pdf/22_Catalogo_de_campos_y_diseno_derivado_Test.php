<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfDocumentSetupHelper;
use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDerivadoPdf;
use App\Models\AfipTicket;
use App\Models\Budget;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\Order;
use App\Models\PdfColumnProfile;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El endpoint que abre el diseñador de PDF (`GET api/pdf-column-profiles/page-layout-catalog`) y
 * el diseño DERIVADO que devuelve: el diseño con cajas equivalente a lo que cada perfil imprime
 * hoy con sus flags (misión diseno-pdf-configurable, 1/10/2026, §2.3 y §5 del plan).
 *
 * Por qué importa el derivado: es lo que el dueño ve al abrir el diseñador sobre un perfil que
 * nunca se diseñó, y lo que queda impreso si guarda sin tocar nada. Si no refleja los flags, el
 * dueño abre su remito de siempre y se encuentra otra cosa. Cada test de flags nombra el lugar
 * del dibujante de siempre (NewSalePdf / ProfileDocumentPdf) que el derivado imita.
 *
 * Cada test crea dentro de su transacción todo lo que usa (perfiles, ventas, presupuestos,
 * pedidos, otro dueño): ninguno toma "la última venta" de la base.
 *
 * @group pdf-diseno-de-pagina
 */
class Catalogo_de_campos_y_diseno_derivado_Test extends TestCase
{
    use DatabaseTransactions;

    const URL = 'api/pdf-column-profiles/page-layout-catalog';

    /** Claves exactas de la respuesta (contrato §2.3). */
    const CLAVES_DE_LA_RESPUESTA = [
        'model_name',
        'es_fiscal',
        'categorias',
        'campos',
        'fijos',
        'formatos_de_hoja',
        'limites',
        'diseno_derivado',
        'comprobante_de_prueba',
    ];

    /**
     * Dueño autenticado (mismo patrón que los tests 4 y 13 de esta carpeta).
     *
     * @param array $atributos atributos del dueño que se pisan adentro de la transacción.
     * @return \App\Models\User
     */
    protected function autenticar($atributos = [])
    {
        $owner = User::find(500);
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        if (! empty($atributos)) {
            $owner->update($atributos);
        }

        $this->actingAs($owner, 'web');

        return $owner;
    }

    /**
     * Un dueño nuevo, sin ventas, presupuestos ni pedidos.
     *
     * @return \App\Models\User
     */
    protected function crear_dueno()
    {
        return User::create([
            'name'         => 'Dueno diseñador de PDF',
            'company_name' => 'Dueno diseñador de PDF',
            'email'        => 'pdf-disenador-'.uniqid().'@test.local',
            'password'     => 'x',
        ]);
    }

    /**
     * Diseño de PDF del dueño, creado directo por Eloquent.
     *
     * @param int   $owner_id
     * @param array $overrides
     * @return \App\Models\PdfColumnProfile
     */
    protected function crear_perfil($owner_id, array $overrides = [])
    {
        return PdfColumnProfile::create(array_merge([
            'user_id'            => $owner_id,
            'model_name'         => 'sale',
            'name'               => 'zz Remito de test diseño derivado',
            'paper_width_mm'     => 210,
            'printable_width_mm' => 210,
            'margin_mm'          => 5,
            'columns'            => [],
        ], $overrides));
    }

    /**
     * GET al catálogo del diseñador.
     *
     * @param array $query
     * @return \Illuminate\Testing\TestResponse
     */
    protected function catalogo($query)
    {
        return $this->getJson(self::URL.'?'.http_build_query($query));
    }

    /**
     * El derivado que devuelve el endpoint para un perfil del dueño autenticado.
     *
     * @param \App\Models\PdfColumnProfile $perfil
     * @return array
     */
    protected function derivado_del_endpoint($perfil)
    {
        $response = $this->catalogo(['model_name' => $perfil->model_name, 'profile_id' => $perfil->id]);
        $response->assertStatus(200);

        return $response->json('diseno_derivado');
    }

    /**
     * Ids de los ítems de una zona (cajas y saltos) y keys de los fijos, en orden.
     *
     * @param array $items
     * @return array<int, string>
     */
    protected function ids($items)
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item['tipo'] === 'fijo' ? 'fijo:'.$item['key'] : $item['id'];
        }

        return $ids;
    }

    /**
     * La caja con ese id, o null.
     *
     * @param array  $diseno
     * @param string $id
     * @return array|null
     */
    protected function caja($diseno, $id)
    {
        foreach (DisenoDePaginaPdf::ZONAS as $zona) {
            foreach ($diseno[$zona] as $item) {
                if ($item['tipo'] === 'caja' && $item['id'] === $id) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * Las keys de los campos de una caja, en orden.
     *
     * @param array $caja
     * @return array<int, string>
     */
    protected function keys($caja)
    {
        $keys = [];
        foreach ($caja['campos'] as $campo) {
            $keys[] = $campo['key'];
        }

        return $keys;
    }

    /**
     * El campo de esa key dentro de una caja.
     *
     * @param array  $caja
     * @param string $key
     * @return array
     */
    protected function campo($caja, $key)
    {
        foreach ($caja['campos'] as $campo) {
            if ($campo['key'] === $key) {
                return $campo;
            }
        }

        $this->fail('La caja '.$caja['id'].' no tiene el campo '.$key.'.');
    }

    /**
     * La extensión vendedor_en_sale_pdf (la crea si la base de testing no la tiene sembrada).
     *
     * @return \App\Models\ExtencionEmpresa
     */
    protected function extension_vendedor_en_sale_pdf()
    {
        $extension = ExtencionEmpresa::where('slug', 'vendedor_en_sale_pdf')->first();

        if (is_null($extension)) {
            $extension = new ExtencionEmpresa();
            $extension->name = 'vendedor_en_sale_pdf';
            $extension->slug = 'vendedor_en_sale_pdf';
            $extension->save();
        }

        return $extension;
    }

    /**
     * Combinaciones de flags para recorrer el derivado de punta a punta.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function combinaciones_de_flags()
    {
        return [
            [],
            ['show_total_in_footer' => false],
            ['show_subtotal_in_footer' => false, 'show_client_description' => false],
            ['show_comissions' => true, 'show_total_costs' => true, 'footer_text' => "Gracias por su compra\r\nVuelva pronto"],
            ['show_total_in_footer' => false, 'show_comissions' => true, 'footer_text' => 'Solo texto'],
            ['show_totals_on_each_page' => true, 'show_total_in_footer' => false, 'show_total_costs' => true],
            /**
             * Sin espacios a propósito: normalizar() recorta DESPUÉS de sacar los espacios del final,
             * así que un corte que cae justo después de un espacio deja uno colgando que una segunda
             * pasada saca (detalle del normalizador, reportado aparte; acá no se mide eso).
             */
            ['footer_text' => str_repeat('Texto-largo.', 150)],
            ['header_layout' => ['receptor' => ['izquierda' => ['cliente_cuit', 'empleado', 'vendedor', 'cliente_nombre', 'cliente_condicion_iva', 'no_existe']]]],
            ['header_layout' => ['emisor' => ['izquierda' => ['razon_social']]]],
        ];
    }

    // ── El endpoint ───────────────────────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function el_endpoint_devuelve_el_contrato_para_cada_modelo()
    {
        $this->autenticar();

        $limites = [
            'max_items_por_zona'  => DisenoDePaginaPdf::MAX_ITEMS_POR_ZONA,
            'max_campos_por_caja' => DisenoDePaginaPdf::MAX_CAMPOS_POR_CAJA,
            'max_titulo'          => DisenoDePaginaPdf::MAX_TITULO,
            'max_etiqueta'        => DisenoDePaginaPdf::MAX_ETIQUETA,
            'max_texto_libre'     => DisenoDePaginaPdf::MAX_TEXTO_LIBRE,
            'tamano_min'          => DisenoDePaginaPdf::TAMANO_MIN,
            'tamano_max'          => DisenoDePaginaPdf::TAMANO_MAX,
            'margen_min'          => DisenoDePaginaPdf::MARGEN_MIN,
            'margen_max'          => DisenoDePaginaPdf::MARGEN_MAX,
            'estilos_de_caja'     => DisenoDePaginaPdf::ESTILOS_DE_CAJA,
            'alineaciones'        => DisenoDePaginaPdf::ALINEACIONES,
        ];

        foreach (CatalogoDeCamposPdf::MODELOS as $model_name) {
            $response = $this->catalogo(['model_name' => $model_name]);

            $response->assertStatus(200);

            $json = $response->json();

            $this->assertSame(self::CLAVES_DE_LA_RESPUESTA, array_keys($json), $model_name.': las claves de la respuesta son las del contrato, ni una más ni una menos.');
            $this->assertSame($model_name, $json['model_name']);
            $this->assertFalse($json['es_fiscal'], $model_name.': sin perfil ni is_afip_ticket no es fiscal.');
            $this->assertSame(CatalogoDeCamposPdf::categorias($model_name), $json['categorias'], $model_name.': las categorías son las del catálogo.');
            $this->assertSame(CatalogoDeCamposPdf::campos($model_name), $json['campos'], $model_name.': los campos son los del catálogo, tal cual.');
            $this->assertSame([], $json['fijos'], $model_name.': sin factura de ARCA no hay bloques fijos.');
            $this->assertSame(CatalogoDeCamposPdf::formatos_de_hoja(), $json['formatos_de_hoja']);
            $this->assertSame($limites, $json['limites'], $model_name.': los límites salen de las constantes de DisenoDePaginaPdf.');
            $this->assertSame(1, $json['diseno_derivado']['version']);
            $this->assertArrayHasKey('superior', $json['diseno_derivado']);
            $this->assertArrayHasKey('pie', $json['diseno_derivado']);
        }
    }

    /**
     * Los bloques fijos y el derivado fiscal solo existen en la venta con "Es factura de ARCA":
     * por el query (el formulario sin guardar) o, si no viene, por el perfil guardado.
     *
     * @test
     */
    public function los_fijos_salen_solo_en_la_venta_fiscal()
    {
        $owner = $this->autenticar();

        $response = $this->catalogo(['model_name' => 'sale', 'is_afip_ticket' => 1]);
        $response->assertStatus(200);
        $this->assertTrue($response->json('es_fiscal'));
        $this->assertSame(CatalogoDeCamposPdf::fijos('sale', true), $response->json('fijos'));
        $this->assertSame(['fijo:afip_receptor'], $this->ids($response->json('diseno_derivado.superior')));

        /** Un presupuesto nunca es fiscal, aunque el query lo pida. */
        $response = $this->catalogo(['model_name' => 'budget', 'is_afip_ticket' => 1]);
        $this->assertFalse($response->json('es_fiscal'));
        $this->assertSame([], $response->json('fijos'));

        /** Sin is_afip_ticket en el query, manda el perfil guardado. */
        $factura = $this->crear_perfil($owner->id, ['is_afip_ticket' => true]);
        $response = $this->catalogo(['model_name' => 'sale', 'profile_id' => $factura->id]);
        $this->assertTrue($response->json('es_fiscal'), 'Sin is_afip_ticket en el query, vale el del perfil.');
        $this->assertCount(2, $response->json('fijos'));

        /** Y el query manda sobre el perfil (el formulario lo destildó y todavía no guardó). */
        $response = $this->catalogo(['model_name' => 'sale', 'profile_id' => $factura->id, 'is_afip_ticket' => 0]);
        $this->assertFalse($response->json('es_fiscal'));
        $this->assertSame([], $response->json('fijos'));
        $this->assertSame('caja_cliente', $response->json('diseno_derivado.superior.0.id'), 'Destildado, el derivado es el del remito.');
    }

    /**
     * @test
     */
    public function un_modelo_que_no_se_disena_con_cajas_da_422()
    {
        $this->autenticar();

        foreach (['article', 'cualquiera', ''] as $model_name) {
            $response = $this->catalogo(['model_name' => $model_name]);

            $response->assertStatus(422);
            $this->assertSame('Ese tipo de diseño no se arma con cajas.', $response->json('message'), 'model_name "'.$model_name.'"');
        }

        $this->getJson(self::URL)->assertStatus(422);

        /** Un model_name que llega como arreglo no puede tumbar el endpoint con un 500. */
        $this->getJson(self::URL.'?model_name[]=sale')
            ->assertStatus(422)
            ->assertJson(['message' => 'Ese tipo de diseño no se arma con cajas.']);
    }

    /**
     * Un profile_id de otro dueño, de otro modelo o que no es un número se ignora (no 404): el
     * diseñador abre igual, con el derivado de un perfil nuevo, y sin mostrar nada del ajeno.
     *
     * @test
     */
    public function un_profile_id_ajeno_o_de_otro_modelo_se_ignora()
    {
        $owner = $this->autenticar();

        $nuevo = DisenoDerivadoPdf::para('sale', null, false, User::find($owner->id));

        $otro_dueno = $this->crear_dueno();
        $ajeno = $this->crear_perfil($otro_dueno->id, [
            'show_total_in_footer' => false,
            'footer_text'          => 'Texto del perfil ajeno',
            'is_afip_ticket'       => true,
        ]);

        $response = $this->catalogo(['model_name' => 'sale', 'profile_id' => $ajeno->id]);
        $response->assertStatus(200);
        $this->assertFalse($response->json('es_fiscal'), 'El is_afip_ticket del perfil ajeno no cuenta.');
        $this->assertSame($nuevo, $response->json('diseno_derivado'), 'El perfil de otro dueño se ignora: sale el derivado de uno nuevo.');
        $this->assertStringNotContainsString('Texto del perfil ajeno', $response->getContent());

        $presupuesto_propio = $this->crear_perfil($owner->id, [
            'model_name'           => 'budget',
            'name'                 => 'zz Presupuesto de test',
            'show_total_in_footer' => false,
        ]);
        $response = $this->catalogo(['model_name' => 'sale', 'profile_id' => $presupuesto_propio->id]);
        $this->assertSame($nuevo, $response->json('diseno_derivado'), 'Un perfil de otro modelo se ignora.');

        $response = $this->catalogo(['model_name' => 'sale', 'profile_id' => 'abc']);
        $response->assertStatus(200);
        $this->assertSame($nuevo, $response->json('diseno_derivado'));
    }

    /**
     * El derivado que devuelve el endpoint es el del perfil pedido (con sus flags), el mismo que
     * arma el helper.
     *
     * @test
     */
    public function el_endpoint_devuelve_el_derivado_del_perfil_pedido()
    {
        $owner = $this->autenticar();

        $perfil = $this->crear_perfil($owner->id, [
            'show_total_in_footer' => false,
            'show_comissions'      => true,
            'footer_text'          => 'Gracias por su compra',
        ]);

        $this->assertSame(
            DisenoDerivadoPdf::para('sale', $perfil->fresh(), false, User::find($owner->id)),
            $this->derivado_del_endpoint($perfil)
        );
    }

    // ── El derivado de la venta (remito) ──────────────────────────────────────────────────────

    /**
     * Un perfil con los flags de fábrica (los defaults de store()): el remito de siempre.
     *
     * @test
     */
    public function el_derivado_del_remito_de_fabrica()
    {
        $owner = $this->autenticar(['mostrar_vendedor_en_venta_pdf' => 0]);
        $owner->extencions()->detach($this->extension_vendedor_en_sale_pdf()->id);

        $diseno = $this->derivado_del_endpoint($this->crear_perfil($owner->id));

        $this->assertSame(
            ['caja_cliente', 'caja_cuenta_corriente', 'caja_observaciones_cliente'],
            $this->ids($diseno['superior']),
            'Arriba: el cliente y la cuenta corriente (lado a lado, 6 + 6) y las observaciones del cliente.'
        );
        $this->assertSame(
            ['caja_totales', 'caja_observaciones'],
            $this->ids($diseno['pie']),
            'Abajo: la caja de totales y el recuadro de observaciones.'
        );

        $cliente = $this->caja($diseno, 'caja_cliente');
        $this->assertSame(6, $cliente['cols']);
        $this->assertSame('borde', $cliente['estilo']);
        $this->assertSame(
            ['cliente_nombre', 'cliente_telefono', 'cliente_localidad', 'cliente_direccion', 'cliente_cuit'],
            $this->keys($cliente),
            'Sin header_layout, el receptor por defecto (PdfColumnProfile::default_header_layout()).'
        );
        $this->assertNull($this->campo($cliente, 'cliente_nombre')['etiqueta'], 'Los rótulos son los del catálogo.');

        $cuenta_corriente = $this->caja($diseno, 'caja_cuenta_corriente');
        $this->assertSame(6, $cuenta_corriente['cols']);
        $this->assertSame(['cc_saldo_anterior', 'cc_compra_actual', 'cc_saldo'], $this->keys($cuenta_corriente), 'Sin mostrar_vendedor_en_venta_pdf, sin vendedor.');

        $observaciones_del_cliente = $this->caja($diseno, 'caja_observaciones_cliente');
        $this->assertSame(12, $observaciones_del_cliente['cols']);
        $this->assertSame(10, $this->campo($observaciones_del_cliente, 'cliente_observaciones')['tamano'], 'PdfHelper::client_description() las imprime en negrita 10.');
        $this->assertTrue($this->campo($observaciones_del_cliente, 'cliente_observaciones')['negrita']);

        $totales = $this->caja($diseno, 'caja_totales');
        $this->assertSame('gris', $totales['estilo'], 'La caja gris de NewSalePdf::print_totals_box().');
        $this->assertSame(
            ['tot_subtotal', 'tot_descuentos', 'tot_recargos', 'tot_canje_de_puntos', 'tot_ajuste_del_total', 'tot_total', 'tot_puntos'],
            $this->keys($totales)
        );

        $observaciones = $this->caja($diseno, 'caja_observaciones');
        $this->assertSame('gris', $observaciones['estilo']);
        $this->assertSame('OBSERVACIONES', $observaciones['titulo']);
        $this->assertSame('', $this->campo($observaciones, 'venta_observaciones')['etiqueta'], 'El texto va sin rótulo, debajo del título.');
    }

    /**
     * Sin "Mostrar total en el pie": no hay caja de totales y tampoco la de cuenta corriente
     * (NewSalePdf::Header() solo la arma con ese flag).
     *
     * @test
     */
    public function sin_total_en_el_pie_no_hay_caja_de_totales_ni_de_cuenta_corriente()
    {
        $owner = $this->autenticar();

        $diseno = $this->derivado_del_endpoint($this->crear_perfil($owner->id, ['show_total_in_footer' => false]));

        $this->assertNull($this->caja($diseno, 'caja_totales'));
        $this->assertNull($this->caja($diseno, 'caja_cuenta_corriente'));
        $this->assertNotNull($this->caja($diseno, 'caja_cliente'));
        $this->assertNotNull($this->caja($diseno, 'caja_observaciones'), 'Las observaciones no dependen del total.');
    }

    /**
     * Comisiones, costos y texto de pie, en el orden del dibujante de siempre: totales, comisiones,
     * costos, texto y observaciones.
     *
     * @test
     */
    public function las_comisiones_los_costos_y_el_texto_de_pie()
    {
        $owner = $this->autenticar();

        $diseno = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'show_comissions'  => true,
            'show_total_costs' => true,
            'footer_text'      => 'Gracias por su compra',
        ]));

        $this->assertSame(
            ['caja_totales', 'caja_comisiones', 'caja_costos', 'caja_texto_de_pie', 'caja_observaciones'],
            $this->ids($diseno['pie'])
        );

        $this->assertSame(['tot_comisiones', 'tot_total_menos_comisiones'], $this->keys($this->caja($diseno, 'caja_comisiones')));
        $this->assertSame(['tot_costos'], $this->keys($this->caja($diseno, 'caja_costos')));

        $texto = $this->caja($diseno, 'caja_texto_de_pie')['campos'][0];
        $this->assertSame('texto_libre', $texto['key']);
        $this->assertSame('texto_de_pie', $texto['id']);
        $this->assertSame('Gracias por su compra', $texto['texto']);

        /** Sin los flags, no hay ninguna de las tres. */
        $diseno = $this->derivado_del_endpoint($this->crear_perfil($owner->id, ['name' => 'zz Remito sin extras']));
        $this->assertSame(['caja_totales', 'caja_observaciones'], $this->ids($diseno['pie']));
    }

    /**
     * Con el total apagado, el remito de siempre imprime comisiones y costos SOLO si hay texto de
     * pie (o con el pie en cada hoja): print_totals_only_on_last_page_when_needed() los cuelga de
     * esa rama. El derivado copia esa condición.
     *
     * @test
     */
    public function con_el_total_apagado_los_extras_dependen_del_texto_de_pie()
    {
        $owner = $this->autenticar();

        $sin_texto = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'show_total_in_footer' => false,
            'show_comissions'      => true,
            'show_total_costs'     => true,
        ]));
        $this->assertSame(['caja_observaciones'], $this->ids($sin_texto['pie']), 'Sin total ni texto de pie, hoy no salen ni comisiones ni costos.');

        $con_texto = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'name'                 => 'zz Remito con texto',
            'show_total_in_footer' => false,
            'show_comissions'      => true,
            'show_total_costs'     => true,
            'footer_text'          => 'Gracias',
        ]));
        $this->assertSame(['caja_comisiones', 'caja_costos', 'caja_texto_de_pie', 'caja_observaciones'], $this->ids($con_texto['pie']));

        $en_cada_hoja = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'name'                     => 'zz Remito en cada hoja',
            'show_total_in_footer'     => false,
            'show_totals_on_each_page' => true,
            'show_comissions'          => true,
        ]));
        $this->assertSame(['caja_comisiones', 'caja_observaciones'], $this->ids($en_cada_hoja['pie']), 'Con el pie en cada hoja, Footer() imprime los extras siempre.');
    }

    /**
     * @test
     */
    public function sin_sub_total_ni_observaciones_del_cliente()
    {
        $owner = $this->autenticar();

        $diseno = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'show_subtotal_in_footer' => false,
            'show_client_description' => false,
        ]));

        $this->assertNotContains('tot_subtotal', $this->keys($this->caja($diseno, 'caja_totales')), 'Sin "Mostrar Sub Total en el pie", la caja no lo lleva.');
        $this->assertNull($this->caja($diseno, 'caja_observaciones_cliente'), 'Sin "Mostrar observaciones del cliente", no va su caja.');
    }

    /**
     * La caja del cliente sigue el orden de header_layout.receptor.izquierda, con `vendedor` y
     * `empleado` llevados a los campos de la venta, y descarta lo que el remito de siempre no
     * conoce. Un header_layout sin receptor deja la caja vacía, igual que hoy queda el cuadrante.
     *
     * @test
     */
    public function la_caja_del_cliente_sigue_el_orden_del_header_layout()
    {
        $owner = $this->autenticar();

        $diseno = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'header_layout' => [
                'emisor'   => ['izquierda' => ['razon_social'], 'derecha' => ['cuit']],
                'receptor' => ['izquierda' => ['cliente_cuit', 'empleado', 'cliente_nombre', 'clave_que_no_existe', 'vendedor', 'cliente_condicion_iva', 'cliente_cuit']],
            ],
        ]));

        $this->assertSame(
            ['cliente_cuit', 'venta_empleado', 'cliente_nombre', 'venta_vendedor', 'cliente_condicion_iva'],
            $this->keys($this->caja($diseno, 'caja_cliente'))
        );

        $sin_receptor = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'name'          => 'zz Remito sin receptor',
            'header_layout' => ['emisor' => ['izquierda' => ['razon_social'], 'derecha' => ['cuit']]],
        ]));

        $this->assertSame([], $this->caja($sin_receptor, 'caja_cliente')['campos'], 'Con header_layout sin receptor, el cuadrante de siempre sale vacío.');
    }

    /**
     * El "Vendedor" del cuadrante de cuenta corriente (mostrar_vendedor_en_venta_pdf, con la regla
     * de PdfHelper::buildCurrentAcountData()) y la línea de la extensión vendedor_en_sale_pdf.
     *
     * @test
     */
    public function el_vendedor_de_la_cuenta_corriente_y_el_de_la_extension()
    {
        $owner = $this->autenticar(['mostrar_vendedor_en_venta_pdf' => 1]);
        $extension = $this->extension_vendedor_en_sale_pdf();
        $owner->extencions()->detach($extension->id);

        $perfil = $this->crear_perfil($owner->id);

        $diseno = $this->derivado_del_endpoint($perfil);
        $cuenta_corriente = $this->caja($diseno, 'caja_cuenta_corriente');
        $this->assertSame(['cc_saldo_anterior', 'cc_compra_actual', 'cc_saldo', 'venta_atendido_por'], $this->keys($cuenta_corriente));
        $this->assertSame('Vendedor', $this->campo($cuenta_corriente, 'venta_atendido_por')['etiqueta']);
        $this->assertNull($this->caja($diseno, 'caja_vendedor'), 'Sin la extensión no va la línea del vendedor.');

        $owner->extencions()->attach($extension->id);

        $diseno = $this->derivado_del_endpoint($perfil);
        $vendedor = $this->caja($diseno, 'caja_vendedor');
        $this->assertNotNull($vendedor, 'Con vendedor_en_sale_pdf va la línea "Vendedor: <empleado>".');
        $this->assertSame(12, $vendedor['cols']);
        $this->assertSame('ninguno', $vendedor['estilo']);
        $this->assertSame(['venta_empleado'], $this->keys($vendedor));
        $this->assertSame('Vendedor', $this->campo($vendedor, 'venta_empleado')['etiqueta']);
        $this->assertSame(
            ['caja_cliente', 'caja_cuenta_corriente', 'caja_vendedor', 'caja_observaciones_cliente'],
            $this->ids($diseno['superior'])
        );

        /** Si el empleado ya está en la caja del cliente, no se repite (cada campo va una sola vez). */
        $con_empleado = $this->crear_perfil($owner->id, [
            'name'          => 'zz Remito con empleado',
            'header_layout' => ['receptor' => ['izquierda' => ['cliente_nombre', 'empleado']]],
        ]);
        $diseno = $this->derivado_del_endpoint($con_empleado);
        $this->assertNull($this->caja($diseno, 'caja_vendedor'));
        $this->assertSame(['cliente_nombre', 'venta_empleado'], $this->keys($this->caja($diseno, 'caja_cliente')));
    }

    // ── El derivado de la factura de ARCA ─────────────────────────────────────────────────────

    /**
     * @test
     */
    public function el_derivado_de_la_factura_de_arca()
    {
        $owner = $this->autenticar();

        $diseno = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'is_afip_ticket'   => true,
            'show_comissions'  => true,
            'footer_text'      => 'Gracias',
        ]));

        $this->assertSame(['fijo:afip_receptor'], $this->ids($diseno['superior']), 'Arriba solo el receptor de ARCA: el header fiscal no lleva ni cuenta corriente ni observaciones del cliente.');
        $this->assertSame(12, $diseno['superior'][0]['cols'], 'El receptor de la factura de siempre va a lo ancho de la hoja.');
        $this->assertSame(
            ['caja_observaciones', 'caja_totales', 'fijo:afip_pie', 'caja_comisiones', 'caja_texto_de_pie'],
            $this->ids($diseno['pie']),
            'El pie fiscal de siempre: observaciones arriba de todo, descuentos, bloque de ARCA, extras y texto.'
        );

        $totales = $this->caja($diseno, 'caja_totales');
        $this->assertSame('ninguno', $totales['estilo'], 'En la factura los renglones van sueltos, sin caja.');
        $this->assertSame(
            ['tot_subtotal', 'tot_descuentos', 'tot_recargos', 'tot_canje_de_puntos', 'tot_puntos'],
            $this->keys($totales),
            'Sin Total ni ajuste: el total lo pone el cuadro de importes de ARCA.'
        );
        $this->assertTrue($diseno['pie'][2]['importes'], 'afip_pie.importes = show_total_in_footer.');

        $sin_total = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'name'                 => 'zz Factura sin total',
            'is_afip_ticket'       => true,
            'show_total_in_footer' => false,
        ]));
        $this->assertSame(['caja_observaciones', 'fijo:afip_pie'], $this->ids($sin_total['pie']));
        $this->assertFalse($sin_total['pie'][1]['importes'], 'Sin total en el pie, el cuadro de importes va apagado (QR y CAE siempre).');

        /** Con el pie en cada hoja, Footer() imprime solo el bloque de ARCA, sin los renglones de descuentos. */
        $en_cada_hoja = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'name'                     => 'zz Factura en cada hoja',
            'is_afip_ticket'           => true,
            'show_totals_on_each_page' => true,
        ]));
        $this->assertSame(['caja_observaciones', 'fijo:afip_pie'], $this->ids($en_cada_hoja['pie']));
        $this->assertTrue($en_cada_hoja['pie'][1]['importes']);
    }

    // ── Presupuesto y pedido ──────────────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function el_derivado_del_presupuesto_mapea_sus_keys()
    {
        $owner = $this->autenticar();

        $de_fabrica = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'model_name' => 'budget',
            'name'       => 'zz Presupuesto de test',
        ]));

        $this->assertSame(['caja_cliente', 'caja_observaciones_cliente'], $this->ids($de_fabrica['superior']));
        $cliente = $this->caja($de_fabrica, 'caja_cliente');
        $this->assertSame(
            ['cliente_nombre', 'cliente_telefono', 'cliente_localidad', 'cliente_direccion', 'cliente_cuit', 'presupuesto_vendedor'],
            $this->keys($cliente),
            'Sin header_layout: el default del presupuesto (PdfDocumentSetupHelper::default_header_layout_for(budget)), con el vendedor.'
        );
        $this->assertNull($this->campo($cliente, 'presupuesto_vendedor')['etiqueta'], '`vendedor` se rotula como en el catálogo ("Vendedor").');

        $this->assertSame(['caja_totales', 'caja_observaciones'], $this->ids($de_fabrica['pie']));
        $this->assertSame('gris', $this->caja($de_fabrica, 'caja_totales')['estilo']);
        $this->assertSame(
            ['tot_subtotal', 'tot_descuentos', 'tot_recargos', 'tot_ajuste_metodo_de_pago', 'tot_ajuste_del_total', 'tot_total'],
            $this->keys($this->caja($de_fabrica, 'caja_totales'))
        );
        $observaciones = $this->caja($de_fabrica, 'caja_observaciones');
        $this->assertSame('OBSERVACIONES', $observaciones['titulo']);
        $this->assertSame(['presupuesto_observaciones'], $this->keys($observaciones));
        $this->assertSame('', $observaciones['campos'][0]['etiqueta']);

        /**
         * `vendedor` y `empleado` imprimen a la misma persona (BudgetPdfDocument::header_document()):
         * van a presupuesto_vendedor una sola vez, con el rótulo del primero que aparezca.
         */
        $con_empleado = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'model_name'           => 'budget',
            'name'                 => 'zz Presupuesto sin precios',
            'show_total_in_footer' => false,
            'footer_text'          => 'Validez 7 días',
            'header_layout'        => ['receptor' => ['izquierda' => ['cliente_nombre', 'empleado', 'vendedor', 'cliente_condicion_iva']]],
        ]));
        $cliente = $this->caja($con_empleado, 'caja_cliente');
        $this->assertSame(['cliente_nombre', 'presupuesto_vendedor', 'cliente_condicion_iva'], $this->keys($cliente));
        $this->assertSame('Empleado', $this->campo($cliente, 'presupuesto_vendedor')['etiqueta'], 'Si venía como `empleado`, el PDF de siempre decía "Empleado:".');
        $this->assertSame(['caja_texto_de_pie', 'caja_observaciones'], $this->ids($con_empleado['pie']), '"Sin precios": sin caja de totales.');
    }

    /**
     * @test
     */
    public function el_derivado_del_pedido_mapea_sus_keys()
    {
        $owner = $this->autenticar();

        $de_fabrica = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'model_name'              => 'order',
            'name'                    => 'zz Pedido online de test',
            'show_client_description' => true,
        ]));

        $this->assertSame(['caja_cliente'], $this->ids($de_fabrica['superior']), 'El comprador no tiene observaciones: no va esa caja aunque el flag esté prendido.');
        $this->assertSame(
            ['comprador_nombre', 'comprador_telefono', 'comprador_localidad', 'comprador_direccion', 'comprador_cuit'],
            $this->keys($this->caja($de_fabrica, 'caja_cliente'))
        );
        $this->assertSame(['caja_totales', 'caja_observaciones'], $this->ids($de_fabrica['pie']));
        $this->assertSame(
            ['tot_subtotal', 'tot_envio', 'tot_cupon', 'tot_ajuste_medio_de_pago', 'tot_total'],
            $this->keys($this->caja($de_fabrica, 'caja_totales'))
        );
        $notas = $this->caja($de_fabrica, 'caja_observaciones');
        $this->assertSame('NOTAS DEL PEDIDO', $notas['titulo']);
        $this->assertSame(['pedido_notas'], $this->keys($notas));
        $this->assertSame('', $notas['campos'][0]['etiqueta']);

        /** La condición de IVA, el vendedor y el empleado el pedido de siempre no los imprime: se descartan. */
        $con_otras = $this->derivado_del_endpoint($this->crear_perfil($owner->id, [
            'model_name'    => 'order',
            'name'          => 'zz Pedido con otras keys',
            'header_layout' => ['receptor' => ['izquierda' => ['cliente_condicion_iva', 'cliente_cuit', 'vendedor', 'empleado', 'cliente_nombre']]],
        ]));
        $this->assertSame(['comprador_cuit', 'comprador_nombre'], $this->keys($this->caja($con_otras, 'caja_cliente')));
    }

    // ── Invariantes del derivado ──────────────────────────────────────────────────────────────

    /**
     * Lo que el derivado pone en una caja tiene que existir en el catálogo de su modelo (si no, el
     * diseñador no sabe mostrarlo y el PDF lo saltea), y sus bloques fijos tienen que ser los del
     * modelo.
     *
     * @test
     */
    public function todo_lo_del_derivado_existe_en_el_catalogo_de_su_modelo()
    {
        $owner = $this->autenticar(['mostrar_vendedor_en_venta_pdf' => 1]);
        $owner->extencions()->syncWithoutDetaching([$this->extension_vendedor_en_sale_pdf()->id]);
        $owner = User::find($owner->id);

        foreach (CatalogoDeCamposPdf::MODELOS as $model_name) {
            foreach ([false, true] as $es_fiscal) {
                foreach ($this->combinaciones_de_flags() as $indice => $flags) {
                    $diseno = DisenoDerivadoPdf::para($model_name, new PdfColumnProfile($flags), $es_fiscal, $owner);
                    $contexto = $model_name.($es_fiscal ? ' fiscal' : '').' / combinación '.$indice;

                    $fijos_del_modelo = [];
                    foreach (CatalogoDeCamposPdf::fijos($model_name, $es_fiscal) as $fijo) {
                        $fijos_del_modelo[] = $fijo['key'];
                    }

                    foreach (DisenoDePaginaPdf::ZONAS as $zona) {
                        foreach ($diseno[$zona] as $item) {
                            if ($item['tipo'] === 'fijo') {
                                $this->assertContains($item['key'], $fijos_del_modelo, $contexto.': bloque fijo que el modelo no tiene.');
                                continue;
                            }

                            foreach ($item['campos'] as $campo) {
                                $this->assertNotNull(
                                    CatalogoDeCamposPdf::campo($model_name, $campo['key']),
                                    $contexto.': el campo '.$campo['key'].' no está en el catálogo del modelo.'
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * El derivado ya es un diseño normalizado y con sus fijos: pasarlo otra vez por normalizar() y
     * asegurar_fijos() no le cambia nada. Es lo que pasa cuando el dueño lo guarda sin tocarlo.
     *
     * @test
     */
    public function el_derivado_sobrevive_a_normalizar_sin_cambios()
    {
        $owner = $this->autenticar(['mostrar_vendedor_en_venta_pdf' => 1]);
        $owner->extencions()->syncWithoutDetaching([$this->extension_vendedor_en_sale_pdf()->id]);
        $owner = User::find($owner->id);

        foreach (CatalogoDeCamposPdf::MODELOS as $model_name) {
            foreach ([false, true] as $es_fiscal) {
                foreach ($this->combinaciones_de_flags() as $indice => $flags) {
                    $contexto = $model_name.($es_fiscal ? ' fiscal' : '').' / combinación '.$indice;
                    $diseno = DisenoDerivadoPdf::para($model_name, new PdfColumnProfile($flags), $es_fiscal, $owner);

                    $this->assertSame($diseno, DisenoDePaginaPdf::normalizar($diseno), $contexto.': normalizar() le cambió algo al derivado.');
                    $this->assertSame(
                        $diseno,
                        DisenoDePaginaPdf::asegurar_fijos($diseno, DisenoDerivadoPdf::es_fiscal($model_name, $es_fiscal)),
                        $contexto.': asegurar_fijos() le cambió algo al derivado.'
                    );
                    $this->assertSame(
                        $diseno,
                        DisenoDePaginaPdf::normalizar(json_encode($diseno)),
                        $contexto.': el derivado tiene que sobrevivir el viaje como JSON.'
                    );
                }
            }
        }

        /** Un perfil nuevo (sin perfil) y el catálogo de artículos, que no se diseña con cajas. */
        $nuevo = DisenoDerivadoPdf::para('sale', null, false, $owner);
        $this->assertSame($nuevo, DisenoDePaginaPdf::normalizar($nuevo));
        $this->assertNull(DisenoDerivadoPdf::para('article', null, false, $owner));
    }

    /**
     * Un texto de pie más largo que lo que admite un texto libre se recorta al armar el derivado
     * (y el resto del texto, el que entra, queda tal cual).
     *
     * @test
     */
    public function un_texto_de_pie_largo_se_recorta_al_tope_del_texto_libre()
    {
        $owner = $this->autenticar();

        $largo = str_repeat('a', DisenoDePaginaPdf::MAX_TEXTO_LIBRE + 200);
        $diseno = DisenoDerivadoPdf::para('sale', new PdfColumnProfile(['footer_text' => $largo]), false, $owner);

        $texto = $this->caja($diseno, 'caja_texto_de_pie')['campos'][0]['texto'];
        $this->assertSame(DisenoDePaginaPdf::MAX_TEXTO_LIBRE, mb_strlen($texto));

        $en_blanco = DisenoDerivadoPdf::para('sale', new PdfColumnProfile(['footer_text' => "   \n "]), false, $owner);
        $this->assertNull($this->caja($en_blanco, 'caja_texto_de_pie'), 'Un texto de pie en blanco no es texto: no va su caja.');
    }

    /**
     * Sin perfil (uno nuevo), el derivado es el de los defaults de hoy. Con PdfDocumentSetupHelper
     * el presupuesto lleva el vendedor por defecto.
     *
     * @test
     */
    public function un_perfil_nuevo_se_deriva_con_los_defaults_de_hoy()
    {
        $owner = $this->autenticar(['mostrar_vendedor_en_venta_pdf' => 0]);
        $owner->extencions()->detach($this->extension_vendedor_en_sale_pdf()->id);
        $owner = User::find($owner->id);

        $venta = DisenoDerivadoPdf::para('sale', null, false, $owner);
        $this->assertSame(['caja_cliente', 'caja_cuenta_corriente', 'caja_observaciones_cliente'], $this->ids($venta['superior']));
        $this->assertSame(['caja_totales', 'caja_observaciones'], $this->ids($venta['pie']));
        $this->assertContains('tot_subtotal', $this->keys($this->caja($venta, 'caja_totales')));

        $presupuesto = DisenoDerivadoPdf::para('budget', null, false, $owner);
        $this->assertContains('presupuesto_vendedor', $this->keys($this->caja($presupuesto, 'caja_cliente')));
        $this->assertContains('vendedor', PdfDocumentSetupHelper::default_header_layout_for('budget')['receptor']['izquierda']);

        $pedido = DisenoDerivadoPdf::para('order', null, true, $owner);
        $this->assertSame([], array_values(array_filter($pedido['superior'], function ($item) {
            return $item['tipo'] === 'fijo';
        })), 'Un pedido nunca es fiscal, aunque se pida.');
    }

    // ── El comprobante de prueba ──────────────────────────────────────────────────────────────

    /**
     * El comprobante para "Ver un PDF de prueba": el último del dueño de cada tipo; en la venta
     * fiscal, la última con una factura con CAE y esa factura. Un dueño sin nada → null.
     *
     * @test
     */
    public function el_comprobante_de_prueba_es_el_ultimo_del_dueno()
    {
        $dueno = $this->crear_dueno();
        $this->actingAs($dueno, 'web');

        foreach (CatalogoDeCamposPdf::MODELOS as $model_name) {
            $this->assertNull(
                $this->catalogo(['model_name' => $model_name])->json('comprobante_de_prueba'),
                $model_name.': un dueño sin comprobantes no tiene PDF de prueba.'
            );
        }
        $this->assertNull($this->catalogo(['model_name' => 'sale', 'is_afip_ticket' => 1])->json('comprobante_de_prueba'));

        /** Cada venta con su correlativo, como lo asigna Controller::num('sales'). */
        $num = 0;
        $venta = function ($extra = []) use ($dueno, &$num) {
            $num++;

            return Sale::create(array_merge([
                'user_id'   => $dueno->id,
                'num'       => $num,
                'total'     => 1000,
                'terminada' => 1,
                'moneda_id' => 1,
            ], $extra));
        };

        $facturada = $venta();
        AfipTicket::create(['sale_id' => $facturada->id, 'cae' => '71234567890120', 'resultado' => 'A']);
        /** Si la venta tiene dos facturas con CAE, va la más nueva. */
        $factura = AfipTicket::create(['sale_id' => $facturada->id, 'cae' => '71234567890123', 'resultado' => 'A']);
        $sin_cae = $venta();
        AfipTicket::create(['sale_id' => $sin_cae->id, 'cae' => '', 'resultado' => 'A']);
        $con_factura_borrada = $venta();
        AfipTicket::create(['sale_id' => $con_factura_borrada->id, 'cae' => '79999999999999'])->delete();
        $ultima = $venta();
        $venta(['is_consolidacion_facturacion' => 1]);

        /** Una venta facturada de OTRO dueño, posterior a todas y con un correlativo más alto, no cuenta. */
        $otro_dueno = $this->crear_dueno();
        $ajena = Sale::create(['user_id' => $otro_dueno->id, 'num' => 999, 'total' => 1, 'terminada' => 1, 'moneda_id' => 1]);
        AfipTicket::create(['sale_id' => $ajena->id, 'cae' => '75555555555555', 'resultado' => 'A']);

        $this->assertSame(
            ['id' => $ultima->id, 'afip_ticket_id' => null],
            $this->catalogo(['model_name' => 'sale'])->json('comprobante_de_prueba'),
            'Remito: la última venta real (la contenedora de una consolidación no cuenta).'
        );
        $this->assertSame(
            ['id' => $facturada->id, 'afip_ticket_id' => $factura->id],
            $this->catalogo(['model_name' => 'sale', 'is_afip_ticket' => 1])->json('comprobante_de_prueba'),
            'Factura: la última venta con una factura con CAE (sin CAE o borrada no cuentan), y esa factura.'
        );

        $cliente = Client::create(['name' => 'Cliente del PDF de prueba', 'user_id' => $dueno->id]);
        Budget::create(['num' => 1, 'user_id' => $dueno->id, 'client_id' => $cliente->id, 'total' => 0]);
        $ultimo_presupuesto = Budget::create(['num' => 2, 'user_id' => $dueno->id, 'client_id' => $cliente->id, 'total' => 0]);

        $this->assertSame(
            ['id' => $ultimo_presupuesto->id, 'afip_ticket_id' => null],
            $this->catalogo(['model_name' => 'budget'])->json('comprobante_de_prueba')
        );

        $comprador = Buyer::create(['name' => 'Comprador del PDF de prueba', 'user_id' => $dueno->id]);
        $pedido = function ($num) use ($dueno, $comprador) {
            return Order::create([
                'num'             => $num,
                'status'          => 'unconfirmed',
                'deliver'         => 0,
                'buyer_id'        => $comprador->id,
                'order_status_id' => 1,
                'user_id'         => $dueno->id,
                'total'           => 0,
            ]);
        };
        $pedido(1);
        $ultimo_pedido = $pedido(2);

        $this->assertSame(
            ['id' => $ultimo_pedido->id, 'afip_ticket_id' => null],
            $this->catalogo(['model_name' => 'order'])->json('comprobante_de_prueba')
        );
    }

    /**
     * El comprobante fiscal sale de UNA consulta que trae UNA fila y que parte de las ventas del
     * dueño, ordenadas por su correlativo (lo sirve el índice user_id, num), con la factura con CAE
     * como subconsulta escalar. Se abre cada vez que se abre el diseñador, y en una base compartida
     * (51 comercios) la consulta de antes recorría afip_tickets entero si el dueño no tenía CAE.
     *
     * Si esto se pone rojo porque alguien "simplificó" la consulta (un EXISTS, ORDER BY id): medí
     * con EXPLAIN antes de tocar la aserción. Está explicado en el docblock de
     * DisenoDerivadoPdf::venta_facturada_de_prueba().
     *
     * @test
     */
    public function el_comprobante_fiscal_sale_de_una_consulta_sobre_las_ventas_del_dueno()
    {
        $dueno = $this->crear_dueno();
        $venta = Sale::create(['user_id' => $dueno->id, 'num' => 1, 'total' => 1, 'terminada' => 1, 'moneda_id' => 1]);
        $factura = AfipTicket::create(['sale_id' => $venta->id, 'cae' => '71234567890123', 'resultado' => 'A']);

        $consultas = [];
        DB::listen(function ($query) use (&$consultas) {
            $consultas[] = $query->sql;
        });

        $comprobante = DisenoDerivadoPdf::comprobante_de_prueba('sale', true, $dueno->id);

        $this->assertSame(['id' => $venta->id, 'afip_ticket_id' => $factura->id], $comprobante);
        $this->assertCount(1, $consultas, 'Una sola consulta: '.json_encode($consultas));

        $sql = $consultas[0];
        $this->assertStringStartsWith('select `sales`.`id`', $sql, 'Parte de las ventas, no de afip_tickets.');
        $this->assertStringContainsString('where `sales`.`user_id` = ?', $sql, 'Las ventas de este dueño.');
        $this->assertStringContainsString(
            'order by `sales`.`num` desc, `sales`.`id` desc',
            $sql,
            'Por el correlativo del dueño: lo sirve el índice (user_id, num) sin ordenar aparte. Con ORDER BY id MySQL recorre PRIMARY, las ventas de todos.'
        );
        $this->assertStringEndsWith('limit 1', $sql, 'Trae una sola fila.');
        $this->assertStringNotContainsString('exists', $sql, 'Sin EXISTS: MySQL lo convierte en semijoin y puede arrancar por afip_tickets.');
    }
}
