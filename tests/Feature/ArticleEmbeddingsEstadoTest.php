<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión embeddings-estado-whatsapp-dashboard (15/9/2026): GET api/article-embeddings/estado.
 *
 * No hay un estado explícito por artículo más allá de "hace falta generarle uno" o no (ver
 * ArticleEmbeddingsEstadoHelper): `sin_generar` y `pendiente` son las dos mitades excluyentes del
 * mismo WHERE que ya usa GenerateArticleEmbeddings::handle(), y `generandose` sale de la tanda
 * viva en `embedding_runs`, no de un estado por artículo (no existe ninguno).
 */
class ArticleEmbeddingsEstadoTest extends TestCase
{
    use DatabaseTransactions;

    /** Vector de prueba, mismo formato (json_encode de floats) que ya usan los tests hermanos. */
    const VECTOR = [1.0, 0.0, 0.0];

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'         => 'Comercio embeddings estado',
            'company_name' => 'Ferreteria estado',
            'email'        => 'embeddings-estado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * @param array $extra
     * @return Article
     */
    protected function articulo(array $extra = [])
    {
        return Article::create(array_merge([
            'name'    => 'Articulo de prueba',
            'status'  => 'active',
            'user_id' => $this->comercio->id,
        ], $extra));
    }

    /**
     * @param array $atributos
     * @return EmbeddingRun
     */
    protected function crear_tanda(array $atributos = [])
    {
        return EmbeddingRun::create(array_merge([
            'user_id'    => $this->comercio->id,
            'origen'     => 'scheduler',
            'status'     => 'en_proceso',
            'total_jobs' => 0,
            'terminados' => 0,
            'generados'  => 0,
            'salteados'  => 0,
            'fallados'   => 0,
        ], $atributos));
    }

    /**
     * Retrasa el `created_at` de la tanda, que es el reloj del vencimiento de 20 minutos. Se
     * escribe con el query builder porque Eloquent pisa los timestamps al guardar (misma técnica
     * que ya usa 16_Embeddings_por_lote_Test.php).
     *
     * @param EmbeddingRun $tanda
     * @param int          $minutos
     * @return void
     */
    protected function envejecer_tanda(EmbeddingRun $tanda, $minutos)
    {
        DB::table('embedding_runs')
            ->where('id', $tanda->id)
            ->update(['created_at' => Carbon::now()->subMinutes($minutos)]);
    }

    function test_sin_generar_cuenta_los_articulos_que_nunca_tuvieron_embedding()
    {
        $this->articulo(['embedding_generated_at' => null]);
        $this->articulo(['embedding_generated_at' => null]);

        $response = $this->actingAs($this->comercio, 'sanctum')
            ->getJson('api/article-embeddings/estado');

        $response->assertStatus(200);
        $response->assertJson([
            'sin_generar' => 2,
            'pendiente'   => 0,
            'generandose' => 0,
        ]);
    }

    function test_pendiente_cuenta_los_articulos_con_embedding_desactualizado()
    {
        // Ya tiene vector, generado hace una hora; el articulo se edito despues
        // (updated_at > embedding_generated_at).
        $articulo = $this->articulo(['embedding_generated_at' => Carbon::now()->subHour()]);

        DB::table('articles')->where('id', $articulo->id)->update([
            'embedding'   => json_encode(self::VECTOR),
            'updated_at'  => Carbon::now(),
        ]);

        $response = $this->actingAs($this->comercio, 'sanctum')
            ->getJson('api/article-embeddings/estado');

        $response->assertJson([
            'sin_generar' => 0,
            'pendiente'   => 1,
            'generandose' => 0,
        ]);
    }

    function test_un_articulo_al_dia_no_cuenta_en_ninguno_de_los_dos()
    {
        // Ya tiene vector, generado despues de la ultima edicion: al dia, no pendiente y no sin_generar.
        $articulo = $this->articulo(['embedding_generated_at' => Carbon::now()]);

        DB::table('articles')->where('id', $articulo->id)->update([
            'embedding'   => json_encode(self::VECTOR),
            'updated_at'  => Carbon::now()->subHour(),
        ]);

        $response = $this->actingAs($this->comercio, 'sanctum')
            ->getJson('api/article-embeddings/estado');

        $response->assertJson([
            'sin_generar' => 0,
            'pendiente'   => 0,
            'generandose' => 0,
        ]);
    }

