<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\FichaArticuloIaHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261128, P6 (CA13, CA14) — TESTS DE ACEPTACIÓN: con listas de precio, el precio de venta
 * que le dice el asistente al dueño es el de la LISTA, no `articles.final_price`.
 *
 * EL CASO REAL (Ferre Total, que usa listas MAYORISTA 0 % y LISTA GENERAL 50 %): el asistente informaba 2.278,43
 * («precio final») cuando lo que se cobra es 3.417,65 (LISTA GENERAL). Con listas, `final_price` es un número que no
 * se le cobra a nadie (`ArticlePricesHelper::resolver_precio_de_venta()`); `consultar_stock_de_articulos` y la
 * ficha del artículo nunca lo miraban, y el prompt lo empujaba («el precio de venta es su precio final»).
 *
 * Qué fija este archivo:
 *   CA13 `stock_de_articulos()` (por id y por búsqueda) devuelve `precio` de la lista que se cobra,
 *        `precio_de_la_lista` y `precios_por_lista` (por `position` ascendente);
 *   CA14 sin listas la fila no cambia (guarda: verde hoy), la ficha usa el mismo precio y el prompt aclara las listas.
 *
 * 🔴 Estado esperado en el commit de tests: ROJOS `con_listas_el_precio_es_el_de_la_lista_y_trae_todas`,
 * `la_ficha_usa_el_mismo_precio` y `el_prompt_aclara_las_listas`. `sin_listas_la_fila_no_cambia` es una GUARDA que
 * ya pasa hoy.
 *
 * Datos como `tests/Feature/Precios/1_Resolver_Precio_De_Venta_Test.php`. Sin red. PHP 7.4.
 *
 * @group chat-ia
 */
class U214_Precio_de_venta_con_listas_Test extends EmpresaTestCase
{
    /** Precio final «único» del artículo: el número que hoy informa el asistente (y que nadie paga con listas). */
    const PRECIO_FINAL_UNICO = 2278.43;

    /** Precio de la lista que se cobra (la de position más alta). */
    const PRECIO_LISTA_GENERAL = 3417.65;

    /** @var User */
    protected $dueno;

    /** @var Article */
    protected $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Arma un artículo con el precio final único y, si se pide, las dos listas del caso real: MAYORISTA
     * (position 1, 0 %) con 2278,43 y LISTA GENERAL (position 2, 50 %) con 3417,65. La LISTA GENERAL se crea
     * primero a propósito: el orden del resultado tiene que salir de `position`, no del id.
     *
     * @param  bool  $con_listas  Si la cuenta trabaja con listas de precio.
     * @return void
     */
    protected function escena($con_listas)
    {
        DB::table('users')->where('id', $this->dueno->id)->update(['listas_de_precio' => $con_listas ? 1 : 0]);
        $this->dueno = User::find($this->dueno->id);

        $this->articulo = Article::create([
            'name'        => 'Cable U214 unipolar 2,5',
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => self::PRECIO_FINAL_UNICO,
        ]);

        if (!$con_listas) {
            return;
        }

        $general = PriceType::create(['name' => 'LISTA GENERAL', 'user_id' => $this->dueno->id, 'position' => 2, 'percentage' => 50]);
        $mayorista = PriceType::create(['name' => 'MAYORISTA', 'user_id' => $this->dueno->id, 'position' => 1, 'percentage' => 0]);

        $this->articulo->price_types()->attach($general->id, ['final_price' => self::PRECIO_LISTA_GENERAL]);
        $this->articulo->price_types()->attach($mayorista->id, ['final_price' => self::PRECIO_FINAL_UNICO]);
    }

