<?php

namespace Tests\Feature\Import;

use App\Jobs\FinalizeArticleImport;
use App\Models\EmbeddingRun;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `FinalizeArticleImport` no reencola una importación que ya está cerrada.
 *
 * EL AGUJERO QUE TAPA
 * -------------------
 * El job se re-despacha a sí mismo cada 10 segundos mientras `processed_chunks < total_chunks`.
 * Cuando una importación se cierra, `ImportFailureHandler::registrar()` marca 'fallo' en las dos
 * tablas pero NO toca `processed_chunks`, así que esa condición queda verdadera para siempre y el
 * job gira eternamente. San Cayetano tenía tres importaciones reencolándose desde el 31/8/2026, a
 * unos 135 re-dispatch por hora.
 *
 * El camino completo, que no es el obvio: un chunk que revienta hace `throw` y corta la chain, así
 * que el bucle NO nace de un chunk que falla. Nace de una importación que se cuelga (OOM, worker
 * reiniciado) y que el watchdog marca 'fallo'; los chunks que quedaban en la cola arrancan, leen el
 * 'fallo' y retornan limpio SIN incrementar `processed_chunks` y SIN tirar excepción — así que la
 * chain no se corta y llega hasta este job, con chunks faltantes que ya no va a procesar nadie.
 *
 * 🔴 LO QUE NO SE PUEDE HACER, Y POR QUÉ EL CASO 4 EXISTE
 * ------------------------------------------------------
 * La guarda mira SOLO 'fallo'. Nunca 'completado' (ImportStatus) ni 'terminado' (ImportHistory):
 * esos dos los deja el ÚLTIMO CHUNK, antes de que este job exista. Una guarda que los mirara
 * cortaría el cierre de TODAS las importaciones que terminan bien —sin notificación, sin
 * matching_counts, sin evento de demo y sin embeddings—, y en silencio.
 *
 * Tampoco va un techo de ciclos de re-dispatch: `ProcessArticleChunk::$timeout` es 1800, o sea que
 * un chunk puede tardar media hora legítimamente sin que `processed_chunks` avance. Un techo por
 * tiempo mataría importaciones vivas, que es justo lo que el piso de 45 minutos del watchdog
 * (`DetectarImportacionesColgadas`) evita a propósito.
 *
 * Estilo calcado de `tests/Feature/Whatsapp/17_Embeddings_post_importacion_Test.php`.
 */
