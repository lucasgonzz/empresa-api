<?php

namespace Tests\Feature\SugerenciasCompras;

use App\Jobs\GeneratePurchaseSuggestionChunksJob;
use App\Models\Address;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Caja;
use App\Models\CreditAccount;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoCaja;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\PurchaseSuggestion;
use App\Models\PurchaseSuggestionArticle;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión sugerencias-compra-proveedores — archivo 5: el pipeline completo
 * (GeneratePurchaseSuggestionChunksJob + ProcessPurchaseSuggestionChunkJob),
 * la prioridad, el contexto financiero y el comando compras:generar.
 *
 * 🔴 El test que más importa de todo el archivo es
 * la_plata_nunca_recorta_las_cantidades_sugeridas(): D9 es una decisión
 * explícita de Lucas — el contexto financiero (deuda, cajas) es puramente
 * informativo y JAMÁS puede cambiar una cantidad_sugerida.
 */
class Pipeline_y_prioridad_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea la generación automática. */
    const SLUG = 'sugerencias_compras';

    /** @var User */
    protected $comercio;

    /** @var Address */
    protected $deposito;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio pipeline P5',
            'email'    => 'sugerencias-compras-p5-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->deposito = Address::create([
            'street'  => 'Deposito pipeline P5',
            'user_id' => $this->comercio->id,
        ]);

        // El comando compras:generar opera sobre el dueño de la instancia.
        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * @param array $overrides
     * @return PurchaseSuggestion
     */
    protected function crear_suggestion(array $overrides = [])
    {
        return PurchaseSuggestion::create(array_merge([
            'user_id'                 => $this->comercio->id,
            'status'                  => 'pendiente',
            'origen_generacion'       => 'manual',
            'dias_punto_pedido'       => 15,
            'dias_cobertura_objetivo' => 30,
            'dias_lead_time'          => 7,
            'dias_vigencia_oferta'    => 120,
        ], $overrides));
    }

    /**
     * @param string $nombre
     * @param float $stock
     * @param float $stock_min
     * @return Article
     */
    protected function articulo_con_minimo($nombre, $stock, $stock_min)
    {
        $article = Article::create(['name' => $nombre, 'user_id' => $this->comercio->id]);

        $article->addresses()->attach($this->deposito->id, [
            'amount'    => $stock,
            'stock_min' => $stock_min,
        ]);

        return $article;
    }

    /**
     * @param string $nombre
     * @param float $stock
     * @param float $unidades_recientes
     * @param int $dias_atras
     * @return Article
     */
    protected function articulo_con_venta($nombre, $stock, $unidades_recientes, $dias_atras = 10)
    {
        $article = Article::create(['name' => $nombre, 'user_id' => $this->comercio->id]);

        $article->addresses()->attach($this->deposito->id, ['amount' => $stock]);

        $sale = Sale::create([
            'user_id'    => $this->comercio->id,
            'created_at' => now()->subDays($dias_atras),
        ]);

        ArticlePurchase::create([
            'sale_id'    => $sale->id,
            'article_id' => $article->id,
            'address_id' => $this->deposito->id,
            'amount'     => $unidades_recientes,
            'created_at' => now()->subDays($dias_atras),
        ]);

        return $article;
    }

    /**
     * @param Article $article
     * @param Provider $provider
     * @param int $costo_pivot article_provider.cost es INTEGER.
     * @return void
     */
    protected function dar_titular($article, $provider, $costo_pivot)
    {
        $article->provider_id = $provider->id;
        $article->save();

        $article->providers()->attach($provider->id, ['cost' => $costo_pivot]);
    }

    /**
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Sugerencias de compra a proveedores',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function la_corrida_completa_asigna_prioridad_1_a_n_sin_huecos_ni_repetidos_con_nulls_al_final()
    {
        $urgente = $this->articulo_con_venta('Urgente P5', 2, 45);    // cobertura 4
        $medio   = $this->articulo_con_venta('Medio P5', 2, 22.5);    // cobertura 8
        $dormido = $this->articulo_con_minimo('Dormido P5', 2, 10);   // cobertura null (sin ventas)

        $suggestion = $this->crear_suggestion();
        (new GeneratePurchaseSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();

        $this->assertEquals('terminado', $suggestion->status);

        $prioridades = PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion->id)
            ->orderBy('prioridad')
            ->pluck('prioridad')
            ->toArray();

        $this->assertEquals(
            range(1, count($prioridades)),
            $prioridades,
            'La prioridad tiene que ser 1..N sin huecos ni repetidos.'
        );

        $linea = function ($article) use ($suggestion) {
            return PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion->id)
                ->where('article_id', $article->id)
                ->first();
        };

        $this->assertEquals(1, $linea($urgente)->prioridad, 'Menor cobertura (más urgente) va primero.');
        $this->assertEquals(2, $linea($medio)->prioridad);
        $this->assertEquals(3, $linea($dormido)->prioridad, 'Cobertura null (infinita) va al final.');
    }

    /**
     * Re-entrancia: con $tries = 3, un reintento (o un segundo dispatch de la
     * misma sugerencia) tiene que volver a calcular desde cero, no apilarse.
     *
     * @group sugerencias-compras
     * @test
     */
    public function correr_el_pipeline_dos_veces_no_duplica_lineas_ni_contadores()
    {
        $this->articulo_con_minimo('Reentrante P5', 2, 10);

        $suggestion = $this->crear_suggestion();
        (new GeneratePurchaseSuggestionChunksJob($suggestion->id))->handle();

        $lineas_primera_corrida = PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion->id)->count();
        $this->assertGreaterThan(0, $lineas_primera_corrida);

        (new GeneratePurchaseSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();

        $this->assertEquals(
            $lineas_primera_corrida,
            PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion->id)->count(),
            'Re-ejecutar el pipeline tiene que re-insertar desde cero, no duplicar las líneas.'
        );
        $this->assertEquals('terminado', $suggestion->status);
        $this->assertEquals($suggestion->total_chunks, $suggestion->processed_chunks);

        $prioridades = PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion->id)
            ->orderBy('prioridad')->pluck('prioridad')->toArray();
        $this->assertEquals(range(1, count($prioridades)), $prioridades);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function failed_no_pisa_una_corrida_que_ya_quedo_terminada()
    {
        $terminada = $this->crear_suggestion(['status' => 'terminado']);

        (new GeneratePurchaseSuggestionChunksJob($terminada->id))->failed(new \Exception('carrera improbable'));

        $terminada->refresh();
        $this->assertEquals('terminado', $terminada->status, 'failed() no puede pisar una corrida que ya terminó bien.');
        $this->assertNull($terminada->error_mensaje);

        // Contraparte: si NO estaba terminada, failed() sí tiene que marcar error.
        $pendiente = $this->crear_suggestion(['status' => 'pendiente']);
        (new GeneratePurchaseSuggestionChunksJob($pendiente->id))->failed(new \Exception('fallo real'));

        $pendiente->refresh();
        $this->assertEquals('error', $pendiente->status);
        $this->assertEquals('fallo real', $pendiente->error_mensaje);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function el_contexto_financiero_lee_credit_accounts_saldo_tratando_null_como_cero_y_solo_suma_cajas_en_pesos()
    {
        $proveedor = Provider::create(['name' => 'Proveedor contexto P5', 'user_id' => $this->comercio->id]);
        $articulo = $this->articulo_con_minimo('Insumo contexto P5', 2, 10);
        $this->dar_titular($articulo, $proveedor, 500);

        // Deuda en pesos con saldo NULL: tiene que leerse como 0.0, no romper.
        CreditAccount::create([
            'model_name' => 'provider', 'model_id' => $proveedor->id,
            'moneda_id'  => 1, 'saldo' => null, 'user_id' => $this->comercio->id,
        ]);
        // Deuda en dólares real.
        CreditAccount::create([
            'model_name' => 'provider', 'model_id' => $proveedor->id,
            'moneda_id'  => 2, 'saldo' => 300.5, 'user_id' => $this->comercio->id,
        ]);

        // Caja en pesos con 1000 disponibles.
        $caja_pesos = Caja::create(['num' => 91001, 'user_id' => $this->comercio->id, 'moneda_id' => 1]);
        MovimientoCaja::create([
            'caja_id' => $caja_pesos->id, 'apertura_caja_id' => 1,
            'ingreso' => 1000, 'egreso' => null,
        ]);

        // Caja en dólares con un ingreso enorme: NO puede sumar al disponible en pesos.
        $caja_dolares = Caja::create(['num' => 91002, 'user_id' => $this->comercio->id, 'moneda_id' => 2]);
        MovimientoCaja::create([
            'caja_id' => $caja_dolares->id, 'apertura_caja_id' => 1,
            'ingreso' => 999999, 'egreso' => null,
        ]);

        $suggestion = $this->crear_suggestion();
        (new GeneratePurchaseSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();

        $this->assertEquals('terminado', $suggestion->status);
        $contexto = json_decode($suggestion->contexto_financiero, true);
        $this->assertIsArray($contexto);

        $this->assertEqualsWithDelta(
            1000.0,
            (float) $contexto['caja_disponible_pesos'],
            0.01,
            'La caja en dólares no puede sumar al disponible informado en pesos.'
        );

        $fila_proveedor = null;
        foreach ($contexto['proveedores'] as $p) {
            if ((int) $p['provider_id'] === $proveedor->id) {
                $fila_proveedor = $p;
            }
        }

        $this->assertNotNull($fila_proveedor);
        $this->assertEqualsWithDelta(0.0, (float) $fila_proveedor['deuda_pesos'], 0.0001, 'credit_accounts.saldo NULL se lee como 0.0.');
        $this->assertEqualsWithDelta(300.5, (float) $fila_proveedor['deuda_dolares'], 0.0001);
    }

    /**
     * 🔴 EL TEST QUE MÁS IMPORTA DE ESTE ARCHIVO (D9, decisión explícita de
     * Lucas): se corre la MISMA sugerencia (mismo artículo, mismo proveedor,
     * mismo stock) dos veces — una sin ninguna deuda ni caja, otra con una
     * deuda gigante y la caja en cero — y las cantidades sugeridas tienen que
     * salir IDÉNTICAS. La plata es contexto informativo, nunca un recorte.
     *
     * @group sugerencias-compras
     * @test
     */
    public function la_plata_nunca_recorta_las_cantidades_sugeridas()
    {
        $proveedor = Provider::create(['name' => 'Proveedor plata no recorta P5', 'user_id' => $this->comercio->id]);
        $articulo = $this->articulo_con_minimo('Insumo plata no recorta P5', 2, 10);
        $this->dar_titular($articulo, $proveedor, 500);

        // Corrida base: sin ninguna deuda ni caja cargada.
        $suggestion_base = $this->crear_suggestion();
        (new GeneratePurchaseSuggestionChunksJob($suggestion_base->id))->handle();

        $linea_base = PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion_base->id)
            ->where('article_id', $articulo->id)->first();
        $this->assertNotNull($linea_base);

        // Deuda gigante con el proveedor + caja en pesos en cero.
        CreditAccount::create([
            'model_name' => 'provider', 'model_id' => $proveedor->id,
            'moneda_id'  => 1, 'saldo' => 50000000, 'user_id' => $this->comercio->id,
        ]);
        $caja_en_cero = Caja::create(['num' => 91101, 'user_id' => $this->comercio->id, 'moneda_id' => 1]);
        MovimientoCaja::create([
            'caja_id' => $caja_en_cero->id, 'apertura_caja_id' => 1,
            'ingreso' => null, 'egreso' => null,
        ]);

        // Corrida con deuda: MISMO artículo, mismo stock, mismo titular.
        $suggestion_con_deuda = $this->crear_suggestion();
        (new GeneratePurchaseSuggestionChunksJob($suggestion_con_deuda->id))->handle();

        $linea_con_deuda = PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion_con_deuda->id)
            ->where('article_id', $articulo->id)->first();
        $this->assertNotNull($linea_con_deuda);

        // Confirmamos que la corrida 2 SÍ vio la deuda gigante (si esto
        // fallara, la aserción de abajo no probaría absolutamente nada).
        $contexto = json_decode($suggestion_con_deuda->fresh()->contexto_financiero, true);
        $deuda_vista = 0.0;
        foreach ($contexto['proveedores'] as $p) {
            if ((int) $p['provider_id'] === $proveedor->id) {
                $deuda_vista = (float) $p['deuda_pesos'];
            }
        }
        $this->assertGreaterThanOrEqual(50000000.0, $deuda_vista, 'Precondición: el contexto tiene que haber capturado la deuda gigante.');
        $this->assertEqualsWithDelta(0.0, (float) $contexto['caja_disponible_pesos'], 0.01, 'Precondición: la caja tiene que haber quedado en cero.');

        // Y ACÁ está lo que importa: las cantidades (y todo lo demás del
        // cálculo) son IDÉNTICAS entre las dos corridas.
        $this->assertEquals(
            (float) $linea_base->cantidad_sugerida,
            (float) $linea_con_deuda->cantidad_sugerida,
            'La deuda y la caja en cero NO pueden cambiar la cantidad sugerida: D9 es una decisión explícita, no un descuido.'
        );
        $this->assertEquals((float) $linea_base->stock_global, (float) $linea_con_deuda->stock_global);
        $this->assertEquals((float) $linea_base->costo_estimado, (float) $linea_con_deuda->costo_estimado);
        $this->assertEquals($linea_base->provider_id, $linea_con_deuda->provider_id);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function compras_generar_sin_extension_no_genera_ni_con_force()
    {
        Queue::fake();

        // A propósito SIN dar_extension().
        $this->comercio->sugerencias_compras_periodicidad = 'diaria';
        $this->comercio->save();

        $this->artisan('compras:generar', ['--force' => true])->assertExitCode(0);

        $this->assertEquals(
            0,
            PurchaseSuggestion::where('user_id', $this->comercio->id)->count(),
            'La extensión es el gate de venta: sin ella no se genera nada, ni con --force.'
        );
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function compras_generar_con_periodicidad_nunca_no_genera()
    {
        Queue::fake();
        $this->dar_extension();

        // 'nunca' es el default de la migración; se explicita igual.
        $this->comercio->sugerencias_compras_periodicidad = 'nunca';
        $this->comercio->save();

        $this->artisan('compras:generar')->assertExitCode(0);

        $this->assertEquals(0, PurchaseSuggestion::where('user_id', $this->comercio->id)->count());
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function compras_generar_force_saltea_la_periodicidad()
    {
        Queue::fake();
        $this->dar_extension();

        $this->comercio->sugerencias_compras_periodicidad = 'nunca';
        $this->comercio->save();

        $this->artisan('compras:generar', ['--force' => true])->assertExitCode(0);

        $this->assertEquals(1, PurchaseSuggestion::where('user_id', $this->comercio->id)->count());
        $this->assertEquals(
            'automatica',
            PurchaseSuggestion::where('user_id', $this->comercio->id)->first()->origen_generacion
        );
        Queue::assertPushed(GeneratePurchaseSuggestionChunksJob::class);
    }

    /**
     * La marca se escribe al CREAR la sugerencia, no al terminar: por eso
     * queda puesta aunque Queue::fake() impida que el job corra.
     *
     * @group sugerencias-compras
     * @test
     */
    public function compras_generar_marca_la_ultima_generacion_al_crear_no_al_terminar()
    {
        Queue::fake();
        $this->dar_extension();

        $this->comercio->sugerencias_compras_periodicidad = 'diaria';
        $this->comercio->sugerencias_compras_ultima_generacion_at = now()->subDay();
        $this->comercio->save();

        $this->artisan('compras:generar')->assertExitCode(0);

        $this->comercio->refresh();
        $this->assertTrue(
            \Carbon\Carbon::parse($this->comercio->sugerencias_compras_ultima_generacion_at)->isToday()
        );

        Queue::assertPushed(GeneratePurchaseSuggestionChunksJob::class);
        // El job todavía no corrió (Queue::fake lo intercepta entero) y la
        // marca ya quedó puesta: no depende de que el cálculo termine.
        $suggestion = PurchaseSuggestion::where('user_id', $this->comercio->id)->first();
        $this->assertEquals('pendiente', $suggestion->status);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function compras_generar_corrido_dos_veces_el_mismo_dia_no_duplica()
    {
        Queue::fake();
        $this->dar_extension();

        $this->comercio->sugerencias_compras_periodicidad = 'diaria';
        $this->comercio->sugerencias_compras_ultima_generacion_at = now()->subDay();
        $this->comercio->save();

        $this->artisan('compras:generar')->assertExitCode(0);
        $this->artisan('compras:generar')->assertExitCode(0);

        $this->assertEquals(
            1,
            PurchaseSuggestion::where('user_id', $this->comercio->id)->count(),
            'La segunda corrida del mismo día no puede crear otra sugerencia.'
        );
    }

    /**
     * Retención: borra (cabecera + líneas) las automáticas de más de 30 días
     * SIN órdenes de compra asociadas; lo usado y lo manual queda siempre.
     *
     * @group sugerencias-compras
     * @test
     */
    public function la_retencion_borra_las_automaticas_viejas_sin_ordenes_y_respeta_el_resto()
    {
        Queue::fake();
        $this->dar_extension();

        $this->comercio->sugerencias_compras_periodicidad = 'diaria';
        $this->comercio->sugerencias_compras_ultima_generacion_at = now()->subDay();
        $this->comercio->save();

        $hace_40_dias = now()->subDays(40);

        // Automática vieja SIN órdenes: tiene que desaparecer con sus líneas.
        $borrable = $this->crear_suggestion([
            'status' => 'terminado', 'origen_generacion' => 'automatica', 'created_at' => $hace_40_dias,
        ]);
        PurchaseSuggestionArticle::create([
            'purchase_suggestion_id' => $borrable->id,
            'article_id'             => 1,
            'cantidad_sugerida'      => 5,
        ]);

        // Automática vieja CON una orden de compra generada: queda para siempre.
        $usada = $this->crear_suggestion([
            'status' => 'terminado', 'origen_generacion' => 'automatica', 'created_at' => $hace_40_dias,
        ]);
        $proveedor = Provider::create(['name' => 'Proveedor retencion P5', 'user_id' => $this->comercio->id]);
        ProviderOrder::create([
            'purchase_suggestion_id' => $usada->id,
            'provider_id'            => $proveedor->id,
            'user_id'                => $this->comercio->id,
        ]);

        // Manual vieja: no se toca jamás, tenga la edad que tenga.
        $manual = $this->crear_suggestion([
            'status' => 'terminado', 'origen_generacion' => 'manual', 'created_at' => $hace_40_dias,
        ]);

        $this->artisan('compras:generar')->assertExitCode(0);

        $this->assertNull(PurchaseSuggestion::find($borrable->id), 'La automática vieja sin órdenes tiene que borrarse.');
        $this->assertEquals(
            0,
            PurchaseSuggestionArticle::where('purchase_suggestion_id', $borrable->id)->count(),
            'Las líneas van con la cabecera: borrar solo la cabecera las dejaría huérfanas.'
        );
        $this->assertNotNull(PurchaseSuggestion::find($usada->id), 'Con una orden de compra generada, se queda para siempre.');
        $this->assertNotNull(PurchaseSuggestion::find($manual->id), 'Las manuales no entran en la retención jamás.');
    }
}
