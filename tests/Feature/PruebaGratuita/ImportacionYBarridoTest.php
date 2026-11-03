<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\import\article\InitExcelImport;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\SemillaRegistroHelper;
use App\Jobs\BorrarSemillaDePruebaJob;
use App\Jobs\FinalizeArticleImport;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Models\PruebaHito;
use App\Models\SemillaRegistro;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * El primer import de la prueba y el barrido (misión prueba-experiencia-empresa, 3/11/2026).
 *
 *  1. `InitExcelImport::mandar_chain()` pone BorrarSemillaDePruebaJob al FRENTE de la cadena solo en modo
 *     prueba y con semilla; en una demo o en un cliente real, la cadena es la de siempre.
 *  2. BorrarSemillaDePruebaJob borra lo sembrado y deja el origen para `prueba.importo`.
 *  3. `prueba:barrido` registra el primer pedido de la tienda que no es de la semilla y el vencimiento,
 *     una sola vez cada uno.
 */
class ImportacionYBarridoTest extends EmpresaTestCase
{
    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        PruebaHelper::olvidar_cache();
        SemillaRegistroHelper::descartar_lote();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        config(['app.USER_ID' => $this->dueno->id]);
    }

    protected function tearDown(): void
    {
        SemillaRegistroHelper::descartar_lote();
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    private function prender_la_prueba()
    {
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
    }

    private function sembrar_un_articulo()
    {
        SemillaRegistroHelper::abrir_lote($this->dueno->id, 'test');
        $articulo = Article::create(['name' => 'Artículo de ejemplo del import', 'user_id' => $this->dueno->id, 'status' => 'active']);
        SemillaRegistroHelper::cerrar_lote();

        return $articulo;
    }

    /**
     * Un InitExcelImport con lo mínimo que mandar_chain() necesita, sin leer ningún Excel.
     *
     * @param string|null $origen
     * @return \App\Http\Controllers\Helpers\import\article\InitExcelImport
     */
    private function importador($origen = null)
    {
        $import = new InitExcelImport();
        $import->user = $this->dueno;
        $import->jobs = [];
        $import->prueba_origen = $origen;
        $import->import_status = ImportStatus::create(['user_id' => $this->dueno->id, 'status' => 'pendiente', 'total_chunks' => 1, 'processed_chunks' => 0]);
        $import->import_history = ImportHistory::create(['user_id' => $this->dueno->id, 'model_name' => 'Article', 'status' => 'en_preparacion', 'import_status_id' => $import->import_status->id]);

        return $import;
    }

    public function test_en_modo_prueba_con_semilla_el_borrado_va_al_frente_de_la_cadena()
    {
        $this->prender_la_prueba();
        $this->sembrar_un_articulo();
        Bus::fake();

        $import = $this->importador('martin');
        $import->mandar_chain();

        Bus::assertDispatched(BorrarSemillaDePruebaJob::class, function ($job) use ($import) {
            return $job->owner_id === $this->dueno->id
                && $job->import_history_id === $import->import_history->id
                && $job->import_status_id === $import->import_status->id
                && $job->origen === 'martin'
                && count($job->chained) === 1;
        });
        Bus::assertNotDispatched(FinalizeArticleImport::class);
    }

    public function test_sin_semilla_la_cadena_es_la_de_siempre()
    {
        $this->prender_la_prueba();
        Bus::fake();

        $this->importador()->mandar_chain();

        Bus::assertNotDispatched(BorrarSemillaDePruebaJob::class);
        Bus::assertDispatched(FinalizeArticleImport::class);
    }

    public function test_fuera_del_modo_prueba_la_cadena_es_la_de_siempre_aunque_haya_semilla()
    {
        $this->sembrar_un_articulo();
        Bus::fake();

        $this->importador()->mandar_chain();

        Bus::assertNotDispatched(BorrarSemillaDePruebaJob::class);
        Bus::assertDispatched(FinalizeArticleImport::class);
    }

    public function test_el_job_borra_lo_sembrado_y_deja_el_origen_para_el_hito()
    {
        $this->prender_la_prueba();
        $articulo = $this->sembrar_un_articulo();
        $import = $this->importador('pdf');

        $this->assertSame('excel', PruebaHelper::origen_de_importacion($this->dueno->id), 'Sin importar todavía, el origen por defecto es excel.');

        (new BorrarSemillaDePruebaJob($this->dueno->id, $import->import_history->id, $import->import_status->id, 'pdf'))->handle();

        $this->assertSame(0, DB::table('articles')->where('id', $articulo->id)->count());
        $this->assertFalse(SemillaRegistroHelper::semilla_presente($this->dueno->id));
        PruebaHelper::olvidar_cache();
        $this->assertSame('pdf', PruebaHelper::origen_de_importacion($this->dueno->id));
        $this->assertNotNull(DB::table('prueba_config')->where('owner_id', $this->dueno->id)->value('semilla_borrada_at'));
    }

    public function test_un_origen_desconocido_cae_a_excel()
    {
        $job = new BorrarSemillaDePruebaJob($this->dueno->id, 1, null, 'telepatia');

        $this->assertSame('excel', $job->origen);
    }

    public function test_el_barrido_registra_el_primer_pedido_de_la_tienda_que_no_es_de_la_semilla()
    {
        $this->prender_la_prueba();

        $pedido_sembrado = DB::table('orders')->insertGetId([
            'status' => 'unconfirmed', 'deliver' => 0, 'buyer_id' => 1, 'order_status_id' => 1,
            'user_id' => $this->dueno->id, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        SemillaRegistro::create(['owner_id' => $this->dueno->id, 'tabla' => 'orders', 'registro_id' => $pedido_sembrado, 'lote' => 'test', 'created_at' => Carbon::now()]);

        Artisan::call('prueba:barrido');
        $this->assertSame(0, PruebaHito::where('owner_id', $this->dueno->id)->where('tipo', 'prueba.tienda_pedido')->count(), 'Un pedido de la semilla no es un hito.');

        DB::table('orders')->insert([
            'status' => 'unconfirmed', 'deliver' => 0, 'buyer_id' => 1, 'order_status_id' => 1,
            'user_id' => $this->dueno->id, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);

        Artisan::call('prueba:barrido');
        Artisan::call('prueba:barrido');

        $this->assertSame(1, PruebaHito::where('owner_id', $this->dueno->id)->where('tipo', 'prueba.tienda_pedido')->count());
    }

    public function test_el_barrido_registra_el_vencimiento_una_sola_vez()
    {
        $this->prender_la_prueba();
        DB::table('users')->where('id', $this->dueno->id)->update(['expired_at' => Carbon::now()->subMinute()]);

        Artisan::call('prueba:barrido');
        Artisan::call('prueba:barrido');

        $this->assertSame(1, PruebaHito::where('owner_id', $this->dueno->id)->where('tipo', 'prueba.vencida')->count());
    }

    public function test_el_barrido_sin_modo_prueba_no_registra_nada()
    {
        DB::table('users')->where('id', $this->dueno->id)->update(['expired_at' => Carbon::now()->subMinute()]);

        Artisan::call('prueba:barrido');

        $this->assertSame(0, PruebaHito::where('owner_id', $this->dueno->id)->count());
    }
}
