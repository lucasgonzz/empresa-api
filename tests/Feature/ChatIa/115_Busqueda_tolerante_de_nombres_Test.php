<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\BusquedaPorPalabrasIaHelper;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\Client;
use App\Models\Provider;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), P8 — la búsqueda tolerante.
 *
 * EL CASO REAL (V14, Truvari): «Kilka Malbec» (es KILLKA), «Rosotto Gustavo» (el cliente está escrito
 * distinto) y «mix clásico» (es la promo MIX CLASICO Nº1). La búsqueda exige cada palabra entera: una
 * letra de más o de menos y no aparece nada. Lo que protege este archivo:
 *
 *  - BusquedaPorPalabrasIaHelper::parecidos() (público y reutilizable) encuentra el parecido, con los números
 *    enteros e iguales, y deja afuera lo que no se parece («Bronze» no es «Bronco»);
 *  - consultar_stock_de_articulos y consultar_clientes devuelven los PARECIDOS cuando no encuentran nada
 *    (con las promos de vinoteca entre los artículos), y la nota dice que se pregunte;
 *  - lo que antes se encontraba se sigue encontrando igual (los parecidos son solo el segundo intento);
 *  - 🔴 las cargas nunca eligen un parecido solas: lo ofrecen como opción (proveedor de una masiva, artículo
 *    de una foto).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class Busqueda_tolerante_de_nombres_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();
    }

    protected function articulo_p8($nombre)
    {
        return Article::create(['name' => $nombre, 'user_id' => $this->dueno->id, 'status' => 'active', 'stock' => 5]);
    }

    protected function lectura($herramienta, array $input)
    {
        list($conversation, $assistant) = $this->vuelta('buscame eso', null, AiMessage::CANAL_WHATSAPP);

        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_p8_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * @test
     */
    public function parecidos_encuentra_lo_mal_escrito_y_no_lo_distinto()
    {
        $this->articulo_p8('KILLKA MALBEC 750 ZZP8');
        $this->articulo_p8('KAIKEN MALBEC ZZP8');
        $this->articulo_p8('REEL BRONCO ZZP8');
        $this->articulo_p8('CAÑA OKINAWA 210 ZZP8');

        $base = Article::where('user_id', $this->dueno->id)->where('status', 'active');

        $parecidos = BusquedaPorPalabrasIaHelper::parecidos($base, 'Kilka Malbec', 'name');

        $this->assertSame('KILLKA MALBEC 750 ZZP8', $parecidos[0]['nombre']);
        $this->assertNotContains('KAIKEN MALBEC ZZP8', array_column($parecidos, 'nombre'), 'Cada palabra tiene que parecerse, no solo «malbec».');
        $this->assertGreaterThanOrEqual(BusquedaPorPalabrasIaHelper::UMBRAL_PARECIDO, $parecidos[0]['similitud']);

        $this->assertSame([], BusquedaPorPalabrasIaHelper::parecidos($base, 'reel bronze', 'name'), '«Bronze» no es «Bronco».');
        $this->assertSame([], BusquedaPorPalabrasIaHelper::parecidos($base, 'okinawa 120', 'name'), 'Un número se compara entero.');
        $this->assertSame([], BusquedaPorPalabrasIaHelper::parecidos($base, 'de la', 'name'));
    }

    /**
     * 🔴 V14: el artículo dictado con una letra de menos llega como PARECIDO, y la promo también.
     *
     * @test
     */
    public function consultar_stock_devuelve_parecidos_de_articulos_y_promos()
    {
        $this->articulo_p8('KILLKA MALBEC 750 ZZP8');

        $encontrado = $this->lectura('consultar_stock_de_articulos', ['busqueda' => 'killka malbec']);

        $this->assertSame('KILLKA MALBEC 750 ZZP8', $encontrado[0]['nombre'], 'Lo que se encontraba se sigue encontrando igual.');

        $r = $this->lectura('consultar_stock_de_articulos', ['busqueda' => 'Kilka Malbec']);

        $this->assertSame(0, $r['encontrados']);
        $this->assertSame('articulo', $r['parecidos'][0]['tipo']);
        $this->assertSame('KILLKA MALBEC 750 ZZP8', $r['parecidos'][0]['nombre']);
        $this->assertStringContainsString('nunca elijas uno solo', $r['nota']);

        if (Schema::hasTable('promocion_vinotecas')) {

            DB::table('promocion_vinotecas')->insert(['name' => 'MIX CLASICO Nº1 ZZP8', 'user_id' => $this->dueno->id, 'stock' => 3, 'created_at' => now(), 'updated_at' => now()]);

            $r = $this->lectura('consultar_stock_de_articulos', ['busqueda' => 'mix clasico zzp8']);

            $this->assertSame('promocion', $r['parecidos'][0]['tipo'], json_encode($r));
            $this->assertSame('MIX CLASICO Nº1 ZZP8', $r['parecidos'][0]['nombre']);
        }

        // Sin nada parecido, la lista vacía de siempre.
        $this->assertSame([], $this->lectura('consultar_stock_de_articulos', ['busqueda' => 'tornillo autoperforante']));
    }

    /**
     * 🔴 V14: «Rosotto Gustavo» encuentra a «Gustavo Rossotto».
     *
     * @test
     */
    public function consultar_clientes_devuelve_parecidos()
    {
        $this->cliente('Gustavo Rossotto');

        $r = $this->lectura('consultar_clientes', ['busqueda' => 'Rosotto Gustavo']);

        $this->assertSame(0, $r['encontrados']);
        $this->assertSame('Gustavo Rossotto', $r['parecidos'][0]['nombre']);
    }

    /**
     * 🔴 Una carga nunca toma un parecido sola: el proveedor de una masiva vuelve como opción.
     *
     * @test
     */
    public function la_masiva_ofrece_el_proveedor_parecido_sin_elegirlo()
    {
        Provider::create(['name' => 'Grupo Vid ZZP8', 'user_id' => $this->dueno->id]);

        list($conversation, $assistant) = $this->vuelta('cuántos artículos tiene gruppo vid');

        $resultado = HerramientasDeCarga::ejecutar('contar_articulos_por_filtro', [
            'filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Gruppo Vid ZZP8']],
        ], $conversation, $assistant);

        $r = json_decode($resultado['content'], true);

        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['faltan'], json_encode($r));
        $this->assertSame('Grupo Vid ZZP8', $r['opciones']['proveedores'][0]['nombre']);
        $this->assertTrue($r['opciones']['proveedores'][0]['parecido']);
    }

    /**
     * Para la foto de un artículo dictado mal escrito, el parecido va como opción.
     *
     * @test
     */
    public function la_foto_ofrece_el_articulo_parecido_sin_elegirlo()
    {
        $this->articulo_p8('KILLKA MALBEC 750 ZZP8');

        list($conversation, $assistant) = $this->vuelta('ponele esta foto al kilka malbec');

        $resultado = HerramientasDeCarga::ejecutar('proponer_foto_articulo', ['articulo' => 'Kilka Malbec'], $conversation, $assistant);

        $r = json_decode($resultado['content'], true);

        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['faltan'], json_encode($r));
        $this->assertSame('KILLKA MALBEC 750 ZZP8', $r['opciones']['articulos'][0]['nombre']);
    }
}
