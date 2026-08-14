<?php

namespace Tests\Feature\Extenciones;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use Database\Seeders\ExtencionEmpresaDescriptionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión 54 — los seeders que le escriben `description`, `modulo` y `en_desuso` al catálogo.
 *
 * El modo de falla que estos tests protegen es el del seeder que Lucas corre a mano en las bases
 * de los 40 clientes: que además de describir, TOQUE algo. Dos cosas no puede tocar nunca —las
 * filas del catálogo (ni crear, ni borrar, ni renombrar) y las asignaciones de cada comercio en
 * `extencion_empresa_user`—, porque una asignación perdida le apaga funcionalidad a un cliente en
 * producción y no hay forma de darse cuenta sin que el cliente llame.
 *
 * DatabaseTransactions y no RefreshDatabase: la base de testing del slot está sembrada de antes y
 * un refresh la vaciaría. Las filas testigo se siembran acá adentro porque `extencion_empresas`
 * puede estar vacía en la base de un slot — medido, lo está.
 */
class Extenciones_description_y_desuso_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Una extensión viva, con el caso testigo que dio Lucas: comparte nombre visible con
     * `warn_article_stock_en_vender` y hace lo contrario.
     *
     * @var string
     */
    const SLUG_VIVA = 'check_article_stock_en_vender';

    /**
     * Una de las once que la misión 53 dejó en `estado: sin_uso`.
     *
     * @var string
     */
    const SLUG_EN_DESUSO = 'ai_excel_import';

    /**
     * Deja la base sin los slugs que el test siembra.
     *
     * La base de testing del slot puede tener el catálogo ya sembrado —lo estuvo y lo va a estar
     * de nuevo—, y entonces la fila testigo del test convive con la real: la aserción de "el
     * seeder no duplicó" contaría dos filas y fallaría por el estado previo, no por el código.
     * DatabaseTransactions revierte el borrado al terminar cada test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        ExtencionEmpresa::whereIn('slug', [
            self::SLUG_VIVA,
            self::SLUG_EN_DESUSO,
            'testigo_ajeno_al_padron_del_test',
        ])->delete();
    }

    /**
     * Corre el seeder de las bases nuevas.
     *
     * @return void
     */
    protected function correr_seeder()
    {
        $this->artisan('db:seed', [
            '--class' => 'Database\Seeders\ExtencionEmpresaDescriptionSeeder',
        ])->assertExitCode(0);
    }

    /**
     * Siembra una fila del catálogo sin describir, como está hoy en las bases de producción.
     *
     * forceCreate porque el modelo no declara `$fillable` y afuera de `artisan db:seed` no rige
     * el `Model::unguarded()` que aplica el comando.
     *
     * @param  string  $slug
     * @return \App\Models\ExtencionEmpresa
     */
    protected function sembrar($slug)
    {
        return ExtencionEmpresa::forceCreate([
            'name' => 'Nombre viejo de ' . $slug,
            'slug' => $slug,
        ]);
    }

    /**
     * El padrón es el trabajo de la misión 53 entero, no un recorte.
     *
     * Sin esta aserción, alguien que borre media lista del array deja un seeder que corre verde
     * y describe la mitad del catálogo: la falla no se ve en ningún lado.
     *
     * @test
     * @return void
     */
    public function el_padron_tiene_las_91_extensiones_y_las_11_en_desuso()
    {
        $padron = ExtencionEmpresaDescriptionSeeder::padron();

        $this->assertCount(91, $padron, 'El padrón no tiene las 91 extensiones que describió la misión 53.');

        $slugs = [];
        $en_desuso = 0;

        foreach ($padron as $fila) {
            $slugs[] = $fila['slug'];

            $this->assertNotEmpty($fila['description'], 'La extensión ' . $fila['slug'] . ' está en el padrón sin descripción.');
            $this->assertNotEmpty($fila['modulo'], 'La extensión ' . $fila['slug'] . ' está en el padrón sin módulo.');

            if ($fila['en_desuso']) {
                $en_desuso++;
            }
        }

        $this->assertCount(91, array_unique($slugs), 'Hay slugs repetidos en el padrón.');
        $this->assertEquals(11, $en_desuso, 'Las marcadas en desuso no son las 11 que la misión 53 dejó como sin_uso.');
    }

    /**
     * Lo que el seeder escribe es lo que dice el padrón, y lo escribe sobre la fila del slug.
     *
     * @test
     * @return void
     */
    public function el_seeder_escribe_descripcion_modulo_y_desuso_por_slug()
    {
        $viva      = $this->sembrar(self::SLUG_VIVA);
        $en_desuso = $this->sembrar(self::SLUG_EN_DESUSO);

        $this->assertNull($viva->description, 'La fila arrancó ya descripta: el test no mediría nada.');

        $this->correr_seeder();

        $viva      = $viva->fresh();
        $en_desuso = $en_desuso->fresh();

        $this->assertNotEmpty($viva->description);
        $this->assertEquals('VENDER', $viva->modulo);
        $this->assertFalse($viva->en_desuso);

        /*
         * El caso testigo de Lucas: la descripción tiene que decir que ESTA bloquea, que es lo
         * único que la distingue de warn_article_stock_en_vender, con la que comparte el nombre
         * visible exacto.
         */
        $this->assertStringContainsString('IMPIDE', $viva->description);

        $this->assertTrue($en_desuso->en_desuso, 'ai_excel_import quedó sin marcar en desuso.');
        $this->assertNotEmpty($en_desuso->description);
    }

    /**
     * Correrlo dos veces deja la base igual, no crea ni borra filas, y no toca lo que cada
     * comercio tiene asignado.
     *
     * @test
     * @return void
     */
    public function el_seeder_es_idempotente_y_no_toca_el_catalogo_ni_las_asignaciones()
    {
        $viva = $this->sembrar(self::SLUG_VIVA);

        /* Fila que NO está en el padrón: tiene que quedar tal cual, sin descripción inventada. */
        $ajena = ExtencionEmpresa::forceCreate([
            'name' => 'Testigo ajeno al padron (solo test)',
            'slug' => 'testigo_ajeno_al_padron_del_test',
        ]);

        $user = User::find(config('app.USER_ID'));
        $this->assertNotNull($user, 'No existe el usuario de config(app.USER_ID) en la base de testing.');

        $user->extencions()->attach($viva->id);
        $asignadas_antes = $user->extencions()->pluck('extencion_empresa_user.extencion_empresa_id')->toArray();

        $total_antes = ExtencionEmpresa::count();

        $this->correr_seeder();
        $primera = ExtencionEmpresa::where('slug', self::SLUG_VIVA)->first()->description;

        $this->correr_seeder();
        $segunda = ExtencionEmpresa::where('slug', self::SLUG_VIVA)->first()->description;

        $this->assertEquals($primera, $segunda, 'La segunda corrida cambió la descripción.');
        $this->assertEquals($total_antes, ExtencionEmpresa::count(), 'El seeder creó o borró filas del catálogo.');
        $this->assertEquals(1, ExtencionEmpresa::where('slug', self::SLUG_VIVA)->count(), 'El seeder duplicó la extensión.');

        $this->assertEquals(
            $asignadas_antes,
            $user->extencions()->pluck('extencion_empresa_user.extencion_empresa_id')->toArray(),
            'El seeder cambió las extensiones asignadas al comercio.'
        );

        $ajena = $ajena->fresh();
        $this->assertNull($ajena->description, 'El seeder le inventó una descripción a una extensión que no está en el padrón.');
        $this->assertEquals('Testigo ajeno al padron (solo test)', $ajena->name, 'El seeder le cambió el nombre a otra extensión.');
    }

    /**
     * El seeder de producción reporta las dos direcciones del desfasaje, que es lo único que
     * agrega sobre el de las bases nuevas.
     *
     * @test
     * @return void
     */
    public function el_seeder_de_produccion_reporta_lo_que_falta_de_los_dos_lados()
    {
        $this->sembrar(self::SLUG_VIVA);

        ExtencionEmpresa::forceCreate([
            'name' => 'Testigo ajeno al padron (solo test)',
            'slug' => 'testigo_ajeno_al_padron_del_test',
        ]);

        $sin_descripcion = ExtencionEmpresaDescriptionSeeder::slugs_sin_descripcion();

        $this->assertContains('testigo_ajeno_al_padron_del_test', $sin_descripcion);
        $this->assertNotContains(self::SLUG_VIVA, $sin_descripcion);

        /* Y corre sin explotar sobre una base a la que le falta casi todo el catálogo. */
        $this->artisan('db:seed', [
            '--class' => 'Database\Seeders\ExtencionEmpresaDescriptionProduccionSeeder',
        ])->assertExitCode(0);

        $this->assertNotEmpty(ExtencionEmpresa::where('slug', self::SLUG_VIVA)->first()->description);
    }
}