    /**
     * El caso que encontró el chequeo independiente de esta misión: el job escribe
     * `embedding_generated_at` por query builder incluso cuando no encontró texto que vectorizar
     * (`embedding` queda NULL para siempre, `updated_at` no se toca). Con el corte viejo
     * (por `embedding_generated_at`) este artículo no contaba en ningún lado, aunque el comando
     * real lo siguiera re-encolando cada ciclo. El corte por `embedding` lo deja en sin_generar,
     * que es lo que de verdad va a pasar: el comando lo va a volver a intentar.
     */
    function test_un_articulo_con_intento_fallido_cuenta_como_sin_generar()
    {
        $articulo = $this->articulo(['embedding_generated_at' => null]);

        // El job marca el intento (embedding_generated_at) sin haber escrito ningun vector.
        DB::table('articles')->where('id', $articulo->id)->update([
            'embedding_generated_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->comercio, 'sanctum')
            ->getJson('api/article-embeddings/estado');

        $response->assertJson([
            'sin_generar' => 1,
            'pendiente'   => 0,
            'generandose' => 0,
        ]);
    }

    function test_generandose_cuenta_lo_que_le_falta_a_la_tanda_viva()
    {
        $this->crear_tanda(['status' => 'en_proceso', 'total_jobs' => 10, 'terminados' => 3]);

        $response = $this->actingAs($this->comercio, 'sanctum')
            ->getJson('api/article-embeddings/estado');

        $response->assertJson(['generandose' => 7]);
    }

    function test_una_tanda_despachando_con_total_jobs_en_cero_da_generandose_cero()
    {
        // Mientras el comando todavia esta encolando (status despachando), total_jobs sigue en 0:
        // es la ventana chica que el propio helper documenta, no un bug de este test.
        $this->crear_tanda(['status' => 'despachando', 'total_jobs' => 0, 'terminados' => 0]);

        $response = $this->actingAs($this->comercio, 'sanctum')
            ->getJson('api/article-embeddings/estado');

        $response->assertJson(['generandose' => 0]);
    }

    function test_una_tanda_vencida_no_cuenta_como_generandose()
    {
        $tanda = $this->crear_tanda(['status' => 'en_proceso', 'total_jobs' => 10, 'terminados' => 2]);

        // FinalizeEmbeddingRun::MINUTOS_PARA_VENCER es 20: a los 21 minutos ya se considera abandonada.
        $this->envejecer_tanda($tanda, 21);

        $response = $this->actingAs($this->comercio, 'sanctum')
            ->getJson('api/article-embeddings/estado');

        $response->assertJson(['generandose' => 0]);
    }

    function test_una_tanda_completada_no_cuenta_como_generandose()
    {
        $this->crear_tanda(['status' => 'completada', 'total_jobs' => 10, 'terminados' => 10]);

        $response = $this->actingAs($this->comercio, 'sanctum')
            ->getJson('api/article-embeddings/estado');

        $response->assertJson(['generandose' => 0]);
    }

    function test_tenencia_no_cuenta_articulos_ni_tandas_de_otro_usuario()
    {
        $otro = User::create([
            'name'         => 'Otro comercio',
            'company_name' => 'Otra ferreteria',
            'email'        => 'otro-embeddings-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        Article::create(['name' => 'Ajeno', 'status' => 'active', 'user_id' => $otro->id, 'embedding_generated_at' => null]);
        EmbeddingRun::create(['user_id' => $otro->id, 'origen' => 'scheduler', 'status' => 'en_proceso', 'total_jobs' => 5, 'terminados' => 0]);

        $this->articulo(['embedding_generated_at' => null]);

        $response = $this->actingAs($this->comercio, 'sanctum')
            ->getJson('api/article-embeddings/estado');

        $response->assertJson([
            'sin_generar' => 1,
            'generandose' => 0,
        ]);
    }

    function test_no_cuenta_articulos_inactivos_ni_borrados()
    {
        $this->articulo(['embedding_generated_at' => null, 'status' => 'inactive']);
        $this->articulo(['embedding_generated_at' => null, 'deleted_at' => Carbon::now()]);
        $this->articulo(['embedding_generated_at' => null]);

        $response = $this->actingAs($this->comercio, 'sanctum')
            ->getJson('api/article-embeddings/estado');

        $response->assertJson(['sin_generar' => 1]);
    }
}
