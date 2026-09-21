<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-omnisciente (21/9/2026, bloque B) — las guardas de las cargas genéricas.
 *
 * Lo que protege:
 *
 * - 🔴 Un registro de OTRO dueño no se edita ni se borra, ni por la herramienta (error) ni por una
 *   tarjeta armada a mano con su id (422 sin escribir nada): los controllers resuelven por
 *   Model::find($id) pelado y esta es la tenencia que ellos no chequean.
 * - 🔴 Los cuatro tipos nuevos (alta, edicion, baja, venta) NUNCA se auto-confirman, ni con el
 *   dueño en "resuelto": no están en AUTO_CONFIRMABLES y sus `case` no pasan por
 *   quizas_auto_confirmar().
 * - `campos_que_no_quedaron` informa un campo que el controller normaliza: un margen 0 que
 *   ArticleController guarda como null (CriterioDePrecioHelper::normalizar).
 * - Un nombre ambiguo devuelve `faltan` con las opciones {id, nombre}; la coincidencia exacta gana.
 * - Un campo que no existe corta con `error` y la lista de campos; uno que la pantalla no carga al
 *   crear (el teléfono de una sucursal) también; un obligatorio que falta vuelve como `faltan`.
 * - Permisos y extensión: un empleado raso no puede; uno con admin_access sí; sin la extensión
 *   vinoteca no hay bodegas.
 * - Las cinco definiciones nuevas van al final, en orden, con su `case` en el mismo archivo.
 *
 * @group chat-ia
 */
