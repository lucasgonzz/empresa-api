<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
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
 * Misión agente-mejoras-uso-real (E2 punto 7, 29/10/2026) — el precio fijo que se perdía al ponerle
 * margen a una lista.
 *
 * El caso real: Pablo (Ferre Total, CONV 12, mensajes 57-62) pidió «ponele 50 %» al artículo que en
 * LISTA GENERAL tenía precio FIJO de $ 5.000; la edición le sacó el precio fijo y el precio BAJÓ a
 * $ 4.682,70 sin avisarle (la tarjeta solo decía "precio fijo $ 5.000 → 50 %"). Lo que protege:
 *  - la tarjeta de edición suma, por lista que cambia, "Precio <lista>: hoy → queda", con la MISMA
 *    cuenta que va a hacer el sistema al confirmar (lo que se estimó es lo que queda);
 *  - con precio fijo, el aviso dice que pasa a calcularse, cuánto queda y si es más barato, y que para
 *    conservarlo no hay que cambiarle el margen a esa lista;
 *  - con un precio que baja (sin precio fijo), el aviso lo dice; si sube, no hay aviso;
 *  - el renglón "Margen <lista>" sigue diciendo lo mismo de siempre;
 *  - estimar no deja nada escrito.
 *
 * Mismo molde que 60_Alta_con_margen_por_lista_y_stock_Test (fixture TestingFerreteriaSeeder, con
 * las listas Minorista 15 %, Mayorista 10 %, Distribuidor 5 %).
 */
class Precio_fijo_al_cambiar_el_margen_Test extends EmpresaTestCase
{
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var ExtencionEmpresa[] */
    protected $extensiones_enganchadas = [];

