<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AltaBebidasIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\BusquedaImagenBebidaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/** Ticket #117: flujo real de propuesta/confirmación, sin servicios externos ni datos productivos. */
class AltaBebidasTest extends EmpresaTestCase
{
    protected $dueno;
    protected $conversacion;
    protected $mensaje;
    protected $datos;
    protected $respuesta_http = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function () { return Http::response($this->respuesta_http); });
        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
        $this->dueno->agente_alta_bebidas = true;
        $this->dueno->agente_confianza = 'directo';
        $this->dueno->listas_de_precio = 0;
        $this->dueno->save();
        foreach (['agentes_ia', 'vinoteca'] as $slug) {
            // slug no está en el $fillable de ExtencionEmpresa: firstOrCreate tiraba MassAssignmentException
            // cuando la base de testing no traía la extensión, y ningún caso de la clase llegaba a correr.
            $e = ExtencionEmpresa::where('slug', $slug)->first() ?: ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
            $this->dueno->extencions()->syncWithoutDetaching([$e->id]);
        }
        $this->dueno->load('extencions');
        $this->actingAs($this->dueno, 'web');
        $this->conversacion = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
        $this->mensaje = AiMessage::create(['ai_conversation_id' => $this->conversacion->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);
        $this->datos = ['name' => 'Bebida de prueba ticket117', 'cost' => 7500, 'percentage_gain' => 25, 'presentacion' => 1,
            'provider_id' => $this->catalogo('providers', 'Proveedor ticket117'),
            'category_id' => $this->catalogo('categories', 'Bebidas ticket117'),
            'bodega_id' => $this->catalogo('bodegas', 'Bodega ticket117'),
            'cepa_id' => $this->catalogo('cepas', 'Cepa ticket117'),
            'aplicar_iva' => false];
        Catalogo::olvidar();
    }

    /** La carpeta del disco `local` propio de este test (ver disco_local_propio()). */
    protected $raiz_local_propia = null;

    /**
     * El disco `local` en una carpeta PROPIA de este test, en vez de Storage::fake('local').
     *
     * 🔴 Storage::fake('local') usa SIEMPRE la misma carpeta (storage/framework/testing/disks/local) y la
     * VACÍA al empezar. Si otro proceso de tests del mismo slot hace su propio Storage::fake('local') entre
     * que buscar_imagen_de_bebida guarda la foto y la confirmación, la foto desaparece y la confirmación da
     * 422 «No pude leer esa foto» (medido el 16/11/2026: 3 de 6 corridas con otra suite andando al lado).
     * Una carpeta única por test no la comparte nadie.
     */
    protected function disco_local_propio()
    {
        $this->raiz_local_propia = storage_path('framework/testing/disks/local-' . str_replace('.', '', uniqid('alta-bebidas-', true)));

        \Illuminate\Support\Facades\Storage::set('local', \Illuminate\Support\Facades\Storage::createLocalDriver(['root' => $this->raiz_local_propia]));
    }

    protected function tearDown(): void
    {
        if (!is_null($this->raiz_local_propia)) {
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->raiz_local_propia);
        }

        parent::tearDown();
    }

    protected function catalogo($tabla, $nombre)
    {
        return DB::table($tabla)->insertGetId(['name' => $nombre, 'user_id' => $this->dueno->id]);
    }

    protected function proponer(array $cambios = [], array $bebida = [], array $extras = [])
    {
        $datos = array_replace($this->datos, $cambios);
        $datos['bebida'] = array_merge(['es_vino' => true, 'botellas_por_caja' => 6, 'sin_imagen' => true], $bebida);
        $input = array_merge(['entidad' => 'article', 'datos' => $datos], $extras);
        $r = HerramientasDeCarga::ejecutar('proponer_alta', $input, $this->conversacion, $this->mensaje);
        $this->assertFalse($r['is_error'], $r['content']);
        return json_decode($r['content'], true);
    }

    protected function accion()
    {
        return AiMessageAction::where('ai_conversation_id', $this->conversacion->id)->where('estado', 'propuesta')->latest('id')->firstOrFail();
    }

    protected function confirmar($id)
    {
        $this->mensaje->estado = 'listo';
        $this->mensaje->save();
        $this->actingAs($this->dueno->fresh(), 'sanctum');
        return $this->postJson('api/ai-conversations/'.$this->conversacion->id.'/acciones/'.$id.'/confirmar');
    }

    public function test_vino_completo_espera_un_solo_si_aun_en_modo_directo_y_guarda_catalogos_existentes()
    {
        $antes = DB::table('bodegas')->count();
        $this->proponer();
        $accion = $this->accion();
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $this->conversacion->id)->count());
        $this->assertFalse(Article::where('name', $this->datos['name'])->exists());
        $this->confirmar($accion->id)->assertStatus(200);
        $a = Article::where('user_id', $this->dueno->id)->where('name', $this->datos['name'])->firstOrFail();
        foreach (['provider_id', 'bodega_id', 'cepa_id', 'presentacion'] as $campo) $this->assertEquals($this->datos[$campo], $a->$campo);
        $this->assertSame($antes, DB::table('bodegas')->count());
        $this->confirmar($accion->id);
        $this->assertSame(1, Article::where('user_id', $this->dueno->id)->where('name', $this->datos['name'])->count());
    }

    public function test_otra_bebida_va_sin_cepa_y_rechaza_una_cepa_sobrante()
    {
        $r = $this->proponer([], ['es_vino' => false]);
        $this->assertArrayHasKey('error', $r);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $this->conversacion->id)->count());
        $this->proponer(['cepa_id' => null], ['es_vino' => false]);
        $this->confirmar($this->accion()->id)->assertStatus(200);
        $this->assertNull(Article::where('name', $this->datos['name'])->firstOrFail()->cepa_id);
    }

    public function test_ambiguedad_y_catalogo_de_otro_dueno_no_crean_nada()
    {
        $this->catalogo('bodegas', 'Bodega ticket117 otra');
        $r = $this->proponer(['bodega_id' => 'Bodega ticket117']);
        // La coincidencia exacta es inequívoca; un prefijo que tiene dos candidatas pregunta.
        $r = $this->proponer(['bodega_id' => 'Bodega ticket']);
        $this->assertArrayHasKey('faltan', $r);
        $ajena = DB::table('bodegas')->insertGetId(['name' => 'Bodega ajena117', 'user_id' => $this->dueno->id + 900000]);
        $r = $this->proponer(['bodega_id' => $ajena]);
        $this->assertArrayHasKey('faltan', $r);
    }

    public function test_cajas_se_convierten_una_vez_y_se_conservan_al_corregir()
    {
        $deposito = DB::table('addresses')->where('user_id', $this->dueno->id)->first();
        $extras = $deposito ? ['deposito' => $deposito->street] : [];
        $this->proponer([], ['cajas' => 4, 'cajas_confirmadas' => true], $extras);
        $accion = $this->accion();
        $this->assertEquals(24, $accion->datos['extras']['stock_inicial']['cantidad']);
        $this->proponer(['name' => 'Bebida corregida ticket117'], [], ['reemplaza_a' => $accion->id]);
        $nueva = $this->accion();
        $this->assertEquals(24, $nueva->datos['extras']['stock_inicial']['cantidad']);
        $this->confirmar($nueva->id)->assertStatus(200);
        $this->assertEquals(24, Article::where('name', 'Bebida corregida ticket117')->firstOrFail()->stock);
    }

    public function test_cajas_no_confirmadas_y_apagar_entre_propuesta_y_ejecucion_no_crean_articulo()
    {
        $r = $this->proponer([], ['cajas' => 4]);
        $this->assertArrayHasKey('faltan', $r);
        $this->proponer();
        $id = $this->accion()->id;
        $this->dueno->agente_alta_bebidas = false;
        $this->dueno->save();
        $this->confirmar($id)->assertStatus(422);
        $this->assertFalse(Article::where('name', $this->datos['name'])->exists());
    }

    public function test_opcion_apagada_conserva_alta_generica_y_no_filtra_config_a_otro_negocio()
    {
        $this->dueno->agente_alta_bebidas = false;
        $this->dueno->save();
        $c = ContextoDeCargaIa::de_la_conversacion($this->conversacion);
        $this->assertArrayNotHasKey('alta_bebidas', Catalogo::que_puedo_cargar('article', $c));
        $r = HerramientasDeCarga::ejecutar('proponer_alta', ['entidad' => 'article', 'datos' => $this->datos], $this->conversacion, $this->mensaje);
        $this->assertFalse($r['is_error']);
        $this->assertTrue(Article::where('user_id', $this->dueno->id)->where('name', $this->datos['name'])->exists());
    }

    public function test_config_opcional_se_preserva_y_empleado_no_la_puede_activar()
    {
        $this->actingAs($this->dueno, 'sanctum')->putJson('api/user/asistente-config', ['confianza' => 'cauteloso', 'pensamiento' => 'agil'])->assertStatus(200);
        $this->assertTrue((bool) $this->dueno->fresh()->agente_alta_bebidas);
        $empleado = User::forceCreate(['name' => 'Empleado117', 'email' => uniqid().'@test.local', 'password' => 'sin-login', 'owner_id' => $this->dueno->id]);
        $this->actingAs($empleado, 'sanctum')->putJson('api/user/asistente-config', ['confianza' => 'cauteloso', 'pensamiento' => 'agil', 'alta_bebidas' => true])->assertStatus(403);
    }

    public function test_busqueda_sin_habilitacion_no_consume_servicios_ni_inventa_imagen()
    {
        $this->dueno->agente_alta_bebidas = false;
        $this->dueno->save();
        $r = BusquedaImagenBebidaIaHelper::buscar($this->dueno->id, $this->conversacion, ['nombre' => 'Bebida117'], $this->mensaje);
        $this->assertArrayHasKey('error', $r);
        Http::assertNothingSent();
    }

    public function test_accesorio_con_opcion_activa_conserva_alta_generica_sin_campos_de_bebida()
    {
        $datos = ['name' => 'Sacacorchos ticket117', 'category_id' => $this->datos['category_id'], 'cost' => 100, 'percentage_gain' => 20];
        $r = HerramientasDeCarga::ejecutar('proponer_alta', ['entidad' => 'article', 'datos' => $datos], $this->conversacion, $this->mensaje);
        $this->assertFalse($r['is_error']);
        $this->assertTrue(Article::where('user_id', $this->dueno->id)->where('name', $datos['name'])->exists());
    }

    public function test_cancelar_propuesta_no_crea_articulo()
    {
        $this->proponer();
        $accion = $this->accion();
        $this->mensaje->estado = 'listo';
        $this->mensaje->save();
        $this->actingAs($this->dueno, 'sanctum')->postJson('api/ai-conversations/'.$this->conversacion->id.'/acciones/'.$accion->id.'/cancelar')->assertStatus(200);
        $this->assertFalse(Article::where('name', $this->datos['name'])->exists());
    }

    public function test_cambiar_cajas_y_botellas_recalcula_desde_la_decision_y_no_desde_stock_normalizado()
    {
        $deposito = DB::table('addresses')->where('user_id', $this->dueno->id)->first();
        $extras = $deposito ? ['deposito' => $deposito->street] : [];
        $this->proponer([], ['cajas' => 4, 'cajas_confirmadas' => true], $extras);
        $primera = $this->accion();
        $this->proponer([], ['cajas' => 3, 'cajas_confirmadas' => true, 'botellas_por_caja' => 12], ['reemplaza_a' => $primera->id]);
        $segunda = $this->accion();
        $this->assertEquals(36, $segunda->datos['extras']['stock_inicial']['cantidad']);
        $this->confirmar($segunda->id)->assertStatus(200);
        $this->assertEquals(36, Article::where('name', $this->datos['name'])->firstOrFail()->stock);
    }

    public function test_bebida_con_listas_reutiliza_el_margen_y_el_iva_del_motor_real()
    {
        $this->dueno->listas_de_precio = 1;
        $this->dueno->save();
        $datos = $this->datos;
        unset($datos['percentage_gain']);
        $datos['iva_id'] = DB::table('ivas')->where('percentage', 21)->value('id');
        $datos['aplicar_iva'] = true;
        $r = HerramientasDeCarga::ejecutar('proponer_alta', ['entidad' => 'article', 'datos' => array_merge($datos,
            ['bebida' => ['es_vino' => true, 'botellas_por_caja' => 6, 'sin_imagen' => true]]),
            'margenes_por_lista' => [['lista' => 'todas', 'margen' => 25]]], $this->conversacion, $this->mensaje);
        $this->assertFalse($r['is_error'], $r['content']);
        $this->confirmar($this->accion()->id)->assertStatus(200);
        $a = Article::where('name', $datos['name'])->firstOrFail();
        $this->assertEquals($datos['iva_id'], $a->iva_id);
        $pivotes = DB::table('article_price_type')->where('article_id', $a->id)->get();
        $this->assertNotEmpty($pivotes);
        foreach ($pivotes as $p) $this->assertEquals(25, $p->percentage);
    }

    public function test_cupo_agotado_no_sale_a_buscar_y_no_inventa_imagen()
    {
        \App\Models\GeocoderCounter::where('user_id', $this->dueno->id)->whereDate('created_at', today())->delete();
        \App\Models\GeocoderCounter::create(['user_id' => $this->dueno->id, 'counter' => 1000000]);
        $r = BusquedaImagenBebidaIaHelper::buscar($this->dueno->id, $this->conversacion, ['nombre' => 'Bebida sin cupo117'], $this->mensaje);
        $this->assertFalse($r['found']);
        $this->assertStringContainsString('cupo', $r['motivo']);
        $this->assertArrayNotHasKey('imagen_id', $r);
        Http::assertNothingSent();
    }

    public function test_imagen_dudosa_no_se_conserva_ni_se_ofrece_como_exacta()
    {
        config(['services.serper.api_key' => 'prueba117']);
        $this->respuesta_http = ['images' => [['imageUrl' => 'https://example.com/bebida.jpg', 'title' => 'Bebida', 'link' => 'https://example.com/ficha']]];
        \App\Models\GeocoderCounter::where('user_id', $this->dueno->id)->whereDate('created_at', today())->update(['counter' => 0]);
        $procesador = \Mockery::mock(\App\Services\ImageAssignment\CandidateImageProcessor::class);
        $procesador->shouldReceive('preparar')->once()->andReturn(['listas' => [['clave' => 0, 'base64' => 'imagen-no-se-envia', 'binario' => 'no-se-guarda']], 'candidatas' => [[]]]);
        $this->instance(\App\Services\ImageAssignment\CandidateImageProcessor::class, $procesador);
        $validador = \Mockery::mock(\App\Services\ArticleImageValidationService::class);
        $validador->shouldReceive('evaluar_candidatas')->once()->andReturn(['resultados' => [0 => ['es_el_producto' => 'dudoso', 'confianza' => 'high', 'problemas' => []]]]);
        $this->instance(\App\Services\ArticleImageValidationService::class, $validador);
        $r = BusquedaImagenBebidaIaHelper::buscar($this->dueno->id, $this->conversacion, ['nombre' => 'Bebida dudosa117'], $this->mensaje);
        $this->assertFalse($r['found']);
        $this->assertArrayNotHasKey('imagen_id', $r);
        $this->assertSame(0, \App\Models\AiMessageImagen::where('ai_message_id', $this->mensaje->id)->count());
    }

    /**
     * 🔴 POLÍTICA CAMBIADA (misión asistente-mejoras-4-dias, 16/11/2026, P6/V13). Hasta acá este test afirmaba
     * que la foto verificada de internet viajaba DENTRO del alta y se asignaba al confirmarla. Truvari (conv 19
     * y 24) recibió así un vino blanco en un Malbec y pidió «preguntame antes». Ahora el alta se crea sin la
     * foto y la foto va aparte, en su propia tarjeta, que la muestra y espera su propio sí (también en directo).
     */
    public function test_imagen_de_internet_va_aparte_del_alta_con_su_propia_confirmacion()
    {
        $this->disco_local_propio();
        config(['services.serper.api_key' => 'prueba117']);
        $this->respuesta_http = ['images' => [['imageUrl' => 'https://example.com/vino.jpg', 'title' => $this->datos['name'], 'link' => 'https://example.com/ficha']]];
        \App\Models\GeocoderCounter::where('user_id', $this->dueno->id)->whereDate('created_at', today())->update(['counter' => 0]);
        $archivo = \Illuminate\Http\UploadedFile::fake()->image('vino.jpg', 400, 400);
        $binario = file_get_contents($archivo->getPathname());
        $procesador = \Mockery::mock(\App\Services\ImageAssignment\CandidateImageProcessor::class);
        $procesador->shouldReceive('preparar')->once()->andReturn(['listas' => [['clave' => 0, 'base64' => base64_encode($binario), 'binario' => $binario]], 'candidatas' => [['pagina' => 'https://example.com/ficha']]]);
        $this->instance(\App\Services\ImageAssignment\CandidateImageProcessor::class, $procesador);
        $validador = \Mockery::mock(\App\Services\ArticleImageValidationService::class);
        $validador->shouldReceive('evaluar_candidatas')->once()->andReturn(['resultados' => [0 => ['es_el_producto' => 'si', 'confianza' => 'high', 'problemas' => []]]]);
        $this->instance(\App\Services\ArticleImageValidationService::class, $validador);
        $r = BusquedaImagenBebidaIaHelper::buscar($this->dueno->id, $this->conversacion, ['nombre' => $this->datos['name']], $this->mensaje);
        $this->assertTrue($r['found'], json_encode($r));
        $this->assertStringContainsString('NO va dentro del alta', $r['siguiente']);
        $alta = $this->proponer([], [], ['imagen_id' => $r['imagen_id']]);
        $this->assertSame($r['imagen_id'], $alta['foto_aparte']['imagen_id'], json_encode($alta));
        $this->assertStringContainsString('proponer_foto_articulo', $alta['foto_aparte']['como_seguir']);
        $accion = $this->accion();
        $this->assertArrayNotHasKey('imagen_id', $accion->datos['extras'] ?? [], 'La foto de internet no viaja en el alta.');
        $this->assertSame($r['imagen_id'], $accion->datos['bebida']['foto_web_aparte']);
        $this->assertContains('Foto', array_column($accion->presentacion['renglones'], 'etiqueta'));
        $this->confirmar($accion->id)->assertStatus(200);
        $articulo = Article::where('name', $this->datos['name'])->firstOrFail();
        $this->assertFalse($articulo->images()->exists(), 'El alta NO le pone la foto de internet.');

        // La foto aparte: su tarjeta la muestra y, aunque el dueño esté en directo, espera el sí.
        $foto = HerramientasDeCarga::ejecutar('proponer_foto_articulo', ['articulo_id' => $articulo->id, 'imagen_id' => $r['imagen_id']], $this->conversacion, $this->mensaje);
        $this->assertFalse($foto['is_error'], $foto['content']);
        $foto = json_decode($foto['content'], true);
        $this->assertTrue($foto['requiere_confirmacion'], json_encode($foto));
        $tarjeta = AiMessageAction::findOrFail($foto['tarjeta_id']);
        $this->assertSame('propuesta', $tarjeta->estado, 'Una foto de internet nunca se asigna sola.');
        $this->assertNotEmpty($tarjeta->presentacion['imagen_url']);
        $this->assertFalse($articulo->images()->exists());
        $this->confirmar($tarjeta->id)->assertStatus(200);
        $this->assertTrue($articulo->images()->exists());
    }

    public function test_candidata_de_otro_color_o_de_otra_cepa_se_descarta_por_su_titulo()
    {
        $this->assertTrue(BusquedaImagenBebidaIaHelper::contradice_la_bebida('Killka Malbec', 'Killka Sauvignon Blanc 750 ml'));
        $this->assertTrue(BusquedaImagenBebidaIaHelper::contradice_la_bebida('Killka Malbec', 'Killka vino blanco'));
        $this->assertTrue(BusquedaImagenBebidaIaHelper::contradice_la_bebida('Gran Enemigo Cabernet Franc', 'Gran Enemigo Malbec'));
        $this->assertFalse(BusquedaImagenBebidaIaHelper::contradice_la_bebida('Killka Malbec', 'Killka Malbec 750 | Vinoteca'));
        $this->assertFalse(BusquedaImagenBebidaIaHelper::contradice_la_bebida('Killka Malbec', ''));
        $this->assertFalse(BusquedaImagenBebidaIaHelper::contradice_la_bebida('Gran Enemigo Cabernet Franc', 'Gran Enemigo Cabernet Franc Gualtallary'));

        // Por el camino real: la única candidata es de un vino blanco y no llega ni a evaluarse.
        config(['services.serper.api_key' => 'prueba117']);
        $this->respuesta_http = ['images' => [['imageUrl' => 'https://example.com/b.jpg', 'title' => 'Killka Sauvignon Blanc', 'link' => 'https://example.com/b']]];
        \App\Models\GeocoderCounter::where('user_id', $this->dueno->id)->whereDate('created_at', today())->update(['counter' => 0]);
        $procesador = \Mockery::mock(\App\Services\ImageAssignment\CandidateImageProcessor::class);
        $procesador->shouldReceive('preparar')->once()->andReturn(['listas' => [['clave' => 0, 'base64' => 'x', 'binario' => 'x']], 'candidatas' => [['titulo' => 'Killka Sauvignon Blanc 750', 'pagina' => 'https://example.com/b']]]);
        $this->instance(\App\Services\ImageAssignment\CandidateImageProcessor::class, $procesador);
        $validador = \Mockery::mock(\App\Services\ArticleImageValidationService::class);
        $validador->shouldNotReceive('evaluar_candidatas');
        $this->instance(\App\Services\ArticleImageValidationService::class, $validador);
        $r = BusquedaImagenBebidaIaHelper::buscar($this->dueno->id, $this->conversacion, ['nombre' => 'Killka Malbec ticket117'], $this->mensaje);
        $this->assertFalse($r['found']);
        $this->assertArrayNotHasKey('imagen_id', $r);
    }

    public function test_botellas_por_caja_se_guardan_en_bulto_solo_con_la_propiedad_real_de_distribuidora()
    {
        $e = ExtencionEmpresa::firstOrCreate(['slug' => 'articulos_con_propiedades_de_distribuidora'], ['name' => 'Distribuidora']);
        $this->dueno->extencions()->syncWithoutDetaching([$e->id]);
        $this->proponer();
        $this->confirmar($this->accion()->id)->assertStatus(200);
        $a = Article::where('name', $this->datos['name'])->firstOrFail();
        $this->assertEquals(6, $a->unidades_por_bulto);
        $this->assertEquals(1, $a->presentacion);
    }

    public function test_correccion_sin_stock_y_sin_foto_no_repone_lo_quitado()
    {
        $deposito = DB::table('addresses')->where('user_id', $this->dueno->id)->first();
        $extras = $deposito ? ['deposito' => $deposito->street] : [];
        $this->proponer([], ['cajas' => 4, 'cajas_confirmadas' => true], $extras);
        $primera = $this->accion();
        $this->proponer([], ['sin_imagen' => false], ['reemplaza_a' => $primera->id, 'stock_inicial' => 0, 'imagen_id' => 0]);
        $segunda = $this->accion();
        $this->assertArrayNotHasKey('stock_inicial', $segunda->datos['extras'] ?? []);
        $this->assertArrayNotHasKey('imagen_id', $segunda->datos['extras'] ?? []);
        $this->assertArrayNotHasKey('cajas', $segunda->datos['bebida']);
        $this->confirmar($segunda->id)->assertStatus(200);
        $this->assertEquals(0, Article::where('name', $this->datos['name'])->firstOrFail()->stock);
        Http::assertNothingSent();
    }

    public function test_presentacion_mayor_a_uno_da_el_mismo_precio_que_crear()
    {
        $this->proponer(['presentacion' => 6]);
        $accion = $this->accion();
        $payload = $accion->datos['payload'];
        $payload['name'] = 'Referencia de Crear ticket117';
        $this->actingAs($this->dueno, 'sanctum')->postJson('api/article', $payload)->assertStatus(201);
        $this->confirmar($accion->id)->assertStatus(200);
        $manual = Article::where('name', $payload['name'])->firstOrFail();
        $asistente = Article::where('name', $this->datos['name'])->firstOrFail();
        $this->assertEquals(6, $asistente->presentacion);
        $this->assertEquals($manual->final_price, $asistente->final_price);
        $this->assertEquals($manual->cost, $asistente->cost);
    }

    public function test_nombre_generico_no_busca_ni_ofrece_foto()
    {
        $r = BusquedaImagenBebidaIaHelper::buscar($this->dueno->id, $this->conversacion, ['nombre' => 'vino tinto'], $this->mensaje);
        $this->assertFalse($r['found']);
        $this->assertStringContainsString('concreta', $r['motivo']);
        Http::assertNothingSent();
    }

    public function test_empleado_sin_permiso_no_puede_proponer_el_alta()
    {
        $empleado = User::forceCreate(['name' => 'Empleado sin permiso117', 'email' => uniqid().'@test.local', 'password' => 'sin-login', 'owner_id' => $this->dueno->id]);
        $this->conversacion->auth_user_id = $empleado->id;
        $this->conversacion->save();
        $r = $this->proponer();
        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsString('permiso', $r['error']);
        $this->assertFalse(Article::where('name', $this->datos['name'])->exists());
    }

    public function test_unidades_sueltas_revalidan_permiso_de_stock_antes_de_crear()
    {
        $deposito = DB::table('addresses')->where('user_id', $this->dueno->id)->first();
        $extras = ['stock_inicial' => 24];
        if ($deposito) $extras['deposito'] = $deposito->street;
        $this->proponer([], [], $extras);
        $datos = $this->accion()->datos;
        $empleado = User::forceCreate(['name' => 'Empleado sin stock117', 'email' => uniqid().'@test.local', 'password' => 'sin-login', 'owner_id' => $this->dueno->id]);
        $c = ContextoDeCargaIa::de_la_conversacion($this->conversacion, $empleado);
        try {
            AltaBebidasIaHelper::verificar($c, $datos);
            $this->fail('El stock sin permiso debe rechazarse antes de crear.');
        } catch (\App\Http\Controllers\Helpers\asistente_ia\AccionIaException $e) {
            $this->assertStringContainsString('permisos', $e->getMessage());
        }
        $this->assertFalse(Article::where('name', $this->datos['name'])->exists());
    }

    public function test_catalogos_nuevos_esperan_consentimiento_aun_en_directo()
    {
        foreach (['bodega' => 'bodegas', 'cepa' => 'cepas', 'provider' => 'providers'] as $entidad => $tabla) {
            $nombre = 'Nuevo '.$entidad.' con consentimiento117';
            $r = HerramientasDeCarga::ejecutar('proponer_alta', ['entidad' => $entidad, 'datos' => ['name' => $nombre]], $this->conversacion, $this->mensaje);
            $this->assertFalse($r['is_error'], $r['content']);
            $this->assertFalse(DB::table($tabla)->where('user_id', $this->dueno->id)->where('name', $nombre)->exists());
            $accion = $this->accion();
            $this->confirmar($accion->id)->assertStatus(200);
            $this->assertSame(1, DB::table($tabla)->where('user_id', $this->dueno->id)->where('name', $nombre)->count());
        }
    }

    public function test_proveedor_con_opcion_apagada_conserva_alta_directa_anterior()
    {
        $this->dueno->agente_alta_bebidas = false;
        $this->dueno->save();
        $nombre = 'Proveedor genérico anterior117';
        $r = HerramientasDeCarga::ejecutar('proponer_alta', ['entidad' => 'provider', 'datos' => ['name' => $nombre]], $this->conversacion, $this->mensaje);
        $this->assertFalse($r['is_error'], $r['content']);
        $this->assertSame(1, DB::table('providers')->where('user_id', $this->dueno->id)->where('name', $nombre)->count());
    }
    /**
     * Misión asistente-diario-20261117, M3: un alta SIN cajas (sin ingreso de stock) no puede decir que la caja
     * logística «se usó para convertir este ingreso»: no hubo ingreso. La tarjeta y el resultado dicen que se
     * vende por botella y que las N botellas por caja no quedaron como presentación de venta.
     */
    public function test_alta_sin_cajas_dice_que_se_vende_por_botella_en_la_tarjeta_y_en_el_resultado()
    {
        $this->proponer();
        $accion = $this->accion();
        $renglones = array_column($accion->presentacion['renglones'], 'valor', 'etiqueta');
        $this->assertSame('Se vende por botella: las 6 botellas por caja no se guardan como presentación de venta.', $renglones['Caja logística']);
        $this->assertStringNotContainsString('convertir este ingreso', json_encode($accion->presentacion, JSON_UNESCAPED_UNICODE));

        $this->confirmar($accion->id)->assertStatus(200);
        $texto = (string) AiMessageAction::findOrFail($accion->id)->resultado->texto;
        $this->assertStringContainsString('Se vende por botella: las 6 botellas por caja no quedaron guardadas como presentación de venta.', $texto);
        $this->assertStringNotContainsString('convertir este ingreso', $texto);
    }

    /** M3: con cajas, la tarjeta y el resultado siguen diciendo que la caja convirtió el ingreso de stock. */
    public function test_alta_con_cajas_conserva_el_texto_de_la_conversion()
    {
        $deposito = DB::table('addresses')->where('user_id', $this->dueno->id)->first();
        $extras = $deposito ? ['deposito' => $deposito->street] : [];
        $this->proponer([], ['cajas' => 2, 'cajas_confirmadas' => true], $extras);
        $accion = $this->accion();
        $renglones = array_column($accion->presentacion['renglones'], 'valor', 'etiqueta');
        $this->assertSame('Se usa para convertir este ingreso de stock; no modifica la presentación de venta.', $renglones['Caja logística']);

        $this->confirmar($accion->id)->assertStatus(200);
        $texto = (string) AiMessageAction::findOrFail($accion->id)->resultado->texto;
        $this->assertStringContainsString('La caja logística se usó solo para convertir este ingreso, no quedó configurada como presentación de venta.', $texto);
    }
    /**
     * M3 (revisión independiente): con la propiedad de distribuidora las botellas por caja SÍ se guardan, como
     * unidades por bulto. Sin cajas, la tarjeta y el resultado lo dicen así y no «no se guardan», que contradecía
     * el renglón «Unidades por bulto guardadas» de la misma tarjeta.
     */
    public function test_alta_sin_cajas_con_propiedad_de_distribuidora_dice_que_quedan_como_bulto()
    {
        $e = ExtencionEmpresa::firstOrCreate(['slug' => 'articulos_con_propiedades_de_distribuidora'], ['name' => 'Distribuidora']);
        $this->dueno->extencions()->syncWithoutDetaching([$e->id]);
        $this->proponer();
        $accion = $this->accion();
        $renglones = array_column($accion->presentacion['renglones'], 'valor', 'etiqueta');
        $this->assertSame('Se vende por botella; las 6 botellas por caja quedan como unidades por bulto, no como presentación de venta.', $renglones['Caja logística']);
        $this->assertSame('6', $renglones['Unidades por bulto guardadas']);
        $this->assertStringNotContainsString('no se guardan', json_encode($accion->presentacion, JSON_UNESCAPED_UNICODE));

        $this->confirmar($accion->id)->assertStatus(200);
        $texto = (string) AiMessageAction::findOrFail($accion->id)->resultado->texto;
        $this->assertStringContainsString('Se vende por botella; las 6 botellas por caja quedaron como unidades por bulto, no como presentación de venta.', $texto);
        $this->assertStringNotContainsString('no quedaron guardadas', $texto);
        $this->assertEquals(6, Article::where('name', $this->datos['name'])->firstOrFail()->unidades_por_bulto);
    }

    /**
     * Misión asistente-diario-20261121 (A2): una cerveza (no vino) puede ir SIN bodega y SIN proveedor cuando el
     * dueño lo dijo; sale con tarjeta que lo muestra, y el artículo queda sin ninguno de los dos.
     */
    public function test_cerveza_sin_bodega_ni_proveedor_sale_con_tarjeta_y_se_crea_sin_ellos()
    {
        $datos = $this->datos;
        unset($datos['bodega_id'], $datos['provider_id'], $datos['cepa_id']);
        $r = HerramientasDeCarga::ejecutar('proponer_alta', ['entidad' => 'article', 'datos' => array_merge($datos,
            ['bebida' => ['es_vino' => false, 'botellas_por_caja' => 12, 'sin_imagen' => true, 'sin_bodega' => true, 'sin_proveedor' => true]])],
            $this->conversacion, $this->mensaje);
        $this->assertFalse($r['is_error'], $r['content']);
        $accion = $this->accion();
        $this->assertArrayNotHasKey('bodega_id', $accion->datos['payload']);
        $this->assertArrayNotHasKey('provider_id', $accion->datos['payload']);
        $renglones = array_column($accion->presentacion['renglones'], 'valor', 'etiqueta');
        $this->assertSame('Sin bodega', $renglones['Bodega']);
        $this->assertSame('Sin proveedor', $renglones['Proveedor']);
        $this->confirmar($accion->id)->assertStatus(200);
        $a = Article::where('user_id', $this->dueno->id)->where('name', $this->datos['name'])->firstOrFail();
        $this->assertNull($a->bodega_id);
        $this->assertNull($a->provider_id);
    }

    /** A2: un vino sin bodega sigue pidiendo bodega, aunque el dueño diga «sin bodega». */
    public function test_vino_sin_bodega_sigue_pidiendo_bodega()
    {
        $datos = $this->datos;
        unset($datos['bodega_id']);
        $r = HerramientasDeCarga::ejecutar('proponer_alta', ['entidad' => 'article', 'datos' => array_merge($datos,
            ['bebida' => ['es_vino' => true, 'botellas_por_caja' => 6, 'sin_imagen' => true, 'sin_bodega' => true]])],
            $this->conversacion, $this->mensaje);
        $respuesta = json_decode($r['content'], true);
        $this->assertArrayHasKey('faltan', $respuesta);
        $this->assertContains('bodega existente', $respuesta['faltan']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $this->conversacion->id)->count());
    }

    /** A2: un vino puede ir sin proveedor si el dueño lo dice; la bodega y la cepa siguen. */
    public function test_vino_sin_proveedor_declarado_no_pide_proveedor()
    {
        $datos = $this->datos;
        unset($datos['provider_id']);
        $r = HerramientasDeCarga::ejecutar('proponer_alta', ['entidad' => 'article', 'datos' => array_merge($datos,
            ['bebida' => ['es_vino' => true, 'botellas_por_caja' => 6, 'sin_imagen' => true, 'sin_proveedor' => true]])],
            $this->conversacion, $this->mensaje);
        $this->assertFalse($r['is_error'], $r['content']);
        $this->assertArrayNotHasKey('faltan', json_decode($r['content'], true));
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $this->conversacion->id)->count());
    }

    /** A2: sin las claves nuevas, una bebida sin bodega ni proveedor sigue pidiendo ambos (compatibilidad). */
    public function test_sin_las_claves_nuevas_sigue_pidiendo_proveedor_y_bodega()
    {
        $datos = $this->datos;
        unset($datos['bodega_id'], $datos['provider_id'], $datos['cepa_id']);
        $r = HerramientasDeCarga::ejecutar('proponer_alta', ['entidad' => 'article', 'datos' => array_merge($datos,
            ['bebida' => ['es_vino' => false, 'botellas_por_caja' => 12, 'sin_imagen' => true]])],
            $this->conversacion, $this->mensaje);
        $respuesta = json_decode($r['content'], true);
        $this->assertArrayHasKey('faltan', $respuesta);
        $this->assertContains('proveedor existente', $respuesta['faltan']);
        $this->assertContains('bodega existente', $respuesta['faltan']);
    }

    /** A2: decir «sin proveedor» y mandar uno a la vez es una contradicción: no se carga nada. */
    public function test_sin_proveedor_con_proveedor_mandado_es_contradiccion()
    {
        $r = $this->proponer(['cepa_id' => null], ['es_vino' => false, 'sin_proveedor' => true]);
        $this->assertArrayHasKey('error', $r);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $this->conversacion->id)->count());
    }

    /**
     * Misión asistente-diario-20261121 (A1): el prompt de carga de un negocio con Vinoteca trae las reglas del
     * pedido a proveedor (costo base, un pedido entero, listado al final) y la regla general de no escribir el
     * precio manual; un negocio SIN vinoteca no recibe las de vinoteca.
     */
    public function test_el_prompt_trae_las_reglas_del_pedido_a_proveedor_solo_con_vinoteca()
    {
        $servicio = new \App\Services\AsistenteIa\AsistenteIaService();
        $prompt = $servicio->build_system_prompt($this->conversacion, $this->dueno->fresh(), true, false);
        $this->assertStringContainsString('es el COSTO BASE por unidad', $prompt);
        $this->assertStringContainsString('UNA sola confirmación', $prompt);
        $this->assertStringContainsString('PRECIO DE VENTA final por unidad', $prompt);
        $this->assertStringContainsString('NUNCA el campo de precio manual', $prompt);
        $this->assertStringContainsString('el margen va en', $prompt);

        $this->dueno->extencions()->detach(ExtencionEmpresa::where('slug', 'vinoteca')->value('id'));
        $sin = $servicio->build_system_prompt($this->conversacion, $this->dueno->fresh(), true, false);
        $this->assertStringNotContainsString('es el COSTO BASE por unidad', $sin);
        $this->assertStringContainsString('NUNCA el campo de precio manual', $sin);
    }
}