class FinalizeArticleImportTest extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: ningún test de esta suite sale a la red.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio finalize import',
            'company_name' => 'Ferreteria finalize import',
            'email'        => 'finalize-import-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // El comando de embeddings resuelve el dueño por config, igual que el scheduler.
        config(['app.USER_ID' => $this->comercio->id]);

        // Ninguna red, ni siquiera por accidente.
        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * @param  string  $status  'pendiente' | 'en_proceso' | 'completado' | 'fallo' (enum de la tabla).
     * @param  int     $total_chunks
     * @param  int     $processed_chunks
     * @return ImportStatus
     */
    protected function import_status($status, $total_chunks = 1, $processed_chunks = 1)
    {
        return ImportStatus::create([
            'user_id'          => $this->comercio->id,
            'status'           => $status,
            'total_chunks'     => $total_chunks,
            'processed_chunks' => $processed_chunks,
        ]);
    }

    /**
     * @param  string|null  $status  'en_preparacion' | 'en_proceso' | 'terminado' | 'fallo' | null.
     * @return ImportHistory
     */
    protected function import_history($status = 'en_proceso')
    {
        return ImportHistory::create([
            'user_id'    => $this->comercio->id,
            'model_name' => 'Article',
            'status'     => $status,
        ]);
    }

    /**
     * Corre el job tal como lo despacha la chain.
     *
     * @param  ImportStatus|null  $import_status
     * @param  ImportHistory      $import_history
     * @return void
     */
    protected function correr_el_job($import_status, $import_history)
    {
        $import_status_id = is_null($import_status) ? 0 : $import_status->id;

        (new FinalizeArticleImport(
            $this->comercio->id,
            $import_history->id,
            $import_status_id
        ))->handle();
    }

    /**
     * Caso 1. 🔴 EL TEST CENTRAL DE LA MISIÓN.
     *
     * Importación en 'fallo' con chunks faltantes: el job corta y no se reencola. Antes de la
     * guarda, acá arrancaba el bucle infinito.
     *
     * Cubre también el job viejo que quedó serializado en la cola: un job con contadores rancios
     * de una importación ya cerrada es exactamente este escenario.
     *
     * @group import
     * @test
     */
    public function un_import_en_fallo_con_chunks_faltantes_no_se_redespacha()
    {
        $import_status  = $this->import_status('fallo', 3, 1);
        $import_history = $this->import_history('fallo');

        Queue::fake();

        $this->correr_el_job($import_status, $import_history);

        Queue::assertNothingPushed();

        // Y el cierre tampoco corrió: una importación fallida no se "termina" por la puerta de atrás.
        $this->assertNotEquals(
            'terminado',
            $import_history->fresh()->status,
            'Un import en fallo no puede terminar cerrándose como si hubiera salido bien.'
        );
        $this->assertNull($import_history->fresh()->terminado_at);
        $this->assertEquals(0, EmbeddingRun::where('user_id', $this->comercio->id)->count());

        Http::assertNothingSent();
    }

    /**
     * Caso 2. El espejo del caso 1: una importación viva se sigue reencolando como siempre.
     *
     * Es el candado contra el techo de ciclos que se descartó: un chunk puede tardar hasta media
     * hora sin que `processed_chunks` avance, y durante todo ese rato el re-dispatch tiene que
     * seguir funcionando.
     *
     * @group import
     * @test
     */
    public function un_import_activo_con_chunks_faltantes_se_sigue_redespachando()
    {
        $import_status  = $this->import_status('en_proceso', 3, 1);
        $import_history = $this->import_history('en_proceso');

        Queue::fake();

        $this->correr_el_job($import_status, $import_history);

        Queue::assertPushed(FinalizeArticleImport::class, 1);
    }

    /**
     * Caso 3. El `ImportHistory` en 'fallo' corta aunque el `ImportStatus` siga activo.
     *
     * Hay dos caminos reales que dejan esa combinación:
     *   1. El watchdog `imports:detectar-colgadas` con `import_status_id` en null (registros
     *      previos al prompt 500): marca el history y nunca llega al status.
     *   2. El early-return idempotente de `ImportFailureHandler::registrar()`, que sale de TODA la
     *      función si el history ya estaba resuelto — el bloque del status queda sin ejecutar.
     *
     * 🔴 Si alguien "simplifica" la guarda sacándole la mirada al ImportHistory, este es el único
     * test que se pone rojo.
     *
     * @group import
     * @test
     */
    public function el_import_history_en_fallo_corta_aunque_el_import_status_siga_activo()
    {
        $import_status  = $this->import_status('en_proceso', 3, 1);
        $import_history = $this->import_history('fallo');

        Queue::fake();

        $this->correr_el_job($import_status, $import_history);

        Queue::assertNothingPushed();
    }

    /**
     * Caso 4. El camino feliz sigue cerrando: 'terminado' y `terminado_at` sellado.
     *
     * El `ImportStatus` llega acá en 'completado' porque se lo dejó el último chunk, ANTES de que
     * este job existiera.
     *
     * ⚠️ OJO CON LO QUE ESTE CASO **NO** PROTEGE. Con `processed == total` el flujo ni siquiera
     * entra al brazo del re-dispatch, así que la guarda no se ejecuta y este test queda verde
     * aunque alguien le meta 'completado' adentro. El candado de eso es el caso 6, que sí la
     * ejecuta. Este caso sólo se cae si se rompen las DOS cosas a la vez (subir la guarda por
     * encima del chequeo de chunks Y hacerle mirar un estado de éxito).
     *
     * Que la guarda no se pueda subir no lo garantiza ningún test: lo garantiza la estructura
     * —los dos `return` viven en el mismo brazo, del que el cierre ya era inalcanzable— y está
     * explicado en el comentario de `handle()`.
     *
     * @group import
     * @test
     */
    public function un_import_completo_se_cierra_igual_que_siempre()
    {
        $import_status  = $this->import_status('completado', 2, 2);
        $import_history = $this->import_history('en_proceso');

        Queue::fake();

        $this->correr_el_job($import_status, $import_history);

        $this->assertEquals(
            'terminado',
            $import_history->fresh()->status,
            'La guarda no puede tocar el camino feliz: sin esto, ninguna importación se cerraría.'
        );
        $this->assertNotNull($import_history->fresh()->terminado_at);

        // Se cerró de una: no quedó ningún re-dispatch dando vueltas.
        Queue::assertNotPushed(FinalizeArticleImport::class);
    }

    /**
     * Caso 5. Sin `ImportStatus`, el flujo cae al cierre normal.
     *
     * Fija el comportamiento del `if ($import_status)` que envuelve todo el bloque: hoy, si el
     * registro no existe, el job cierra la importación igual. La guarda no lo cambia, y este test
     * impide que un refactor futuro lo cambie sin querer.
     *
     * @group import
     * @test
     */
    public function un_import_status_borrado_cae_al_cierre_normal()
    {
        $import_history = $this->import_history('en_proceso');

        Queue::fake();

        $this->correr_el_job(null, $import_history);

        $this->assertEquals('terminado', $import_history->fresh()->status);
        $this->assertNotNull($import_history->fresh()->terminado_at);
    }

    /**
     * Caso 6. 🔴 EL CANDADO CONTRA EL FALSO ARREGLO — el que el caso 4 no da.
     *
     * `ImportStatus` en 'completado' pero con chunks faltantes: es el único escenario que entra al
     * brazo del re-dispatch Y ejecuta la guarda con un estado de éxito en la mano. Hoy tiene que
     * re-despachar, porque 'completado' no significa que la importación esté cerrada — se lo puede
     * haber dejado un chunk mientras otros siguen.
     *
     * Se pone rojo apenas alguien agregue 'completado' (o 'terminado') a `import_esta_cerrado()`,
     * que es la "corrección" más plausible que le pueden hacer a este arreglo: el enum de
     * `import_statuses` es ['pendiente','en_proceso','completado','fallo'] y no tiene 'terminado',
     * así que el primero que lo lea va a querer emparejarlos. Esa guarda cortaría el cierre de
     * todas las importaciones exitosas, en silencio.
     *
     * @group import
     * @test
     */
    public function un_import_completado_pero_con_chunks_faltantes_se_sigue_redespachando()
    {
        $import_status  = $this->import_status('completado', 3, 1);
        $import_history = $this->import_history('en_proceso');

        Queue::fake();

        $this->correr_el_job($import_status, $import_history);

        // Si esto se cae, alguien le agregó un estado de éxito a la guarda.
        Queue::assertPushed(FinalizeArticleImport::class, 1);
    }
}
