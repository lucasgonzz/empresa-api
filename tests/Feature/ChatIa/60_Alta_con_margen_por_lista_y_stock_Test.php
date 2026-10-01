<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión alta-por-agente-margen-y-stock (29/9/2026) — EL MARGEN POR LISTA en el alta y la edición de
 * un artículo por el asistente.
 *
 * De dónde sale: demo3, 29/9/2026, conversación 12, artículo 17320 (Cera Nic). El dueño dictó
 * "costo de 10.000 y un margen de ganancia del 30 % para la lista general". El agente mandó
 * `percentage_gain: 30` con `price_types: []`, la tarjeta se ejecutó sola (modo directo), el
 * resultado dijo "creado" con `campos_que_no_quedaron: []`, y las DOS listas del negocio quedaron
 * con margen 0 % y precio 10.000 = el costo. `percentage_gain` solo mueve `articles.final_price`,
 * un precio que en una cuenta con listas la ficha ni siquiera muestra.
 *
 * Lo que protege este archivo:
 *  - 🔴 El margen por lista entra por el MISMO camino que la ficha (`price_types` con
 *    `pivot.percentage` → attach_price_types → setFinalPrice) y el precio que queda es IGUAL al de
 *    la pantalla: el test 1 crea el mismo artículo por `POST api/article` con el `price_types` que
 *    manda la ficha y compara lista por lista, sin ningún número cableado.
 *  - 🔴 Con listas, un margen suelto (`percentage_gain` o su alias) se RECHAZA con un error que dice
 *    que el margen va por lista y que hay que preguntar cuál (decisión 1 de Lucas).
 *  - La edición mueve el pivote de verdad (decisión 2 de Lucas), y una lista con precio fijo pasa a
 *    margen.
 *  - La tarjeta del alta dice SIEMPRE con qué margen queda cada lista no nombrada.
 *  - En modo "directo", el caso de demo3 de punta a punta (con listas y sin depósitos): el
 *    resultado trae el precio real de la lista y el stock que quedó.
 *  - Las definiciones (lo nuevo al final, `deposito` ya no obligatorio) y que_puedo_cargar con las
 *    listas y los depósitos del negocio.
 *
 * Los casos del stock (inicial en el alta y total sin depósitos) están en
 * 61_Stock_inicial_y_stock_sin_depositos_Test.
 *
 * El dueño de testing (user 500 del TestingFerreteriaSeeder) tiene 4 listas (Distribuidor 5 %,
 * Mayorista 10 %, Minorista 15 %, Tienda Nube 50 %) y `listas_de_precio = 0`: cada test prende lo
 * que necesita. Todo corre dentro de la transacción de EmpresaTestCase, y las dos columnas que son
 * interruptores globales de la cuenta (`listas_de_precio`, `agente_confianza`) se devuelven a mano en
 * tearDown(), igual que en 51_Modo_directo_y_escalado_Test.
 *
 * 🔴 Ningún test sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Alta_con_margen_por_lista_y_stock_Test extends EmpresaTestCase
{
    /** Delta para comparar precios y porcentajes guardados en decimal(…,2). */
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var array<int,ExtencionEmpresa> Extensiones enganchadas por este archivo. */
    protected $extensiones_enganchadas = [];

    /** @var array Las columnas del dueño que este archivo mueve, como estaban antes (ver tearDown). */
    protected $columnas_originales = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba-p60']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->columnas_originales = [
            'listas_de_precio' => $this->dueno->listas_de_precio,
            'agente_confianza' => $this->dueno->agente_confianza,
        ];

        $this->dar_extension('asistente_ia');

        // En "cauteloso" el alta deja tarjeta y se confirma por el endpoint de la pantalla.
        $this->dueno_con(['agente_confianza' => 'cauteloso']);

        Catalogo::olvidar();
    }

    protected function tearDown(): void
    {
        /* Los interruptores globales vuelven a como estaban, aunque el rollback no alcance. */
        User::where('id', $this->dueno->id)->update($this->columnas_originales);

        foreach ($this->extensiones_enganchadas as $extencion) {
            $this->dueno->extencions()->detach($extencion->id);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Engancha una extensión al dueño (creando la fila si la base no la tiene).
     *
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
     * Guarda columnas del dueño y lo vuelve a autenticar con el modelo fresco.
     *
     * @param  array  $columnas
     * @return void
     */
    protected function dueno_con(array $columnas)
    {
        User::where('id', $this->dueno->id)->update($columnas);

        $this->dueno = User::find($this->dueno->id);

        $this->actuar_como_el_dueno();
    }

    /**
     * El negocio trabaja con listas de precio por artículo (`users.listas_de_precio = 1`), como demo3.
     *
     * @return void
     */
    protected function con_listas()
    {
        $this->dueno_con(['listas_de_precio' => 1]);
    }

    /**
     * Vuelve a autenticar al dueño en el guard `web` (después de una request HTTP sanctum queda
     * como guard por defecto). Mismo motivo que en 51_Modo_directo_y_escalado_Test.
     *
     * @return void
     */
    protected function actuar_como_el_dueno()
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($this->dueno, 'web');
    }

    /**
     * Una lista del dueño por nombre.
     *
     * @param  string  $nombre
     * @return PriceType
     */
    protected function lista($nombre)
    {
        return PriceType::where('user_id', $this->dueno->id)->where('name', $nombre)->firstOrFail();
    }

    /**
     * Conversación del dueño con su pedido y el assistant pendiente con las acciones habilitadas.
     *
     * @param  string  $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido = 'Dame de alta este producto')
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
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
     * Ejecuta una herramienta de carga por el despacho real y devuelve lo que ve el modelo.
     *
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
     * Confirma la tarjeta por el endpoint real, el mismo que aprieta la persona.
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

        $this->actuar_como_el_dueno();

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /**
     * Los renglones de la tarjeta como [etiqueta => valor].
     *
     * @param  int  $tarjeta_id
     * @return array<string, string>
     */
    protected function renglones($tarjeta_id)
    {
        return array_column(AiMessageAction::find($tarjeta_id)->presentacion['renglones'], 'valor', 'etiqueta');
    }

    /**
     * El pivote artículo–lista, leído de la base en este instante.
     *
     * @param  int  $article_id
     * @param  int  $price_type_id
     * @return object|null
     */
    protected function pivote($article_id, $price_type_id)
    {
        return DB::table('article_price_type')
            ->where('article_id', (int) $article_id)
            ->where('price_type_id', (int) $price_type_id)
            ->first();
    }

    /**
     * El `price_types` que manda la FICHA al crear un artículo: cada lista del negocio con
     * `pivot.incluir_en_excel_para_clientes` y `pivot.setear_precio_final` de la lista
     * (`model_functions.js::set_article_price_types`), más el `pivot.percentage` que la persona tipeó
     * en las listas que tipeó (el input de la solapa, `properties_to_set` de `src/models/article.js`).
     *
     * @param  array<string, float>  $margenes  [nombre de la lista => margen tipeado]
     * @return array
     */
    protected function price_types_de_la_ficha(array $margenes)
    {
        $price_types = [];

        foreach (PriceType::where('user_id', $this->dueno->id)->orderBy('position')->get() as $lista) {

            $fila = $lista->toArray();

            $fila['pivot'] = [
                'incluir_en_excel_para_clientes' => $lista->incluir_en_lista_de_precios_de_excel,
                'setear_precio_final'            => $lista->setear_precio_final,
            ];

            if (array_key_exists($lista->name, $margenes)) {
                $fila['pivot']['percentage'] = $margenes[$lista->name];
            }

            $price_types[] = $fila;
        }

        return $price_types;
    }

    /**
     * Propone y confirma el alta de un artículo con esos márgenes; devuelve el artículo creado y la
     * respuesta de la confirmación.
     *
     * @param  string  $nombre
     * @param  array  $margenes
     * @param  float  $costo
     * @return array{0: Article, 1: \Illuminate\Testing\TestResponse, 2: int}
     */
    protected function alta_confirmada($nombre, array $margenes, $costo = 10000)
    {
        list($conversation, $assistant) = $this->conversacion('Dame de alta ' . $nombre);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => $nombre, 'cost' => $costo],
            'margenes_por_lista' => $margenes,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', $nombre)->first();

        $this->assertNotNull($articulo, 'El alta no creó el artículo.');

        return [$articulo, $confirmacion, (int) $respuesta['tarjeta_id']];
    }

    // =====================================================================
    // 1. El margen por lista queda IGUAL que desde la ficha
    // =====================================================================

    /**
     * 🔴 EL CAMINO CRÍTICO. Con listas, `margenes_por_lista` arma la tarjeta con el renglón de la
     * lista y el aviso de las demás; al confirmar, el pivote de Minorista queda en 30 % sin precio
     * fijo y el precio de CADA lista es exactamente el que da la pantalla creando el mismo artículo
     * por `POST api/article` con el `price_types` de la ficha.
     *
     * @test
     */
    public function el_margen_por_lista_queda_igual_que_desde_la_ficha()
    {
        $this->con_listas();

        list($conversation, $assistant) = $this->conversacion('Cera zz-p60, costo 10000, margen 30 para la minorista');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 asistente', 'cost' => 10000],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 30]],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $renglones = $this->renglones($tarjeta->id);

        $this->assertSame('30 %', $renglones['Margen Minorista'], json_encode($renglones));

        // El aviso dice con qué margen queda cada lista que la persona NO nombró.
        $this->assertStringContainsString('Las demás listas quedan con su margen por defecto', (string) $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('Distribuidor 5 %', (string) $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('Tienda Nube 50 %', (string) $tarjeta->presentacion['aviso']);
        $this->assertStringNotContainsString('Minorista', (string) $tarjeta->presentacion['aviso']);

        // Y el modelo lo recibe en la respuesta, no solo la persona en la tarjeta.
        $this->assertStringContainsString('margen por defecto', (string) $respuesta['aviso']);

        // El margen no va al payload como campo: se arma como `price_types` al ejecutar.
        $this->assertArrayNotHasKey('percentage_gain', $tarjeta->datos['payload']);
        $this->assertSame([], $tarjeta->datos['payload']['price_types']);

        $confirmacion = $this->confirmar($conversation, $assistant, $tarjeta->id);

        $confirmacion->assertStatus(200);

        $del_asistente = Article::where('user_id', $this->dueno->id)->where('name', 'Cera zz-p60 asistente')->first();

        $this->assertNotNull($del_asistente);

        $minorista = $this->lista('Minorista');

        $pivote = $this->pivote($del_asistente->id, $minorista->id);

        $this->assertNotNull($pivote, 'El artículo no quedó asociado a la lista Minorista.');
        $this->assertEqualsWithDelta(30, (float) $pivote->percentage, self::DELTA);
        $this->assertSame(0, (int) $pivote->setear_precio_final, 'Un margen dictado no puede quedar como precio fijo.');

        // El resultado dice el precio REAL de la lista, releído del pivote.
        $texto = (string) $confirmacion->json('model.resultado.texto');

        $this->assertStringContainsString('margen 30 % en Minorista', $texto);
        $this->assertStringContainsString(\App\Http\Controllers\Helpers\asistente_ia\FormatoIaHelper::monto($pivote->final_price), $texto);

        /*
         * La PANTALLA: el mismo payload de la tarjeta, con otro nombre y el `price_types` que manda
         * la ficha cuando la persona tipea 30 en la Minorista.
         */
        $payload = $tarjeta->datos['payload'];
        $payload['name'] = 'Cera zz-p60 pantalla';
        $payload['price_types'] = $this->price_types_de_la_ficha(['Minorista' => 30]);

        $this->actuar_como_el_dueno();

        $this->postJson('api/article', $payload)->assertStatus(201);

        $de_la_pantalla = Article::where('user_id', $this->dueno->id)->where('name', 'Cera zz-p60 pantalla')->first();

        $this->assertNotNull($de_la_pantalla);

        foreach (PriceType::where('user_id', $this->dueno->id)->get() as $lista) {

            $asistente = $this->pivote($del_asistente->id, $lista->id);
            $pantalla = $this->pivote($de_la_pantalla->id, $lista->id);

            $this->assertNotNull($asistente, 'El alta del asistente no tiene la lista ' . $lista->name);
            $this->assertNotNull($pantalla, 'El alta de la pantalla no tiene la lista ' . $lista->name);

            $this->assertEqualsWithDelta((float) $pantalla->final_price, (float) $asistente->final_price, self::DELTA, 'El precio de ' . $lista->name . ' no es el de la pantalla.');
            $this->assertEqualsWithDelta((float) $pantalla->percentage, (float) $asistente->percentage, self::DELTA, 'El margen de ' . $lista->name . ' no es el de la pantalla.');
        }

        // Y la Minorista con 30 % vale más que el costo: el margen movió el precio de verdad.
        $this->assertGreaterThan(10000, (float) $pivote->final_price);
    }

    // =====================================================================
    // 2. Con listas, el margen suelto se rechaza
    // =====================================================================

    /**
     * 🔴 LO QUE PASÓ EN DEMO3. Con listas, `percentage_gain` (y su alias "margen de ganancia") corta
     * con un error que nombra las listas y dice que hay que preguntar cuál. No queda tarjeta.
     *
     * @test
     */
    public function con_listas_el_margen_suelto_se_rechaza_y_nombra_las_listas()
    {
        $this->con_listas();

        foreach (['percentage_gain', 'margen de ganancia'] as $clave) {

            list($conversation, $assistant) = $this->conversacion('Margen 30');

            $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
                'entidad' => 'article',
                'datos'   => ['name' => 'Cera zz-p60 suelto', 'cost' => 10000, $clave => 30],
            ]);

            $this->assertFalse($respuesta['ok'], json_encode($respuesta));
            $this->assertStringContainsString('POR LISTA', (string) $respuesta['error']);
            $this->assertStringContainsString('margenes_por_lista', (string) $respuesta['error']);
            $this->assertStringContainsString('preguntale', (string) $respuesta['error']);
            $this->assertStringContainsString('Minorista', (string) $respuesta['error']);

            $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Con el error no queda tarjeta.');
        }

        $this->assertNull(Article::where('user_id', $this->dueno->id)->where('name', 'Cera zz-p60 suelto')->first());
    }

    // =====================================================================
    // 3. "todas"
    // =====================================================================

    /**
     * "todas" expande a las 4 listas con el mismo margen, y sin listas sin nombrar no hay aviso de
     * "las demás".
     *
     * @test
     */
    public function todas_las_listas_llevan_el_mismo_margen()
    {
        $this->con_listas();

        list($conversation, $assistant) = $this->conversacion('Margen 25 a todas');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 todas', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'todas', 'margen' => '25%']],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $renglones = $this->renglones($respuesta['tarjeta_id']);

        foreach (['Distribuidor', 'Mayorista', 'Minorista', 'Tienda Nube'] as $nombre) {
            $this->assertSame('25 %', $renglones['Margen ' . $nombre], json_encode($renglones));
        }

        $this->assertStringNotContainsString('margen por defecto', (string) AiMessageAction::find($respuesta['tarjeta_id'])->presentacion['aviso']);

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Cera zz-p60 todas')->first();

        foreach (PriceType::where('user_id', $this->dueno->id)->get() as $lista) {
            $this->assertEqualsWithDelta(25, (float) $this->pivote($articulo->id, $lista->id)->percentage, self::DELTA, $lista->name);
        }
    }

    /**
     * Una lista nombrada le gana a "todas" ("30 a todas y 40 a la minorista"), en cualquier orden.
     *
     * @test
     */
    public function una_lista_nombrada_le_gana_a_todas()
    {
        $this->con_listas();

        list($conversation, $assistant) = $this->conversacion('30 a todas y 40 a la minorista');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 mezcla', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'la minorista', 'margen' => 40], ['lista' => 'todas', 'margen' => 30]],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $renglones = $this->renglones($respuesta['tarjeta_id']);

        $this->assertSame('40 %', $renglones['Margen Minorista']);
        $this->assertSame('30 %', $renglones['Margen Mayorista']);

        // Mandado como texto JSON (DeepSeek a veces serializa los arrays anidados) también se entiende.
        $como_texto = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 texto', 'cost' => 1000],
            'margenes_por_lista' => '[{"lista": "Distribuidor", "margen": "12,5"}]',
        ]);

        $this->assertTrue(!empty($como_texto['ok']), json_encode($como_texto));
        $this->assertSame('12,50 %', $this->renglones($como_texto['tarjeta_id'])['Margen Distribuidor']);
    }

    // =====================================================================
    // 4. Lista inexistente y lista ambigua
    // =====================================================================

    /**
     * Una lista que no existe corta con las listas que sí hay; una que encaja con dos vuelve como
     * `faltan` con las opciones; y la exacta le gana a la parecida.
     *
     * @test
     */
    public function lista_inexistente_da_error_y_ambigua_da_faltan()
    {
        $this->con_listas();

        PriceType::create([
            'name'     => 'Mayorista Especial',
            'user_id'  => $this->dueno->id,
            'position' => 9,
        ]);

        list($conversation, $assistant) = $this->conversacion('Margen 30 a la lista oro');

        $inexistente = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 oro', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'Lista Oro', 'margen' => 30]],
        ]);

        $this->assertFalse($inexistente['ok']);
        $this->assertStringContainsString('Lista Oro', (string) $inexistente['error']);
        $this->assertStringContainsString('Distribuidor', (string) $inexistente['error']);

        $ambigua = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 mayor', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'Mayor', 'margen' => 30]],
        ]);

        $this->assertFalse($ambigua['ok']);
        $this->assertNotEmpty($ambigua['faltan']);
        $this->assertSame(['Mayorista', 'Mayorista Especial'], array_column($ambigua['opciones']['listas'], 'nombre'));

        $exacta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 mayorista', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'mayorista', 'margen' => 30]],
        ]);

        $this->assertTrue(!empty($exacta['ok']), json_encode($exacta));
        $this->assertSame('30 %', $this->renglones($exacta['tarjeta_id'])['Margen Mayorista']);

        // Sin lista: se pregunta para cuál.
        $sin_lista = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 sin lista', 'cost' => 1000],
            'margenes_por_lista' => [['margen' => 30]],
        ]);

        $this->assertFalse($sin_lista['ok']);
        $this->assertStringContainsString('para qué lista', implode(' ', $sin_lista['faltan']));

        // Y la misma lista dos veces es un error, no "gana la última".
        $repetida = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 repetida', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 30], ['lista' => 'minorista', 'margen' => 40]],
        ]);

        $this->assertFalse($repetida['ok']);
        $this->assertStringContainsString('dos veces', (string) $repetida['error']);
    }

    // =====================================================================
    // 5. Sin listas
    // =====================================================================

    /**
     * En una cuenta SIN listas, margenes_por_lista corta y dice dónde va el margen; percentage_gain
     * sigue andando como siempre.
     *
     * @test
     */
    public function sin_listas_el_margen_va_en_percentage_gain()
    {
        $this->dueno_con(['listas_de_precio' => 0]);

        list($conversation, $assistant) = $this->conversacion('Margen 30');

        $por_lista = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 sin listas', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 30]],
        ]);

        $this->assertFalse($por_lista['ok']);
        $this->assertStringContainsString('no trabaja con listas', (string) $por_lista['error']);
        $this->assertStringContainsString('percentage_gain', (string) $por_lista['error']);

        $suelto = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'article',
            'datos'   => ['name' => 'Cera zz-p60 sin listas', 'cost' => 1000, 'percentage_gain' => 30],
        ]);

        $this->assertTrue(!empty($suelto['ok']), json_encode($suelto));

        $tarjeta = AiMessageAction::find($suelto['tarjeta_id']);

        // Sin listas no hay aviso de listas.
        $this->assertStringNotContainsString('margen por defecto', (string) $tarjeta->presentacion['aviso']);

        $this->confirmar($conversation, $assistant, $tarjeta->id)->assertStatus(200);

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Cera zz-p60 sin listas')->first();

        $this->assertEqualsWithDelta(30, (float) $articulo->percentage_gain, self::DELTA);
    }

    // =====================================================================
    // 6. Lista con precio fijo por defecto
    // =====================================================================

    /**
     * 🔴 Una lista marcada como "precio fijo" por defecto (`setear_precio_final = 1`) con un margen
     * dictado queda SIN precio fijo y el margen aplica: con el 1, el cálculo ignora el porcentaje.
     * Se compara contra otra lista con el mismo margen y sin precio fijo: tienen que dar igual.
     *
     * @test
     */
    public function una_lista_con_precio_fijo_por_defecto_toma_el_margen_dictado()
    {
        $this->con_listas();

        $minorista = $this->lista('Minorista');
        $minorista->setear_precio_final = 1;
        $minorista->save();

        $mayorista = $this->lista('Mayorista');

        list($articulo) = $this->alta_confirmada('Cera zz-p60 fijo', [
            ['lista' => 'Minorista', 'margen' => 30],
            ['lista' => 'Mayorista', 'margen' => 30],
        ]);

        $pivote_minorista = $this->pivote($articulo->id, $minorista->id);
        $pivote_mayorista = $this->pivote($articulo->id, $mayorista->id);

        $this->assertSame(0, (int) $pivote_minorista->setear_precio_final);
        $this->assertEqualsWithDelta(30, (float) $pivote_minorista->percentage, self::DELTA);
        $this->assertEqualsWithDelta((float) $pivote_mayorista->final_price, (float) $pivote_minorista->final_price, self::DELTA, 'Con el mismo margen, las dos listas tienen que dar el mismo precio.');
        $this->assertGreaterThan(10000, (float) $pivote_minorista->final_price);
    }

    // =====================================================================
    // 10. La corrección hereda el margen
    // =====================================================================

    /**
     * "Sí, pero cambiale el nombre": la corrección hereda el margen por lista de la tarjeta que
     * reemplaza; `[]` explícito lo saca.
     *
     * @test
     */
    public function la_correccion_hereda_el_margen_y_vacio_lo_saca()
    {
        $this->con_listas();

        list($conversation, $assistant) = $this->conversacion('Dame de alta la cera');

        $primera = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 hereda', 'cost' => 1000],
            'margenes_por_lista' => [['lista' => 'la minorista', 'margen' => 30]],
        ]);

        $this->assertTrue(!empty($primera['ok']), json_encode($primera));

        $corregida = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'     => 'article',
            'datos'       => ['name' => 'Cera zz-p60 hereda mate'],
            'reemplaza_a' => $primera['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($corregida['ok']), json_encode($corregida));

        $extras = AiMessageAction::find($corregida['tarjeta_id'])->datos['extras'];

        $this->assertSame((int) $this->lista('Minorista')->id, (int) $extras['margenes_por_lista'][0]['price_type_id']);
        $this->assertEquals(30, $extras['margenes_por_lista'][0]['margen']);
        $this->assertSame('30 %', $this->renglones($corregida['tarjeta_id'])['Margen Minorista']);

        $sin_margen = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 hereda mate'],
            'margenes_por_lista' => [],
            'reemplaza_a'        => $corregida['tarjeta_id'],
        ]);

        $this->assertTrue(!empty($sin_margen['ok']), json_encode($sin_margen));

        $datos = AiMessageAction::find($sin_margen['tarjeta_id'])->datos;

        $this->assertTrue(empty($datos['extras']['margenes_por_lista']), 'Con [] el margen heredado se saca: ' . json_encode($datos));
        $this->assertArrayNotHasKey('Margen Minorista', $this->renglones($sin_margen['tarjeta_id']));
    }

    // =====================================================================
    // 11. La edición
    // =====================================================================

    /**
     * 🔴 "Cambiale el margen de la minorista a 35": renglón "antes → después", y al confirmar el
     * pivote queda en 35 y el precio sube. Una lista con precio fijo en el pivote pasa a margen.
     *
     * @test
     */
    public function la_edicion_mueve_el_margen_de_la_lista_y_saca_el_precio_fijo()
    {
        $this->con_listas();

        list($articulo) = $this->alta_confirmada('Cera zz-p60 edicion', [['lista' => 'Minorista', 'margen' => 30]]);

        $minorista = $this->lista('Minorista');
        $mayorista = $this->lista('Mayorista');

        $precio_antes = (float) $this->pivote($articulo->id, $minorista->id)->final_price;

        // La Mayorista con precio fijo en el pivote del artículo.
        DB::table('article_price_type')
            ->where('article_id', $articulo->id)
            ->where('price_type_id', $mayorista->id)
            ->update(['setear_precio_final' => 1, 'final_price' => 99999]);

        list($conversation, $assistant) = $this->conversacion('Cambiale el margen de la minorista a 35 y el de la mayorista a 20');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'            => 'article',
            'registro'           => 'Cera zz-p60 edicion',
            'cambios'            => [],
            'margenes_por_lista' => [['lista' => 'minorista', 'margen' => 35], ['lista' => 'Mayorista', 'margen' => 20]],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $renglones = $this->renglones($respuesta['tarjeta_id']);

        $this->assertSame('30 % → 35 %', $renglones['Margen Minorista']);
        $this->assertSame('precio fijo $ 99.999 → 20 %', $renglones['Margen Mayorista']);

        $confirmacion = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmacion->assertStatus(200);

        $pivote_minorista = $this->pivote($articulo->id, $minorista->id);
        $pivote_mayorista = $this->pivote($articulo->id, $mayorista->id);

        $this->assertEqualsWithDelta(35, (float) $pivote_minorista->percentage, self::DELTA);
        $this->assertGreaterThan($precio_antes, (float) $pivote_minorista->final_price, 'Con más margen, el precio de la lista tiene que subir.');

        $this->assertSame(0, (int) $pivote_mayorista->setear_precio_final, 'La lista con precio fijo pasa a margen.');
        $this->assertEqualsWithDelta(20, (float) $pivote_mayorista->percentage, self::DELTA);
        $this->assertNotEqualsWithDelta(99999, (float) $pivote_mayorista->final_price, self::DELTA);

        $texto = (string) $confirmacion->json('model.resultado.texto');

        $this->assertStringContainsString('margen 35 % en Minorista', $texto);
        $this->assertStringContainsString('margen 20 % en Mayorista', $texto);

        // Las demás listas no se tocaron: la Distribuidor sigue con su margen por defecto.
        $this->assertEqualsWithDelta(5, (float) $this->pivote($articulo->id, $this->lista('Distribuidor')->id)->percentage, self::DELTA);
    }

    /**
     * En la edición también: con listas, el margen suelto se rechaza; y un margen igual al que ya
     * tiene la lista da "ya está así".
     *
     * @test
     */
    public function la_edicion_con_margen_suelto_o_sin_cambios_corta()
    {
        $this->con_listas();

        list($articulo) = $this->alta_confirmada('Cera zz-p60 edicion corta', [['lista' => 'Minorista', 'margen' => 30]]);

        list($conversation, $assistant) = $this->conversacion('Cambiale el margen a 35');

        $suelto = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'article',
            'registro' => 'Cera zz-p60 edicion corta',
            'cambios'  => ['percentage_gain' => 35],
        ]);

        $this->assertFalse($suelto['ok']);
        $this->assertStringContainsString('margenes_por_lista', (string) $suelto['error']);

        $igual = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'            => 'article',
            'registro'           => 'Cera zz-p60 edicion corta',
            'cambios'            => [],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 30]],
        ]);

        $this->assertFalse($igual['ok']);
        $this->assertStringContainsString('ya está así', (string) $igual['error']);

        // Y metido adentro de `cambios` también se entiende (el modelo a veces lo manda ahí).
        $adentro = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'article',
            'registro' => 'Cera zz-p60 edicion corta',
            'cambios'  => ['margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 40]]],
        ]);

        $this->assertTrue(!empty($adentro['ok']), json_encode($adentro));
        $this->assertSame('30 % → 40 %', $this->renglones($adentro['tarjeta_id'])['Margen Minorista']);
    }

    // =====================================================================
    // 13. Modo directo, como demo3
    // =====================================================================

    /**
     * 🔴 EL CASO DE DEMO3 DE PUNTA A PUNTA: cuenta con listas, SIN depósitos y el dueño en
     * "directo". El alta se ejecuta sola y el `resultado` —lo único que el modelo repite— trae el
     * precio REAL de la lista (releído del pivote) y el stock que quedó en la fila.
     *
     * @test
     */
    public function en_directo_el_alta_se_ejecuta_sola_y_el_resultado_trae_el_precio_y_el_stock()
    {
        $this->con_listas();

        $this->dueno_con(['agente_confianza' => 'directo']);

        Address::where('user_id', $this->dueno->id)->whereNull('buyer_id')->delete();

        list($conversation, $assistant) = $this->conversacion(
            'Dame de alta este producto, costo de 10 000 y un margen de ganancia del 30 % para la lista minorista y ponele un stock de 20 unidades'
        );

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => 'Cera zz-p60 directo', 'cost' => 10000],
            'margenes_por_lista' => [['lista' => 'minorista', 'margen' => 30]],
            'stock_inicial'      => 20,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame('confirmada', $respuesta['estado'], 'En directo el alta se ejecuta sola: ' . json_encode($respuesta));

        $articulo = Article::where('user_id', $this->dueno->id)->where('name', 'Cera zz-p60 directo')->first();

        $this->assertNotNull($articulo);

        $pivote = $this->pivote($articulo->id, $this->lista('Minorista')->id);

        $this->assertEqualsWithDelta(30, (float) $pivote->percentage, self::DELTA);
        $this->assertEqualsWithDelta(20, (float) DB::table('articles')->where('id', $articulo->id)->value('stock'), self::DELTA);

        $texto = (string) $respuesta['resultado'];

        $this->assertStringContainsString('margen 30 % en Minorista (precio ' . \App\Http\Controllers\Helpers\asistente_ia\FormatoIaHelper::monto($pivote->final_price) . ')', $texto);
        $this->assertStringContainsString('stock inicial 20', $texto);
        $this->assertStringNotContainsString(', pero ', $texto, 'No falló nada: el resultado no puede traer fallas.');
    }

    // =====================================================================
    // 14. Las definiciones y que_puedo_cargar
    // =====================================================================

    /**
     * Las propiedades nuevas van AL FINAL (regla del prefijo del caché), la descripción del alta ya
     * no dice "con el costo y el margen el precio sale solo" sin la salvedad de las listas, y
     * proponer_stock_en_deposito no exige el depósito.
     *
     * @test
     */
    public function las_definiciones_llevan_lo_nuevo_al_final_y_sin_deposito_obligatorio()
    {
        $por_nombre = [];

        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            $por_nombre[$definicion['name']] = $definicion;
        }

        $alta = $por_nombre['proponer_alta'];

        $this->assertSame(
            ['margenes_por_lista', 'stock_inicial', 'deposito'],
            array_slice(array_keys($alta['input_schema']['properties']), -3),
            'Lo nuevo va al final de las propiedades (prefijo del caché).'
        );

        $this->assertSame(['entidad', 'datos'], $alta['input_schema']['required'], 'Lo nuevo es opcional.');
        $this->assertStringContainsString('margenes_por_lista', $alta['description']);
        $this->assertStringContainsString('stock_inicial', $alta['description']);
        $this->assertStringContainsString('NO se carga', $alta['description']);
        $this->assertStringContainsString('POR LISTA', $alta['description']);

        $edicion = $por_nombre['proponer_edicion'];

        $propiedades_de_edicion = array_keys($edicion['input_schema']['properties']);

        $this->assertSame('margenes_por_lista', end($propiedades_de_edicion));

        $stock = $por_nombre['proponer_stock_en_deposito'];

        $this->assertNotContains('deposito', $stock['input_schema']['required']);
        $this->assertArrayHasKey('deposito', $stock['input_schema']['properties'], 'deposito sigue existiendo: un cliente MCP viejo lo manda.');
    }

    /**
     * que_puedo_cargar de `article`, por la herramienta (con el contexto del negocio), dice si el
     * margen va por lista y cuáles son, y si hay depósitos. Sin contexto (el recurso del MCP) queda
     * como siempre.
     *
     * @test
     */
    public function que_puedo_cargar_de_article_cuenta_las_listas_y_los_depositos_del_negocio()
    {
        $this->con_listas();

        list($conversation, $assistant) = $this->conversacion('¿Qué puedo cargar de un artículo?');

        $catalogo = $this->herramienta($conversation, $assistant, 'que_puedo_cargar', ['entidad' => 'article']);

        $this->assertTrue($catalogo['listas_de_precio']['trabaja_con_listas']);
        $this->assertStringContainsString('margenes_por_lista', $catalogo['listas_de_precio']['el_margen_va']);
        $this->assertContains('Minorista', array_column($catalogo['listas_de_precio']['listas'], 'nombre'));
        $this->assertTrue($catalogo['depositos']['tiene_depositos']);

        $this->dueno_con(['listas_de_precio' => 0]);

        Address::where('user_id', $this->dueno->id)->whereNull('buyer_id')->delete();

        $sin = $this->herramienta($conversation, $assistant, 'que_puedo_cargar', ['entidad' => 'article']);

        $this->assertFalse($sin['listas_de_precio']['trabaja_con_listas']);
        $this->assertStringContainsString('percentage_gain', $sin['listas_de_precio']['el_margen_va']);
        $this->assertFalse($sin['depositos']['tiene_depositos']);

        $sin_contexto = Catalogo::que_puedo_cargar('article');

        $this->assertArrayNotHasKey('listas_de_precio', $sin_contexto);
        $this->assertArrayNotHasKey('depositos', $sin_contexto);
    }
}
