<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PermisosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaGastoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaTareaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\ExpenseCategory;
use App\Models\ExpenseConcept;
use App\Models\ExtencionEmpresa;
use App\Models\Pending;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-altas-y-conversacion (16/10/2026) — D7: escrituras a medias.
 *
 * 🔴 LOS CASOS REALES (WhatsApp, demo3, confianza "directo"):
 *  - "creala vos, ponele servicios" → "No puedo crear categorías nuevas de gasto: eso se carga desde la
 *    pantalla de Gastos". Sí puede: expense_concept es una entidad del ABM genérico. La respuesta de
 *    proponer_gasto no lo decía y las descripciones decían "NO es para gastos".
 *  - "recordame el viernes a las 10 que tengo que llamar a…" → la tarea quedó el viernes a las 00:00.
 *  - "armame un combo… a 28 mil" sin la extensión → "te lo armo" y recién al confirmar "no tenés Combos".
 *
 * Lo que protege:
 *  - que proponer_gasto con una subcategoría que no está diga cómo crearla, que las descripciones lo
 *    permitan, y que en "directo" la subcategoría se cree por el ABM genérico;
 *  - que la tarea guarde la hora (escrita adelante del detalle), que pregunte una hora que no
 *    entiende, y que el resultado diga que no hay aviso por WhatsApp;
 *  - que el prompt nombre los módulos que el negocio no tiene.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Gasto_tarea_con_hora_y_modulos_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var string|null */
    protected $confianza_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->confianza_original = $this->dueno->agente_confianza;

        $this->dar_extension('asistente_ia');
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

        parent::tearDown();
    }

    protected function dar_extension($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');
    }

    protected function sacar_extension($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (! is_null($extencion)) {
            $this->dueno->extencions()->detach($extencion->id);
        }

        $this->dueno->load('extencions');
    }

    protected function en_directo()
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);

        $this->dueno = $this->dueno->fresh();

        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($this->dueno, 'web');
    }

    protected function conversacion($pedido)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        foreach ([['rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo'], ['rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]] as $mensaje) {
            $mensaje['ai_conversation_id'] = $conversation->id;
            $mensaje['canal'] = AiMessage::CANAL_WHATSAPP;
            $ultimo = AiMessage::create($mensaje);
        }

        return [$conversation, $ultimo];
    }

    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    protected function descripcion_de($herramienta)
    {
        foreach (HerramientasDeCarga::definiciones(true) as $definicion) {
            if ($definicion['name'] === $herramienta) {
                return $definicion['description'];
            }
        }

        $this->fail($herramienta . ' no está declarada');
    }

    // ---------------------------------------------------------------------
    // La subcategoría de gasto que falta
    // ---------------------------------------------------------------------

    /**
     * 🔴 "No puedo crear categorías nuevas de gasto": la respuesta y las descripciones dicen que sí.
     *
     * @test
     */
    public function el_gasto_con_una_subcategoria_que_no_esta_dice_como_crearla()
    {
        list($conversation, $assistant) = $this->conversacion('anotá un gasto de 45 mil de la luz');

        $inexistente = $this->herramienta($conversation, $assistant, 'proponer_gasto', ['subcategoria_id' => 999999, 'monto' => 45000]);

        $this->assertNotEmpty($inexistente['error']);
        $this->assertSame(PropuestaGastoIaHelper::SI_NO_ESTA, $inexistente['opciones']['si_no_esta']);
        $this->assertStringContainsString('expense_concept', $inexistente['opciones']['si_no_esta']);

        $sin = $this->herramienta($conversation, $assistant, 'proponer_gasto', ['monto' => 45000]);

        $this->assertNotEmpty($sin['faltan']);
        $this->assertSame(PropuestaGastoIaHelper::SI_NO_ESTA, $sin['opciones']['si_no_esta']);

        // Y las dos descripciones que la mandaban a la pantalla ahora la incluyen.
        $this->assertStringContainsString('expense_concept', $this->descripcion_de('que_puedo_cargar'));
        $this->assertStringContainsString('expense_concept', $this->descripcion_de('proponer_alta'));
        $this->assertStringNotContainsString('NO es para gastos, pagos', $this->descripcion_de('que_puedo_cargar'));
    }

    /**
     * En "directo" la subcategoría nueva se crea en el acto por el ABM genérico, colgada de su categoría.
     *
     * @test
     */
    public function en_directo_la_subcategoria_de_gasto_se_crea_por_el_abm_generico()
    {
        $this->en_directo();

        $categoria = ExpenseCategory::create(['name' => 'ZZP71 Fijos', 'user_id' => $this->dueno->id]);

        list($conversation, $assistant) = $this->conversacion('creala vos, ponele servicios');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'expense_concept',
            'datos'   => ['name' => 'ZZP71 Servicios', 'expense_category_id' => 'ZZP71 Fijos'],
        ]);

        $this->assertSame('confirmada', isset($respuesta['estado']) ? $respuesta['estado'] : null, json_encode($respuesta));

        $concepto = ExpenseConcept::where('user_id', $this->dueno->id)->where('name', 'ZZP71 Servicios')->first();

        $this->assertNotNull($concepto, 'No se creó la subcategoría de gasto.');
        $this->assertSame((int) $categoria->id, (int) $concepto->expense_category_id);

        // Y el modelo recibe su id en la misma vuelta, para cargar el gasto sin volver a buscarla.
        $this->assertSame(['entidad' => 'expense_concept', 'id' => (int) $concepto->id], $respuesta['detalle']['registro']);

        // El prompt también lo dice.
        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->dueno, true, true);

        $this->assertStringContainsString('creás vos con proponer_alta de expense_concept', $prompt);
    }

    // ---------------------------------------------------------------------
    // La tarea con hora
    // ---------------------------------------------------------------------

    /** @test */
    public function la_hora_se_entiende_en_las_formas_en_que_se_dice()
    {
        $this->assertSame('10:00', PropuestaTareaIaHelper::hora_legible('10'));
        $this->assertSame('10:00', PropuestaTareaIaHelper::hora_legible('10hs'));
        $this->assertSame('10:30', PropuestaTareaIaHelper::hora_legible('10:30'));
        $this->assertSame('10:30', PropuestaTareaIaHelper::hora_legible('10.30'));
        $this->assertSame('09:05', PropuestaTareaIaHelper::hora_legible('9 05'));
        $this->assertSame('18:00', PropuestaTareaIaHelper::hora_legible('18 horas'));

        $this->assertNull(PropuestaTareaIaHelper::hora_legible('25'));
        $this->assertNull(PropuestaTareaIaHelper::hora_legible('10:75'));
        $this->assertNull(PropuestaTareaIaHelper::hora_legible('a la mañana'));
    }

    /**
     * 🔴 "recordame el viernes a las 10": la hora queda escrita en la tarea y el resultado dice que no
     * hay aviso por WhatsApp.
     *
     * @test
     */
    public function la_tarea_guarda_la_hora_y_no_promete_un_aviso()
    {
        $this->en_directo();

        $viernes = Carbon::now()->next(Carbon::FRIDAY)->format('Y-m-d');

        list($conversation, $assistant) = $this->conversacion('recordame el viernes a las 10 que tengo que llamar a ferretera del sur');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => 'ZZP71 Llamar a Ferretera del Sur para reclamar 2 duchas',
            'fecha'   => $viernes,
            'hora'    => '10',
        ]);

        $this->assertSame('confirmada', isset($respuesta['estado']) ? $respuesta['estado'] : null, json_encode($respuesta));

        $tarea = Pending::where('user_id', $this->dueno->id)->where('detalle', 'like', '%ZZP71 Llamar a Ferretera%')->first();

        $this->assertNotNull($tarea);
        $this->assertSame('10:00 · ZZP71 Llamar a Ferretera del Sur para reclamar 2 duchas', $tarea->detalle);
        $this->assertSame($viernes, Carbon::parse($tarea->fecha_realizacion)->format('Y-m-d'));

        $this->assertSame(PropuestaTareaIaHelper::AVISO_SIN_RECORDATORIO, $respuesta['detalle']['aviso']);
        $this->assertStringContainsString('no le llega ningún aviso por WhatsApp', $respuesta['detalle']['aviso']);

        // Una hora que no se entiende no se adivina: se pregunta, y no se crea nada.
        $antes = Pending::where('user_id', $this->dueno->id)->count();

        $rara = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => 'ZZP71 Otra cosa',
            'fecha'   => $viernes,
            'hora'    => '25',
        ]);

        $this->assertNotEmpty($rara['faltan']);
        $this->assertSame($antes, Pending::where('user_id', $this->dueno->id)->count());
    }

    /**
     * Corregir solo la hora reemplaza la tarjeta (no deja dos) y no apila horas en el detalle.
     *
     * @test
     */
    public function corregir_la_hora_reemplaza_la_tarjeta_sin_apilar_horas()
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => ConfianzaDelAgenteIaHelper::CAUTELOSO]);

        $viernes = Carbon::now()->next(Carbon::FRIDAY)->format('Y-m-d');

        list($conversation, $assistant) = $this->conversacion('recordame el viernes a las 10 llamar a ferretera del sur');

        $primera = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => 'ZZP71 Llamar a Ferretera del Sur',
            'fecha'   => $viernes,
            'hora'    => '10',
        ]);

        $this->assertTrue(! empty($primera['ok']), json_encode($primera));

        // El modelo reenvía el detalle de la tarjeta, con la hora vieja adelante.
        $segunda = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => '10:00 · ZZP71 Llamar a Ferretera del Sur',
            'fecha'   => $viernes,
            'hora'    => '11',
        ]);

        $this->assertTrue(! empty($segunda['ok']), json_encode($segunda));

        $nueva = AiMessageAction::find($segunda['tarjeta_id']);

        $this->assertSame('11:00 · ZZP71 Llamar a Ferretera del Sur', $nueva->datos['detalle']);
        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, AiMessageAction::find($primera['tarjeta_id'])->estado_guardado());

        // Un detalle largo no pasa el largo de la columna.
        $larga = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => 'ZZP71 ' . str_repeat('x', 300),
            'fecha'   => $viernes,
            'hora'    => '9',
        ]);

        $this->assertLessThanOrEqual(255, mb_strlen(AiMessageAction::find($larga['tarjeta_id'])->datos['detalle']));
    }

    // ---------------------------------------------------------------------
    // Los módulos que el negocio no tiene
    // ---------------------------------------------------------------------

    /**
     * 🔴 El combo prometido sin la extensión: el prompt lo dice desde el primer mensaje.
     *
     * @test
     */
    public function el_prompt_nombra_los_modulos_que_el_negocio_no_tiene()
    {
        list($conversation) = $this->conversacion('armame un combo');

        $service = new AsistenteIaService();

        $this->sacar_extension(PermisosIaHelper::EXTENCION_COMBOS);

        $sin_combos = $service->build_system_prompt($conversation, $this->dueno->fresh(), true, true);

        $this->assertStringContainsString('Módulos que este negocio NO tiene', $sin_combos);
        $this->assertStringContainsString('Combos (no podés armar combos)', $sin_combos);

        $this->dar_extension(PermisosIaHelper::EXTENCION_COMBOS);

        $con_combos = $service->build_system_prompt($conversation, $this->dueno->fresh(), true, true);

        $this->assertStringNotContainsString('Combos (no podés armar combos)', $con_combos);

        // Sin las herramientas de carga no hay nada que prometer: la línea no va.
        $this->sacar_extension(PermisosIaHelper::EXTENCION_COMBOS);

        $solo_lectura = $service->build_system_prompt($conversation, $this->dueno->fresh(), false, true);

        $this->assertStringNotContainsString('Módulos que este negocio NO tiene', $solo_lectura);
    }
}
