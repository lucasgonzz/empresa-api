<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\FiltroDeArticulosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaActualizacionMasivaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión agente-mejoras-uso-real (E2 punto 3, 29/10/2026) — contar artículos por el margen de CADA
 * lista de precio.
 *
 * El caso real: Pablo (Ferre Total, CONV 12) preguntó «¿hay algún producto con margen 0 en la lista de
 * precios?» y el agente contestó «uno solo»: miraba `articles.percentage_gain`, que con listas por
 * artículo no arma ningún precio (en su base: 19 con margen 0 en LISTA GENERAL, 12 con stock, y 3.206
 * en MAYORISTA). Lo que protege:
 *  - margen_en_lista con lista "cualquiera" cuenta los que tienen ese margen en ALGUNA lista y trae el
 *    conteo de cada una (artículos, con stock, con precio fijo) y una muestra con su article_id;
 *  - con una lista nombrada, cuenta solo esa; mayor y menor andan; el margen de la lista vale para los
 *    artículos sin fila en el pivote;
 *  - precio_fijo_en_lista cuenta los de precio fijado a mano;
 *  - margen_de_ganancia (el suelto) se rechaza con listas, y la masiva no acepta los filtros por lista.
 *
 * 🔴 Sin red: la clave de Anthropic va en null.
 */
