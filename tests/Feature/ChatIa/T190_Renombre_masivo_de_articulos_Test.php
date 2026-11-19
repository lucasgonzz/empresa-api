<?php

namespace Tests\Feature\ChatIa;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\article\RenombreEnMasivaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PresupuestoDeOperacionesIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\MasiveUpdate;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\AsistenteIa\Mcp\McpHerramientasHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261119 (rama C, A4) — el renombre masivo de artículos.
 *
 * EL CASO REAL (Ferre Total, conv. 72, mensajes 700-723): «cambiá de cada artículo ESMALTE AC. MULT. por
 * ESMALTE ACCIÓN MÚLTIPLE». El agente lo prometió «como actualización masiva», Pablo confirmó y recién
 * ahí le dijo que no había herramienta que renombre en masa. Lo que protege este archivo:
 *
 *  - proponer_renombre_masivo arma UNA tarjeta con la cantidad y ejemplos «antes → después» calculados
 *    con la misma función que aplica la cola, y no toca nada hasta el sí (ni con el dueño en "directo");
 *  - 🔴 al confirmar se encola por el camino de la actualización masiva y cambia SOLO ese pedazo del
 *    nombre de los artículos del dueño: los de otro negocio con el mismo texto no se tocan;
 *  - la reversión del historial devuelve el nombre anterior de cada artículo;
 *  - los filtros (proveedor) acotan, y un artículo que entró después de la tarjeta no se renombra;
 *  - el reemplazo es literal, sin distinguir mayúsculas, y vaciar el texto no deja dobles espacios;
 *  - la herramienta está al final del registro, en NUNCA_AUTO_CONFIRMABLES y marcada destructiva en MCP.
 *
 * 🔴 Sin red: la clave de Anthropic va en null y la cola se falsea donde se encola.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T190_Renombre_masivo_de_articulos_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User Otro negocio con artículos de nombre parecido. */
    protected $otro;

    /** @var Provider */
    protected $pinturas;

    /** @var Article[] Los esmaltes del comercio (los que alcanza el renombre). */
    protected $esmaltes = [];

    /** @var Article Un esmalte de OTRO proveedor del mismo comercio. */
    protected $esmalte_de_otro_proveedor;

    /** @var Article Un artículo del comercio sin el texto. */
    protected $rodillo;

    /** @var Article El esmalte del OTRO negocio, con el mismo texto. */
    protected $esmalte_ajeno;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Ferre renombre C',
            'company_name'     => 'Ferre renombre C',
            'email'            => 'renombre-c-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            // "directo" a propósito: aun así el renombre espera el sí.
            'agente_confianza' => 'directo',
        ]);

        $this->otro = User::create([
            'name'     => 'Otro negocio renombre C',
            'email'    => 'renombre-c-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->pinturas = Provider::create(['name' => 'PINTURAS SUDAMERICANAS C', 'user_id' => $this->comercio->id]);
        $bulonera = Provider::create(['name' => 'BULONERA C', 'user_id' => $this->comercio->id]);

        foreach (['ESMALTE AC. MULT. 500 ML BLANCO', 'ESMALTE AC. MULT. 1 L NEGRO', 'Esmalte Ac. Mult. 4 L Azul'] as $nombre) {
            $this->esmaltes[] = $this->articulo($nombre, $this->comercio->id, $this->pinturas->id);
        }

        $this->esmalte_de_otro_proveedor = $this->articulo('ESMALTE AC. MULT. 250 ML ROJO', $this->comercio->id, $bulonera->id);
        $this->rodillo = $this->articulo('RODILLO LANA 22 CM', $this->comercio->id, $this->pinturas->id);
        $this->esmalte_ajeno = $this->articulo('ESMALTE AC. MULT. 500 ML BLANCO', $this->otro->id, null);
    }

    /**
     * @param  string  $nombre
     * @param  int  $user_id
     * @param  int|null  $provider_id
     * @return Article
     */
    protected function articulo($nombre, $user_id, $provider_id)
    {
        return Article::create([
            'name'            => $nombre,
            'user_id'         => $user_id,
            'status'          => 'active',
            'cost'            => 1000,
            'percentage_gain' => 30,
            'provider_id'     => $provider_id,
        ]);
    }

    /**
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion()
    {
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'necesito que cambies de cada artículo ESMALTE AC. MULT. Por ESMALTE ACCIÓN MÚLTIPLE',
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'contenido'            => 'Te dejé la tarjeta.',
            'estado'               => 'listo',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Corre la herramienta por el despacho real y devuelve lo que ve el modelo.
     */
    protected function proponer(AiConversation $conversation, AiMessage $assistant, array $input)
    {
        $this->actingAs($this->comercio);

        $resultado = HerramientasDeCarga::ejecutar('proponer_renombre_masivo', $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /**
     * Confirma la tarjeta como la persona y corre el job de la masiva a mano.
     *
     * @return MasiveUpdate
     */
    protected function confirmar_y_correr(AiConversation $conversation, $tarjeta_id)
    {
        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $this->actingAs($this->comercio);

        $resultado = EjecutorAccionesIaHelper::confirmar($conversation, (int) $tarjeta_id, $this->comercio, function () {
            return 1;
        });

        $this->assertSame(200, $resultado['status'], json_encode($resultado, JSON_UNESCAPED_UNICODE));

        $masiva = MasiveUpdate::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();
        $this->assertNotNull($masiva, 'El renombre se encola como una actualización masiva.');

        Notification::fake();
        MasiveUpdateHelper::process_update($masiva->fresh());

        $this->assertSame('completed', $masiva->fresh()->status);

        return $masiva->fresh();
    }

    /**
     * @test
     */
    public function la_tarjeta_trae_la_cantidad_y_los_ejemplos_y_no_toca_nada_hasta_el_si()
    {
        list($conversation, $assistant) = $this->conversacion();

        $r = $this->proponer($conversation, $assistant, ['buscar' => 'AC. MULT.', 'reemplazo' => 'ACCIÓN MÚLTIPLE']);

        $this->assertTrue($r['ok'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(4, $r['articulos_alcanzados'], 'Los cuatro esmaltes del comercio; nunca el del otro negocio ni el rodillo.');
        $this->assertContains('ESMALTE AC. MULT. 500 ML BLANCO → ESMALTE ACCIÓN MÚLTIPLE 500 ML BLANCO', $r['ejemplos']);
        $this->assertContains('Esmalte Ac. Mult. 4 L Azul → Esmalte ACCIÓN MÚLTIPLE 4 L Azul', $r['ejemplos'], 'No distingue mayúsculas.');

        $tarjeta = AiMessageAction::findOrFail($r['tarjeta_id']);

        $this->assertSame(AiMessageAction::TIPO_RENOMBRE_MASIVO, $tarjeta->tipo);
        $this->assertSame('propuesta', $tarjeta->estado, 'Ni en "directo" se aplica solo.');
        $this->assertContains(['etiqueta' => 'Artículos alcanzados', 'valor' => '4'], $tarjeta->presentacion['renglones']);
        $this->assertContains(['etiqueta' => 'Ejemplo', 'valor' => 'ESMALTE AC. MULT. 1 L NEGRO → ESMALTE ACCIÓN MÚLTIPLE 1 L NEGRO'], $tarjeta->presentacion['renglones']);
        $this->assertNotContains($this->esmalte_ajeno->id, $tarjeta->datos['ids']);

        $this->assertSame('ESMALTE AC. MULT. 500 ML BLANCO', $this->esmaltes[0]->fresh()->name, 'Nada se aplicó todavía.');
        $this->assertSame(0, MasiveUpdate::where('user_id', $this->comercio->id)->count());
    }

    /**
     * 🔴 El corazón del arreglo: al confirmar cambia SOLO ese pedazo del nombre, solo en los del dueño, y la
     * reversión devuelve los nombres de antes.
     *
     * @test
     */
    public function al_confirmar_renombra_solo_los_del_dueno_y_se_revierte_desde_el_historial()
    {
        list($conversation, $assistant) = $this->conversacion();

        $r = $this->proponer($conversation, $assistant, [
            'buscar'    => 'AC. MULT.',
            'reemplazo' => 'ACCIÓN MÚLTIPLE',
            'filtros'   => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'PINTURAS SUDAMERICANAS C']],
        ]);

        $this->assertTrue($r['ok'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, $r['articulos_alcanzados'], 'El filtro por proveedor deja afuera al esmalte de la bulonera.');

        $masiva = $this->confirmar_y_correr($conversation, $r['tarjeta_id']);

        $this->assertSame('ESMALTE ACCIÓN MÚLTIPLE 500 ML BLANCO', $this->esmaltes[0]->fresh()->name);
        $this->assertSame('ESMALTE ACCIÓN MÚLTIPLE 1 L NEGRO', $this->esmaltes[1]->fresh()->name);
        $this->assertSame('Esmalte ACCIÓN MÚLTIPLE 4 L Azul', $this->esmaltes[2]->fresh()->name);

        $this->assertSame('ESMALTE AC. MULT. 250 ML ROJO', $this->esmalte_de_otro_proveedor->fresh()->name, 'Fuera del filtro: no se toca.');
        $this->assertSame('RODILLO LANA 22 CM', $this->rodillo->fresh()->name);
        $this->assertSame('ESMALTE AC. MULT. 500 ML BLANCO', $this->esmalte_ajeno->fresh()->name, '🔴 El artículo de OTRO negocio no se toca nunca.');

        $this->assertSame(3, (int) $masiva->affected_count);

        // El historial guarda el nombre ENTERO de antes.
        $cambios = json_decode($masiva->articles()->where('articles.id', $this->esmaltes[0]->id)->first()->pivot->changes_json, true);
        $this->assertSame('ESMALTE AC. MULT. 500 ML BLANCO', $cambios['name']['old']);
        $this->assertSame('ESMALTE ACCIÓN MÚLTIPLE 500 ML BLANCO', $cambios['name']['new']);

        $this->assertTrue(MasiveUpdateHelper::can_revert($masiva));

        $reversion = MasiveUpdateHelper::create_pending_revert($masiva, $this->comercio->id);
        MasiveUpdateHelper::process_revert($reversion->fresh(), $masiva->fresh());

        $this->assertSame('ESMALTE AC. MULT. 500 ML BLANCO', $this->esmaltes[0]->fresh()->name, 'Revertir devuelve el nombre de antes.');
        $this->assertSame('Esmalte Ac. Mult. 4 L Azul', $this->esmaltes[2]->fresh()->name);
    }

    /**
     * Un artículo que entró DESPUÉS de la tarjeta no se renombra: la persona no lo vio.
     *
     * @test
     */
    public function un_articulo_que_entro_despues_de_la_tarjeta_no_se_renombra()
    {
        list($conversation, $assistant) = $this->conversacion();

        $r = $this->proponer($conversation, $assistant, ['buscar' => 'AC. MULT.', 'reemplazo' => 'ACCIÓN MÚLTIPLE']);
        $this->assertTrue($r['ok']);

        $nuevo = $this->articulo('ESMALTE AC. MULT. 4 L VERDE', $this->comercio->id, $this->pinturas->id);

        $this->confirmar_y_correr($conversation, $r['tarjeta_id']);

        $this->assertSame('ESMALTE ACCIÓN MÚLTIPLE 500 ML BLANCO', $this->esmaltes[0]->fresh()->name);
        $this->assertSame('ESMALTE AC. MULT. 4 L VERDE', $nuevo->fresh()->name);
    }

    /**
     * Sin artículos con el texto: error para el modelo, sin tarjeta.
     *
     * @test
     */
    public function sin_articulos_con_el_texto_no_arma_tarjeta()
    {
        list($conversation, $assistant) = $this->conversacion();

        $r = $this->proponer($conversation, $assistant, ['buscar' => 'ACRILICO SATINADO', 'reemplazo' => 'ACRÍLICO SATINADO']);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Ningún artículo activo', $r['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        $faltan = $this->proponer($conversation, $assistant, ['reemplazo' => 'X']);
        $this->assertNotEmpty($faltan['faltan']);
    }

    /**
     * El reemplazo es literal y sin distinguir mayúsculas; vaciar el texto no deja dobles espacios; un
     * artículo de otro dueño no se toca aunque llegue a la rama de la masiva.
     *
     * @test
     */
    public function el_reemplazo_es_literal_y_la_rama_de_la_masiva_no_toca_articulos_ajenos()
    {
        $this->assertSame('TORNILLO 6X2', RenombreEnMasivaHelper::reemplazar('TORNILLO OFERTA 6X2', 'oferta', ''));
        $this->assertSame('PRECIO $1 \\ OK', RenombreEnMasivaHelper::reemplazar('PRECIO X OK', 'x', '$1 \\'));
        $this->assertSame('CAÑO PVC', RenombreEnMasivaHelper::reemplazar('caño PVC', 'CAÑO', 'CAÑO'));

        $form = RenombreEnMasivaHelper::item_del_formulario('AC. MULT.', 'ACCIÓN MÚLTIPLE');

        // El modelo del otro negocio, con el dueño de ESTA masiva: no hay cambio.
        $this->assertNull(MasiveUpdateHelper::apply_form_change($this->esmalte_ajeno->fresh(), $form, $this->comercio));
        $this->assertSame('ESMALTE AC. MULT. 500 ML BLANCO', $this->esmalte_ajeno->fresh()->name);

        $cambio = MasiveUpdateHelper::apply_form_change($this->esmaltes[0]->fresh(), $form, $this->comercio);
        $this->assertSame('name', $cambio['prop_key']);
        $this->assertSame('ESMALTE AC. MULT. 500 ML BLANCO', $cambio['old_value']);
    }

    /**
     * Al final del registro, siempre con confirmación, escritura de artículos y destructiva en MCP.
     *
     * @test
     */
    public function la_herramienta_esta_registrada_y_nunca_se_auto_confirma()
    {
        $this->assertTrue(HerramientasDeCarga::maneja('proponer_renombre_masivo'));
        $this->assertContains(AiMessageAction::TIPO_RENOMBRE_MASIVO, HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES);
        $this->assertContains('proponer_renombre_masivo', McpHerramientasHelper::DESTRUCTIVAS);
        $this->assertTrue(PresupuestoDeOperacionesIaHelper::es_de_articulos(new AiMessageAction(['tipo' => AiMessageAction::TIPO_RENOMBRE_MASIVO])));

        $descripcion = null;

        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            if ($definicion['name'] === 'proponer_renombre_masivo') {
                $descripcion = $definicion['description'];
            }
        }

        $this->assertNotNull($descripcion);
        $this->assertStringContainsString('proponer_actualizacion_masiva NO cambia el nombre', $descripcion);
    }
}
