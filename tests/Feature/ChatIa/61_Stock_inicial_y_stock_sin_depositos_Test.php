<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\ConceptoStockMovement;
use App\Models\ExtencionEmpresa;
use App\Models\PermissionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión alta-por-agente-margen-y-stock (29/9/2026) — EL STOCK que el asistente decía que cargaba y
 * no cargaba.
 *
 * De dónde sale: demo3, 29/9/2026, conversación 12, artículo 17320 (Cera Nic). El dueño dictó el
 * alta "con un stock de 20 unidades". El agente contestó "tengo listo el alta ... y 20 unidades"
 * antes de tener tarjeta (el alta no tenía dónde ponerlas), y después del alta, en un negocio SIN
 * depósitos, proponer_stock_en_deposito devolvió "el stock se edita sobre el total del artículo" y no
 * había ninguna herramienta que editara ese total. El modelo leyó el error como "ya está" y afirmó
 * "las 20 unidades ya quedaron cargadas". `articles.stock` quedó en NULL.
 *
 * Lo que protege este archivo:
 *  - 🔴 El stock inicial del alta queda de verdad, por el mismo camino que el botón "Asignar Stock"
 *    (un "Ingreso manual" por StockMovementController::crear), en unidades y no en bultos.
 *  - Con depósitos va al nombrado, al de la persona o al único; si hay que elegir, `faltan`.
 *  - Sin permiso de stock, el alta con stock inicial corta.
 *  - La corrección hereda el stock; `0` lo saca.
 *  - 🔴 En un negocio sin depósitos, proponer_stock_en_deposito deja el stock TOTAL del artículo
 *    (sumar / restar / fijar, el delta resuelto al confirmar, nunca negativo, no con variantes).
 *
 * El dueño de testing (user 500) tiene UN depósito ("Principal"). Para los casos sin depósitos se
 * borra adentro de la transacción de EmpresaTestCase, que lo devuelve al terminar.
 *
 * 🔴 Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Stock_inicial_y_stock_sin_depositos_Test extends EmpresaTestCase
{
    /** Delta para comparar cantidades de stock, que son decimal(…,2). */
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var array<int,ExtencionEmpresa> Extensiones enganchadas por este archivo. */
    protected $extensiones_enganchadas = [];

    /** @var string|null El modo de confianza del dueño antes de este archivo (ver tearDown). */
    protected $confianza_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba-p61']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->confianza_original = $this->dueno->agente_confianza;

        $this->dar_extension('asistente_ia');

        // En "cauteloso" todo deja tarjeta y se confirma por el endpoint de la pantalla.
        User::where('id', $this->dueno->id)->update(['agente_confianza' => 'cauteloso']);

        $this->dueno = User::find($this->dueno->id);

        $this->actuar_como($this->dueno);

        Catalogo::olvidar();
    }

    protected function tearDown(): void
    {
        /* El interruptor global vuelve a como estaba, aunque el rollback no alcance. */
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

        foreach ($this->extensiones_enganchadas as $extencion) {
            $this->dueno->extencions()->detach($extencion->id);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * @param  string  $slug
     * @return void
     */
    protected function dar_extension($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');

        $this->extensiones_enganchadas[] = $extencion;
    }

    /**
     * Autentica a una persona en el guard `web` (después de una request HTTP sanctum queda como
     * guard por defecto; ver 51_Modo_directo_y_escalado_Test).
     *
     * @param  User  $persona
     * @return void
     */
    protected function actuar_como(User $persona)
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($persona, 'web');
    }

    /**
     * El negocio se queda sin depósitos, como demo3. Adentro de la transacción del test: vuelven
     * solos al terminar.
     *
     * @return void
     */
    protected function sin_depositos()
    {
        Address::where('user_id', $this->dueno->id)->whereNull('buyer_id')->delete();

        $this->assertSame(0, Address::where('user_id', $this->dueno->id)->whereNull('buyer_id')->count());
    }

    /**
     * Una sucursal del dueño.
     *
     * @param  string  $nombre
     * @return Address
     */
    protected function sucursal($nombre)
    {
        return Address::create([
            'street'  => $nombre,
            'user_id' => $this->dueno->id,
        ]);
    }

    /**
     * Un artículo del dueño ya existente.
     *
     * @param  string  $nombre
     * @param  float|null  $stock
     * @return Article
     */
    protected function articulo_de_prueba($nombre, $stock = null)
    {
        return Article::create([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 1000,
            'cost'        => 500,
            'stock'       => $stock,
            'iva_id'      => 2,
        ]);
    }

    /**
     * Un empleado del dueño con los permisos que se le pasen por slug.
     *
     * @param  array<int,string>  $slugs
     * @return User
     */
    protected function empleado(array $slugs)
    {
        $empleado = User::create([
            'name'     => 'Empleado p61',
            'email'    => 'empleado-p61-' . uniqid() . '@test.local',
            'password' => Hash::make('secreto'),
            'owner_id' => $this->dueno->id,
        ]);

        $ids = [];

        foreach ($slugs as $slug) {

            $permiso = PermissionEmpresa::where('slug', $slug)->first();

            if (is_null($permiso)) {
                $permiso = PermissionEmpresa::forceCreate(['name' => $slug, 'slug' => $slug, 'model_name' => 'article']);
            }

            $ids[] = $permiso->id;
        }

        $empleado->permissions()->sync($ids);
        $empleado->load('permissions');

        return $empleado;
    }

    /**
     * Conversación de la persona (el dueño si no se pasa otra) con su assistant pendiente.
     *
     * @param  string  $pedido
     * @param  User|null  $persona
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido = 'Dame de alta este producto', $persona = null)
    {
        $persona = is_null($persona) ? $this->dueno : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $persona->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
            'estado'             => 'listo',
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
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  string  $herramienta
     * @param  array  $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /**
     * Confirma la tarjeta por el endpoint real, autenticado como el dueño.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  int  $tarjeta_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $this->actuar_como($this->dueno);

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /**
     * @param  int  $tarjeta_id
     * @return array<string, string>
     */
    protected function renglones($tarjeta_id)
    {
        return array_column(AiMessageAction::find($tarjeta_id)->presentacion['renglones'], 'valor', 'etiqueta');
    }

    /**
     * `articles.stock` de la fila en este instante.
     *
     * @param  int  $article_id
     * @return float|null
     */
    protected function stock($article_id)
    {
        $stock = DB::table('articles')->where('id', (int) $article_id)->value('stock');

        return is_null($stock) ? null : (float) $stock;
    }

    /**
     * @param  int  $article_id
     * @param  int  $address_id
     * @return float|null
     */
    protected function pivot($article_id, $address_id)
    {
        $fila = DB::table('address_article')
            ->where('article_id', (int) $article_id)
            ->where('address_id', (int) $address_id)
            ->first(['amount']);

        return is_null($fila) ? null : (float) $fila->amount;
    }

    /**
     * Propone el alta con stock inicial y devuelve la respuesta.
     *
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  string  $nombre
     * @param  array  $extras  stock_inicial, deposito...
     * @param  array  $datos
     * @return array
     */
    protected function proponer_alta($conversation, $assistant, $nombre, array $extras, array $datos = [])
    {
        return $this->herramienta($conversation, $assistant, 'proponer_alta', array_merge([
            'entidad' => 'article',
            'datos'   => array_merge(['name' => $nombre, 'cost' => 1000], $datos),
        ], $extras));
    }

    // =====================================================================
    // 7. Stock inicial en un negocio SIN depósitos
    // =====================================================================

    /**
     * 🔴 LO QUE PASÓ EN DEMO3, CERRADO. Sin depósitos, `stock_inicial: 20` va en un renglón de la
     * tarjeta y al confirmar queda `articles.stock = 20` con UN movimiento "Ingreso manual" de 20 y
     * sin fila en `address_article`. Con `unidades_individuales = 12` sigue siendo 20: la persona dijo
     * unidades, no bultos.
     *
     * @test
     */
    public function el_stock_inicial_sin_depositos_queda_en_el_total_y_en_unidades()
    {
        $this->sin_depositos();

        list($conversation, $assistant) = $this->conversacion('Dame de alta la cera con 20 unidades');

        $respuesta = $this->proponer_alta($conversation, $assistant, 'Cera zz-p61 sin depositos', ['stock_inicial' => '20 unidades'], ['unidades_individuales' => 12]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $this->assertSame('20', $this->renglones($respuesta['tarjeta_id'])['Stock inicial']);

        // El modelo también lo lee en el resumen: lo que dice que se carga está en la tarjeta.
        $this->assertStringContainsString('Stock inicial: 20', (string) $respuesta['resumen']);

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Cera zz-p61 sin depositos')->first();

        $this->assertNotNull($articulo);
        $this->assertEqualsWithDelta(20, $this->stock($articulo->id), self::DELTA, 'El stock inicial no quedó (o se multiplicó por las unidades del bulto).');
        $this->assertSame(0, DB::table('address_article')->where('article_id', $articulo->id)->count(), 'Sin depósitos no se abre ninguno.');

        $ingreso_manual = ConceptoStockMovement::where('name', 'Ingreso manual')->value('id');

        $movimientos = DB::table('stock_movements')->where('article_id', $articulo->id)->get();

        $this->assertCount(1, $movimientos, 'Un solo movimiento: el del stock inicial.');
        $this->assertEqualsWithDelta(20, (float) $movimientos[0]->amount, self::DELTA);
        $this->assertEquals($ingreso_manual, $movimientos[0]->concepto_stock_movement_id);
        $this->assertNull($movimientos[0]->to_address_id);

        $this->assertStringContainsString('stock inicial 20', (string) $confirmacion->json('model.resultado.texto'));
    }

    /**
     * Sin depósitos, nombrar un depósito es un error claro (no se ignora ni se inventa uno).
     *
     * @test
     */
    public function sin_depositos_nombrar_un_deposito_es_un_error()
    {
        $this->sin_depositos();

        list($conversation, $assistant) = $this->conversacion('Con 20 en Florida');

        $respuesta = $this->proponer_alta($conversation, $assistant, 'Cera zz-p61 florida', ['stock_inicial' => 20, 'deposito' => 'Florida']);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('no tiene depósitos', (string) $respuesta['error']);

        // Y un depósito sin stock tampoco se calla.
        $solo_deposito = $this->proponer_alta($conversation, $assistant, 'Cera zz-p61 florida', ['deposito' => 'Florida']);

        $this->assertFalse($solo_deposito['ok']);
        $this->assertStringContainsString('stock_inicial', (string) $solo_deposito['error']);
    }

    // =====================================================================
    // 8. Stock inicial en un negocio CON depósitos
    // =====================================================================

    /**
     * Con depósitos: nombrado → ese (pivote y total en 20); sin nombrar y con UN depósito → ese.
     *
     * @test
     */
    public function el_stock_inicial_con_depositos_va_al_nombrado_o_al_unico()
    {
        $principal = Address::where('user_id', $this->dueno->id)->whereNull('buyer_id')->first();

        $this->assertNotNull($principal, 'El fixture tiene que traer un depósito.');

        // Sin nombrar, con un solo depósito: ése.
        list($conversation, $assistant) = $this->conversacion('Con 20 unidades');

        $unico = $this->proponer_alta($conversation, $assistant, 'Cera zz-p61 unico', ['stock_inicial' => 20]);

        $this->assertTrue(!empty($unico['ok']), json_encode($unico));
        $this->assertSame('20 (en ' . $principal->street . ')', $this->renglones($unico['tarjeta_id'])['Stock inicial']);

        $this->confirmar($conversation, $assistant, $unico['tarjeta_id'])->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Cera zz-p61 unico')->first();

        $this->assertEqualsWithDelta(20, $this->pivot($articulo->id, $principal->id), self::DELTA);
        $this->assertEqualsWithDelta(20, $this->stock($articulo->id), self::DELTA);

        // Nombrado: ése, aunque haya otro.
        $florida = $this->sucursal('zz-p61 Florida');

        list($conversation, $assistant) = $this->conversacion('Con 20 en Florida');

        $nombrado = $this->proponer_alta($conversation, $assistant, 'Cera zz-p61 florida', ['stock_inicial' => 20, 'deposito' => 'florida']);

        $this->assertTrue(!empty($nombrado['ok']), json_encode($nombrado));

        $this->confirmar($conversation, $assistant, $nombrado['tarjeta_id'])->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Cera zz-p61 florida')->first();

        $this->assertEqualsWithDelta(20, $this->pivot($articulo->id, $florida->id), self::DELTA);
        $this->assertNull($this->pivot($articulo->id, $principal->id), 'El stock fue a Florida: Principal no se abre.');
        $this->assertEqualsWithDelta(20, $this->stock($articulo->id), self::DELTA);
    }

    /**
     * Con dos depósitos, sin default y sin depósito en la ficha de la persona → `faltan` con los dos.
     * Con el depósito en la ficha de la persona, ése.
     *
     * @test
     */
    public function con_dos_depositos_sin_default_se_pregunta()
    {
        $principal = Address::where('user_id', $this->dueno->id)->whereNull('buyer_id')->first();

        $florida = $this->sucursal('zz-p61 Florida dos');

        Address::where('user_id', $this->dueno->id)->update(['default_address' => 0]);

        list($conversation, $assistant) = $this->conversacion('Con 20 unidades');

        $respuesta = $this->proponer_alta($conversation, $assistant, 'Cera zz-p61 dos', ['stock_inicial' => 20]);

        $this->assertFalse($respuesta['ok']);
        $this->assertNotEmpty($respuesta['faltan']);

        $nombres = array_column($respuesta['opciones']['depositos'], 'nombre');

        $this->assertContains($principal->street, $nombres);
        $this->assertContains('zz-p61 Florida dos', $nombres);

        // Con el depósito en la ficha de quien escribe, va a ése sin preguntar.
        User::where('id', $this->dueno->id)->update(['address_id' => $florida->id]);

        $this->dueno = User::find($this->dueno->id);

        $con_ficha = $this->proponer_alta($conversation, $assistant, 'Cera zz-p61 dos', ['stock_inicial' => 20]);

        $this->assertTrue(!empty($con_ficha['ok']), json_encode($con_ficha));
        $this->assertSame('20 (en zz-p61 Florida dos)', $this->renglones($con_ficha['tarjeta_id'])['Stock inicial']);
    }

    // =====================================================================
    // 9. Sin permiso de stock
    // =====================================================================

    /**
     * Un empleado que puede crear artículos pero no editar el stock: el alta con stock inicial
     * corta con el motivo (y sin stock, el alta sigue andando).
     *
     * @test
     */
    public function sin_permiso_de_stock_el_stock_inicial_corta()
    {
        $empleado = $this->empleado(['article.store']);

        list($conversation, $assistant) = $this->conversacion('Con 20 unidades', $empleado);

        $respuesta = $this->proponer_alta($conversation, $assistant, 'Cera zz-p61 permiso', ['stock_inicial' => 20]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('permiso', (string) $respuesta['error']);

        $sin_stock = $this->proponer_alta($conversation, $assistant, 'Cera zz-p61 permiso', []);

        $this->assertTrue(!empty($sin_stock['ok']), json_encode($sin_stock));
    }

    // =====================================================================
    // 10. La corrección hereda el stock
    // =====================================================================

    /**
     * "Sí, pero cambiale el nombre" hereda el stock inicial y su depósito; `stock_inicial: 0` los
     * saca.
     *
     * @test
     */
    public function la_correccion_hereda_el_stock_y_cero_lo_saca()
    {
        $florida = $this->sucursal('zz-p61 Florida hereda');

        list($conversation, $assistant) = $this->conversacion('Con 20 en Florida');

        $primera = $this->proponer_alta($conversation, $assistant, 'Cera zz-p61 hereda', ['stock_inicial' => 20, 'deposito' => 'zz-p61 Florida hereda']);

        $this->assertTrue(!empty($primera['ok']), json_encode($primera));

        $corregida = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'Cera zz-p61 hereda mate'],
            'reemplaza_a' => $primera['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($corregida['ok']), json_encode($corregida));

        $extras = AiMessageAction::find($corregida['tarjeta_id'])->datos['extras'];

        $this->assertEquals(20, $extras['stock_inicial']['cantidad']);
        $this->assertSame((int) $florida->id, (int) $extras['stock_inicial']['address_id']);
        $this->assertSame('20 (en zz-p61 Florida hereda)', $this->renglones($corregida['tarjeta_id'])['Stock inicial']);

        // Cambiar solo la cantidad deja el depósito.
        $treinta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'       => 'article',
            'datos'         => ['name' => 'Cera zz-p61 hereda mate'],
            'stock_inicial' => 30,
            'reemplaza_a'   => $corregida['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($treinta['ok']), json_encode($treinta));
        $this->assertSame('30 (en zz-p61 Florida hereda)', $this->renglones($treinta['tarjeta_id'])['Stock inicial']);

        $sin_stock = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'       => 'article',
            'datos'         => ['name' => 'Cera zz-p61 hereda mate'],
            'stock_inicial' => 0,
            'reemplaza_a'   => $treinta['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($sin_stock['ok']), json_encode($sin_stock));

        $datos = AiMessageAction::find($sin_stock['tarjeta_id'])->datos;

        $this->assertTrue(empty($datos['extras']['stock_inicial']), 'Con 0 el stock heredado se saca: ' . json_encode($datos));
        $this->assertArrayNotHasKey('Stock inicial', $this->renglones($sin_stock['tarjeta_id']));
    }

    // =====================================================================
    // 12. El stock total de un artículo existente, sin depósitos
    // =====================================================================

    /**
     * 🔴 "Cargale 15 unidades más" en un negocio sin depósitos: ya no es el error que el modelo leía
     * como "ya está", es una tarjeta "Stock total 5 → 20". Al confirmar se mueve la DIFERENCIA con
     * un "Ingreso manual" y el delta se vuelve a resolver sobre lo que hay en ese momento.
     *
     * @test
     */
    public function sin_depositos_el_stock_total_se_suma_y_el_delta_se_resuelve_al_confirmar()
    {
        $this->sin_depositos();

        $articulo = $this->articulo_de_prueba('zz-p61 Pastina total', 5);

        list($conversation, $assistant) = $this->conversacion('Cargale 15 unidades más a la pastina');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p61 Pastina total',
            'cantidad' => 15,
            'modo'     => 'sumar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $renglones = $this->renglones($respuesta['tarjeta_id']);

        $this->assertSame('5 → 20', $renglones['Stock total']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertTrue(!empty($tarjeta->datos['esperado']['sin_depositos']));
        $this->assertSame(AiMessageAction::TIPO_STOCK_DEPOSITO, $tarjeta->tipo);

        // Entre la tarjeta y el clic se vendieron 2: el "sumale 15" se aplica sobre 3.
        DB::table('articles')->where('id', $articulo->id)->update(['stock' => 3]);

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $this->assertEqualsWithDelta(18, $this->stock($articulo->id), self::DELTA);
        $this->assertStringContainsString('Ojo', (string) $confirmacion->json('model.resultado.texto'));

        $movimiento = DB::table('stock_movements')->where('article_id', $articulo->id)->orderBy('id', 'DESC')->first();

        $this->assertEqualsWithDelta(15, (float) $movimiento->amount, self::DELTA);
        $this->assertEquals(ConceptoStockMovement::where('name', 'Ingreso manual')->value('id'), $movimiento->concepto_stock_movement_id);
        $this->assertSame(0, DB::table('address_article')->where('article_id', $articulo->id)->count());
    }

    /**
     * Restar por debajo de cero corta; fijar deja el número; un artículo que no llevaba stock y se
     * fija en 0 queda en 0 sin movimiento.
     *
     * @test
     */
    public function sin_depositos_restar_nunca_deja_negativo_y_fijar_deja_el_numero()
    {
        $this->sin_depositos();

        $articulo = $this->articulo_de_prueba('zz-p61 Pastina fijar', 5);

        list($conversation, $assistant) = $this->conversacion('Restale 8');

        $negativo = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p61 Pastina fijar',
            'cantidad' => 8,
            'modo'     => 'restar',
        ]);

        $this->assertFalse($negativo['ok']);
        $this->assertStringContainsString('negativo', (string) $negativo['error']);

        $fijar = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p61 Pastina fijar',
            'cantidad' => 2,
            'modo'     => 'fijar',
        ]);

        $this->assertTrue(!empty($fijar['ok']), json_encode($fijar));

        $this->confirmar($conversation, $assistant, $fijar['tarjeta_id'])->assertStatus(200);

        $this->assertEqualsWithDelta(2, $this->stock($articulo->id), self::DELTA);

        $movimiento = DB::table('stock_movements')->where('article_id', $articulo->id)->orderBy('id', 'DESC')->first();

        $this->assertEqualsWithDelta(-3, (float) $movimiento->amount, self::DELTA, 'Se mueve la diferencia, no el absoluto.');

        // Sin stock (null) y fijar 0: se escribe la columna, sin movimiento.
        $sin_stock = $this->articulo_de_prueba('zz-p61 Pastina cero', null);

        list($conversation, $assistant) = $this->conversacion('Dejala en cero');

        $cero = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p61 Pastina cero',
            'cantidad' => 0,
            'modo'     => 'fijar',
        ]);

        $this->assertTrue(!empty($cero['ok']), json_encode($cero));

        $this->confirmar($conversation, $assistant, $cero['tarjeta_id'])->assertStatus(200);

        $this->assertSame(0.0, $this->stock($sin_stock->id));
        $this->assertSame(0, DB::table('stock_movements')->where('article_id', $sin_stock->id)->count());
    }

    /**
     * Sin depósitos: un artículo con variantes no se toca por el total, y nombrar un depósito es un
     * error. Con depósitos y sin nombrarlo, se pregunta cuál (como siempre).
     *
     * @test
     */
    public function el_stock_total_no_va_con_variantes_ni_con_deposito_nombrado()
    {
        $articulo = $this->articulo_de_prueba('zz-p61 Remera variantes', 5);

        list($conversation, $assistant) = $this->conversacion('Cargale 10');

        // Con depósitos y sin nombrarlo: faltan, como hasta hoy.
        $falta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p61 Remera variantes',
            'cantidad' => 10,
            'modo'     => 'sumar',
        ]);

        $this->assertFalse($falta['ok']);
        $this->assertNotEmpty($falta['faltan']);

        $this->sin_depositos();

        $con_deposito = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p61 Remera variantes',
            'deposito' => 'Florida',
            'cantidad' => 10,
            'modo'     => 'sumar',
        ]);

        $this->assertFalse($con_deposito['ok']);
        $this->assertStringContainsString('No mandes deposito', (string) $con_deposito['error']);

        ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Talle M']);

        $con_variantes = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p61 Remera variantes',
            'cantidad' => 10,
            'modo'     => 'sumar',
        ]);

        $this->assertFalse($con_variantes['ok']);
        $this->assertStringContainsString('variantes', (string) $con_variantes['error']);

        $this->assertEqualsWithDelta(5, $this->stock($articulo->id), self::DELTA, 'No se tocó nada.');
    }
}