class Margen_por_lista_de_precios_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var PriceType */
    protected $general;

    /** @var PriceType */
    protected $mayorista;

    /** @var array<string, Article> */
    protected $articulos = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Comercio listas E2',
            'company_name'     => 'Ferreteria listas E2',
            'email'            => 'listas-e2-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
            'listas_de_precio' => 1,
        ]);

        $this->general = PriceType::create(['name' => 'LISTA GENERAL', 'percentage' => 30, 'position' => 1, 'user_id' => $this->comercio->id]);
        $this->mayorista = PriceType::create(['name' => 'MAYORISTA', 'percentage' => 20, 'position' => 2, 'user_id' => $this->comercio->id]);

        // El sellador del caso: margen 0 y precio FIJO en la general, margen 0 en la mayorista.
        $this->articulos['sellador'] = $this->articulo('OFERTA SELLADOR ZINGUERIA 280CC', 7, [
            $this->general->id   => ['percentage' => 0, 'setear_precio_final' => 1, 'final_price' => 5000],
            $this->mayorista->id => ['percentage' => 0],
        ]);

        // Sin stock: margen 0 en la general, 25 en la mayorista.
        $this->articulos['pincel'] = $this->articulo('PINCEL N 10', 0, [
            $this->general->id   => ['percentage' => 0],
            $this->mayorista->id => ['percentage' => 25],
        ]);

        // Con stock: 40 en la general, 0 en la mayorista.
        $this->articulos['rodillo'] = $this->articulo('RODILLO LANA 22CM', 5, [
            $this->general->id   => ['percentage' => 40],
            $this->mayorista->id => ['percentage' => 0],
        ]);

        // Sin ninguna fila: manda el margen de cada lista (30 y 20).
        $this->articulos['lija'] = $this->articulo('LIJA AL AGUA 120', 3, []);

        // Inactivo con margen 0: no cuenta.
        $this->articulos['viejo'] = $this->articulo('ARTICULO VIEJO', 9, [$this->general->id => ['percentage' => 0]], 'inactive');
    }

    /**
     * Un artículo del comercio con sus filas de pivote por lista. El margen suelto es 30 en todos (el
     * conteo viejo, por `percentage_gain`, daba 0 artículos con margen 0).
     *
     * @param  string  $nombre
     * @param  float  $stock
     * @param  array  $pivotes  [price_type_id => columnas]
     * @param  string  $status
     * @return Article
     */
    protected function articulo($nombre, $stock, array $pivotes, $status = 'active')
    {
        $articulo = Article::create([
            'name'            => $nombre,
            'user_id'         => $this->comercio->id,
            'status'          => $status,
            'cost'            => 1000,
            'percentage_gain' => 30,
            'stock'           => $stock,
        ]);

        foreach ($pivotes as $price_type_id => $columnas) {
            DB::table('article_price_type')->insert(array_merge([
                'article_id'    => $articulo->id,
                'price_type_id' => $price_type_id,
            ], $columnas));
        }

        return $articulo;
    }

    /**
     * contar_articulos_por_filtro con esos filtros.
     *
     * @param  array  $filtros
     * @return array
     */
    protected function contar(array $filtros)
    {
        return FiltroDeArticulosIaHelper::contar_para_la_ia($this->comercio->id, ['filtros' => $filtros]);
    }

    /**
     * El renglón de una lista en por_lista.
     *
     * @param  array  $respuesta
     * @param  string  $lista
     * @return array|null
     */
    protected function fila(array $respuesta, $lista)
    {
        foreach ($respuesta['por_lista'] as $fila) {
            if ($fila['lista'] === $lista) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * La pregunta de Pablo: «¿hay algún producto con margen 0 en la lista de precios?».
     *
     * @group chat-ia
     * @test
     */
    public function margen_cero_en_cualquier_lista_cuenta_por_lista_con_stock_y_precio_fijo()
    {
        $respuesta = $this->contar([
            ['campo' => 'margen_en_lista', 'operador' => 'igual', 'valor' => 0, 'lista' => 'la lista de precios'],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(3, $respuesta['total'], 'Sellador, pincel y rodillo (el inactivo no; la lija tiene 30 y 20).');
        $this->assertSame(['Margen en la lista: cualquier lista: 0 %'], $respuesta['filtros_legibles']);

        $this->assertSame(['lista' => 'LISTA GENERAL', 'articulos' => 2, 'con_stock' => 1, 'con_precio_fijo' => 1], $this->fila($respuesta, 'LISTA GENERAL'));
        $this->assertSame(['lista' => 'MAYORISTA', 'articulos' => 2, 'con_stock' => 2, 'con_precio_fijo' => 0], $this->fila($respuesta, 'MAYORISTA'));

        // La muestra: los que tienen stock primero, con su article_id y el detalle por lista.
        $muestra = $respuesta['muestra_por_lista'];

        $this->assertCount(3, $muestra);
        $this->assertSame((int) $this->articulos['sellador']->id, $muestra[0]['article_id']);
        $this->assertEquals(7, $muestra[0]['stock']);
        $this->assertSame(['lista' => 'LISTA GENERAL', 'margen' => 0.0, 'precio' => 5000.0, 'precio_fijo' => true], $muestra[0]['listas'][0]);
        $this->assertSame((int) $this->articulos['pincel']->id, $muestra[2]['article_id'], 'El pincel, sin stock, al final.');
        $this->assertArrayHasKey('nota_por_lista', $respuesta);

        // El conteo viejo, por el margen suelto, no encontraba ninguno: por eso ahora se rechaza.
        $suelto = $this->contar([['campo' => 'margen_de_ganancia', 'operador' => 'igual', 'valor' => 0]]);

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($suelto));
        $this->assertStringContainsString('margen_en_lista', $suelto['error']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function con_una_lista_nombrada_cuenta_solo_esa_y_mayor_y_menor_andan()
    {
        $general = $this->contar([['campo' => 'margen_en_lista', 'operador' => 'igual', 'valor' => 0, 'lista' => 'general']]);

        $this->assertTrue($general['ok'], json_encode($general));
        $this->assertSame(2, $general['total']);
        $this->assertCount(1, $general['por_lista']);
        $this->assertSame('LISTA GENERAL', $general['por_lista'][0]['lista']);

        // Más de 35 en la general: solo el rodillo (40).
        $mayor = $this->contar([['campo' => 'margen_en_lista', 'operador' => 'mayor', 'valor' => 35, 'lista' => 'LISTA GENERAL']]);
        $this->assertSame(1, $mayor['total']);

        // Menos de 21 en la mayorista: sellador y rodillo (0) y la lija, que no tiene fila y hereda el 20.
        $menor = $this->contar([['campo' => 'margen_en_lista', 'operador' => 'menor', 'valor' => 21, 'lista' => 'mayorista']]);
        $this->assertSame(3, $menor['total']);

        // Combinado con otro filtro (nombre).
        $combinado = $this->contar([
            ['campo' => 'nombre', 'operador' => 'contiene', 'valor' => 'sellador'],
            ['campo' => 'margen_en_lista', 'operador' => 'igual', 'valor' => 0, 'lista' => 'cualquiera'],
        ]);
        $this->assertSame(1, $combinado['total']);

        // Una lista que no existe: el error nombra las que hay.
        $inexistente = $this->contar([['campo' => 'margen_en_lista', 'operador' => 'igual', 'valor' => 0, 'lista' => 'minorista']]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($inexistente));
        $this->assertStringContainsString('LISTA GENERAL', $inexistente['error']);
        $this->assertStringNotContainsString('No se cargó nada', $inexistente['error']);
    }

    /**
     * Una lista cuyo margen es 0: los artículos sin fila en ella también tienen margen 0 ahí.
     *
     * @group chat-ia
     * @test
     */
    public function el_margen_de_la_lista_vale_para_los_articulos_sin_fila()
    {
        PriceType::create(['name' => 'LISTA CERO', 'percentage' => 0, 'position' => 3, 'user_id' => $this->comercio->id]);

        $respuesta = $this->contar([['campo' => 'margen_en_lista', 'operador' => 'igual', 'valor' => 0, 'lista' => 'lista cero']]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(4, $respuesta['total'], 'Los cuatro activos, que no tienen fila en LISTA CERO.');
    }

    /**
     * @group chat-ia
     * @test
     */
    public function precio_fijo_en_lista_cuenta_los_de_precio_fijado_a_mano()
    {
        $si = $this->contar([['campo' => 'precio_fijo_en_lista', 'operador' => 'igual', 'valor' => 'si', 'lista' => 'cualquiera']]);

        $this->assertTrue($si['ok'], json_encode($si));
        $this->assertSame(1, $si['total']);
        $this->assertSame((int) $this->articulos['sellador']->id, $si['muestra_por_lista'][0]['article_id']);

        $no = $this->contar([['campo' => 'precio_fijo_en_lista', 'operador' => 'igual', 'valor' => 'no', 'lista' => 'general']]);
        $this->assertSame(3, $no['total'], 'Pincel, rodillo y lija.');
    }

    /**
     * La masiva (y por lo tanto la búsqueda de imágenes) no acepta los filtros por lista: una masiva
     * no puede filtrar por un pivote que no ve la pantalla.
     *
     * @group chat-ia
     * @test
     */
    public function la_masiva_no_acepta_los_filtros_por_lista_ni_el_margen_suelto()
    {
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $por_lista = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, [
            'filtros' => [['campo' => 'margen_en_lista', 'operador' => 'igual', 'valor' => 0, 'lista' => 'general']],
            'cambios' => [['campo' => 'en_tienda', 'operacion' => 'activar']],
        ]);

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($por_lista));
        $this->assertStringContainsString('sirve solo para contar', $por_lista['error']);

        $suelto = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, [
            'filtros' => [['campo' => 'margen_de_ganancia', 'operador' => 'igual', 'valor' => 0]],
            'cambios' => [['campo' => 'en_tienda', 'operacion' => 'activar']],
        ]);

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($suelto));
    }

    /**
     * Sin listas de precio, el margen suelto se sigue pudiendo filtrar como siempre.
     *
     * @group chat-ia
     * @test
     */
    public function sin_listas_el_margen_suelto_sigue_andando_y_la_herramienta_declara_la_lista()
    {
        $this->comercio->listas_de_precio = 0;
        $this->comercio->save();

        $respuesta = $this->contar([['campo' => 'margen_de_ganancia', 'operador' => 'igual', 'valor' => 30]]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(4, $respuesta['total']);
        $this->assertArrayNotHasKey('por_lista', $respuesta);

        $definicion = null;

        foreach (HerramientasDeCarga::definiciones(false) as $herramienta) {
            if ($herramienta['name'] === 'contar_articulos_por_filtro') {
                $definicion = $herramienta;
            }
        }

        $item = $definicion['input_schema']['properties']['filtros']['items'];

        $this->assertContains('margen_en_lista', $item['properties']['campo']['enum']);
        $this->assertContains('precio_fijo_en_lista', $item['properties']['campo']['enum']);
        $this->assertArrayHasKey('lista', $item['properties']);
        $this->assertStringContainsString('por_lista', $definicion['description']);
    }
}