    /** @var array */
    protected $columnas_originales = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba-e2']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->columnas_originales = [
            'listas_de_precio' => $this->dueno->listas_de_precio,
            'agente_confianza' => $this->dueno->agente_confianza,
        ];

        $this->dar_extension('agentes_ia');

        $this->dueno_con(['agente_confianza' => 'cauteloso', 'listas_de_precio' => 1]);

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
     * @return void
     */
    protected function actuar_como_el_dueno()
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($this->dueno, 'web');
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
     * @param  string  $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido)
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo']);

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

        $this->assertFalse($resultado['is_error'], 'Falla técnica: ' . $resultado['content']);

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

        $this->actuar_como_el_dueno();

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /**
     * Da de alta un artículo con margen en la Minorista y lo confirma (como lo carga el agente).
     *
     * @param  string  $nombre
     * @return Article
     */
    protected function alta_confirmada($nombre)
    {
        list($conversation, $assistant) = $this->conversacion('Dame de alta ' . $nombre);

        /*
         * Con categoría propia: si el dueño del fixture ya tiene categorías en la base del slot (pasa en
         * las bases que se usaron para probar a mano), el alta pide una y no arma la tarjeta. El test no
         * es sobre la categoría, así que se la da (dentro de la transacción del test).
         */
        $categoria = \App\Models\Category::create(['name' => 'Categoría zz-e2 ' . uniqid(), 'user_id' => $this->dueno->id]);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad'            => 'article',
            'datos'              => ['name' => $nombre, 'cost' => 1000, 'category_id' => $categoria->id],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 30]],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        return Article::where('user_id', $this->dueno->id)->where('name', $nombre)->firstOrFail();
    }

    /**
     * @param  int  $article_id
     * @param  int  $price_type_id
     * @return object|null
     */
    protected function pivote($article_id, $price_type_id)
    {
        return DB::table('article_price_type')->where('article_id', $article_id)->where('price_type_id', $price_type_id)->first();
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
     * "$ 5.000" → 5000.0, "$ 4.682,70" → 4682.7.
     *
     * @param  string  $monto
     * @return float
     */
    protected function a_numero($monto)
    {
        return (float) str_replace(['$', ' ', '.', ','], ['', '', '', '.'], $monto);
    }

    /**
     * 🔴 El caso de Pablo: precio fijo alto en una lista y «ponele un margen»: la tarjeta dice el precio
     * de hoy, el que queda, que es más barato y que deja de ser fijo; y lo estimado es lo que queda.
     *
     * @test
     */
    public function con_precio_fijo_la_tarjeta_avisa_que_pasa_a_calcularse_y_cuanto_queda()
    {
        $articulo = $this->alta_confirmada('Sellador zz-e2 zingueria');

        $mayorista = $this->lista('Mayorista');

        // La Mayorista con precio FIJO de $ 99.999 (como el $ 5.000 de Pablo: más alto que el calculado).
        DB::table('article_price_type')
            ->where('article_id', $articulo->id)
            ->where('price_type_id', $mayorista->id)
            ->update(['setear_precio_final' => 1, 'final_price' => 99999]);

        list($conversation, $assistant) = $this->conversacion('Ponele 50 % en la mayorista');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'            => 'article',
            'registro'           => 'Sellador zz-e2 zingueria',
            'cambios'            => [],
            'margenes_por_lista' => [['lista' => 'Mayorista', 'margen' => 50]],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $renglones = $this->renglones($respuesta['tarjeta_id']);

        // El renglón de siempre, igual.
        $this->assertSame('precio fijo $ 99.999 → 50 %', $renglones['Margen Mayorista']);

        // Y el precio: hoy → queda.
        $this->assertArrayHasKey('Precio Mayorista', $renglones, json_encode($renglones));
        $this->assertStringStartsWith('$ 99.999 → $ ', $renglones['Precio Mayorista']);

        $estimado = $this->a_numero(explode(' → ', $renglones['Precio Mayorista'])[1]);
        $this->assertLessThan(99999, $estimado);

        // El aviso: en la tarjeta y en la respuesta al modelo.
        $aviso = AiMessageAction::find($respuesta['tarjeta_id'])->presentacion['aviso'];

        $this->assertStringContainsString('Hoy Mayorista tiene precio fijo de $ 99.999', $aviso);
        $this->assertStringContainsString('pasa a calcularse', $aviso);
        $this->assertStringContainsString('más barato', $aviso);
        $this->assertStringContainsString('Para conservar el precio fijo, no le cambies el margen a esa lista', $aviso);
        $this->assertSame($aviso, $respuesta['aviso']);

        // Estimar no dejó nada escrito: el pivote sigue con su precio fijo.
        $antes = $this->pivote($articulo->id, $mayorista->id);
        $this->assertSame(1, (int) $antes->setear_precio_final);
        $this->assertEqualsWithDelta(99999, (float) $antes->final_price, self::DELTA);

        // Al confirmar, queda EXACTAMENTE lo estimado.
        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $despues = $this->pivote($articulo->id, $mayorista->id);

        $this->assertSame(0, (int) $despues->setear_precio_final);
        $this->assertEqualsWithDelta($estimado, (float) $despues->final_price, self::DELTA, 'Lo que mostró la tarjeta es lo que quedó.');
    }

    /**
     * Sin precio fijo: si el precio baja hay aviso; si sube, solo el renglón del precio.
     *
     * @test
     */
    public function sin_precio_fijo_avisa_solo_cuando_el_precio_baja()
    {
        $articulo = $this->alta_confirmada('Rodillo zz-e2 lana');

        $minorista = $this->lista('Minorista');
        $precio_hoy = (float) $this->pivote($articulo->id, $minorista->id)->final_price;

        // Baja: de 30 % a 10 %.
        list($conversation, $assistant) = $this->conversacion('Bajale el margen de la minorista a 10');

        $baja = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'            => 'article',
            'registro'           => 'Rodillo zz-e2 lana',
            'cambios'            => [],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 10]],
        ]);

        $this->assertTrue(!empty($baja['ok']), json_encode($baja));

        $renglones = $this->renglones($baja['tarjeta_id']);

        $this->assertSame('30 % → 10 %', $renglones['Margen Minorista']);
        $this->assertStringStartsWith('$ ', $renglones['Precio Minorista']);
        $this->assertEqualsWithDelta($precio_hoy, $this->a_numero(explode(' → ', $renglones['Precio Minorista'])[0]), self::DELTA);
        $this->assertStringContainsString('Con margen 10 % el precio de Minorista baja de', $baja['aviso']);

        // Sube: de 30 % a 40 %. Sin aviso de precio.
        list($conversation, $assistant) = $this->conversacion('Subile el margen de la minorista a 40');

        $sube = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'            => 'article',
            'registro'           => 'Rodillo zz-e2 lana',
            'cambios'            => [],
            'margenes_por_lista' => [['lista' => 'Minorista', 'margen' => 40]],
        ]);

        $this->assertTrue(!empty($sube['ok']), json_encode($sube));
        $this->assertArrayHasKey('Precio Minorista', $this->renglones($sube['tarjeta_id']));
        $this->assertArrayNotHasKey('aviso', $sube);

        // Y nada quedó escrito por las estimaciones.
        $this->assertEqualsWithDelta(30, (float) $this->pivote($articulo->id, $minorista->id)->percentage, self::DELTA);
        $this->assertEqualsWithDelta($precio_hoy, (float) $this->pivote($articulo->id, $minorista->id)->final_price, self::DELTA);
    }

    /**
     * 🔴 Con el dueño en "directo" (como Pablo: su edición se ejecutó en el acto), una edición que le
     * saca el precio fijo a una lista NO se ejecuta sola: queda la tarjeta con el aviso.
     *
     * @test
     */
    public function en_directo_la_edicion_que_saca_un_precio_fijo_queda_para_confirmar()
    {
        $articulo = $this->alta_confirmada('Sopapa zz-e2 directo');

        $mayorista = $this->lista('Mayorista');

        DB::table('article_price_type')
            ->where('article_id', $articulo->id)
            ->where('price_type_id', $mayorista->id)
            ->update(['setear_precio_final' => 1, 'final_price' => 99999]);

        $this->dueno_con(['agente_confianza' => 'directo']);

        list($conversation, $assistant) = $this->conversacion('Ponele 50 % en la mayorista');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'            => 'article',
            'registro'           => 'Sopapa zz-e2 directo',
            'cambios'            => [],
            'margenes_por_lista' => [['lista' => 'Mayorista', 'margen' => 50]],
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertTrue($respuesta['requiere_confirmacion']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado(), 'Queda la tarjeta: no se ejecutó sola.');

        $pivote = $this->pivote($articulo->id, $mayorista->id);
        $this->assertSame(1, (int) $pivote->setear_precio_final, 'El precio fijo sigue hasta que la persona confirme.');
        $this->assertEqualsWithDelta(99999, (float) $pivote->final_price, self::DELTA);
    }

    /**
     * La herramienta le dice al modelo qué hacer con el aviso.
     *
     * @test
     */
    public function la_descripcion_de_la_edicion_explica_el_precio_fijo()
    {
        $descripcion = null;

        foreach (HerramientasDeCarga::definiciones(false) as $herramienta) {
            if ($herramienta['name'] === 'proponer_edicion') {
                $descripcion = $herramienta['description'];
            }
        }

        $this->assertStringContainsString('ANTES de que confirme', $descripcion);
        $this->assertStringContainsString('para conservar un precio fijo no se le cambia el margen a esa lista', $descripcion);
    }
}
