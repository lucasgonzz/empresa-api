<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\MargenesPorListaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaStockIaHelper;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\PermissionEmpresa;
use App\Models\PriceType;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión alta-por-agente-margen-y-stock — RONDA DE CORRECCIONES (29/9/2026) de los tres chequeos
 * independientes. Cada test falla sin su arreglo:
 *
 *  - A. `margenes_por_lista` vacío ([], "", "[]") es "no vino" en la edición, para cualquier entidad;
 *       y en el alta, "[]" como texto en una cuenta sin listas no corta.
 *  - B. El stock inicial es atómico: si algo lanza después de sumar el stock, no queda nada escrito
 *       (y el stock total de un artículo existente tiene la misma garantía).
 *  - C. Una corrección SUMA márgenes a los de la tarjeta que reemplaza (alta y edición).
 *  - D. heredar() solo hereda de tarjetas vivas, nunca de un alta ya confirmada.
 *  - E. Con listas, el resultado del alta (y de la edición con márgenes) dice el precio de TODAS
 *       las listas, releído del pivote, también en modo directo.
 *  - F. Los rechazos empiezan con "No se cargó nada:" (y el movimiento sin depósitos, "No se movió
 *       nada:" y manda a la herramienta que sí carga).
 *  - G. Con unidades por bulto, la tarjeta dice "unidades sueltas (no bultos de N)".
 *  - H. Con listas, también se rechazan el precio manual y "aplica el margen del proveedor".
 *  - I. que_puedo_cargar marca esos tres campos como que no aplican.
 *  - J. Miles ("1.500"), búsqueda de lista sin contención inversa, `faltan` sin opciones, stock total
 *       sin cambio, y los campos escondidos heredados de una tarjeta vieja se descartan con aviso.
 *
 * El dueño de testing es el user 500 del TestingFerreteriaSeeder (4 listas, 1 depósito,
 * `listas_de_precio = 0`). Todo corre en la transacción de EmpresaTestCase; los dos interruptores
 * globales de la cuenta se devuelven a mano en tearDown().
 *
 * 🔴 Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Correcciones_de_alta_con_margen_y_stock_Test extends EmpresaTestCase
{
    /** Delta para comparar cantidades y porcentajes decimal(…,2). */
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var array<int,ExtencionEmpresa> */
    protected $extensiones_enganchadas = [];

    /** @var array Las columnas del dueño que este archivo mueve, como estaban antes. */
    protected $columnas_originales = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba-p62']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->columnas_originales = [
            'listas_de_precio' => $this->dueno->listas_de_precio,
            'agente_confianza' => $this->dueno->agente_confianza,
        ];

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'asistente_ia']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->extensiones_enganchadas[] = $extencion;

        $this->dueno_con(['agente_confianza' => 'cauteloso']);

        Catalogo::olvidar();
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update($this->columnas_originales);

        foreach ($this->extensiones_enganchadas as $extencion) {
            $this->dueno->extencions()->detach($extencion->id);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Fixture (el mismo molde que 60 y 61)
    // ---------------------------------------------------------------------

    /**
     * @param  array  $columnas
     * @return void
     */
    protected function dueno_con(array $columnas)
    {
        User::where('id', $this->dueno->id)->update($columnas);

        $this->dueno = User::find($this->dueno->id);

        $this->actuar_como($this->dueno);
    }

    /**
     * @return void
     */
    protected function con_listas()
    {
        $this->dueno_con(['listas_de_precio' => 1]);
    }

    /**
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
     * El negocio sin depósitos, adentro de la transacción del test.
     *
     * @return void
     */
    protected function sin_depositos()
    {
        Address::where('user_id', $this->dueno->id)->whereNull('buyer_id')->delete();
    }

    /**
     * @param  string  $nombre
     * @return PriceType
     */
    protected function lista($nombre)
    {
        return PriceType::where('user_id', $this->dueno->id)->where('name', $nombre)->firstOrFail();
    }

    /**
     * @param  string  $nombre
     * @param  float|null  $stock
     * @param  array  $mas
     * @return Article
     */
    protected function articulo_de_prueba($nombre, $stock = null, array $mas = [])
    {
        return Article::create(array_merge([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 1000,
            'cost'        => 500,
            'stock'       => $stock,
            'iva_id'      => 2,
        ], $mas));
    }

    /**
     * @param  array<int,string>  $slugs
     * @return User
     */
    protected function empleado(array $slugs)
    {
        $empleado = User::create([
            'name'     => 'Empleado p62',
            'email'    => 'empleado-p62-' . uniqid() . '@test.local',
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
     * @param  int  $article_id
     * @return float|null
     */
    protected function stock($article_id)
    {
        $stock = DB::table('articles')->where('id', (int) $article_id)->value('stock');

        return is_null($stock) ? null : (float) $stock;
    }

    /**
     * Hace que el sistema falle DESPUÉS de haber sumado el stock: tira cuando `SetStockResultante`
     * guarda el movimiento y el artículo ya quedó con `$stock_que_dispara`. Es la ventana exacta que
     * dejaba el stock sumado con un resultado de "falló". El listener vive en el despachador de esta
     * aplicación, que se rearma en cada test.
     *
     * @param  float  $stock_que_dispara
     * @return void
     */
    protected function fallar_despues_de_sumar_el_stock($stock_que_dispara)
    {
        StockMovement::updated(function ($movimiento) use ($stock_que_dispara) {

            $stock = DB::table('articles')->where('id', (int) $movimiento->article_id)->value('stock');

            if (!is_null($stock) && abs((float) $stock - (float) $stock_que_dispara) < 0.01) {

                throw new \RuntimeException('Falla simulada después de sumar el stock (test p62).');
            }
        });
    }

    // =====================================================================
    // A. Vacío es "no vino"
    // =====================================================================

    /**
     * A. En la edición, `margenes_por_lista` vacío (`[]`, `""`, `"[]"`) no corta: ni en un cliente
     * ("sólo para el margen de un artículo") ni en un artículo sin listas ("no trabaja con listas").
     * Con `cambios` vacío también, recién ahí el error de siempre.
     *
     * @test
     */
    public function en_la_edicion_los_margenes_vacios_son_como_si_no_vinieran()
    {
        $this->dueno_con(['listas_de_precio' => 0]);

        $cliente = Client::create(['name' => 'zz-p62 Cliente vacio', 'user_id' => $this->dueno->id]);

        $articulo = $this->articulo_de_prueba('zz-p62 Articulo vacio');

        list($conversation, $assistant) = $this->conversacion('Cambiale el teléfono');

        foreach ([[], '', '[]'] as $indice => $vacio) {

            $del_cliente = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
                'entidad'            => 'client',
                'registro'           => 'zz-p62 Cliente vacio',
                'cambios'            => ['phone' => '351555000' . $indice],
                'margenes_por_lista' => $vacio,
            ]);

            $this->assertTrue(!empty($del_cliente['ok']), 'Cliente con ' . json_encode($vacio) . ': ' . json_encode($del_cliente));

            $del_articulo = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
                'entidad'            => 'article',
                'registro'           => 'zz-p62 Articulo vacio',
                'cambios'            => ['cost' => 700 + $indice],
                'margenes_por_lista' => $vacio,
            ]);

            $this->assertTrue(!empty($del_articulo['ok']), 'Artículo sin listas con ' . json_encode($vacio) . ': ' . json_encode($del_articulo));
        }

        $sin_nada = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'            => 'client',
            'registro'           => 'zz-p62 Cliente vacio',
            'cambios'            => [],
            'margenes_por_lista' => [],
        ]);

        $this->assertFalse($sin_nada['ok']);
        $this->assertStringContainsString('No mandaste ningún cambio', (string) $sin_nada['error']);

        $this->assertNotNull($cliente->id);
        $this->assertNotNull($articulo->id);
    }

    /**
     * A. En el alta, `"[]"` como TEXTO en una cuenta sin listas no corta con "no trabaja con
     * listas": es un vacío (en una corrección, sacaría los heredados).
     *
     * @test
     */
    public function en_el_alta_el_texto_vacio_en_una_cuenta_sin_listas_no_corta()
    {
        $this->dueno_con(['listas_de_precio' => 0]);

        list($conversation, $assistant) = $this->conversacion('Dame de alta la cera');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'zz-p62 Cera texto vacio', 'cost' => 1000],
            'margenes_por_lista' => '[]',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
    }

    // =====================================================================
    // B. Stock atómico
    // =====================================================================

    /**
     * 🔴 B. Si algo lanza DESPUÉS de sumar el stock inicial, no queda nada escrito: ni el stock ni
     * el movimiento. El alta sí queda (ya pasó por el controller) y el resultado dice que el stock
     * NO se cargó. Antes quedaba el stock sumado con un "falló", y reintentar lo duplicaba.
     *
     * @test
     */
    public function el_stock_inicial_que_falla_no_deja_nada_escrito()
    {
        $this->sin_depositos();

        $this->fallar_despues_de_sumar_el_stock(20);

        list($conversation, $assistant) = $this->conversacion('Con 20 unidades');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'       => 'article',
            'datos'         => ['name' => 'zz-p62 Cera atomica', 'cost' => 1000],
            'stock_inicial' => 20,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'zz-p62 Cera atomica')->first();

        $this->assertNotNull($articulo, 'El alta no se deshace: ya pasó por el controller de la pantalla.');

        $this->assertNotEquals(20.0, $this->stock($articulo->id), 'El stock quedó sumado con un resultado de "falló".');
        $this->assertSame(0, DB::table('stock_movements')->where('article_id', $articulo->id)->count(), 'Quedó un movimiento escrito de un stock que "falló".');

        $this->assertStringContainsString('el stock inicial NO se cargó', (string) $confirmacion->json('model.resultado.texto'));
    }

    /**
     * B. El stock total de un artículo que ya existe tiene la misma garantía: corre adentro de la
     * transacción del ejecutor, así que una falla después de sumar lo revierte todo.
     *
     * @test
     */
    public function el_stock_total_que_falla_no_deja_nada_escrito()
    {
        $this->sin_depositos();

        $articulo = $this->articulo_de_prueba('zz-p62 Pastina atomica', 5);

        list($conversation, $assistant) = $this->conversacion('Cargale 10');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p62 Pastina atomica',
            'cantidad' => 10,
            'modo'     => 'sumar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $this->fallar_despues_de_sumar_el_stock(15);

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $this->assertGreaterThanOrEqual(400, $confirmacion->status());

        $this->assertEqualsWithDelta(5, $this->stock($articulo->id), self::DELTA, 'El stock quedó sumado aunque la carga falló.');
        $this->assertSame(0, DB::table('stock_movements')->where('article_id', $articulo->id)->count());
    }

    // =====================================================================
    // C. Las correcciones suman márgenes
    // =====================================================================

    /**
     * 🔴 C. "Sí, y a la mayorista 40 también" sobre una tarjeta con la minorista en 30: quedan las
     * dos. Si se repite una lista, gana lo nuevo. `[]` saca todas.
     *
     * @test
     */
    public function la_correccion_del_alta_suma_margenes_y_gana_lo_nuevo()
    {
        $this->con_listas();

        list($conversation, $assistant) = $this->conversacion('Margen 30 a la minorista');

        $primera = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'zz-p62 Cera suma', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 30]],
        ]);

        $this->assertTrue(!empty($primera['ok']), json_encode($primera));

        $suma = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'zz-p62 Cera suma'],
            'margenes_por_lista' => [['lista' => 'Mayorista', 'margen' => 40]],
            'reemplaza_a'        => $primera['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($suma['ok']), json_encode($suma));

        $renglones = $this->renglones($suma['tarjeta_id']);

        $this->assertSame('30 %', isset($renglones['Margen Minorista']) ? $renglones['Margen Minorista'] : null, 'La minorista volvió a su default: la corrección pisó en vez de sumar. ' . json_encode($renglones));
        $this->assertSame('40 %', $renglones['Margen Mayorista']);

        $pisa = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'zz-p62 Cera suma'],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 35]],
            'reemplaza_a'        => $suma['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($pisa['ok']), json_encode($pisa));

        $renglones = $this->renglones($pisa['tarjeta_id']);

        $this->assertSame('35 %', $renglones['Margen Minorista'], 'Lo nuevo gana.');
        $this->assertSame('40 %', $renglones['Margen Mayorista']);

        $sin = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'zz-p62 Cera suma'],
            'margenes_por_lista' => [],
            'reemplaza_a'        => $pisa['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($sin['ok']), json_encode($sin));
        $this->assertArrayNotHasKey('Margen Minorista', $this->renglones($sin['tarjeta_id']));
        $this->assertArrayNotHasKey('Margen Mayorista', $this->renglones($sin['tarjeta_id']));
    }

    /**
     * C. Lo mismo en la edición: una corrección de una edición del mismo artículo, todavía propuesta,
     * suma los márgenes a los de la tarjeta anterior.
     *
     * @test
     */
    public function la_correccion_de_la_edicion_suma_margenes()
    {
        $this->con_listas();

        $articulo = $this->articulo_de_prueba('zz-p62 Cera edicion suma');

        list($conversation, $assistant) = $this->conversacion('Minorista a 35');

        $primera = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'            => 'article',
            'registro'           => 'zz-p62 Cera edicion suma',
            'cambios'            => [],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 35]],
        ]);

        $this->assertTrue(!empty($primera['ok']), json_encode($primera));

        $suma = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'            => 'article',
            'registro'           => 'zz-p62 Cera edicion suma',
            'cambios'            => [],
            'margenes_por_lista' => [['lista' => 'Mayorista', 'margen' => 20]],
            'reemplaza_a'        => $primera['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($suma['ok']), json_encode($suma));

        $renglones = $this->renglones($suma['tarjeta_id']);

        $this->assertArrayHasKey('Margen Minorista', $renglones, 'La corrección de la edición pisó el margen de la minorista: ' . json_encode($renglones));
        $this->assertStringEndsWith('→ 35 %', $renglones['Margen Minorista']);
        $this->assertStringEndsWith('→ 20 %', $renglones['Margen Mayorista']);

        $this->assertNotNull($articulo->id);
    }

    // =====================================================================
    // D. Solo de tarjetas vivas
    // =====================================================================

    /**
     * 🔴 D. Un reemplaza_a mal usado sobre un alta YA CONFIRMADA no hereda su stock inicial (en
     * directo, eso se ejecutaba solo y cargaba el stock otra vez en otro artículo).
     *
     * @test
     */
    public function la_correccion_no_hereda_de_un_alta_ya_confirmada()
    {
        $this->sin_depositos();

        list($conversation, $assistant) = $this->conversacion('Con 20 unidades');

        $confirmada = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'       => 'article',
            'datos'         => ['name' => 'zz-p62 Cera confirmada', 'cost' => 1000],
            'stock_inicial' => 20,
        ]);

        $this->assertTrue(!empty($confirmada['ok']), json_encode($confirmada));

        $this->confirmar($conversation, $assistant, $confirmada['tarjeta_id'])->assertStatus(200);

        // Un turno nuevo de la MISMA conversación: el reemplaza_a apunta a una tarjeta de acá, pero ya confirmada.
        $otro_assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        $nueva = $this->herramienta($conversation, $otro_assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'zz-p62 Cera nueva', 'cost' => 1000],
            'reemplaza_a' => $confirmada['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($nueva['ok']), json_encode($nueva));

        $datos = AiMessageAction::find($nueva['tarjeta_id'])->datos;

        $this->assertTrue(empty($datos['extras']['stock_inicial']), 'Heredó el stock de un alta ya confirmada: ' . json_encode($datos));
        $this->assertArrayNotHasKey('Stock inicial', $this->renglones($nueva['tarjeta_id']));
    }

    // =====================================================================
    // E. El resultado dice el precio de todas las listas
    // =====================================================================

    /**
     * 🔴 E. Con listas, un alta SIN margen (el modelo se lo olvidó) ya no vuelve "creado" a secas:
     * el resultado dice que no se cargó ningún margen por lista y el precio de cada una, releído del
     * pivote. En directo, que es donde el aviso de la tarjeta no le llega al modelo.
     *
     * @test
     */
    public function en_directo_el_alta_sin_margen_dice_el_precio_de_cada_lista()
    {
        $this->con_listas();

        $this->dueno_con(['agente_confianza' => 'directo']);

        list($conversation, $assistant) = $this->conversacion('Dame de alta la cera, costo 1000');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'zz-p62 Cera sin margen', 'cost' => 1000],
        ]);

        $this->assertSame('confirmada', isset($respuesta['estado']) ? $respuesta['estado'] : null, json_encode($respuesta));

        $texto = (string) $respuesta['resultado'];

        $this->assertStringContainsString('No se cargó ningún margen por lista', $texto);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'zz-p62 Cera sin margen')->first();

        foreach (['Distribuidor', 'Mayorista', 'Minorista', 'Tienda Nube'] as $nombre) {

            $pivote = DB::table('article_price_type')->where('article_id', $articulo->id)->where('price_type_id', $this->lista($nombre)->id)->first();

            $this->assertStringContainsString(
                $nombre . ' ' . MargenesPorListaIaHelper::porcentaje($pivote->percentage) . ' (' . \App\Http\Controllers\Helpers\asistente_ia\FormatoIaHelper::monto($pivote->final_price) . ')',
                $texto
            );
        }
    }

    /**
     * E. Con una lista nombrada, las demás aparecen como "quedaron con su margen por defecto"; en la
     * edición con márgenes, "siguen como estaban".
     *
     * @test
     */
    public function el_resultado_nombra_las_demas_listas_en_el_alta_y_en_la_edicion()
    {
        $this->con_listas();

        list($conversation, $assistant) = $this->conversacion('Margen 30 a la minorista');

        $alta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'zz-p62 Cera demas', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 30]],
        ]);

        $texto = (string) $this->confirmar($conversation, $assistant, $alta['tarjeta_id'])->json('model.resultado.texto');

        $this->assertStringContainsString('Las demás listas quedaron con su margen por defecto: ', $texto);
        $this->assertStringContainsString('Distribuidor 5 %', $texto);
        $this->assertStringNotContainsString('Minorista 15 %', $texto, 'La minorista es la nombrada: no va en "las demás".');

        list($conversation, $assistant) = $this->conversacion('Minorista a 35');

        $edicion = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'            => 'article',
            'registro'           => 'zz-p62 Cera demas',
            'cambios'            => [],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 35]],
        ]);

        $this->assertTrue(!empty($edicion['ok']), json_encode($edicion));

        $texto = (string) $this->confirmar($conversation, $assistant, $edicion['tarjeta_id'])->json('model.resultado.texto');

        $this->assertStringContainsString('Las demás listas siguen como estaban: ', $texto);
        $this->assertStringContainsString('Mayorista 10 %', $texto);
    }

    // =====================================================================
    // F. Los rechazos dicen que no se cargó nada
    // =====================================================================

    /**
     * 🔴 F. Ningún rechazo de esta misión se puede leer como un hecho cumplido (en demo3 "el stock
     * se edita sobre el total del artículo" se leyó como "ya quedaron cargadas"). Y el movimiento
     * entre depósitos en un negocio SIN depósitos dice que no se movió nada y manda a la herramienta
     * que sí carga el stock total.
     *
     * @test
     */
    public function los_rechazos_empiezan_diciendo_que_no_se_cargo_nada()
    {
        $this->con_listas();

        list($conversation, $assistant) = $this->conversacion('Varias cosas');

        $rechazos = [];

        $rechazos['lista inexistente'] = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'zz-p62 Cera f', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'Lista Oro', 'margen' => 30]],
        ]);

        $rechazos['margen suelto'] = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'zz-p62 Cera f', 'cost' => 1000, 'percentage_gain' => 30],
        ]);

        $rechazos['margen no numérico'] = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'zz-p62 Cera f', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 'mucho']],
        ]);

        $empleado = $this->empleado(['article.store']);

        list($del_empleado, $assistant_empleado) = $this->conversacion('Con 20', $empleado);

        $rechazos['stock sin permiso'] = $this->herramienta($del_empleado, $assistant_empleado, 'proponer_alta', [
            'entidad'       => 'article',
            'datos'         => ['name' => 'zz-p62 Cera f', 'cost' => 1000],
            'stock_inicial' => 20,
        ]);

        $this->sin_depositos();

        $articulo = $this->articulo_de_prueba('zz-p62 Pastina f', 5);

        $rechazos['alta con depósito sin depósitos'] = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'       => 'article',
            'datos'         => ['name' => 'zz-p62 Cera f', 'cost' => 1000],
            'stock_inicial' => 20,
            'deposito'      => 'Florida',
        ]);

        $rechazos['stock con depósito sin depósitos'] = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p62 Pastina f',
            'deposito' => 'Florida',
            'cantidad' => 10,
            'modo'     => 'sumar',
        ]);

        foreach ($rechazos as $caso => $respuesta) {

            $this->assertFalse($respuesta['ok'], $caso . ': ' . json_encode($respuesta));
            $this->assertStringStartsWith('No se cargó nada:', (string) $respuesta['error'], $caso);
        }

        $this->assertStringContainsString('sin deposito', (string) $rechazos['stock con depósito sin depósitos']['error']);

        $movimiento = $this->herramienta($conversation, $assistant, 'proponer_movimiento_de_stock', [
            'articulo' => 'zz-p62 Pastina f',
            'cantidad' => 2,
            'desde'    => 'Principal',
            'hacia'    => 'Florida',
        ]);

        $this->assertFalse($movimiento['ok']);
        $this->assertStringStartsWith('No se movió nada:', (string) $movimiento['error']);
        $this->assertStringContainsString('proponer_stock_en_deposito sin deposito', (string) $movimiento['error']);
        $this->assertStringNotContainsString('una sola sucursal', (string) $movimiento['error']);

        $this->assertEqualsWithDelta(5, $this->stock($articulo->id), self::DELTA);
    }

    // =====================================================================
    // G. Unidades sueltas, a la vista
    // =====================================================================

    /**
     * G. Con unidades por bulto, la tarjeta del stock total dice que son unidades sueltas.
     *
     * @test
     */
    public function el_stock_total_dice_unidades_sueltas_si_el_articulo_va_por_bulto()
    {
        $this->sin_depositos();

        $this->articulo_de_prueba('zz-p62 Pastina bulto', 5, ['unidades_individuales' => 6]);

        list($conversation, $assistant) = $this->conversacion('Cargale 10');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p62 Pastina bulto',
            'cantidad' => 10,
            'modo'     => 'sumar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame('5 → 15 unidades sueltas (no bultos de 6)', $this->renglones($respuesta['tarjeta_id'])['Stock total']);
    }

    // =====================================================================
    // H. La clase entera: precio manual y margen del proveedor
    // =====================================================================

    /**
     * 🔴 H. Con listas, la ficha esconde también el precio manual y "aplica el margen del
     * proveedor", y ninguno mueve un precio de lista: si los MANDA EL MODELO se rechazan, en el alta
     * y en la edición. "Aplica el margen" en no pasa (no mueve nada).
     *
     * @test
     */
    public function con_listas_se_rechazan_el_precio_manual_y_el_margen_del_proveedor()
    {
        $this->con_listas();

        list($conversation, $assistant) = $this->conversacion('Precio 5000');

        $precio = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'zz-p62 Cera precio', 'cost' => 1000, 'price' => 5000],
        ]);

        $this->assertFalse($precio['ok'], json_encode($precio));
        $this->assertStringStartsWith('No se cargó nada:', (string) $precio['error']);
        $this->assertStringContainsString('price', (string) $precio['error']);
        $this->assertStringContainsString('margenes_por_lista', (string) $precio['error']);

        $del_proveedor = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'zz-p62 Cera precio', 'cost' => 1000, 'apply_provider_percentage_gain' => 'si'],
        ]);

        $this->assertFalse($del_proveedor['ok'], json_encode($del_proveedor));
        $this->assertStringContainsString('apply_provider_percentage_gain', (string) $del_proveedor['error']);

        $apagado = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'zz-p62 Cera precio', 'cost' => 1000, 'apply_provider_percentage_gain' => 'no'],
        ]);

        $this->assertTrue(!empty($apagado['ok']), json_encode($apagado));

        $this->articulo_de_prueba('zz-p62 Cera precio edicion');

        $edicion = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'article',
            'registro' => 'zz-p62 Cera precio edicion',
            'cambios'  => ['price' => 9000],
        ]);

        $this->assertFalse($edicion['ok'], json_encode($edicion));
        $this->assertStringStartsWith('No se cargó nada:', (string) $edicion['error']);
    }

    // =====================================================================
    // I. que_puedo_cargar marca lo que no aplica
    // =====================================================================

    /**
     * I. Con listas, que_puedo_cargar de `article` marca el margen suelto, el precio manual y "aplica
     * el margen del proveedor" como que no aplican; sin listas, no marca nada.
     *
     * @test
     */
    public function que_puedo_cargar_marca_los_campos_que_no_aplican_con_listas()
    {
        $this->con_listas();

        list($conversation, $assistant) = $this->conversacion('¿Qué cargo?');

        $catalogo = $this->herramienta($conversation, $assistant, 'que_puedo_cargar', ['entidad' => 'article']);

        $por_campo = [];

        foreach ($catalogo['campos'] as $campo) {
            $por_campo[$campo['campo']] = $campo;
        }

        foreach (['percentage_gain', 'price', 'apply_provider_percentage_gain'] as $columna) {
            $this->assertArrayHasKey('no_aplica', $por_campo[$columna], $columna);
            $this->assertStringContainsString('margenes_por_lista', $por_campo[$columna]['no_aplica']);
        }

        $this->assertArrayNotHasKey('no_aplica', $por_campo['cost']);

        $this->dueno_con(['listas_de_precio' => 0]);

        $sin = $this->herramienta($conversation, $assistant, 'que_puedo_cargar', ['entidad' => 'article']);

        foreach ($sin['campos'] as $campo) {
            $this->assertArrayNotHasKey('no_aplica', $campo, $campo['campo']);
        }
    }

    // =====================================================================
    // J. Menores
    // =====================================================================

    /**
     * J. "1.500 unidades" son mil quinientas, no una y media; "1.000" de margen es mil.
     *
     * @test
     */
    public function el_punto_de_miles_se_lee_como_miles()
    {
        $this->assertSame(1000.0, PropuestaStockIaHelper::cantidad_de_stock('1.000'));
        $this->assertSame(1500.0, PropuestaStockIaHelper::cantidad_de_stock('1.500 unidades'));
        $this->assertSame(1500.5, PropuestaStockIaHelper::cantidad_de_stock('1.500,5'));
        $this->assertSame(1.5, PropuestaStockIaHelper::cantidad_de_stock('1.5'));
        $this->assertSame(20.5, PropuestaStockIaHelper::cantidad_de_stock('20,5'));
        $this->assertSame(1000.0, MargenesPorListaIaHelper::a_margen('1.000'));
        $this->assertSame(12.5, MargenesPorListaIaHelper::a_margen('12,5%'));

        $this->sin_depositos();

        list($conversation, $assistant) = $this->conversacion('Con 1.500 unidades');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'       => 'article',
            'datos'         => ['name' => 'zz-p62 Cera miles', 'cost' => 1000],
            'stock_inicial' => '1.500 unidades',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame('1500', $this->renglones($respuesta['tarjeta_id'])['Stock inicial']);
    }

    /**
     * J. Una lista con palabras de más no cae en otra: "lista 11" no es "Lista 1", y "mayorista
     * especial" (que no existe) no es "Mayorista". Contesta con las listas que hay.
     *
     * @test
     */
    public function una_lista_con_palabras_de_mas_no_cae_en_otra()
    {
        $this->con_listas();

        foreach (['Lista 1', 'Lista 2', 'Lista 3'] as $posicion => $nombre) {
            PriceType::create(['name' => $nombre, 'user_id' => $this->dueno->id, 'position' => 10 + $posicion]);
        }

        list($conversation, $assistant) = $this->conversacion('Margen 30');

        foreach (['lista 11', 'mayorista especial'] as $dicho) {

            $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
                'entidad'            => 'article',
                'datos'              => ['name' => 'zz-p62 Cera lista', 'cost' => 1000],
                'margenes_por_lista' => [['lista' => $dicho, 'margen' => 30]],
            ]);

            $this->assertFalse($respuesta['ok'], '"' . $dicho . '" cayó en otra lista: ' . json_encode($respuesta));
            $this->assertStringContainsString('no hay ninguna lista de precios que se llame', (string) $respuesta['error']);
        }

        // "la lista 1" sí es Lista 1 (lo que sobra es relleno), y "mayor" sigue siendo Mayorista.
        $bien = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'zz-p62 Cera lista bien', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'la lista 1', 'margen' => 30], ['lista' => 'mayor', 'margen' => 20]],
        ]);

        $this->assertTrue(!empty($bien['ok']), json_encode($bien));

        $renglones = $this->renglones($bien['tarjeta_id']);

        $this->assertSame('30 %', $renglones['Margen Lista 1']);
        $this->assertSame('20 %', $renglones['Margen Mayorista']);
    }

    /**
     * J. Un empleado con "solo su sucursal" y SIN sucursal asignada no recibe un `faltan` sin
     * opciones (una pregunta sin respuesta posible): recibe un error claro.
     *
     * @test
     */
    public function solo_su_sucursal_sin_sucursal_asignada_es_un_error_claro()
    {
        Address::create(['street' => 'zz-p62 Florida', 'user_id' => $this->dueno->id]);

        $empleado = $this->empleado(['article.store', 'article.edit_stock_only_sucursal']);

        list($conversation, $assistant) = $this->conversacion('Con 20 unidades', $empleado);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'       => 'article',
            'datos'         => ['name' => 'zz-p62 Cera sucursal', 'cost' => 1000],
            'stock_inicial' => 20,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame([], $respuesta['faltan'], 'Un faltan sin opciones no tiene respuesta: ' . json_encode($respuesta));
        $this->assertStringStartsWith('No se cargó nada:', (string) $respuesta['error']);
        $this->assertStringContainsString('ninguna asignada', (string) $respuesta['error']);
    }

    /**
     * J. Stock total sin cambio ("5 → 5") no arma tarjeta: no hay nada para cargar.
     *
     * @test
     */
    public function el_stock_total_sin_cambio_no_arma_tarjeta()
    {
        $this->sin_depositos();

        $this->articulo_de_prueba('zz-p62 Pastina igual', 5);

        list($conversation, $assistant) = $this->conversacion('Dejala en 5');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p62 Pastina igual',
            'cantidad' => 5,
            'modo'     => 'fijar',
        ]);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertStringStartsWith('No se cargó nada:', (string) $respuesta['error']);
        $this->assertStringContainsString('no hay nada para cambiar', (string) $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * J. La transición del deploy: un margen suelto guardado en una tarjeta de ANTES (cuando el
     * negocio no tenía listas, o antes de esta misión) que llega SOLO por herencia no corta con "no
     * lo mandes": se descarta y la tarjeta lo avisa.
     *
     * @test
     */
    public function lo_escondido_que_llega_solo_por_herencia_se_descarta_y_se_avisa()
    {
        $this->dueno_con(['listas_de_precio' => 0]);

        list($conversation, $assistant) = $this->conversacion('Margen 30');

        $vieja = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'zz-p62 Cera vieja', 'cost' => 1000, 'percentage_gain' => 30],
        ]);

        $this->assertTrue(!empty($vieja['ok']), json_encode($vieja));

        $this->con_listas();

        $corregida = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'zz-p62 Cera vieja mate'],
            'reemplaza_a' => $vieja['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($corregida['ok']), 'Lo heredado cortó la corrección: ' . json_encode($corregida));

        $tarjeta = AiMessageAction::find($corregida['tarjeta_id']);

        $this->assertArrayNotHasKey('percentage_gain', $tarjeta->datos['pedidos']);
        $this->assertTrue(!isset($tarjeta->datos['payload']['percentage_gain']) || is_null($tarjeta->datos['payload']['percentage_gain']));
        $this->assertStringContainsString('percentage_gain', (string) $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('no se carga', (string) $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('percentage_gain', (string) $corregida['aviso']);

        // Pero si el modelo lo MANDA en la corrección, sí corta.
        $mandado = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'zz-p62 Cera vieja mate', 'percentage_gain' => 30],
            'reemplaza_a' => $corregida['tarjeta_id'],
        ]);

        $this->assertFalse($mandado['ok']);
    }
}
