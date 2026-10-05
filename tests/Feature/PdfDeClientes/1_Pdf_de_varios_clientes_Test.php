<?php

namespace Tests\Feature\PdfDeClientes;

use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * El PDF con el estado de cuenta de varios clientes (misión pdf-estados-de-cuenta-clientes,
 * 5/10/2026): `GET client/pdf`, en routes/web.php, que la SPA abre desde el embudo del listado de
 * Clientes con los seleccionados (`?clients_id=21-17`) o con los filtros de lupa (`?filters=[...]`).
 *
 * Hasta 4.3.6 daba 500 por los dos caminos ("Undefined index: user" en PdfHelper.php:125, medido
 * en el log de demo2) y, aunque no hubiera dado, imprimía `clients.saldo`, una columna que nadie
 * mantiene desde la multimoneda (NULL en demo2; el saldo vivo es `saldo_pesos`).
 *
 * Todo se pide por la ruta real y se lee el PDF que vuelve: los textos se buscan en el contenido de
 * las páginas, que el FPDF comprime con zlib (texto_del_pdf() los descomprime).
 *
 * 🔴 Los filtros están COPIADOS de lo que manda la SPA (BtnPdf.vue →
 * get_active_filters_for_export() de common-vue/mixins/filters.js: el objeto de columna entero de
 * models/client.js, con el criterio puesto), no escritos mirando el helper.
 *
 * @group pdf_de_clientes
 */
class Pdf_de_varios_clientes_Test extends EmpresaTestCase
{
    /** @var User El dueño del fixture (el que deja logueado EmpresaTestCase). */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Un cliente del dueño (o de otro dueño), con el saldo de su cuenta en pesos.
     *
     * @param  string  $nombre
     * @param  float  $saldo_pesos
     * @param  array  $extra
     * @return Client
     */
    protected function cliente($nombre, $saldo_pesos, array $extra = [])
    {
        return Client::create(array_merge([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'saldo_pesos' => $saldo_pesos,
            'saldo'       => null,
        ], $extra));
    }

    /**
     * Otro comercio de la misma base: un dueño sin owner_id.
     *
     * @return User
     */
    protected function otro_dueno()
    {
        return User::create([
            'name'     => 'Otro comercio pdf clientes',
            'email'    => 'pdf-clientes-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => null,
        ]);
    }

    /**
     * El filtro "Nombre que contenga" tal como lo arma la columna de models/client.js.
     *
     * @param  string  $texto
     * @return string  El JSON listo para la query string.
     */
    protected function filtro_nombre_que_contenga($texto)
    {
        return json_encode([[
            'text'                     => 'Nombre',
            'key'                      => 'name',
            'type'                     => 'text',
            'value'                    => '',
            'show'                     => true,
            'use_to_filter_in_search'  => true,
            'use_to_filter_in_modal'   => true,
            'filter_modal_position'    => 1,
            'que_contenga'             => $texto,
            'igual_que'                => '',
            'en_blanco'                => false,
            'no_en_blanco'             => false,
            'ordenar_de'               => '',
        ]]);
    }

