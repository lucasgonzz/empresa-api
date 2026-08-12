<?php

namespace Tests\Feature\Extenciones;

use App\Models\ExtencionEmpresa;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión 26 — la extensión `personalizar_nombre_en_vender` y su seeder standalone.
 *
 * Lo que estos tests protegen es el modo de falla real de este cambio: que el seeder que Lucas
 * corre en las bases de los 40 clientes DUPLIQUE el catálogo. `ExtencionSeeder` hace
 * `ExtencionEmpresa::create()` en un foreach —sin `firstOrCreate`— así que correrlo dos veces
 * duplica todas las extensiones; el seeder de esta misión tiene que ser idempotente, y eso sólo
 * se puede afirmar corriéndolo dos veces y contando.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 */
class Extencion_personalizar_nombre_en_vender_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Slug de la extensión, compartido con el front (`hasExtencion` en `src/mixins/generals.js`).
     *
     * @var string
     */
    const SLUG = 'personalizar_nombre_en_vender';

    /**
     * Nombre mostrado al asignar la extensión al comercio.
     *
     * @var string
     */
    const NAME = 'Personalizar nombre del articulo en VENDER';

    /**
     * Deja la base sin la extensión, para que el test arranque siempre del mismo estado
     * (la base del slot puede tener el catálogo ya sembrado de una corrida anterior).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        ExtencionEmpresa::where('slug', self::SLUG)->delete();
    }

    /**
     * Corre el seeder standalone.
     *
     * @return void
     */
    protected function correr_seeder()
    {
        $this->artisan('db:seed', [
            '--class' => 'Database\Seeders\ExtencionPersonalizarNombreEnVenderSeeder',
        ]);
    }

    /**
     * Criterio 5: dos corridas seguidas dejan UNA sola fila con el slug, y no tocan el resto
     * del catálogo.
     *
     * @test
     * @return void
     */
    public function el_seeder_es_idempotente_y_no_altera_el_resto_del_catalogo()
    {
        // Foto del catálogo antes de sembrar nada, para comparar contra el final.
        $total_antes = ExtencionEmpresa::count();
        $otras_antes = ExtencionEmpresa::where('slug', '!=', self::SLUG)
            ->orderBy('id')
            ->pluck('name', 'id')
            ->toArray();

        $this->correr_seeder();

        // Primera corrida: la fila tiene que existir, una sola vez.
        $total_primera = ExtencionEmpresa::count();
        $this->assertEquals(1, ExtencionEmpresa::where('slug', self::SLUG)->count());
        $this->assertEquals($total_antes + 1, $total_primera);

        $this->correr_seeder();

        // Segunda corrida: nada cambió. Es la aserción que da sentido al test.
        $this->assertEquals(
            1,
            ExtencionEmpresa::where('slug', self::SLUG)->count(),
            'El seeder duplicó la extensión al correrlo dos veces: no es idempotente.'
        );
        $this->assertEquals(
            $total_primera,
            ExtencionEmpresa::count(),
            'La segunda corrida cambió la cantidad de filas de extencion_empresas.'
        );

        // Y ninguna de las otras extensiones del catálogo se tocó.
        $otras_despues = ExtencionEmpresa::where('slug', '!=', self::SLUG)
            ->orderBy('id')
            ->pluck('name', 'id')
            ->toArray();
        $this->assertEquals($otras_antes, $otras_despues);
    }

    /**
     * La fila que siembra es la que el front espera: el slug exacto y el nombre con la marca
     * "en VENDER" que la distingue del permiso homónimo en el admin.
     *
     * @test
     * @return void
     */
    public function la_extencion_queda_con_el_slug_y_el_nombre_esperados()
    {
        $this->correr_seeder();

        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        $this->assertNotNull($extencion, 'El seeder no creó la extensión.');
        $this->assertEquals(self::NAME, $extencion->name);
    }
}
