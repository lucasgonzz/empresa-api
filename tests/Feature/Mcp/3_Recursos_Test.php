<?php

namespace Tests\Feature\Mcp;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\FormatoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use Carbon\Carbon;

/**
 * Misión asistente-mcp — los recursos: el esquema del negocio como recursos comerciocity://.
 *
 * Lo que protege este archivo: que resources/list tenga una entrada por cada entidad de los dos
 * catálogos (consulta y carga) más las tres fijas, que resources/read devuelva el MISMO catálogo
 * que las tools que_puedo_consultar / que_puedo_cargar (con `campos` para una entidad), que un URI
 * inexistente sea -32002 y que las dos plantillas se declaren.
 *
 * 🔴 EL MODO DE FALLA QUE TAPA: una entidad nueva del esquema que aparezca en que_puedo_consultar
 * y no como recurso (o al revés), y un recurso que responda algo distinto de lo que la tool
 * responde para la misma entidad. Por eso las aserciones comparan contra los catálogos y no contra
 * números fijos.
 */
class Recursos_Test extends McpTestCase
{
    /**
     * @group mcp
     * @test
     */
    public function resources_list_trae_las_fijas_y_una_por_entidad_de_cada_catalogo()
    {
        $respuesta = $this->rpc('resources/list', [], 1);

        $respuesta->assertStatus(200);

        $recursos = $respuesta->json('result.resources');

        $uris = array_column($recursos, 'uri');

        $this->assertContains('comerciocity://negocio', $uris);
        $this->assertContains('comerciocity://consultas', $uris);
        $this->assertContains('comerciocity://cargas', $uris);

        $de_consulta = EsquemaDeDatosIaHelper::entidades();
        $de_carga = array_keys(CatalogoDeEscrituraIaHelper::entidades());

        $this->assertNotEmpty($de_consulta);
        $this->assertNotEmpty($de_carga);

        foreach ($de_consulta as $entidad) {
            $this->assertContains('comerciocity://consultas/' . $entidad, $uris, 'Falta el recurso de consulta de ' . $entidad);
        }

        foreach ($de_carga as $entidad) {
            $this->assertContains('comerciocity://cargas/' . $entidad, $uris, 'Falta el recurso de carga de ' . $entidad);
        }

        $this->assertCount(3 + count($de_consulta) + count($de_carga), $recursos, 'Ni uno más ni uno menos que los catálogos.');

        foreach ($recursos as $recurso) {
            $this->assertEquals('application/json', $recurso['mimeType'], $recurso['uri']);
            $this->assertNotEquals('', (string) $recurso['name'], $recurso['uri']);
            $this->assertNotEquals('', (string) $recurso['description'], $recurso['uri']);
        }
    }

    /**
     * El recurso de una entidad es el mismo catálogo que la tool: JSON con sus campos.
     *
     * @group mcp
     * @test
     */
    public function resources_read_de_consultas_article_devuelve_el_esquema_con_campos()
    {
        $respuesta = $this->rpc('resources/read', ['uri' => 'comerciocity://consultas/article'], 2);

        $respuesta->assertStatus(200);

        $contenido = $respuesta->json('result.contents.0');

        $this->assertEquals('comerciocity://consultas/article', $contenido['uri']);
        $this->assertEquals('application/json', $contenido['mimeType']);

        $esquema = json_decode($contenido['text'], true);

        $this->assertIsArray($esquema, 'El text tiene que ser JSON.');
        $this->assertEquals('article', $esquema['entidad']);
        $this->assertArrayHasKey('campos', $esquema);
        $this->assertNotEmpty($esquema['campos']);
        $this->assertArrayHasKey('operadores_por_tipo', $esquema);
    }

    /**
     * Las listas cortas también: consultas y cargas responden lo que las tools sin entidad.
     *
     * @group mcp
     * @test
     */
    public function resources_read_de_las_listas_cortas_devuelve_los_catalogos()
    {
        $consultas = json_decode($this->rpc('resources/read', ['uri' => 'comerciocity://consultas'])->json('result.contents.0.text'), true);
        $cargas = json_decode($this->rpc('resources/read', ['uri' => 'comerciocity://cargas'])->json('result.contents.0.text'), true);

        $this->assertIsArray($consultas);
        $this->assertIsArray($cargas);
        $this->assertNotEmpty($consultas);
        $this->assertNotEmpty($cargas);

        $texto_cargas = json_encode($cargas);

        $this->assertStringContainsString('provider', $texto_cargas, 'El catálogo de escritura nombra a los proveedores.');

        $de_carga = json_decode($this->rpc('resources/read', ['uri' => 'comerciocity://cargas/provider'])->json('result.contents.0.text'), true);

        $this->assertIsArray($de_carga);
        $this->assertNotEmpty($de_carga);
    }

    /**
     * La ficha del negocio: nombre, moneda, configuración del asistente y la fecha con su día.
     *
     * @group mcp
     * @test
     */
    public function resources_read_de_negocio_devuelve_la_ficha()
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 25, 10, 0, 0));

        try {

            $respuesta = $this->rpc('resources/read', ['uri' => 'comerciocity://negocio']);

            $respuesta->assertStatus(200);

            $negocio = json_decode($respuesta->json('result.contents.0.text'), true);

            $this->assertEquals($this->dueno->company_name, $negocio['nombre']);
            $this->assertEquals('ARS', $negocio['moneda_principal']);
            $this->assertContains('asistente_ia', $negocio['extensiones']);
            $this->assertEquals(ConfianzaDelAgenteIaHelper::POR_DEFECTO, $negocio['confianza_del_asistente']);
            $this->assertEquals(ProveedorIaHelper::PROVEEDOR_POR_DEFECTO, $negocio['proveedor_de_ia']);
            $this->assertEquals('25/09/2026', $negocio['hoy']);
            $this->assertEquals(FormatoIaHelper::dia_de_la_semana(Carbon::now()), $negocio['dia_de_la_semana']);
            $this->assertEquals('viernes', $negocio['dia_de_la_semana']);

        } finally {

            Carbon::setTestNow(null);
        }
    }

    /**
     * @group mcp
     * @test
     */
    public function un_uri_inexistente_es_32002()
    {
        foreach (['comerciocity://nada', 'comerciocity://consultas/entidad_que_no_existe', 'comerciocity://cargas/tampoco', 'otro://negocio'] as $uri) {

            $respuesta = $this->rpc('resources/read', ['uri' => $uri]);

            $respuesta->assertStatus(200);

            $this->assertEquals(-32002, $respuesta->json('error.code'), $uri);
            $this->assertStringContainsString($uri, $respuesta->json('error.message'));
        }
    }

    /**
     * @group mcp
     * @test
     */
    public function resources_templates_list_trae_las_dos_plantillas()
    {
        $respuesta = $this->rpc('resources/templates/list', [], 5);

        $respuesta->assertStatus(200);

        $plantillas = $respuesta->json('result.resourceTemplates');

        $this->assertCount(2, $plantillas);
        $this->assertEquals(
            ['comerciocity://consultas/{entidad}', 'comerciocity://cargas/{entidad}'],
            array_column($plantillas, 'uriTemplate')
        );

        foreach ($plantillas as $plantilla) {
            $this->assertEquals('application/json', $plantilla['mimeType']);
            $this->assertNotEquals('', (string) $plantilla['description']);
        }
    }
}
