<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\OpcionesDeCargaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessageAction;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión agente-mejoras-uso-real (29/10/2026) — arreglo de la revisión independiente de E3:
 * LOS CHEQUES DE UNA FOTO (CONV 23 de Ferre Total): 5 e-cheq cargados con los números 7, 8, 9, 10 y 11 y la
 * emisión de hoy, en el acto. El prompt y la herramienta dicen de dónde salen los datos; la tarjeta marca la
 * emisión que no vino, avisa los números cortos y correlativos y, con esos números, espera el sí también en
 * "directo". Los de una chequera de verdad (8 dígitos) no se marcan.
 *
 * 🔴 Nada sale a la red.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Cheques_de_una_foto_sin_inventar_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::CAUTELOSO]);

        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * Una conversación de esa persona.
     *
     * @param  User|null  $persona
     * @return AiConversation
     */
    protected function conversacion_de($persona = null)
    {
        return AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => is_null($persona) ? $this->dueno->id : $persona->id,
        ]);
    }

    /**
     * El método de pago de tipo cheque del catálogo.
     *
     * @return \App\Models\CurrentAcountPaymentMethod
     */
    protected function metodo_cheque()
    {
        foreach (OpcionesDeCargaIaHelper::metodos_de_pago() as $metodo) {

            if (OpcionesDeCargaIaHelper::es_cheque($metodo)) {
                return $metodo;
            }
        }

        $this->fail('El catálogo tiene que tener un método de pago de tipo cheque.');
    }

    /**
     * Las filas de un pago con un cheque por número.
     *
     * @param  array<int, string>  $numeros
     * @param  bool  $con_emision
     * @return array
     */
    protected function pagos_con_cheques(array $numeros, $con_emision = false)
    {
        $metodo = $this->metodo_cheque();

        $pagos = [];

        foreach ($numeros as $i => $numero) {

            $cheque = [
                'numero'     => $numero,
                'banco'      => 'Banco Galicia',
                'fecha_pago' => now()->addDays(7 * ($i + 1))->format('Y-m-d'),
                'es_echeq'   => true,
            ];

            if ($con_emision) {
                $cheque['fecha_emision'] = now()->subDays(3)->format('Y-m-d');
            }

            $pagos[] = ['metodo_de_pago_id' => $metodo->id, 'monto' => 1000, 'cheque' => $cheque];
        }

        return $pagos;
    }

    // =====================================================================
    // 3 · Los cheques de una foto
    // =====================================================================

    /**
     * El prompt y la descripción de la herramienta dicen de dónde salen los datos de un cheque.
     *
     * @test
     */
    public function la_regla_de_los_cheques_esta_en_el_prompt_y_en_la_herramienta()
    {
        $prompt = (new AsistenteIaService())->build_system_prompt($this->conversacion_de(), $this->dueno->fresh(), true, true);

        $this->assertStringContainsString('de un cheque salen SOLO de lo que se lee en la foto o de lo que dice la persona', $prompt);
        $this->assertStringContainsString('nunca los numeres correlativos', $prompt);

        $esquema = '';

        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            if ($definicion['name'] === 'proponer_pago') {
                $esquema = json_encode($definicion['input_schema'], JSON_UNESCAPED_UNICODE);
            }
        }

        $this->assertStringContainsString('nunca lo inventes ni numeres los cheques correlativos', $esquema);
        $this->assertStringContainsString('solo si figura en el cheque o la dice la persona', $esquema);
    }

    /**
     * 🔴 CONV 23: con 7, 8, 9, 10 y 11 la tarjeta avisa, la emisión que no vino se marca, y en "directo"
     * el pago NO se registra solo.
     *
     * @test
     */
    public function cheques_numerados_a_mano_se_avisan_y_esperan_el_si_aun_en_directo()
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);
        $this->dueno = $this->dueno->fresh();

        $cliente = $this->cliente('ZZE Pinturas Sudamericanas', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('Me cargas este pago en la cuenta corriente');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_pago', [
            'tipo'  => 'cliente',
            'id'    => $cliente->id,
            'monto' => 5000,
            'pagos' => $this->pagos_con_cheques(['7', '8', '9', '10', '11']),
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('estado', $respuesta, 'Con números para revisar no se ejecuta solo en directo.');
        $this->assertTrue($respuesta['requiere_confirmacion']);
        $this->assertSame(['7', '8', '9', '10', '11'], $respuesta['cheques_para_revisar']);
        $this->assertStringContainsString('preguntáselos', $respuesta['aviso_de_cheques']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertStringContainsString('Los números de cheque 7, 8, 9, 10 y 11 son correlativos y muy cortos', $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('La fecha de emisión de 5 cheques no vino: va la de hoy', $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('emisión: hoy (no vino)', json_encode($tarjeta->presentacion['renglones'], JSON_UNESCAPED_UNICODE));

        // Lo que llega al backend no lleva la marca: es solo para la tarjeta.
        foreach ($tarjeta->datos['current_acount_payment_methods'] as $fila) {
            $this->assertArrayNotHasKey('emision_supuesta', $fila);
        }
    }

    /**
     * Los de una chequera de verdad (8 dígitos, correlativos) y con la emisión dicha no se marcan; y en
     * "directo" se registran en el acto como siempre.
     *
     * @test
     */
    public function cheques_de_verdad_con_su_emision_no_se_marcan()
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);
        $this->dueno = $this->dueno->fresh();

        $cliente = $this->cliente('ZZE Cliente con chequera', '5493444202020');

        list($conversation, $assistant) = $this->vuelta('Me pagó con tres cheques');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_pago', [
            'tipo'  => 'cliente',
            'id'    => $cliente->id,
            'monto' => 3000,
            'pagos' => $this->pagos_con_cheques(['00012345', '00012346', '00012347'], true),
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertArrayNotHasKey('requiere_confirmacion', $respuesta);
        $this->assertArrayNotHasKey('cheques_para_revisar', $respuesta);
        $this->assertArrayHasKey('estado', $respuesta, 'En directo, un pago sin nada que revisar se registra en el acto.');

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertStringNotContainsString('correlativos', (string) $tarjeta->presentacion['aviso']);
        $this->assertStringNotContainsString('no vino', json_encode($tarjeta->presentacion, JSON_UNESCAPED_UNICODE));
    }
}
