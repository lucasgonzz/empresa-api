<?php

namespace Tests\Feature\DimensionSearch;

use App\Jobs\ProcessDimensionSearchRunJob;
use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use Illuminate\Support\Facades\Queue;

/**
 * El comando `medidas:buscar` (misión medidas-masivas-catalogo, 27/10/2026): lanza la búsqueda de todo el
 * catálogo desde la consola, con el dueño de `config('app.USER_ID')`.
 */
class Comando_medidas_buscar_Test extends DimensionSearchTestCase
{
    /** @var mixed */
    protected $user_id_anterior;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id_anterior = config('app.USER_ID');

        config(['app.USER_ID' => $this->owner->id]);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        config(['app.USER_ID' => $this->user_id_anterior]);

        parent::tearDown();
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_dry_run_muestra_la_previa_y_no_crea_ni_despacha_nada()
    {
        $this->nuevo_articulo('CON STOCK', null, ['stock' => 4]);
        $this->nuevo_articulo('SIN STOCK', null, ['stock' => 0]);
        $this->nuevo_articulo('COMPLETO', null, ['peso' => 1, 'alto' => 1, 'ancho' => 1, 'profundidad' => 1]);

        $this->artisan('medidas:buscar', ['--dry-run' => true, '--solo-con-stock' => true])
            ->expectsOutput('Artículos activos:                          3')
            ->expectsOutput('A buscar en esta corrida:                   1 (tope 5000)')
            ->expectsOutput('Es un --dry-run: no se creó ni se despachó nada.')
            ->assertExitCode(0);

        $this->assertSame(0, DimensionSearchRun::count());
        Queue::assertNothingPushed();
    }

    /**
     * @group dimension-search
     * @test
     */
    public function lanza_la_corrida_de_catalogo_con_los_articulos_con_stock_y_despacha_el_job()
    {
        $con_stock = $this->nuevo_articulo('CON STOCK', null, ['stock' => 4]);
        $this->nuevo_articulo('SIN STOCK', null, ['stock' => 0]);

        $this->artisan('medidas:buscar', ['--solo-con-stock' => true])->assertExitCode(0);

        $run = DimensionSearchRun::first();

        $this->assertNotNull($run);
        $this->assertSame($this->owner->id, (int) $run->user_id);
        $this->assertSame('catalogo', $run->origen);
        $this->assertTrue((bool) $run->solo_con_stock);
        $this->assertNull($run->creada_por, 'Lo lanzó la consola: no hay persona.');
        $this->assertSame(1, (int) $run->total);
        $this->assertSame([$con_stock->id], DimensionSearchItem::where('run_id', $run->id)->pluck('article_id')->all());

        Queue::assertPushed(ProcessDimensionSearchRunJob::class, 1);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_limite_acota_los_articulos()
    {
        for ($i = 0; $i < 4; $i++) {
            $this->nuevo_articulo('ARTICULO '.$i);
        }

        $this->artisan('medidas:buscar', ['--limite' => '2'])->assertExitCode(0);

        $this->assertSame(2, (int) DimensionSearchRun::first()->total);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function un_limite_invalido_o_vacio_es_un_error_y_no_lanza_nada()
    {
        $this->nuevo_articulo('ARTICULO');

        foreach (['', 'abc', '0', '-3', '1.5'] as $limite) {
            $this->artisan('medidas:buscar', ['--limite' => $limite])
                ->expectsOutput('--limite tiene que ser un número entero mayor a cero.')
                ->assertExitCode(1);
        }

        $this->assertSame(0, DimensionSearchRun::count());
        Queue::assertNothingPushed();
    }

    /**
     * @group dimension-search
     * @test
     */
    public function sin_serper_sin_ia_o_sin_dueno_no_lanza_y_avisa()
    {
        $this->nuevo_articulo('ARTICULO');

        // Sin clave de Serper.
        config(['services.serper.api_key' => '']);
        $this->artisan('medidas:buscar')->assertExitCode(1);

        // Sin clave de IA.
        config(['services.serper.api_key' => 'SERPER-DE-PRUEBA', 'services.deepseek.api_key' => '']);
        $this->artisan('medidas:buscar')->assertExitCode(1);

        // Sin dueño en la configuración.
        config(['services.deepseek.api_key' => 'DEEPSEEK-DE-PRUEBA', 'app.USER_ID' => 0]);
        $this->artisan('medidas:buscar')->assertExitCode(1);

        $this->assertSame(0, DimensionSearchRun::count());
        Queue::assertNothingPushed();
    }

    /**
     * No lanza una segunda mientras hay otra de catálogo en curso.
     *
     * @group dimension-search
     * @test
     */
    public function no_lanza_otra_corrida_si_ya_hay_una_de_catalogo_en_curso()
    {
        $this->nuevo_articulo('UNO');
        $this->nuevo_articulo('DOS');

        $this->artisan('medidas:buscar', ['--limite' => '1'])->assertExitCode(0);
        $this->artisan('medidas:buscar')->assertExitCode(1);

        $this->assertSame(1, DimensionSearchRun::count());
    }
}
