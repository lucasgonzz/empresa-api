<?php

namespace Tests\Feature\Extenciones;

use App\Models\ExtencionEmpresa;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión sugerencias inteligentes de stock (pieza P3) — la extensión
 * `sugerencias_inteligentes` y su seeder standalone.
 *
 * Lo que estos tests protegen es el modo de falla real de este cambio: que el seeder que Lucas
 * corre en las bases de los clientes DUPLIQUE el catálogo. `ExtencionSeeder` hace
 * `ExtencionEmpresa::create()` en un foreach —sin `firstOrCreate`— así que correrlo dos veces
 * duplica todas las extensiones; el seeder de esta misión tiene que ser idempotente, y eso sólo
 * se puede afirmar corriéndolo dos veces y contando. (La otra pata de la doble siembra —la
 * entrada en el array de `ExtencionSeeder`, que es la única vía por la que la extensión llega a
 * las instancias nuevas de UserSetupHelper/DemoSetupHelper— se verifica acá indirectamente: el
 * firstOrCreate del seeder suelto tiene que convivir con esa fila sin duplicarla.)
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites. Ojo que `extencion_empresas` puede estar
 * vacía igual en la base de un slot: por eso el test siembra su propia fila testigo en vez de
 * confiar en que el catálogo tenga algo.
 */
class Extencion_sugerencias_inteligentes_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Slug de la extensión, compartido con el front (`hasExtencion` / `if_has_extencion`) y con
     * el middleware `check_extencion_empresa` de las rutas nuevas.
     *
     * @var string
     */
    const SLUG = 'sugerencias_inteligentes';

    /**
     * Nombre mostrado al asignar la extensión al comercio.
     *
     * @var string
     */
    const NAME = 'Sugerencias inteligentes de stock';

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
     * Corre el seeder standalone (el mismo que se declara en el despliegue para las bases de
     * producción existentes; nunca `ExtencionSeeder`, ver D49 del plan).
     *
     * @return void
     */
    protected function correr_seeder()
    {
        $this->artisan('db:seed', [
            '--class' => 'Database\Seeders\ExtencionSugerenciasInteligentesSeeder',
        ])->assertExitCode(0);
    }

    /**
     * Foto del resto del catálogo: id, slug y nombre de cada fila que no es la de esta misión.
     *
     * Va con `slug` adentro y no sólo `name` porque lo que apaga funcionalidad en producción es
     * que a otra extensión le cambie el slug, no el nombre que se muestra.
     *
     * @return array<int, string>
     */
    protected function foto_del_resto_del_catalogo()
    {
        return ExtencionEmpresa::where('slug', '!=', self::SLUG)
            ->orderBy('id')
            ->get(['id', 'slug', 'name'])
            ->map(function ($extencion) {
                return $extencion->id . '|' . $extencion->slug . '|' . $extencion->name;
            })
            ->toArray();
    }

    /**
     * Dos corridas seguidas dejan UNA sola fila con el slug, y no tocan el resto del catálogo.
     *
     * @test
     * @return void
     */
    public function el_seeder_es_idempotente_y_no_altera_el_resto_del_catalogo()
    {
        /**
         * Fila testigo del catálogo. Va sembrada a mano porque la base de testing del slot puede
         * tener `extencion_empresas` vacía, y contra una tabla vacía la comparación del final
         * sería [] contra [] — una aserción que pasa sin medir nada. Con el testigo, el test
         * atrapa un seeder que le pise el nombre o el slug a otra extensión del catálogo.
         */
        // forceCreate: el modelo no declara $fillable y afuera de `artisan db:seed` no rige el
        // Model::unguarded() que aplica el comando, así que un create() común no asignaría nada.
        $testigo = ExtencionEmpresa::forceCreate([
            'name' => 'Testigo de catalogo (solo test)',
            'slug' => 'testigo_de_catalogo_del_test',
        ]);

        // Foto del catálogo antes de sembrar nada, para comparar contra el final.
        $total_antes = ExtencionEmpresa::count();
        $otras_antes = $this->foto_del_resto_del_catalogo();
        $this->assertContains(
            $testigo->id . '|testigo_de_catalogo_del_test|Testigo de catalogo (solo test)',
            $otras_antes,
            'La foto del catálogo no incluye la fila testigo: la comparación del final no mediría nada.'
        );

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

        // Y ninguna de las otras extensiones del catálogo se tocó: mismo id, slug y nombre.
        $this->assertEquals($otras_antes, $this->foto_del_resto_del_catalogo());
    }

    /**
     * La fila que siembra es la que el gate espera: el slug exacto (el que miran el middleware
     * `check_extencion_empresa:sugerencias_inteligentes` y el `if_has_extencion` de la SPA)
     * y el nombre con el que se asigna en el admin.
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