class Guardas_de_las_cargas_genericas_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->service = new AsistenteIaService();

        Catalogo::olvidar();
    }

    /**
     * @param User|null $persona
     * @param User|null $owner
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($persona = null, $owner = null)
    {
        $persona = is_null($persona) ? $this->dueno : $persona;
        $owner = is_null($owner) ? $this->dueno : $owner;

        $conversation = AiConversation::create([
            'user_id'      => $owner->id,
            'auth_user_id' => $persona->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Cargame esto',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param string $herramienta
     * @param array $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param int $tarjeta_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /**
     * @return User
     */
    protected function otro_dueno()
    {
        return User::create([
            'name'     => 'Otro comercio P36',
            'email'    => 'otro-p36-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * 🔴 Tenencia: por la herramienta y por una tarjeta forjada.
     *
     * @test
     */
    public function un_registro_de_otro_dueno_no_se_edita_ni_se_borra_ni_por_id_ni_por_tarjeta_forjada()
    {
        $otro = $this->otro_dueno();
        $ajeno = Provider::create(['name' => 'Proveedor ajeno P36', 'user_id' => $otro->id, 'num' => 1, 'phone' => 'ajeno']);

        list($conversation, $assistant) = $this->conversacion();

        $edicion = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'provider',
            'registro' => $ajeno->id,
            'cambios'  => ['phone' => 'pisado'],
        ]);

        $this->assertFalse($edicion['ok']);
        $this->assertStringContainsString('No encontré', $edicion['error']);

        $baja = $this->herramienta($conversation, $assistant, 'proponer_baja', ['entidad' => 'provider', 'registro' => $ajeno->id]);

        $this->assertFalse($baja['ok']);
        $this->assertStringContainsString('No encontré', $baja['error']);

        // Por nombre tampoco: la búsqueda va por dueño.
        $por_nombre = $this->herramienta($conversation, $assistant, 'proponer_baja', ['entidad' => 'provider', 'registro' => 'Proveedor ajeno P36']);
        $this->assertFalse($por_nombre['ok']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        // Una tarjeta armada a mano con el id ajeno: el ejecutor vuelve a chequear la tenencia.
        $forjada = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $assistant->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => AiMessageAction::TIPO_BAJA,
            'clave'              => 'baja:provider:' . $ajeno->id,
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => ['entidad' => 'provider', 'operacion' => 'baja', 'id' => $ajeno->id, 'nombre' => 'Proveedor ajeno P36'],
            'presentacion'       => ['titulo' => 'Borrar proveedor: Proveedor ajeno P36', 'renglones' => [], 'aviso' => null],
        ]);

        $confirmacion = $this->confirmar($conversation, $assistant, $forjada->id);

        $confirmacion->assertStatus(422);
        $this->assertStringContainsString('ya no existe entre los tuyos', $confirmacion->json('model.error_mensaje'));
        $this->assertNull(DB::table('providers')->where('id', $ajeno->id)->value('deleted_at'), 'No se borró nada');
        $this->assertSame('ajeno', $ajeno->fresh()->phone);
    }

    /**
     * 🔴 Nunca se auto-confirman, los cuatro tipos.
     *
     * @test
     */
    public function ninguno_de_los_cuatro_tipos_nuevos_se_auto_confirma_con_el_dueno_en_resuelto()
    {
        foreach ([AiMessageAction::TIPO_ALTA, AiMessageAction::TIPO_EDICION, AiMessageAction::TIPO_BAJA, AiMessageAction::TIPO_VENTA] as $tipo) {
            $this->assertNotContains($tipo, HerramientasDeCarga::AUTO_CONFIRMABLES, $tipo);
        }

        // Y el despacho no pasa por quizas_auto_confirmar(): se lee el archivo, como el test 26.
        $contenido = file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php'));

        foreach (['proponer_alta', 'proponer_edicion', 'proponer_baja', 'proponer_venta'] as $herramienta) {
            $desde = strpos($contenido, "case '" . $herramienta . "':");
            $this->assertNotFalse($desde, $herramienta . ' no se despacha');
            $hasta = strpos($contenido, 'case ', $desde + 10);
            $bloque = substr($contenido, $desde, $hasta - $desde);
            $this->assertStringNotContainsString('quizas_auto_confirmar', $bloque, $herramienta . ' pasa por la auto-confirmación');
        }

        $this->dueno->agente_confianza = 'resuelto';
        $this->dueno->save();

        $categoria = Category::create(['name' => 'Rubro resuelto P36', 'user_id' => $this->dueno->id, 'num' => 903]);

        list($conversation, $assistant) = $this->conversacion();

        $propuestas = [
            $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'Marca resuelta P36']]),
            $this->herramienta($conversation, $assistant, 'proponer_edicion', ['entidad' => 'category', 'registro' => 'Rubro resuelto P36', 'cambios' => ['percentage_gain' => 12]]),
            $this->herramienta($conversation, $assistant, 'proponer_baja', ['entidad' => 'category', 'registro' => 'Rubro resuelto P36']),
        ];

        foreach ($propuestas as $respuesta) {
            $this->assertTrue($respuesta['ok'], json_encode($respuesta));
            $this->assertArrayNotHasKey('estado', $respuesta, 'Una respuesta con "estado" es la de confirmar_del_agente: pasó por la auto-confirmación.');
            $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        }

        $this->assertSame(0, DB::table('brands')->where('user_id', $this->dueno->id)->where('name', 'Marca resuelta P36')->count());
        $this->assertNull($categoria->fresh()->percentage_gain);
        $this->assertNotNull(Category::find($categoria->id));
    }

    /**
     * @test
     */
    public function campos_que_no_quedaron_informa_el_margen_cero_que_el_controller_guarda_como_null()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Artículo sin margen P36', 'cost' => 500, 'percentage_gain' => 0],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Artículo sin margen P36')->first();

        $this->assertNotNull($articulo);
        $this->assertNull($articulo->percentage_gain, 'ArticleController normaliza el 0 a null');

        $no_quedaron = $confirmacion->json('model.resultado.campos_que_no_quedaron');

        $this->assertCount(1, $no_quedaron);
        $this->assertSame('percentage_gain', $no_quedaron[0]['campo']);
        $this->assertSame('0 %', $no_quedaron[0]['pedido']);
        $this->assertSame('(vacío)', $no_quedaron[0]['quedo']);
    }

    /**
     * @test
     */
    public function un_nombre_ambiguo_devuelve_faltan_con_opciones_y_la_coincidencia_exacta_gana()
    {
        $norte = Provider::create(['name' => 'Ferretería P36 Norte', 'user_id' => $this->dueno->id, 'num' => 904]);
        $sur = Provider::create(['name' => 'Ferretería P36 Sur', 'user_id' => $this->dueno->id, 'num' => 905]);

        list($conversation, $assistant) = $this->conversacion();

        $ambigua = $this->herramienta($conversation, $assistant, 'proponer_baja', ['entidad' => 'provider', 'registro' => 'Ferretería P36']);

        $this->assertFalse($ambigua['ok']);
        $this->assertNull($ambigua['error']);
        $this->assertCount(1, $ambigua['faltan']);
        $this->assertStringContainsString('hay 2 que encajan', $ambigua['faltan'][0]);
        $this->assertSame(
            [['id' => $norte->id, 'nombre' => 'Ferretería P36 Norte'], ['id' => $sur->id, 'nombre' => 'Ferretería P36 Sur']],
            $ambigua['opciones']['provider']
        );
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        $exacta = $this->herramienta($conversation, $assistant, 'proponer_baja', ['entidad' => 'provider', 'registro' => 'ferretería p36 sur']);

        $this->assertTrue($exacta['ok'], json_encode($exacta));
        $this->assertSame($sur->id, AiMessageAction::find($exacta['tarjeta_id'])->datos['id']);

        $inexistente = $this->herramienta($conversation, $assistant, 'proponer_baja', ['entidad' => 'provider', 'registro' => 'Nave espacial P36']);

        $this->assertFalse($inexistente['ok']);
        $this->assertStringContainsString('No encontré', $inexistente['error']);

        // Una relación ambigua en un alta: dos categorías que encajan.
        Category::create(['name' => 'Ferre P36 A', 'user_id' => $this->dueno->id, 'num' => 906]);
        Category::create(['name' => 'Ferre P36 B', 'user_id' => $this->dueno->id, 'num' => 907]);

        $relacion = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Bulón P36', 'category_id' => 'Ferre P36'],
        ]);

        $this->assertFalse($relacion['ok']);
        $this->assertCount(2, $relacion['opciones']['category_id']);
        $this->assertSame('Ferre P36 A', $relacion['opciones']['category_id'][0]['nombre']);
    }

    /**
     * @test
     */
    public function un_campo_inexistente_uno_que_no_se_carga_al_crear_y_un_obligatorio_que_falta_cortan_con_el_motivo()
    {
        list($conversation, $assistant) = $this->conversacion();

        $inexistente = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'provider',
            'datos'   => ['name' => 'Acme P36', 'color_favorito' => 'rojo'],
        ]);

        $this->assertFalse($inexistente['ok']);
        $this->assertStringContainsString('"color_favorito" no existe', $inexistente['error']);
        $this->assertStringContainsString('name', $inexistente['error']);
        $this->assertNotEmpty($inexistente['opciones']['campos']);

        // addresses.phone lo lee update() pero no store(): al crear no se carga.
        $no_al_crear = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'address',
            'datos'   => ['street' => 'Sucursal P36', 'phone' => '351'],
        ]);

        $this->assertFalse($no_al_crear['ok']);
        $this->assertStringContainsString('no se carga al crear', $no_al_crear['error']);

        $sin_nombre = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'provider',
            'datos'   => ['phone' => '351'],
        ]);

        $this->assertFalse($sin_nombre['ok']);
        $this->assertSame(['nombre'], $sin_nombre['faltan']);

        // Un valor que no es del tipo del campo.
        $mal_tipo = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'category',
            'datos'   => ['name' => 'Rubro P36', 'percentage_gain' => 'treinta'],
        ]);

        $this->assertFalse($mal_tipo['ok']);
        $this->assertStringContainsString('tiene que ser un número', $mal_tipo['error']);

        // Una entidad que no entra, y una operación que la entidad no admite.
        $afuera = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'combo', 'datos' => ['name' => 'x']]);
        $this->assertFalse($afuera['ok']);
        $this->assertStringContainsString('no se puede cargar por acá', $afuera['error']);
        $this->assertContains('provider', $afuera['opciones']['entidades']);

        $venta_nueva = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'sale', 'datos' => ['total' => 1]]);
        $this->assertFalse($venta_nueva['ok']);
        $this->assertStringContainsString('proponer_venta', $venta_nueva['error']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * @test
     */
    public function un_empleado_raso_no_puede_y_uno_con_admin_access_si_y_sin_la_extension_no_hay_bodegas()
    {
        $raso = User::create([
            'name'     => 'Empleado raso P36',
            'email'    => 'raso-p36-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        list($conversation, $assistant) = $this->conversacion($raso);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'provider', 'datos' => ['name' => 'Acme raso P36']]);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame('No tenés permiso para cargar proveedores desde tu usuario.', $respuesta['error']);

        $encargado = User::create([
            'name'         => 'Encargado P36',
            'email'        => 'encargado-p36-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->dueno->id,
            'admin_access' => 1,
        ]);

        list($conversation, $assistant) = $this->conversacion($encargado);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'provider', 'datos' => ['name' => 'Acme encargado P36']]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        // Sin la extensión vinoteca, las bodegas no existen para este comercio.
        $this->assertFalse(\App\Http\Controllers\Helpers\asistente_ia\PermisosIaHelper::tiene_extencion($this->dueno, 'vinoteca'), 'El fixture no trae la extensión vinoteca');

        list($conversation, $assistant) = $this->conversacion();

        $bodega = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'bodega', 'datos' => ['name' => 'Bodega P36']]);

        $this->assertFalse($bodega['ok']);
        $this->assertStringContainsString('no tiene activado el módulo', $bodega['error']);
    }

    /**
     * 🔴 El ejecutor exige que la persona esté autenticada: sin sesión no llama a ningún controller.
     *
     * @test
     */
    public function sin_la_persona_autenticada_el_ejecutor_corta_con_500_sin_escribir()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'Marca sin sesión P36']]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $contexto = \App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa::de_la_conversacion($conversation);
        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        Auth::logout();

        try {
            \App\Http\Controllers\Helpers\asistente_ia\EjecutorGenericoIaHelper::ejecutar($contexto, $accion);
            $this->fail('Tenía que cortar sin persona autenticada');
        } catch (\App\Http\Controllers\Helpers\asistente_ia\AccionIaException $e) {
            $this->assertSame(500, $e->status);
            $this->assertSame('No se pudo autenticar a la persona para ejecutar la carga.', $e->getMessage());
        }

        $this->assertSame(0, DB::table('brands')->where('user_id', $this->dueno->id)->where('name', 'Marca sin sesión P36')->count());
    }

    /**
     * Las dos puntas de cada herramienta nueva, y el orden al final del array (prefijo del caché).
     *
     * @test
     */
    public function las_cinco_definiciones_nuevas_van_al_final_en_orden_y_se_despachan()
    {
        $nombres = HerramientasDeCarga::nombres();

        $this->assertSame(
            ['que_puedo_cargar', 'proponer_alta', 'proponer_edicion', 'proponer_baja', 'proponer_venta'],
            array_slice($nombres, -5)
        );

        $contenido = file_get_contents(app_path('Services/AsistenteIa/HerramientasDeCarga.php'));

        foreach (array_slice($nombres, -5) as $nombre) {
            $this->assertStringContainsString("case '" . $nombre . "':", $contenido, $nombre);
        }

        $definiciones = [];

        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            $definiciones[$definicion['name']] = $definicion;
        }

        // Las claves de datos/cambios son libres (van por que_puedo_cargar, no por el enum).
        $this->assertTrue($definiciones['proponer_alta']['input_schema']['properties']['datos']['additionalProperties']);
        $this->assertSame(['entidad', 'datos'], $definiciones['proponer_alta']['input_schema']['required']);
        $this->assertSame(['entidad', 'registro', 'cambios'], $definiciones['proponer_edicion']['input_schema']['required']);
        $this->assertSame(['entidad', 'registro'], $definiciones['proponer_baja']['input_schema']['required']);
        $this->assertSame([], $definiciones['que_puedo_cargar']['input_schema']['required']);

        // proponer_venta con el input_schema del contrato §4.
        $venta = $definiciones['proponer_venta']['input_schema'];
        $this->assertSame(['items'], $venta['required']);
        foreach (['items', 'cliente', 'cobro', 'metodo_de_pago', 'caja', 'lista_de_precios', 'tipo_de_venta', 'descuento_porcentaje', 'observaciones', 'sucursal', 'fecha_entrega', 'reemplaza_a'] as $propiedad) {
            $this->assertArrayHasKey($propiedad, $venta['properties'], $propiedad);
        }
        $this->assertSame(['contado', 'cuenta_corriente'], $venta['properties']['cobro']['enum']);
        $this->assertSame(['cantidad'], $venta['properties']['items']['items']['required']);

        // Y dicen cuándo NO usarlas.
        $this->assertStringContainsString('su propia herramienta', $definiciones['proponer_alta']['description']);
        $this->assertStringContainsString('NUNCA se crea sola', $definiciones['proponer_alta']['description']);

        // Sin mensaje donde colgar la tarjeta, una propuesta va con is_error (regla común).
        list($conversation) = $this->conversacion();
        $sin_mensaje = HerramientasDeCarga::ejecutar('proponer_alta', ['entidad' => 'brand', 'datos' => ['name' => 'x']], $conversation, null);
        $this->assertTrue($sin_mensaje['is_error']);

        // que_puedo_cargar por el despacho.
        list($conversation, $assistant) = $this->conversacion();
        $catalogo = $this->herramienta($conversation, $assistant, 'que_puedo_cargar', []);
        $this->assertNotEmpty($catalogo['entidades']);
        $detalle = $this->herramienta($conversation, $assistant, 'que_puedo_cargar', ['entidad' => 'brand']);
        $this->assertSame('brand', $detalle['entidad']);
    }
}
