<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Caja;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseConcept;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261116, rama B — 30/10 #3: el gasto con categoría y subcategoría nuevas y
 * el criterio de caja.
 *
 * EL CASO REAL (Ferre Total, 30/10/2026, conv 31·221-240): «cargame un gasto del 1,2 % … como impuesto
 * al cheque». Después de 9 vueltas el agente pidió una caja que no había, dijo que el gasto quedaba «en
 * espera» y solo se creó la categoría «Gastos bancarios»: la subcategoría y el gasto, prometidos «apenas
 * la confirmes», nunca se crearon. Lo que protege este archivo:
 *
 *  - proponer_gasto acepta la subcategoría (y la categoría) NUEVAS por nombre y arma el alta de las dos
 *    y el gasto en UNA tarjeta; al confirmar se crean las tres juntas;
 *  - una categoría o subcategoría que ya existe (aunque esté escrita con otras mayúsculas o tildes) se
 *    reusa: nunca se duplica;
 *  - sin categoría para la subcategoría nueva, se pregunta con las categorías que hay;
 *  - caja: con UNA sola caja abierta se usa sola y se nombra; con cajas pero ninguna abierta, el gasto
 *    va sin caja como en la pantalla de Gastos (que no exige caja), con el aviso en la tarjeta.
 *
 * Ningún test sale a la red. PHP 7.4.
 *
 * @group chat-ia
 */