    /**
     * El texto de todas las páginas del PDF: descomprime cada stream (FlateDecode) y deja los
     * literales `(...) Tj` con los paréntesis y barras sin escapar.
     *
     * @param  string  $pdf
     * @return string
     */
    protected function texto_del_pdf($pdf)
    {
        $texto = '';

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        foreach ($streams[1] as $stream) {
            $plano = @gzuncompress($stream);
            $texto .= ($plano === false ? $stream : $plano) . "\n";
        }

        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $texto);
    }

    /**
     * @param  string  $pdf
     * @return int
     */
    protected function paginas($pdf)
    {
        return preg_match_all('#/Type /Page[^s]#', $pdf);
    }

    /**
     * Pide el PDF y verifica que volvió un PDF de verdad.
     *
     * @param  string  $query
     * @return string  El binario.
     */
    protected function pedir_pdf($query)
    {
        $response = $this->get('client/pdf?' . $query);

        $this->assertSame(200, $response->getStatusCode(), 'client/pdf?' . $query . ' tenía que dar 200 y dio ' . $response->getStatusCode() . ': ' . substr((string) $response->getContent(), 0, 300));
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);

        return $pdf;
    }

    /** @test */
    public function los_seleccionados_salen_con_el_saldo_de_su_cuenta_en_pesos()
    {
        $vendedor = Seller::create(['name' => 'Vendedor Ruta Sur', 'user_id' => $this->dueno->id]);

        $tucumana = $this->cliente('Ferreteria Tucumana PDF', 464808.56, [
            'phone'     => '3815551234',
            'seller_id' => $vendedor->id,
        ]);
        $industrial = $this->cliente('Ferreteria Industrial PDF', 0);
        $no_pedido = $this->cliente('Ferreteria No Pedida PDF', 1000);

        $texto = $this->texto_del_pdf($this->pedir_pdf('clients_id=' . $tucumana->id . '-' . $industrial->id));

        $this->assertStringContainsString('Ferreteria Tucumana PDF', $texto);
        $this->assertStringContainsString('Ferreteria Industrial PDF', $texto);
        $this->assertStringNotContainsString('Ferreteria No Pedida PDF', $texto);

        // El saldo vivo, con el formato de los PDF del sistema. Con `clients.saldo` (NULL) salía $0.
        $this->assertStringContainsString('$464.808,56', $texto);
        $this->assertStringContainsString('3815551234', $texto);
        $this->assertStringContainsString('Vendedor Ruta Sur', $texto);

        // Sin la extensión de ventas en dólares no hay columna en dólares.
        $this->assertStringNotContainsString('Saldo USD', $texto);
    }

    /** @test */
    public function los_filtrados_salen_solo_los_que_cumplen_el_filtro()
    {
        $this->cliente('Ferreteria Filtro Uno PDF', 537738.31);
        $this->cliente('Ferreteria Filtro Dos PDF', 297664.03);
        $this->cliente('Corralon Fuera Del Filtro PDF', 5000);

        $texto = $this->texto_del_pdf($this->pedir_pdf('filters=' . urlencode($this->filtro_nombre_que_contenga('Filtro'))));

        $this->assertStringContainsString('Ferreteria Filtro Uno PDF', $texto);
        $this->assertStringContainsString('$537.738,31', $texto);
        $this->assertStringContainsString('Ferreteria Filtro Dos PDF', $texto);
        $this->assertStringContainsString('$297.664,03', $texto);
        $this->assertStringNotContainsString('Corralon Fuera Del Filtro PDF', $texto);
    }

    /** @test */
    public function un_cliente_de_otro_comercio_no_sale_por_ningun_camino()
    {
        $otro = $this->otro_dueno();

        $propio = $this->cliente('Ferreteria Propia Tenencia PDF', 100);
        $ajeno = $this->cliente('Ferreteria Ajena Tenencia PDF', 999, ['user_id' => $otro->id]);

        // Seleccionados: el id ajeno se ignora como uno inexistente.
        $texto = $this->texto_del_pdf($this->pedir_pdf('clients_id=' . $propio->id . '-' . $ajeno->id));
        $this->assertStringContainsString('Ferreteria Propia Tenencia PDF', $texto);
        $this->assertStringNotContainsString('Ferreteria Ajena Tenencia PDF', $texto);

        // Solo ids ajenos: no hay nada que imprimir.
        $response = $this->get('client/pdf?clients_id=' . $ajeno->id);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());

        // Filtrados: el mismo texto matchea a los dos, sale solo el propio.
        $texto = $this->texto_del_pdf($this->pedir_pdf('filters=' . urlencode($this->filtro_nombre_que_contenga('Tenencia'))));
        $this->assertStringContainsString('Ferreteria Propia Tenencia PDF', $texto);
        $this->assertStringNotContainsString('Ferreteria Ajena Tenencia PDF', $texto);
    }

    /** @test */
    public function un_key_de_filtro_que_no_es_una_columna_se_rechaza_y_no_saltea_al_dueno()
    {
        $otro = $this->otro_dueno();
        $this->cliente('Ferreteria Ajena Inyeccion PDF', 999, ['user_id' => $otro->id]);

        // `whereRaw(key.' LIKE ?')` sin paréntesis: este key dejaría
        // `user_id = <dueño> AND 1=1 OR name LIKE '%Inyeccion%'` y traería el cliente ajeno.
        $filtros = json_decode($this->filtro_nombre_que_contenga('Inyeccion'), true);
        $filtros[0]['key'] = '1=1 OR name';

        $response = $this->get('client/pdf?filters=' . urlencode(json_encode($filtros)));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
    }

    /** @test */
    public function sin_sesion_da_401_y_no_cae_en_el_usuario_del_env()
    {
        $this->cliente('Ferreteria Sin Sesion PDF', 100);

        // Los frentes de los clientes tienen USER_ID en el .env: sin sesión, userId() cae ahí.
        config(['app.USER_ID' => $this->dueno->id]);

        Auth::forgetGuards();

        $response = $this->get('client/pdf?filters=' . urlencode($this->filtro_nombre_que_contenga('Sin Sesion')));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());

        $response = $this->get('client/pdf?clients_id=1-2-3');
        $this->assertSame(401, $response->getStatusCode());
    }

    /** @test */
    public function sin_seleccion_ni_filtros_validos_da_422_y_no_500()
    {
        // Lo que barrió un bot en San Cayetano (3/9/2026): sin parámetros daba 500.
        $this->assertSame(422, $this->get('client/pdf')->getStatusCode());
        $this->assertSame(422, $this->get('client/pdf?filters=' . urlencode('[]'))->getStatusCode());
        $this->assertSame(422, $this->get('client/pdf?filters=no-es-json')->getStatusCode());
        $this->assertSame(422, $this->get('client/pdf?clients_id=abc-0--1')->getStatusCode());
    }

    /** @test */
    public function con_ventas_en_dolares_sale_tambien_el_saldo_en_dolares()
    {
        $extencion = ExtencionEmpresa::where('slug', 'ventas_en_dolares')->first();

        if (is_null($extencion)) {
            $this->fail('El catálogo extencion_empresas no tiene ventas_en_dolares: resembrar la base del slot.');
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        $cliente = $this->cliente('Ferreteria Dolares PDF', 1500, ['saldo_dolares' => 320.5]);

        $texto = $this->texto_del_pdf($this->pedir_pdf('clients_id=' . $cliente->id));

        $this->assertStringContainsString('Saldo USD', $texto);
        $this->assertStringContainsString('$1.500', $texto);
        $this->assertStringContainsString('USD 320,5', $texto);
    }

    /** @test */
    public function muchos_clientes_pasan_a_otra_hoja_sin_perder_ninguno()
    {
        $ids = [];

        for ($i = 1; $i <= 60; $i++) {
            $ids[] = $this->cliente('Cliente Hoja ' . str_pad($i, 2, '0', STR_PAD_LEFT) . ' PDF', $i * 100)->id;
        }

        $pdf = $this->pedir_pdf('clients_id=' . implode('-', $ids));
        $texto = $this->texto_del_pdf($pdf);

        $this->assertGreaterThanOrEqual(2, $this->paginas($pdf));

        for ($i = 1; $i <= 60; $i++) {
            $this->assertStringContainsString('Cliente Hoja ' . str_pad($i, 2, '0', STR_PAD_LEFT) . ' PDF', $texto);
        }

        // Cada hoja repite la fila de títulos.
        $this->assertSame($this->paginas($pdf), substr_count($texto, '(Descripcion) Tj'));
    }
}
