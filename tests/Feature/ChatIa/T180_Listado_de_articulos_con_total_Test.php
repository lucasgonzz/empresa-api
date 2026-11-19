<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261119 (rama A, A3) — «no hay ningún remache con medida 6» y había 9.
 *
 * 🔴 EL CASO REAL (Ferre Total, 18/11/2026, conv. 72, msgs 676-681): «haceme un listado de los remaches».
 * consultar_stock_de_articulos trajo 20 (su tope) sin decir cuántos había; el asistente armó el Excel sobre
 * esa página y afirmó que no había remaches de medida 6. Pablo: «Si hay con 6».
 *
 * Lo que se cuida:
 *  - si no entró todo, la herramienta dice el total (articulos_encontrados) y hay_mas, con una nota que
 *    prohíbe afirmar que algo no existe;
 *  - se puede pedir la página siguiente o subir el límite (hasta 100) y llegan los que faltaban;
 *  - si entró todo, la lista de siempre (la forma que ya conocían los tests y el canal «sistema:»);
 *  - «5X16» encuentra «5 X 16» (la medida compacta, BusquedaPorPalabrasIaHelper).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class T180_Listado_de_articulos_con_total_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Ferreteria T180',
            'email'    => 't180-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        // 16 remaches de 3,5 a 5 (entran en la primera página) y 9 de medida 6 (quedan en la segunda).
        $medidas = ['3,5 X 06', '3,5 X 08', '3,5 X 10', '3,5 X 12', '3,5 X 14', '3,5 X 16', '4 X 08', '4 X 10', '4 X 12', '4 X 14', '4 X 16', '5 X 08', '5 X 10', '5 X 12', '5 X 14', '5 X 16'];

        foreach ($medidas as $medida) {
            $this->articulo('REMACHES ' . $medida . ' EN CAJA T180');
        }

        foreach (['6 X 10', '6 X 12', '6 X 14', '6 X 16', '6 X 20', '6 X 25', '6 X 30', '6 X 35', '6 X 40'] as $medida) {
            $this->articulo('REMACHES ' . $medida . ' EN CAJA T180');
        }

        $this->articulo('TARUGO N 8 T180');
    }

    /**
     * 🔴 El listado topeado dice cuántos son y que hay más, y la nota prohíbe el «no hay».
     *
     * @group chat-ia
     * @test
     */
    public function el_listado_topeado_trae_el_total_y_hay_mas()
    {
        $r = $this->consultar(['busqueda' => 'remaches t180']);

        $this->assertArrayHasKey('articulos_encontrados', $r, json_encode($r));
        $this->assertSame(25, $r['articulos_encontrados']);
        $this->assertSame(20, $r['articulos_en_esta_lista']);
        $this->assertTrue($r['hay_mas']);
        $this->assertCount(20, $r['articulos']);
        $this->assertStringContainsString('NO digas que algo no existe', $r['nota']);
        $this->assertStringContainsString('exportar_articulos_excel', $r['nota']);

        // Los de medida 6 están en la página siguiente.
        $segunda = $this->consultar(['busqueda' => 'remaches t180', 'pagina' => 2]);

        $this->assertSame(25, $segunda['articulos_encontrados']);
        $this->assertSame(5, $segunda['articulos_en_esta_lista']);
        $this->assertFalse($segunda['hay_mas']);

        $nombres = array_merge(array_column($r['articulos'], 'nombre'), array_column($segunda['articulos'], 'nombre'));
        $this->assertCount(25, array_unique($nombres), 'Entre las dos páginas, los 25 sin repetir.');

        $de_seis = array_filter($nombres, function ($nombre) {
            return strpos($nombre, 'REMACHES 6 X') === 0;
        });
        $this->assertCount(9, $de_seis, 'Los 9 de medida 6.');
    }

    /**
     * Con limite 100 entran todos en una: vuelve la lista de siempre.
     *
     * @group chat-ia
     * @test
     */
    public function con_limite_entran_todos_y_vuelve_la_lista()
    {
        $r = $this->consultar(['busqueda' => 'remaches t180', 'limite' => 100]);

        $this->assertArrayNotHasKey('articulos_encontrados', $r);
        $this->assertCount(25, $r);
    }

    /**
     * Lo que entra en la primera página va como siempre (sin envoltura).
     *
     * @group chat-ia
     * @test
     */
    public function lo_que_entra_va_como_siempre()
    {
        $r = $this->consultar(['busqueda' => 'tarugo t180']);

        $this->assertCount(1, $r);
        $this->assertSame('TARUGO N 8 T180', $r[0]['nombre']);

        // El canal «sistema:» del admin llama sin límite ni página: la misma forma y el mismo tope de siempre.
        $sistema = ConsultasSistemaIaHelper::stock_de_articulos($this->comercio->id, 'remaches t180');
        $this->assertCount(ConsultasSistemaIaHelper::MAX_RESULTS, $sistema);
        $this->assertArrayHasKey('nombre', $sistema[0]);
    }

    /**
     * «5X16» (como lo escribe el dueño) encuentra «5 X 16», y no el 3,5 X 16 ni el 6 X 16 como si fueran él.
     *
     * @group chat-ia
     * @test
     */
    public function la_medida_pegada_encuentra_la_medida_con_espacios()
    {
        $r = $this->consultar(['busqueda' => 'remaches 5X16 t180']);

        $nombres = array_column(isset($r['articulos']) ? $r['articulos'] : $r, 'nombre');

        $this->assertContains('REMACHES 5 X 16 EN CAJA T180', $nombres);
        $this->assertNotContains('REMACHES 6 X 16 EN CAJA T180', $nombres);
    }

    // ---------------------------------------------------------------------

    /**
     * @param  string  $nombre
     * @return Article
     */
    protected function articulo($nombre)
    {
        return Article::create(['name' => $nombre, 'user_id' => $this->comercio->id, 'status' => 'active', 'stock' => 10, 'final_price' => 100]);
    }

    /**
     * consultar_stock_de_articulos por el despacho real.
     *
     * @param  array  $input
     * @return array
     */
    protected function consultar(array $input)
    {
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_t180_' . uniqid(),
            'name'  => 'consultar_stock_de_articulos',
            'input' => $input,
        ]], $conversation);

        return json_decode($resultados[0]['content'], true);
    }
}
