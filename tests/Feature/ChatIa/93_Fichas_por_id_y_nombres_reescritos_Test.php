<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\BusquedaPorPalabrasIaHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión agente-mejoras-uso-real (E2 punto 6, 29/10/2026) — las fichas que "no existían".
 *
 * El caso real: Pablo (Ferre Total, CONV 11) preguntó si dos artículos de un ranking tenían stock y
 * compras, y el agente contestó que no encontraba sus fichas: había reescrito los nombres
 * («CUYO BISAGRAS HºCARROCERA 76X5X50MM C/AG.112» → «cuño bisagras H° carrocera 76x5x50 mm con aguja
 * 112», «TUBOFORTE 1.8 RAMAL 90 º 60 X 60 MH» → «tuboforte 1.8 ramal 90° 60x60») y las consultas de
 * ficha solo aceptaban texto. Lo que protege:
 *  - las cinco consultas de UN artículo aceptan `articulo_id`, que manda sobre el texto y encuentra
 *    también los inactivos y los de la papelera (lo dicen en `estado_del_articulo` / `estado`);
 *  - un id de otro negocio no se usa;
 *  - por texto, "90° 60x60" encuentra "90 º 60 X 60" (grados, comillas y la "x" entre medidas);
 *  - las etiquetas de un ranking marcan los artículos borrados o inactivos;
 *  - las herramientas declaran `articulo_id` y el handler lo pasa.
 */
