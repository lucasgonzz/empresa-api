<?php

namespace Tests\Feature\SugerenciasStock;

use App\Jobs\GenerateStockSuggestionChunksJob;
use App\Models\ExtencionEmpresa;
use App\Models\StockSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión sugerencias-inteligentes-stock — P8: la generación automática.
 *
 * Protege que el comando decida contra sugerencias_ultima_generacion_at (no
 * contra el día del calendario), que 'nunca' realmente sea nunca, que correrlo
 * dos veces el mismo día no duplique, que --force saltee la periodicidad (pero
 * no el gate por extensión) y que el PUT de configuración persista las cinco
 * columnas nuevas.
 */
class Generacion_automatica_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea la generación. */
    const SLUG = 'sugerencias_inteligentes';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();

        $this->comercio = User::create([
            'name'     => 'Comercio sugerencias P8',
            'email'    => 'sugerencias-p8-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        // El comando opera sobre el dueño de la instancia.
        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * Asigna la extensión al comercio (creando la fila si la base del slot no
     * la tiene sembrada todavía).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Sugerencias inteligentes de stock',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
    }

    /**
     * Cantidad de sugerencias del comercio del test.
     *
     * @return int
     */
    protected function sugerencias_creadas()
    {
        return StockSuggestion::where('user_id', $this->comercio->id)->count();
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function con_periodicidad_nunca_no_crea_nada()
    {
        $this->dar_extension();

        // 'nunca' es el default de la migración; se explicita igual para que
        // el test no dependa del default.
        $this->comercio->sugerencias_periodicidad = 'nunca';
        $this->comercio->save();

        $this->artisan('sugerencias:generar')->assertExitCode(0);

        $this->assertEquals(0, $this->sugerencias_creadas());
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function sin_la_extension_no_genera_ni_con_force()
    {
        $this->comercio->sugerencias_periodicidad = 'diaria';
        $this->comercio->save();

        $this->artisan('sugerencias:generar', ['--force' => true])->assertExitCode(0);

        $this->assertEquals(
            0,
            $this->sugerencias_creadas(),
            'La extensión es el gate de venta: sin ella no se genera nada, ni con --force.'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function con_diaria_y_ultima_generacion_de_ayer_crea_una_automatica_con_los_defaults()
    {
        $this->dar_extension();

        $this->comercio->sugerencias_periodicidad = 'diaria';
        $this->comercio->sugerencias_modo = 'maximo';
        $this->comercio->sugerencias_origen = 'relativo';
        $this->comercio->sugerencias_limite_origen = 'sin_limite';
        $this->comercio->sugerencias_ultima_generacion_at = now()->subDay();
        $this->comercio->save();

        $this->artisan('sugerencias:generar')->assertExitCode(0);

        $this->assertEquals(1, $this->sugerencias_creadas());

        $suggestion = StockSuggestion::where('user_id', $this->comercio->id)->first();

        $this->assertEquals('automatica', $suggestion->origen_generacion);
        // La sugerencia nace con los defaults configurados por el usuario.
        $this->assertEquals('maximo', $suggestion->modo);
        $this->assertEquals('relativo', $suggestion->origen);
        $this->assertEquals('sin_limite', $suggestion->limite_origen);
        $this->assertEquals('pendiente', $suggestion->status);

        // El cálculo va por el MISMO job del flujo manual.
        Queue::assertPushed(GenerateStockSuggestionChunksJob::class);

        // Y la marca de última generación quedó en hoy.
        $this->comercio->refresh();
        $this->assertTrue(
            \Carbon\Carbon::parse($this->comercio->sugerencias_ultima_generacion_at)->isToday()
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function corrido_dos_veces_el_mismo_dia_no_duplica()
    {
        $this->dar_extension();

        $this->comercio->sugerencias_periodicidad = 'diaria';
        $this->comercio->sugerencias_ultima_generacion_at = now()->subDay();
        $this->comercio->save();

        $this->artisan('sugerencias:generar')->assertExitCode(0);
        $this->artisan('sugerencias:generar')->assertExitCode(0);

        $this->assertEquals(
            1,
            $this->sugerencias_creadas(),
            'La segunda corrida del mismo día no puede crear otra sugerencia.'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function force_genera_aunque_la_periodicidad_diga_que_no_toca()
    {
        $this->dar_extension();

        $this->comercio->sugerencias_periodicidad = 'nunca';
        $this->comercio->save();

        $this->artisan('sugerencias:generar', ['--force' => true])->assertExitCode(0);

        $this->assertEquals(1, $this->sugerencias_creadas());
        $this->assertEquals(
            'automatica',
            StockSuggestion::where('user_id', $this->comercio->id)->first()->origen_generacion
        );
    }

    /**
     * El PUT de Configuración → general persiste la configuración nueva.
     * Payload como lo manda el frontend (el modelo entero, patrón de la suite
     * de Preferencias), sobre el usuario del fixture.
     *
     * @group sugerencias-stock
     * @test
     */
    public function el_put_de_usuario_persiste_las_cinco_columnas()
    {
        $user = User::find(500);
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $payload = array_merge($user->fresh()->toArray(), [
            'sugerencias_periodicidad'         => 'semanal',
            'sugerencias_modo'                 => 'maximo',
            'sugerencias_origen'               => 'relativo',
            'sugerencias_limite_origen'        => 'ideal',
            'sugerencias_ultima_generacion_at' => '2026-08-13 05:00:00',
        ]);

        $response = $this->putJson('api/user/' . $user->id, $payload);
        $response->assertStatus(200);

        $fresco = $user->fresh();

        $this->assertEquals('semanal', $fresco->sugerencias_periodicidad);
        $this->assertEquals('maximo', $fresco->sugerencias_modo);
        $this->assertEquals('relativo', $fresco->sugerencias_origen);
        $this->assertEquals('ideal', $fresco->sugerencias_limite_origen);
        $this->assertEquals(
            '2026-08-13 05:00:00',
            \Carbon\Carbon::parse($fresco->sugerencias_ultima_generacion_at)->format('Y-m-d H:i:s')
        );
    }
}