class T161_Gasto_con_categoria_nueva_y_caja_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        // En "cauteloso" la tarjeta espera el sí: así se ve qué se propone ANTES de crear nada.
        \App\Models\User::where('id', $this->dueno->id)->update(['agente_confianza' => 'cauteloso']);
        $this->dueno = $this->dueno->fresh();
        $this->actuar_como($this->dueno);
    }

    /** El método de pago Efectivo del catálogo global. */
    protected function efectivo()
    {
        return CurrentAcountPaymentMethod::where('name', 'Efectivo')->first();
    }

    /** Una conversación del dueño con su assistant 'pendiente'. */
    protected function conversacion($pedido = 'Cargame el gasto del impuesto al cheque')
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

    /** proponer_gasto por el despacho real. */
    protected function gasto($conversation, $assistant, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar('proponer_gasto', $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'Falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /** Confirma la tarjeta por el endpoint real. */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta.';
        $assistant->estado = 'listo';
        $assistant->save();

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /** Una caja del negocio del test, abierta o no. */
    protected function caja($nombre, $abierta)
    {
        $caja = Caja::create(['name' => $nombre, 'user_id' => $this->dueno->id, 'num' => Caja::where('user_id', $this->dueno->id)->count() + 1]);

        if ($abierta) {

            (new CajaAperturaHelper($caja->id))->abrir_caja();
        }

        return $caja->fresh();
    }

    /** Todos los renglones de la tarjeta, como texto. */
    protected function tarjeta_en_texto($tarjeta_id)
    {
        $presentacion = AiMessageAction::find($tarjeta_id)->presentacion;

        return json_encode($presentacion, JSON_UNESCAPED_UNICODE);
    }

    /**
     * El caso real: categoría y subcategoría nuevas + gasto, en UNA tarjeta; se crean las tres al confirmar.
     *
     * @test
     */
    public function categoria_y_subcategoria_nuevas_y_el_gasto_van_en_una_sola_tarjeta()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->gasto($conversation, $assistant, [
            'subcategoria_nueva' => 'Impuesto al cheque T161',
            'categoria'          => 'Gastos bancarios T161',
            'monto'              => 416179,
            'pagos'              => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(1, AiMessageAction::where('ai_message_id', $assistant->id)->count(), 'Una sola tarjeta.');

        // Nada se crea antes del sí.
        $this->assertSame(0, ExpenseCategory::where('user_id', $this->dueno->id)->where('name', 'Gastos bancarios T161')->count());
        $this->assertSame(0, ExpenseConcept::where('user_id', $this->dueno->id)->where('name', 'Impuesto al cheque T161')->count());

        $texto = $this->tarjeta_en_texto($respuesta['tarjeta_id']);
        $this->assertStringContainsString('Impuesto al cheque T161', $texto);
        $this->assertStringContainsString('nueva', $texto, 'La tarjeta dice que la subcategoría es nueva.');

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $categoria = ExpenseCategory::where('user_id', $this->dueno->id)->where('name', 'Gastos bancarios T161')->first();
        $this->assertNotNull($categoria, 'Se creó la categoría.');

        $concepto = ExpenseConcept::where('user_id', $this->dueno->id)->where('name', 'Impuesto al cheque T161')->first();
        $this->assertNotNull($concepto, 'Se creó la subcategoría.');
        $this->assertSame((int) $categoria->id, (int) $concepto->expense_category_id, 'La subcategoría cuelga de la categoría nueva.');

        $gasto = Expense::where('user_id', $this->dueno->id)->where('expense_concept_id', $concepto->id)->first();
        $this->assertNotNull($gasto, 'Se registró el gasto.');
        $this->assertEqualsWithDelta(416179, (float) $gasto->amount, 0.01);
    }

    /**
     * Revisión de la rama B (decisión del coordinador): con el dueño en "directo", una tarjeta que CREA
     * categoría o subcategoría igual espera el sí. Un gasto sobre una subcategoría existente se hace solo.
     *
     * @test
     */
    public function en_directo_la_tarjeta_que_crea_subcategoria_espera_el_si()
    {
        \App\Models\User::where('id', $this->dueno->id)->update(['agente_confianza' => 'directo']);
        $this->dueno = $this->dueno->fresh();
        $this->actuar_como($this->dueno);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->gasto($conversation, $assistant, [
            'subcategoria_nueva' => 'Peajes T161',
            'categoria'          => 'Viajes T161',
            'monto'              => 800,
            'pagos'              => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertTrue($respuesta['requiere_confirmacion']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($respuesta['tarjeta_id'])->estado_guardado());
        $this->assertSame(0, ExpenseConcept::where('user_id', $this->dueno->id)->where('name', 'Peajes T161')->count(), 'No se creó nada sin el sí.');
        $this->assertSame(0, Expense::where('user_id', $this->dueno->id)->count());
    }

    /**
     * 🔴 Revisión de la rama B, hallazgo 1: el mismo gasto propuesto dos veces en el turno, en "directo".
     * La primera tarjeta CREA la subcategoría (clave por nombre) y se confirma; la segunda llega con la
     * subcategoría ya creada (por id). La guarda de doble registro tiene que verla: un solo gasto.
     *
     * @test
     */
    public function el_mismo_gasto_dos_veces_en_el_turno_en_directo_queda_una_sola_vez()
    {
        \App\Models\User::where('id', $this->dueno->id)->update(['agente_confianza' => 'directo']);
        $this->dueno = $this->dueno->fresh();
        $this->actuar_como($this->dueno);

        list($conversation, $assistant) = $this->conversacion();

        $primera = $this->gasto($conversation, $assistant, [
            'subcategoria_nueva' => 'Impuesto al cheque T161',
            'categoria'          => 'Gastos bancarios T161',
            'monto'              => 416179,
            'pagos'              => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ]);

        $this->assertTrue($primera['ok'], json_encode($primera));

        $this->confirmar($conversation, $assistant, $primera['tarjeta_id'])->assertStatus(200);

        $concepto = ExpenseConcept::where('user_id', $this->dueno->id)->where('name', 'Impuesto al cheque T161')->first();
        $this->assertNotNull($concepto);
        $this->assertSame(1, Expense::where('user_id', $this->dueno->id)->count());

        // En el mismo turno (sin un mensaje nuevo de la persona), el modelo vuelve a proponer el mismo gasto.
        $otro_assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        $segunda = $this->gasto($conversation, $otro_assistant, [
            'subcategoria_id' => $concepto->id,
            'monto'           => 416179,
            'pagos'           => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ]);

        $this->assertNotEmpty($segunda['confirmada_parecida'], 'La guarda ve el gasto ya registrado: ' . json_encode($segunda));
        $this->assertSame(1, Expense::where('user_id', $this->dueno->id)->count(), 'Un solo gasto, no dos.');
    }

    /**
     * Una categoría que ya existe (escrita distinto) se reusa, y la subcategoría nueva cuelga de ella.
     *
     * @test
     */
    public function la_categoria_que_ya_existe_se_reusa_sin_duplicarla()
    {
        $existente = ExpenseCategory::create(['name' => 'Gastos Bancarios T161', 'user_id' => $this->dueno->id]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->gasto($conversation, $assistant, [
            'subcategoria_nueva' => 'Comisiones T161',
            'categoria'          => 'gastos bancarios t161',
            'monto'              => 1500,
            'pagos'              => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertSame(1, ExpenseCategory::where('user_id', $this->dueno->id)->whereRaw('LOWER(name) = ?', ['gastos bancarios t161'])->count(), 'No se duplicó la categoría.');

        $concepto = ExpenseConcept::where('user_id', $this->dueno->id)->where('name', 'Comisiones T161')->first();
        $this->assertNotNull($concepto);
        $this->assertSame((int) $existente->id, (int) $concepto->expense_category_id);
    }

    /**
     * Una subcategoría «nueva» que ya existe (con tildes o mayúsculas distintas) se reusa: no se duplica.
     *
     * @test
     */
    public function la_subcategoria_que_ya_existe_se_reusa_sin_duplicarla()
    {
        $categoria = ExpenseCategory::create(['name' => 'Impuestos T161', 'user_id' => $this->dueno->id]);
        $concepto = ExpenseConcept::create(['name' => 'Impuesto al Chéque T161', 'user_id' => $this->dueno->id, 'expense_category_id' => $categoria->id, 'num' => 1]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->gasto($conversation, $assistant, [
            'subcategoria_nueva' => 'impuesto al cheque t161',
            'monto'              => 900,
            'pagos'              => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame((int) $concepto->id, (int) AiMessageAction::find($respuesta['tarjeta_id'])->datos['expense_concept_id']);

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertSame(1, ExpenseConcept::where('user_id', $this->dueno->id)->count(), 'No se duplicó la subcategoría.');
        $this->assertSame(1, Expense::where('user_id', $this->dueno->id)->where('expense_concept_id', $concepto->id)->count());
    }

    /**
     * Sin decir la categoría de la subcategoría nueva, se pregunta (con las categorías que hay).
     *
     * @test
     */
    public function una_subcategoria_nueva_sin_categoria_pregunta_en_cual_va()
    {
        ExpenseCategory::create(['name' => 'Servicios T161', 'user_id' => $this->dueno->id]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->gasto($conversation, $assistant, [
            'subcategoria_nueva' => 'Internet T161',
            'monto'              => 25000,
            'pagos'              => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertNotEmpty($respuesta['faltan']);
        $this->assertStringContainsString('categoría', $respuesta['faltan'][0]);
        $this->assertNotEmpty($respuesta['opciones']['categorias']);
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count());
    }

    /**
     * Con UNA sola caja abierta, el gasto la usa sin preguntar y la tarjeta la nombra.
     *
     * @test
     */
    public function con_una_sola_caja_abierta_la_usa_y_la_nombra()
    {
        $caja = $this->caja('Caja mostrador T161', true);

        $categoria = ExpenseCategory::create(['name' => 'Varios T161', 'user_id' => $this->dueno->id]);
        $concepto = ExpenseConcept::create(['name' => 'Limpieza T161', 'user_id' => $this->dueno->id, 'expense_category_id' => $categoria->id, 'num' => 1]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->gasto($conversation, $assistant, [
            'subcategoria_id' => $concepto->id,
            'monto'           => 3000,
            'pagos'           => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $datos = AiMessageAction::find($respuesta['tarjeta_id'])->datos;
        $this->assertSame((int) $caja->id, (int) $datos['payment_methods'][0]['caja_id']);
        $this->assertStringContainsString('Caja mostrador T161', $this->tarjeta_en_texto($respuesta['tarjeta_id']));
    }

    /**
     * Revisión de la rama B: una caja abierta que NO acepta el método de la fila (tiene otros métodos
     * cargados) no cuenta. Con dos abiertas pero una sola que acepta Efectivo, se usa ésa sola, como filtra
     * get_caja_options() de la pantalla.
     *
     * @test
     */
    public function la_unica_caja_que_acepta_el_metodo_se_usa_aunque_haya_otras_abiertas()
    {
        $otro_metodo = CurrentAcountPaymentMethod::where('id', '!=', $this->efectivo()->id)->orderBy('id')->first();

        $solo_otro = $this->caja('Caja solo otro metodo T161', true);
        $solo_otro->current_acount_payment_methods()->attach($otro_metodo->id);

        $acepta_todo = $this->caja('Caja general T161', true);

        $categoria = ExpenseCategory::create(['name' => 'Varios T161', 'user_id' => $this->dueno->id]);
        $concepto = ExpenseConcept::create(['name' => 'Cafeteria T161', 'user_id' => $this->dueno->id, 'expense_category_id' => $categoria->id, 'num' => 1]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->gasto($conversation, $assistant, [
            'subcategoria_id' => $concepto->id,
            'monto'           => 1200,
            'pagos'           => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame((int) $acepta_todo->id, (int) AiMessageAction::find($respuesta['tarjeta_id'])->datos['payment_methods'][0]['caja_id']);
    }

    /**
     * Con dos cajas abiertas que aceptan el método, sigue preguntando a cuál va (no elige al azar).
     *
     * @test
     */
    public function con_dos_cajas_que_aceptan_el_metodo_pregunta()
    {
        $this->caja('Caja uno T161', true);
        $this->caja('Caja dos T161', true);

        $categoria = ExpenseCategory::create(['name' => 'Varios T161', 'user_id' => $this->dueno->id]);
        $concepto = ExpenseConcept::create(['name' => 'Papeleria T161', 'user_id' => $this->dueno->id, 'expense_category_id' => $categoria->id, 'num' => 1]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->gasto($conversation, $assistant, [
            'subcategoria_id' => $concepto->id,
            'monto'           => 500,
            'pagos'           => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertNotEmpty($respuesta['opciones']['cajas']);
    }

    /**
     * Con cajas pero NINGUNA abierta, el gasto va sin caja (como en la pantalla de Gastos) y la tarjeta lo dice.
     *
     * @test
     */
    public function con_cajas_pero_ninguna_abierta_el_gasto_va_sin_caja_con_aviso()
    {
        $this->caja('Caja cerrada T161', false);

        $categoria = ExpenseCategory::create(['name' => 'Varios T161', 'user_id' => $this->dueno->id]);
        $concepto = ExpenseConcept::create(['name' => 'Fletes T161', 'user_id' => $this->dueno->id, 'expense_category_id' => $categoria->id, 'num' => 1]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->gasto($conversation, $assistant, [
            'subcategoria_id' => $concepto->id,
            'monto'           => 7000,
            'pagos'           => [['metodo_de_pago_id' => $this->efectivo()->id]],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame(0, (int) $accion->datos['payment_methods'][0]['caja_id']);
        $this->assertNotEmpty($accion->presentacion['aviso'], 'La tarjeta avisa que va sin caja.');
        $this->assertStringContainsString('ninguna caja que puedas usar para este gasto', $accion->presentacion['aviso']);

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertSame(1, Expense::where('user_id', $this->dueno->id)->where('expense_concept_id', $concepto->id)->count());
    }
}