class Fichas_por_id_y_nombres_reescritos_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro;

    /** @var array<string, Article> */
    protected $articulos = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio fichas E2',
            'email'    => 'fichas-e2-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->otro = User::create([
            'name'     => 'Otro comercio fichas E2',
            'email'    => 'fichas-e2-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->articulos['cuyo'] = $this->articulo('CUYO BISAGRAS HºCARROCERA 76X5X50MM C/AG.112 E2', 4);
        $this->articulos['tuboforte'] = $this->articulo('TUBOFORTE 1.8 RAMAL 90 º 60 X 60 MH E2', 6);

        $this->articulos['borrado'] = $this->articulo('DESPLAZADOR INODORO PLASTICO CORTO 6CM E2', 5);
        $this->articulos['borrado']->delete();

        $this->articulos['inactivo'] = $this->articulo('AEROSOL ALUMINIO ALTA TEMP E2', 0, 'inactive');

        $this->articulos['ajeno'] = Article::create(['name' => 'TUERCA DE OTRO E2', 'user_id' => $this->otro->id, 'status' => 'active', 'stock' => 9]);
    }

    /**
     * @param  string  $nombre
     * @param  float  $stock
     * @param  string  $status
     * @return Article
     */
    protected function articulo($nombre, $stock, $status = 'active')
    {
        return Article::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
            'status'  => $status,
            'stock'   => $stock,
            'cost'    => 100,
        ]);
    }

    /**
     * Las palabras de lo que reescribe el agente quedan como las del nombre cargado.
     *
     * @group chat-ia
     * @test
     */
    public function las_palabras_sacan_grados_comillas_y_separan_la_x_entre_medidas()
    {
        $this->assertSame(['tuboforte', '1.8', 'ramal', '90', '60'], BusquedaPorPalabrasIaHelper::palabras('tuboforte 1.8 ramal 90° 60x60'));
        $this->assertSame(['tuboforte', '1.8', 'ramal', '90', '60'], BusquedaPorPalabrasIaHelper::palabras('TUBOFORTE 1.8 RAMAL 90 º 60 X 60'));
        $this->assertSame(['76', '5', '50mm'], BusquedaPorPalabrasIaHelper::palabras('76x5x50mm'));
        $this->assertSame(['canilla', '1/2'], BusquedaPorPalabrasIaHelper::palabras('canilla 1/2"'));

        // Y el nombre reescrito por el agente es "seguro" contra el cargado: las medidas son las mismas.
        $this->assertTrue(BusquedaPorPalabrasIaHelper::es_seguro('TUBOFORTE 1.8 RAMAL 90 º 60 X 60 MH', 'tuboforte 1.8 ramal 90° 60x60'));
    }

    /**
     * Por texto, el nombre reescrito con "°" y "60x60" encuentra la ficha cargada con "º" y "60 X 60".
     *
     * @group chat-ia
     * @test
     */
    public function por_texto_el_nombre_reescrito_con_grados_y_medidas_encuentra_la_ficha()
    {
        $stock = ConsultasSistemaIaHelper::stock_por_deposito($this->comercio->id, 'tuboforte 1.8 ramal 90° 60x60 MH');

        $this->assertArrayNotHasKey('error', $stock, json_encode($stock));
        $this->assertSame((int) $this->articulos['tuboforte']->id, $stock['articulo_id']);

        $compras = ConsultasSistemaIaHelper::compras_de_un_articulo($this->comercio->id, 'TUBOFORTE 1.8 RAMAL 90° 60X60');

        $this->assertArrayNotHasKey('error', $compras, json_encode($compras));
        $this->assertSame((int) $this->articulos['tuboforte']->id, $compras['articulo_id']);
    }

    /**
     * «cuño bisagras H° carrocera … con aguja 112» no se puede encontrar por texto (el agente cambió
     * palabras): con el id, sí, en las cinco consultas.
     *
     * @group chat-ia
     * @test
     */
    public function con_el_id_las_cinco_consultas_contestan_sobre_ese_articulo()
    {
        $reescrito = 'cuño bisagras H° carrocera 76x5x50 mm con aguja 112';
        $id = (int) $this->articulos['cuyo']->id;

        $sin_id = ConsultasSistemaIaHelper::compras_de_un_articulo($this->comercio->id, $reescrito);
        $this->assertArrayHasKey('error', $sin_id, 'Por texto no se encuentra: el agente cambió las palabras.');
        $this->assertStringContainsString('articulo_id', $sin_id['como_sigo'], 'Y le dice cómo seguir: con el id.');

        $compras = ConsultasSistemaIaHelper::compras_de_un_articulo($this->comercio->id, $reescrito, 0, $id);
        $this->assertSame($id, $compras['articulo_id']);
        $this->assertArrayNotHasKey('estado_del_articulo', $compras, 'Activo: la respuesta de siempre.');

        $stock = ConsultasSistemaIaHelper::stock_por_deposito($this->comercio->id, $reescrito, 0, $id);
        $this->assertSame($id, $stock['articulo_id']);
        $this->assertEquals(4, $stock['stock_total_del_articulo']);

        $quien = ConsultasSistemaIaHelper::quien_compro_un_articulo($this->comercio->id, '', 0, 0, $id);
        $this->assertSame($id, $quien['articulo_id']);

        $interesados = ConsultasSistemaIaHelper::interesados_en_un_articulo($this->comercio->id, '', 30, $id);
        $this->assertSame($this->articulos['cuyo']->name, $interesados['articulo']);

        $ficha = ConsultasSistemaIaHelper::stock_de_articulos($this->comercio->id, $reescrito, $id);
        $this->assertCount(1, $ficha);
        $this->assertSame($id, $ficha[0]['id']);
        $this->assertEquals(4, $ficha[0]['stock']);
        $this->assertArrayNotHasKey('estado', $ficha[0]);
    }

    /**
     * Con el id se encuentran también los de la papelera y los inactivos, y la respuesta lo dice; un id
     * de otro negocio no se usa.
     *
     * @group chat-ia
     * @test
     */
    public function con_el_id_se_encuentran_los_borrados_e_inactivos_y_un_id_ajeno_no_se_usa()
    {
        $borrado = ConsultasSistemaIaHelper::compras_de_un_articulo($this->comercio->id, '', 0, (int) $this->articulos['borrado']->id);

        $this->assertSame((int) $this->articulos['borrado']->id, $borrado['articulo_id']);
        $this->assertStringContainsString('papelera', $borrado['estado_del_articulo']);

        $inactivo = ConsultasSistemaIaHelper::stock_de_articulos($this->comercio->id, '', (int) $this->articulos['inactivo']->id);

        $this->assertCount(1, $inactivo);
        $this->assertStringContainsString('inactivo', $inactivo[0]['estado']);

        // Por texto, el inactivo también avisa su estado.
        $por_texto = ConsultasSistemaIaHelper::quien_compro_un_articulo($this->comercio->id, 'AEROSOL ALUMINIO ALTA TEMP E2');
        $this->assertStringContainsString('inactivo', $por_texto['estado_del_articulo']);

        // El de otro negocio, no.
        $ajeno = ConsultasSistemaIaHelper::compras_de_un_articulo($this->comercio->id, '', 0, (int) $this->articulos['ajeno']->id);
        $this->assertArrayHasKey('error', $ajeno);
        $this->assertStringContainsString('No hay ningun articulo con el id', $ajeno['error']);

        $this->assertSame([], ConsultasSistemaIaHelper::stock_de_articulos($this->comercio->id, '', (int) $this->articulos['ajeno']->id));
    }

    /**
     * Las etiquetas de un ranking (resumir_datos agrupando por article_id) marcan los borrados.
     *
     * @group chat-ia
     * @test
     */
    public function las_etiquetas_del_ranking_marcan_los_borrados_y_los_inactivos()
    {
        $etiquetas = CatalogoDeDatosIaHelper::etiquetas_de_relacion(
            ['tabla' => 'articles', 'campo' => 'name'],
            [(int) $this->articulos['cuyo']->id, (int) $this->articulos['borrado']->id, (int) $this->articulos['inactivo']->id]
        );

        $this->assertSame($this->articulos['cuyo']->name, $etiquetas[(int) $this->articulos['cuyo']->id]);
        $this->assertSame($this->articulos['borrado']->name . ' (en la papelera)', $etiquetas[(int) $this->articulos['borrado']->id]);
        $this->assertSame($this->articulos['inactivo']->name . ' (inactivo)', $etiquetas[(int) $this->articulos['inactivo']->id]);
    }

    /**
     * Las cinco herramientas declaran articulo_id y el handler lo pasa.
     *
     * @group chat-ia
     * @test
     */
    public function las_herramientas_declaran_articulo_id_y_el_handler_lo_pasa()
    {
        $servicio = new AsistenteIaService();

        $con_id = [];

        foreach ($servicio->herramientas_de_lectura() as $herramienta) {
            if (isset($herramienta['input_schema']['properties']['articulo_id'])) {
                $con_id[] = $herramienta['name'];
            }
        }

        foreach (['consultar_stock_de_articulos', 'consultar_interesados_en_un_articulo', 'consultar_quien_compro_un_articulo', 'consultar_compras_de_un_articulo', 'consultar_stock_por_deposito'] as $nombre) {
            $this->assertContains($nombre, $con_id);
        }

        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $resultados = $servicio->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => 'consultar_stock_por_deposito',
            'input' => ['busqueda' => 'cuño bisagras H° carrocera con aguja', 'articulo_id' => (int) $this->articulos['cuyo']->id],
        ]], $conversation);

        $contenido = json_decode($resultados[0]['content'], true);

        $this->assertSame((int) $this->articulos['cuyo']->id, $contenido['articulo_id']);
    }
}
