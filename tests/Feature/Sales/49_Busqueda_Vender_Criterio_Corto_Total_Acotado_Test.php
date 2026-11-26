<?php

namespace Tests\Feature\Sales;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión search-modal-paginado-y-busqueda-lenta (22/11/2026), criterios CA-A2 a CA-A6.
 *
 * Con un criterio de hasta 3 caracteres ("MA") el COUNT exacto recorre todo el catálogo (Servian:
 * 568.000 artículos) para un número que nadie lee. El buscador de Vender cuenta entonces "hasta
 * 20 páginas por delante de la que se mira" y avisa con `total_es_minimo` cuando el número es un
 * piso y no el total. Con criterios largos, la ruta vieja y la extensión de variantes nada cambia.
 *
 * Los tests usan `per_page` chico: el tope es `offset + per_page * 20`, así que con per_page = 2
 * son 40 filas por delante y no hace falta sembrar miles de artículos.
 */
class Busqueda_Vender_Criterio_Corto_Total_Acotado_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\Models\User Dueño del catálogo de cada test. */
    private $dueno;

    /**
     * Crea el dueño y lo deja logueado.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dueno = User::create(['name' => 'Catálogo corto', 'email' => uniqid() . '@test.local', 'password' => 'prueba']);
        $this->actingAs($this->dueno, 'web');
    }

    /**
     * Siembra artículos activos del dueño con created_at ascendente (de a pares empatados, para
     * ejercitar el desempate por id) y devuelve sus ids en el orden en que la búsqueda los debe dar
     * (created_at DESC, id DESC).
     *
     * @param  int    $cantidad
     * @param  string $prefijo  Nombre base ("Mazo" contiene "ma"; "Tuerca" no).
     * @param  int    $user_id
     * @param  int    $stock
     * @return array  Ids en el orden esperado de la búsqueda.
     */
    private function sembrar($cantidad, $prefijo = 'Mazo', $user_id = null, $stock = 1)
    {
        $user_id = $user_id ?: $this->dueno->id;
        $ids = [];
        for ($i = 1; $i <= $cantidad; $i++) {
            $ids[] = DB::table('articles')->insertGetId([
                'user_id' => $user_id, 'name' => $prefijo . ' ' . $i, 'status' => 'active', 'stock' => $stock,
                'created_at' => date('Y-m-d H:i:s', 1700000000 + intdiv($i, 2) * 60), 'updated_at' => now(),
            ]);
        }

        return array_reverse($ids);
    }

    /**
     * Pide una página del buscador de Vender.
     *
     * @param  string $criterio
     * @param  int    $pagina
     * @param  int    $per_page
     * @param  array  $extra
     * @return \Illuminate\Testing\TestResponse
     */
    private function buscar($criterio, $pagina = 1, $per_page = 2, $extra = [])
    {
        return $this->postJson('api/global-search/article?page=' . $pagina, array_merge([
            'query_value' => $criterio, 'props' => ['name'], 'contexto' => 'vender', 'per_page' => $per_page,
        ], $extra))->assertStatus(200);
    }

    /**
     * ¿Alguna consulta del log coincide con la expresión regular?
     *
     * @param  array  $log
     * @param  string $regex
     * @return bool
     */
    private function log_contiene($log, $regex)
    {
        foreach ($log as $consulta) {
            if (preg_match($regex, $consulta['query'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * CA-A2: más coincidencias que el tope -> total = tope + 1, bandera true, last_page = 21; la
     * página 21 corre el tope y devuelve el total exacto.
     *
     * @return void
     */
    public function test_criterio_corto_con_mas_coincidencias_que_el_tope_devuelve_total_minimo()
    {
        $esperados = $this->sembrar(60);

        $uno = $this->buscar('ma', 1)
            ->assertJsonPath('models.total', 41)
            ->assertJsonPath('models.last_page', 21)
            ->assertJsonPath('total_es_minimo', true);
        $this->assertSame(array_slice($esperados, 0, 2), array_column($uno->json('models.data'), 'id'));

        $final = $this->buscar('ma', 21)
            ->assertJsonPath('models.total', 60)
            ->assertJsonPath('total_es_minimo', false);
        $this->assertSame(array_slice($esperados, 40, 2), array_column($final->json('models.data'), 'id'));
    }

    /**
     * CA-A3: página incompleta -> total deducido, sin ningún COUNT en el log.
     *
     * @return void
     */
    public function test_criterio_corto_con_pagina_incompleta_no_cuenta()
    {
        $this->sembrar(3);

        DB::enableQueryLog();
        $this->buscar('ma', 1, 50)
            ->assertJsonPath('models.total', 3)
            ->assertJsonPath('total_es_minimo', false)
            ->assertJsonCount(3, 'models.data');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertFalse($this->log_contiene($log, '/count\(\*\) as aggregate/i'), 'Con la página incompleta el total sale de la propia página.');
    }

    /**
     * CA-A3: con la página llena y menos coincidencias que el tope, el total es exacto.
     *
     * @return void
     */
    public function test_criterio_corto_por_debajo_del_tope_es_exacto()
    {
        $this->sembrar(5);

        $this->buscar('ma', 1, 2)
            ->assertJsonPath('models.total', 5)
            ->assertJsonPath('models.last_page', 3)
            ->assertJsonPath('total_es_minimo', false);
    }

    /**
     * CA-A4: recorrer todas las páginas siguiendo last_page devuelve los 60 ids, sin repetidos y en
     * el orden del camino exacto.
     *
     * @return void
     */
    public function test_recorrer_todas_las_paginas_devuelve_el_mismo_conjunto_y_orden()
    {
        $esperados = $this->sembrar(60);

        $vistos = [];
        $pagina = 1;
        $ultima = 1;
        while ($pagina <= $ultima && $pagina < 100) {
            $respuesta = $this->buscar('ma', $pagina);
            $vistos = array_merge($vistos, array_column($respuesta->json('models.data'), 'id'));
            $ultima = (int) $respuesta->json('models.last_page');
            $pagina++;
        }

        $this->assertSame(count($vistos), count(array_unique($vistos)), 'Hay artículos repetidos entre páginas.');
        $this->assertSame($esperados, $vistos);
    }

    /**
     * Misión busqueda-vender-conteo-acotado-siempre, CA1/CA2 (cambio de contrato pedido por Lucas el
     * 26/11/2026: antes un criterio largo contaba EXACTO aunque hubiera más coincidencias que el tope;
     * en Servian "amort" -> 26.844 coincidencias tardaba ~2,4 s en el COUNT). Ahora el conteo se acota
     * para criterios de cualquier largo: más coincidencias que el tope -> piso tope + 1 y bandera true.
     *
     * @return void
     */
    public function test_criterio_largo_con_mas_coincidencias_que_el_tope_devuelve_total_minimo()
    {
        $esperados = $this->sembrar(60, 'Amortiguador');

        DB::enableQueryLog();
        $uno = $this->buscar('amort', 1, 2)
            ->assertJsonPath('models.total', 41)
            ->assertJsonPath('models.last_page', 21)
            ->assertJsonPath('total_es_minimo', true);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(array_slice($esperados, 0, 2), array_column($uno->json('models.data'), 'id'));
        $this->assertTrue($this->log_contiene($log, '/limit 41/i'), 'El criterio largo debe contar con tope (LIMIT tope + 1).');
        $this->assertFalse($this->log_contiene($log, '/select count\(\*\) as aggregate from `articles`/i'), 'No debe quedar un COUNT exacto sobre articles.');
    }

    /**
     * CA2: criterio largo con menos coincidencias que el tope (página llena) -> total exacto, false.
     *
     * @return void
     */
    public function test_criterio_largo_por_debajo_del_tope_es_exacto()
    {
        $this->sembrar(5, 'Amortiguador');

        $this->buscar('amort', 1, 2)
            ->assertJsonPath('models.total', 5)
            ->assertJsonPath('models.last_page', 3)
            ->assertJsonPath('total_es_minimo', false);
    }

    /**
     * CA3: criterio largo con la página incompleta -> total deducido de la página, sin ningún COUNT.
     *
     * @return void
     */
    public function test_criterio_largo_con_pagina_incompleta_no_cuenta()
    {
        $this->sembrar(3, 'Amortiguador');

        DB::enableQueryLog();
        $this->buscar('amort', 1, 50)
            ->assertJsonPath('models.total', 3)
            ->assertJsonPath('total_es_minimo', false)
            ->assertJsonCount(3, 'models.data');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertFalse($this->log_contiene($log, '/count\(\*\) as aggregate/i'), 'Con la página incompleta el total sale de la propia página.');
    }

    /**
     * CA5: con criterio largo, avanzar de página corre el tope y no deja artículos inalcanzables;
     * recorrer siguiendo last_page devuelve los 60 ids sin repetidos y en orden.
     *
     * @return void
     */
    public function test_criterio_largo_recorre_todas_las_paginas_y_la_21_da_el_total_exacto()
    {
        $esperados = $this->sembrar(60, 'Amortiguador');

        $final = $this->buscar('amort', 21)
            ->assertJsonPath('models.total', 60)
            ->assertJsonPath('total_es_minimo', false);
        $this->assertSame(array_slice($esperados, 40, 2), array_column($final->json('models.data'), 'id'));

        $vistos = [];
        $pagina = 1;
        $ultima = 1;
        while ($pagina <= $ultima && $pagina < 100) {
            $respuesta = $this->buscar('amort', $pagina);
            $vistos = array_merge($vistos, array_column($respuesta->json('models.data'), 'id'));
            $ultima = (int) $respuesta->json('models.last_page');
            $pagina++;
        }
        $this->assertSame($esperados, $vistos);
    }

    /**
     * CA4: con la extensión article_variants activa el criterio largo sigue por el camino exacto.
     *
     * @return void
     */
    public function test_criterio_largo_con_variantes_no_cambia()
    {
        $this->sembrar(60, 'Amortiguador');
        $extension = ExtencionEmpresa::firstOrCreate(['slug' => 'article_variants'], ['name' => 'article_variants']);
        $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        $this->dueno->load('extencions');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->dueno, 'web');

        $this->buscar('amort', 1)
            ->assertJsonPath('models.total', 60)
            ->assertJsonPath('total_es_minimo', false);
    }

    /**
     * CA-A5: la ruta vieja (sin contexto) no cambia: total exacto, sin tope, sin la clave nueva.
     *
     * @return void
     */
    public function test_ruta_vieja_sin_cambios()
    {
        $this->sembrar(60);

        DB::enableQueryLog();
        $respuesta = $this->postJson('api/vender/buscar-articulo-por-nombre/0?page=1', ['query_value' => 'ma'])
            ->assertStatus(200)
            ->assertJsonPath('total', 60);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertArrayNotHasKey('total_es_minimo', $respuesta->json());
        $this->assertTrue($this->log_contiene($log, '/select count\(\*\) as aggregate from `articles`/i'));
        $this->assertFalse($this->log_contiene($log, '/limit 1001/i'), 'La ruta vieja no usa el tope.');
    }

    /**
     * CA-A6: criterio corto sin coincidencias.
     *
     * @return void
     */
    public function test_sin_coincidencias()
    {
        $this->sembrar(4, 'Tuerca');

        $this->buscar('ma', 1)
            ->assertJsonPath('models.total', 0)
            ->assertJsonPath('models.data', [])
            ->assertJsonPath('total_es_minimo', false);
    }

    /**
     * CA-A6: criterio vacío con un filtro fijo toma el mismo camino corto.
     *
     * @return void
     */
    public function test_criterio_vacio_con_filtro_fijo()
    {
        $this->sembrar(3, 'Tuerca', null, 5);
        $this->sembrar(2, 'Perno', null, 0);

        $this->buscar('', 1, 2, ['extra_filters' => [['key' => 'stock', 'operator' => '>', 'value' => 0]]])
            ->assertJsonPath('models.total', 3)
            ->assertJsonPath('total_es_minimo', false);
    }

    /**
     * CA-A6: con la extensión article_variants activa el camino de expansión queda intacto.
     *
     * @return void
     */
    public function test_con_variantes_no_cambia()
    {
        $this->sembrar(60);
        $extension = ExtencionEmpresa::firstOrCreate(['slug' => 'article_variants'], ['name' => 'article_variants']);
        $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        $this->dueno->load('extencions');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->dueno, 'web');

        $this->buscar('ma', 1)
            ->assertJsonPath('models.total', 60)
            ->assertJsonPath('total_es_minimo', false);
    }

    /**
     * CA-A6: otro modelo del mismo endpoint no recibe la clave nueva.
     *
     * @return void
     */
    public function test_otro_modelo_sin_la_clave()
    {
        $respuesta = $this->postJson('api/global-search/client?page=1', ['query_value' => 'ma', 'props' => ['name']])
            ->assertStatus(200);

        $this->assertArrayNotHasKey('total_es_minimo', $respuesta->json());
    }

    /**
     * CA-A6: los artículos de otro comercio no cuentan.
     *
     * @return void
     */
    public function test_aislamiento_entre_comercios()
    {
        $otro = User::create(['name' => 'Otro comercio', 'email' => uniqid() . '@test.local', 'password' => 'prueba']);
        $this->sembrar(10, 'Mazo', $otro->id);
        $this->sembrar(3);

        $this->buscar('ma', 1, 50)
            ->assertJsonPath('models.total', 3)
            ->assertJsonPath('total_es_minimo', false);
    }
}
