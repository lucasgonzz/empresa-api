<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\FormatoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPresupuestoIaHelper;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Budget;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-altas-y-conversacion (16/10/2026) — D3: habla como la pantalla y pregunta de más.
 *
 * 🔴 LOS CASOS REALES (WhatsApp, demo3, confianza "directo", DeepSeek Flash):
 *  - la masiva: "Dejé la tarjeta para confirmar… Confirmala" — en WhatsApp no hay tarjeta;
 *  - el combo: "te lo armo cuando me digas que sí" sin haber llamado a ninguna herramienta;
 *  - el presupuesto y la venta: preguntó la sucursal cada vez, y al crearlos no dijo el total;
 *  - el cliente: "con domicilio en Ferreteria El Clavo", que era la razón social.
 *
 * Lo que protege:
 *  - que por WhatsApp en "directo" el prompt diga que se llama a la herramienta en la misma vuelta
 *    (y no "preguntá si lo registrás"), y que en los otros modos quede como estaba;
 *  - que la nota de una propuesta que espera el sí NO hable de tarjeta en WhatsApp, y en el sistema sí;
 *  - que lo que el modelo recibe al ejecutar en "directo" traiga `detalle` con el número y el total;
 *  - que la sucursal ya dicha en la conversación no se vuelva a preguntar.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Whatsapp_directo_detalle_y_sucursal_Test extends EmpresaTestCase
{
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var string|null */
    protected $confianza_original = null;

    /** @var int|null */
    protected $address_id_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->confianza_original = $this->dueno->agente_confianza;
        $this->address_id_original = $this->dueno->address_id;

        foreach (['agentes_ia', PropuestaPresupuestoIaHelper::EXTENSION] as $slug) {

            $extencion = ExtencionEmpresa::where('slug', $slug)->first();

            if (is_null($extencion)) {
                $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
            }

            $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        }

        $this->dueno->load('extencions');
    }

    protected function tearDown(): void
    {
        /* Interruptores globales de la cuenta: vuelven a como estaban (ver test 51). */
        User::where('id', $this->dueno->id)->update([
            'agente_confianza' => $this->confianza_original,
            'address_id'       => $this->address_id_original,
        ]);

        parent::tearDown();
    }

    protected function dueno_en($modo)
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $modo]);

        $this->dueno = $this->dueno->fresh();
    }

    protected function actuar_como_el_dueno()
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($this->dueno, 'web');
    }

    protected function articulo_nuevo($nombre, $precio)
    {
        return Article::create([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => $precio,
            'cost'        => $precio / 2,
            'stock'       => 10,
            'iva_id'      => 2,
        ]);
    }

    /**
     * @param  string  $pedido
     * @param  string|null  $canal  null = el panel del sistema.
     * @param  AiConversation|null  $conversation  Para seguir una conversación que ya existe.
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido, $canal = null, $conversation = null)
    {
        if (is_null($conversation)) {
            $conversation = AiConversation::create([
                'user_id'      => $this->dueno->id,
                'auth_user_id' => $this->dueno->id,
            ]);
        }

        $user = ['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo'];
        $assistant = ['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true];

        if (! is_null($canal)) {
            $user['canal'] = $canal;
            $assistant['canal'] = $canal;
        }

        AiMessage::create($user);

        return [$conversation, AiMessage::create($assistant)];
    }

    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    // ---------------------------------------------------------------------
    // El prompt de WhatsApp según la confianza
    // ---------------------------------------------------------------------

    /**
     * 🔴 El combo prometido: en "directo" por WhatsApp se llama a la herramienta en la misma vuelta.
     *
     * @test
     */
    public function por_whatsapp_en_directo_el_prompt_dice_que_se_hace_en_la_misma_vuelta()
    {
        $service = new AsistenteIaService();

        list($conversation) = $this->conversacion('hola', AiMessage::CANAL_WHATSAPP);

        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        $directo = $service->build_system_prompt($conversation, $this->dueno, true, true);

        $this->assertStringContainsString('en la MISMA vuelta, sin preguntar antes si lo hacés', $directo);
        $this->assertStringContainsString('te lo armo cuando me digas que sí', $directo);
        $this->assertStringNotContainsString('preguntá si lo registrás', $directo);

        // Las que siempre esperan el sí siguen confirmándose por texto, sin la palabra tarjeta.
        $this->assertStringContainsString('Lo que SIEMPRE espera el sí', $directo);
        $this->assertStringContainsString('confirmar_carga_pendiente', $directo);

        foreach ([ConfianzaDelAgenteIaHelper::CAUTELOSO, ConfianzaDelAgenteIaHelper::RESUELTO] as $modo) {

            $this->dueno_en($modo);

            $prompt = $service->build_system_prompt($conversation, $this->dueno, true, true);

            $this->assertStringContainsString('preguntá si lo registrás', $prompt, $modo);
            $this->assertStringNotContainsString('en la MISMA vuelta, sin preguntar antes si lo hacés', $prompt, $modo);
        }
    }

    // ---------------------------------------------------------------------
    // La nota de una propuesta que espera el sí
    // ---------------------------------------------------------------------

    /**
     * 🔴 "Dejé la tarjeta para confirmar… Confirmala": por WhatsApp la nota no habla de tarjeta.
     *
     * @test
     */
    public function por_whatsapp_la_nota_de_lo_que_espera_el_si_no_habla_de_tarjeta()
    {
        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);

        Category::create(['name' => 'ZZP70 Categoria para borrar', 'user_id' => $this->dueno->id, 'num' => 99701]);

        // Por WhatsApp: borrar espera el sí, y la nota lo dice sin "tarjeta".
        list($conversation, $assistant) = $this->conversacion('borrá la categoría zzp70', AiMessage::CANAL_WHATSAPP);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_baja', [
            'entidad'  => 'category',
            'registro' => 'ZZP70 Categoria para borrar',
        ]);

        $this->assertTrue(! empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(HerramientasDeCarga::NOTA_PROPUESTA_POR_TEXTO, $respuesta['nota']);
        $this->assertStringContainsString('no digas "tarjeta"', $respuesta['nota']);

        // En el sistema, la de siempre: ahí sí hay tarjeta.
        list($conversation, $assistant) = $this->conversacion('borrá la categoría zzp70');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_baja', [
            'entidad'  => 'category',
            'registro' => 'ZZP70 Categoria para borrar',
        ]);

        $this->assertSame(AccionesIaHelper::NOTA_PROPUESTA, $respuesta['nota']);
    }

    // ---------------------------------------------------------------------
    // El detalle de lo ejecutado
    // ---------------------------------------------------------------------

    /**
     * 🔴 "Listo, quedó el presupuesto N° 900004" sin el total: el total llega en `detalle`.
     *
     * @test
     */
    public function en_directo_el_presupuesto_ejecutado_le_llega_al_modelo_con_su_total()
    {
        $this->dueno_en(ConfianzaDelAgenteIaHelper::DIRECTO);
        $this->actuar_como_el_dueno();

        $this->articulo_nuevo('ZZP70 Ducha de mano metal Gloa', 22415.01);
        $this->articulo_nuevo('ZZP70 Llave Allen 9 piezas', 16284.40);

        list($conversation, $assistant) = $this->conversacion('presupuesto para cliente contado', AiMessage::CANAL_WHATSAPP);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items'    => [
                ['articulo' => 'zzp70 duchas gloa', 'cantidad' => 3],
                ['articulo' => 'zzp70 llave allen', 'cantidad' => 2],
            ],
            'cliente'  => TestingFerreteriaSeeder::CLIENTE_CONTADO,
            'sucursal' => $this->una_sucursal()->street,
        ]);

        $this->assertSame('confirmada', isset($respuesta['estado']) ? $respuesta['estado'] : null, json_encode($respuesta));
        $this->assertSame(ConfirmacionPorTextoIaHelper::NOTA_EJECUTADA, $respuesta['nota']);
        $this->assertStringContainsString('`detalle`', $respuesta['nota']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $presupuesto = Budget::find((int) $tarjeta->resultado->presupuesto_id);

        $this->assertNotNull($presupuesto);

        // El texto, igual que siempre; el número y el total, en el detalle.
        $this->assertSame('Presupuesto N° ' . (int) $presupuesto->num . ' creado', $respuesta['resultado']);
        $this->assertSame((int) $presupuesto->num, $respuesta['detalle']['numero']);
        $this->assertSame(FormatoIaHelper::monto((float) $presupuesto->total), $respuesta['detalle']['total']);

        // 3 × 22.415,01 + 2 × 16.284,40 = 99.813,83 (el de demo3).
        $this->assertEqualsWithDelta(99813.83, (float) $presupuesto->total, 0.05);
    }

    /**
     * El detalle es una lista cerrada: rutas, ids y parámetros de la pantalla no viajan.
     *
     * @test
     */
    public function el_detalle_lleva_solo_las_claves_que_el_modelo_puede_repetir()
    {
        $detalle = ConfirmacionPorTextoIaHelper::detalle_del_resultado((object) [
            'texto'                  => 'Cliente Juan Carlos Mendez creado',
            'ruta'                   => ['name' => 'client', 'params' => []],
            'id'                     => 27,
            'campos_guardados'       => [['etiqueta' => 'razón social', 'valor' => 'Ferreteria El Clavo']],
            'campos_que_no_quedaron' => [],
            'total'                  => 1500.5,
        ]);

        $this->assertSame(['total', 'campos_guardados'], array_keys($detalle));
        $this->assertSame('razón social', $detalle['campos_guardados'][0]['etiqueta']);

        $this->assertSame([], ConfirmacionPorTextoIaHelper::detalle_del_resultado(null));
    }

    // ---------------------------------------------------------------------
    // La sucursal ya dicha
    // ---------------------------------------------------------------------

    protected function una_sucursal()
    {
        $sucursal = Address::where('user_id', $this->dueno->id)->orderBy('id')->first();

        if (is_null($sucursal)) {
            $sucursal = Address::create(['user_id' => $this->dueno->id, 'street' => 'ZZP70 Sucursal unica']);
        }

        return $sucursal;
    }

    /**
     * 🔴 "¿De qué sucursal?" en el presupuesto y otra vez en la venta: la segunda carga de la charla
     * usa la que ya se dijo.
     *
     * @test
     */
    public function la_sucursal_ya_dicha_en_la_conversacion_no_se_vuelve_a_preguntar()
    {
        $this->dueno_en(ConfianzaDelAgenteIaHelper::CAUTELOSO);

        // Sin sucursal propia y con varias: el caso de demo3.
        User::where('id', $this->dueno->id)->update(['address_id' => null]);
        $this->dueno = $this->dueno->fresh();

        Address::create(['user_id' => $this->dueno->id, 'street' => 'ZZP70 Sucursal Norte']);
        $sur = Address::create(['user_id' => $this->dueno->id, 'street' => 'ZZP70 Sucursal Sur']);

        $this->articulo_nuevo('ZZP70 Timbre inalambrico', 8080.86);

        $item = [['articulo' => 'zzp70 timbre', 'cantidad' => 2]];

        // Primera carga de la charla, sin sucursal: pregunta.
        list($conversation, $assistant) = $this->conversacion('presupuesto de 2 timbres');

        $sin = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items'   => $item,
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ]);

        $this->assertTrue(empty($sin['ok']));
        $this->assertNotEmpty($sin['faltan'], json_encode($sin));

        // La persona la dice: queda en esa.
        $con = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items'    => $item,
            'cliente'  => TestingFerreteriaSeeder::CLIENTE_CONTADO,
            'sucursal' => 'zzp70 sucursal sur',
        ]);

        $this->assertTrue(! empty($con['ok']), json_encode($con));

        // Mensaje siguiente de la MISMA conversación, sin sucursal: toma la Sur, no pregunta.
        list($conversation, $siguiente) = $this->conversacion('y otro igual para cliente contado', null, $conversation);

        $otro = $this->herramienta($conversation, $siguiente, 'proponer_presupuesto', [
            'items'   => [['articulo' => 'zzp70 timbre', 'cantidad' => 3]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ]);

        $this->assertTrue(! empty($otro['ok']), 'Volvió a preguntar la sucursal: ' . json_encode($otro));

        $tarjeta = AiMessageAction::find($otro['tarjeta_id']);

        $this->assertSame((int) $sur->id, (int) $tarjeta->datos['payload']['address_id']);

        // Otra conversación no hereda nada: pregunta.
        list($nueva, $assistant_nuevo) = $this->conversacion('presupuesto de 2 timbres');

        $de_cero = $this->herramienta($nueva, $assistant_nuevo, 'proponer_presupuesto', [
            'items'   => $item,
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ]);

        $this->assertTrue(empty($de_cero['ok']), json_encode($de_cero));
    }
}