    /**
     * 🔴 CA13 — Con listas, el precio es el de la lista que se cobra (LISTA GENERAL, la de position más alta) y
     * vienen todas las listas, por id y por búsqueda.
     *
     * @test
     */
    public function con_listas_el_precio_es_el_de_la_lista_y_trae_todas()
    {
        $this->escena(true);

        $por_id = ConsultasSistemaIaHelper::stock_de_articulos((int) $this->dueno->id, '', (int) $this->articulo->id);
        $por_busqueda = ConsultasSistemaIaHelper::stock_de_articulos((int) $this->dueno->id, 'Cable U214 unipolar');

        foreach ([$por_id, $por_busqueda] as $filas) {

            $this->assertCount(1, $filas);

            $fila = $filas[0];

            $this->assertEqualsWithDelta(self::PRECIO_LISTA_GENERAL, $fila['precio'], 0.001, 'El precio de venta es el de la lista, no el final_price.');
            $this->assertArrayHasKey('precio_de_la_lista', $fila);
            $this->assertSame('LISTA GENERAL', $fila['precio_de_la_lista']);

            $this->assertArrayHasKey('precios_por_lista', $fila);
            $this->assertCount(2, $fila['precios_por_lista']);
            $this->assertSame('MAYORISTA', $fila['precios_por_lista'][0]['lista'], 'Por position ascendente.');
            $this->assertEqualsWithDelta(self::PRECIO_FINAL_UNICO, $fila['precios_por_lista'][0]['precio'], 0.001);
            $this->assertSame('LISTA GENERAL', $fila['precios_por_lista'][1]['lista']);
            $this->assertEqualsWithDelta(self::PRECIO_LISTA_GENERAL, $fila['precios_por_lista'][1]['precio'], 0.001);
        }
    }

    /**
     * CA14 — Sin listas la fila queda IDÉNTICA a la de hoy: el precio es el final_price y no aparecen las claves
     * nuevas. GUARDA de regresión: pasa hoy y tiene que seguir pasando.
     *
     * @test
     */
    public function sin_listas_la_fila_no_cambia()
    {
        $this->escena(false);

        $fila = ConsultasSistemaIaHelper::stock_de_articulos((int) $this->dueno->id, '', (int) $this->articulo->id)[0];

        $this->assertEqualsWithDelta(self::PRECIO_FINAL_UNICO, $fila['precio'], 0.001);
        $this->assertArrayNotHasKey('precio_de_la_lista', $fila);
        $this->assertArrayNotHasKey('precios_por_lista', $fila);

        // Las primeras claves, en el orden de siempre.
        $this->assertSame(['id', 'nombre', 'codigo', 'precio', 'stock', 'tiene_imagen'], array_slice(array_keys($fila), 0, 6));

        $fila_por_busqueda = ConsultasSistemaIaHelper::stock_de_articulos((int) $this->dueno->id, 'Cable U214 unipolar')[0];

        $this->assertSame(array_keys($fila), array_keys($fila_por_busqueda), 'Por id y por búsqueda la fila tiene las mismas claves.');
        $this->assertEqualsWithDelta(self::PRECIO_FINAL_UNICO, $fila_por_busqueda['precio'], 0.001);
    }

    /**
     * 🔴 CA14 — La ficha del artículo (la tarjeta de una mención) dice el mismo precio que la consulta: el de la lista.
     *
     * @test
     */
    public function la_ficha_usa_el_mismo_precio()
    {
        $this->escena(true);

        $ficha = FichaArticuloIaHelper::ficha((int) $this->articulo->id, (int) $this->dueno->id, $this->dueno);

        $this->assertNotNull($ficha, 'La ficha del artículo tiene que existir.');
        $this->assertEqualsWithDelta(self::PRECIO_LISTA_GENERAL, (float) $ficha['precio'], 0.001);
    }

    /**
     * 🔴 CA14 — El prompt deja de decir a secas «el precio de venta es su precio final»: aclara que con listas de
     * precios el precio es el de cada lista.
     *
     * @test
     */
    public function el_prompt_aclara_las_listas()
    {
        $this->escena(true);

        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->dueno->fresh(), true, false);

        // El prompt parte las líneas a mano (con sangría): se compara con los espacios y saltos normalizados.
        $prompt = preg_replace('/\s+/u', ' ', $prompt);

        $this->assertStringContainsString('El precio de venta de un artículo es su precio final', $prompt, 'La regla de siempre sigue (precondición).');
        $this->assertStringContainsString('salvo que el negocio trabaje con listas de precios', $prompt);
        $this->assertStringContainsString('precios_por_lista', $prompt);
    }
}
