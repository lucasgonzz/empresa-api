<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\PadronDeArcaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PadronFueraDeTiempoException;
use App\Models\AiMessageAction;
use App\Models\Provider;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261116 (rama C, 16/11/2026), ítem 30/10 #9 — un CUIT nuevo trae la razón social.
 *
 * EL CASO REAL (Ferre Total, conversación 29, 30/10/2026): Pablo pidió cambiarle el CUIT a un cliente para
 * facturarle; el agente cambió el CUIT y dejó la razón social vieja. Lo que protege este archivo:
 *
 *  - la edición del CUIT de un cliente trae la razón social y la condición de IVA del padrón en la MISMA
 *    tarjeta, y el aviso dice de dónde salieron;
 *  - si el padrón falla, la tarjeta lo dice y no inventa ninguna razón social;
 *  - lo que la persona dicta gana: una razón social en mayúsculas no se pisa ni se consulta el padrón;
 *  - un CUIT que no cambia no consulta nada;
 *  - el alta de un proveedor con CUIT también la trae.
 *
 * 🔴 Nunca sale a ARCA: el padrón es un doble registrado en el contenedor (PadronDeArcaIaHelper::consultar()).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T170_Cuit_nuevo_con_razon_social_del_padron_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    /** Un CUIT con dígito verificador válido (sociedad). */
    const CUIT_NUEVO = '30712345671';

    /** @var object El doble del padrón: cuenta las consultas y contesta lo que se le pida. */
    protected $padron;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        // En "resuelto" una edición o un alta quedan como tarjeta: se ve qué trae la propuesta.
        $this->dueno->agente_confianza = 'resuelto';
        $this->dueno->save();

        $this->padron = new class extends PadronDeArcaIaHelper {

            /** @var array Lo que contesta. */
            public $respuesta = [];

            /** @var array<int, string> Los CUIT consultados. */
            public $consultas = [];

            /** @var float Segundos que tarda en contestar (un ARCA lento). */
            public $demora = 0;

            /** @var \Throwable|null Lo que tira en vez de contestar (un timeout). */
            public $tira = null;

            public function consultar($digitos)
            {
                $this->consultas[] = (string) $digitos;

                if ($this->demora > 0) {
                    usleep((int) ($this->demora * 1000000));
                }

                if (!is_null($this->tira)) {
                    throw $this->tira;
                }

                return $this->respuesta;
            }
        };

        $this->padron->respuesta = [
            'hubo_un_error' => false,
            'afip_data'     => [
                'nombre'        => 'Transfarmaco SA',
                'razon_social'  => 'Transfarmaco SA',
                'cuit'          => self::CUIT_NUEVO,
                'condicion_iva' => 'RESPONSABLE INSCRIPTO',
            ],
        ];

        $this->app->instance(PadronDeArcaIaHelper::class, $this->padron);
    }

    /**
     * Edita el CUIT (y lo que se pida) de un cliente por la herramienta real y devuelve lo que ve el modelo.
     *
     * @param  int  $client_id
     * @param  array  $cambios
     * @return array
     */
    protected function editar_cliente($client_id, array $cambios)
    {
        list($conversation, $assistant) = $this->vuelta('cambiale el cuit al cliente');

        return $this->herramienta($conversation, $assistant, 'proponer_edicion', [
            'entidad'  => 'client',
            'registro' => $client_id,
            'cambios'  => $cambios,
        ]);
    }

    /**
     * 🔴 El caso de Ferre Total: el CUIT nuevo trae la razón social y la condición de IVA en la misma tarjeta.
     *
     * @test
     */
    public function el_cuit_nuevo_trae_la_razon_social_y_la_condicion_del_padron()
    {
        $client = $this->cliente('ZZC170 Transfarmaco');
        $client->razon_social = 'Otra Razon Vieja SRL';
        $client->save();

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame([self::CUIT_NUEVO], $this->padron->consultas);
        $this->assertStringContainsString('padrón de ARCA', $r['aviso']);

        $accion = AiMessageAction::find($r['tarjeta_id']);
        $cambios = json_decode(json_encode($accion->datos), true)['cambios'];

        $this->assertSame(self::CUIT_NUEVO, (string) $cambios['cuit']);
        $this->assertSame('Transfarmaco SA', $cambios['razon_social'], 'La razón social viaja en la misma tarjeta que el CUIT.');
        $this->assertSame(PadronDeArcaIaHelper::id_de_condicion('Responsable inscripto'), (int) $cambios['iva_condition_id']);

        $renglones = json_encode(json_decode(json_encode($accion->presentacion), true)['renglones'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Otra Razon Vieja SRL → Transfarmaco SA', $renglones);
    }

    /**
     * Si el padrón falla, la tarjeta lo dice y la razón social no se toca.
     *
     * @test
     */
    public function si_el_padron_falla_la_tarjeta_lo_dice_y_no_inventa()
    {
        $this->padron->respuesta = ['hubo_un_error' => true, 'error' => 'ARCA fuera de servicio'];

        $client = $this->cliente('ZZC170 Sin padron');

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertStringContainsString('No pude traer la razón social', $r['aviso']);
        $this->assertStringContainsString('ARCA fuera de servicio', $r['aviso']);

        $cambios = json_decode(json_encode(AiMessageAction::find($r['tarjeta_id'])->datos), true)['cambios'];

        $this->assertArrayNotHasKey('razon_social', $cambios);
        $this->assertSame(['cuit'], array_keys($cambios));
    }

    /**
     * 🔴 Lo cosmético que dicta el dueño gana: «todo en mayúscula» queda así y el padrón ni se consulta.
     *
     * @test
     */
    public function la_razon_social_dictada_en_mayusculas_se_respeta()
    {
        $client = $this->cliente('ZZC170 Mayusculas');

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO, 'razon_social' => 'TRANSFARMACO S.A.']);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame([], $this->padron->consultas);

        $cambios = json_decode(json_encode(AiMessageAction::find($r['tarjeta_id'])->datos), true)['cambios'];

        $this->assertSame('TRANSFARMACO S.A.', $cambios['razon_social']);
    }

    /**
     * Un cambio que no toca el CUIT (o lo repite igual) no consulta el padrón.
     *
     * @test
     */
    public function sin_cuit_nuevo_no_se_consulta()
    {
        $client = $this->cliente('ZZC170 Mismo cuit');
        $client->cuit = self::CUIT_NUEVO;
        $client->save();

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO, 'phone' => '3415550000']);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame([], $this->padron->consultas);
        $this->assertArrayNotHasKey('aviso', $r);
    }

    /**
     * El alta de un proveedor con CUIT trae la razón social del padrón.
     *
     * @test
     */
    public function el_alta_de_un_proveedor_con_cuit_trae_la_razon_social()
    {
        list($conversation, $assistant) = $this->vuelta('dá de alta al proveedor transfarmaco');

        $r = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'provider',
            'datos'   => ['name' => 'ZZC170 Transfarmaco proveedor', 'cuit' => self::CUIT_NUEVO],
        ]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame([self::CUIT_NUEVO], $this->padron->consultas);

        $payload = json_decode(json_encode(AiMessageAction::find($r['tarjeta_id'])->datos), true)['payload'];

        $this->assertSame('Transfarmaco SA', $payload['razon_social']);
        $this->assertSame(0, Provider::where('name', 'ZZC170 Transfarmaco proveedor')->count(), 'Es una tarjeta: no crea nada.');
    }

    /**
     * 🔴 En "directo" la edición se aplica sola, salvo que el padrón no conteste: ahí queda la tarjeta para
     * que la persona vea el aviso y diga la razón social.
     *
     * @test
     */
    public function en_directo_un_padron_sin_respuesta_deja_la_tarjeta()
    {
        $this->dueno->agente_confianza = 'directo';
        $this->dueno->save();

        $this->padron->respuesta = ['hubo_un_error' => true, 'error' => 'ARCA fuera de servicio'];

        $client = $this->cliente('ZZC170 Directo');

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($r['tarjeta_id'])->estado);
        $this->assertStringContainsString('No pude traer la razón social', $r['aviso']);
        $this->assertNotSame(self::CUIT_NUEVO, (string) $client->fresh()->cuit);
    }

    /**
     * En "directo" con el padrón andando, se aplica sola con la razón social nueva.
     *
     * @test
     */
    public function en_directo_con_padron_queda_guardada_la_razon_social()
    {
        $this->dueno->agente_confianza = 'directo';
        $this->dueno->save();

        $client = $this->cliente('ZZC170 Directo ok');

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('Transfarmaco SA', $client->fresh()->razon_social);
        $this->assertSame(self::CUIT_NUEVO, (string) $client->fresh()->cuit);
    }

    /**
     * 🔴 Un padrón lento: pasado el tope del asistente, la tarjeta dice que no contestó a tiempo y no trae
     * ninguna razón social (aunque la respuesta haya llegado tarde).
     *
     * @test
     */
    public function un_padron_lento_pasa_el_tope_y_la_tarjeta_lo_dice()
    {
        config(['services.afip.padron_tope_asistente' => 0.3]);

        $this->padron->demora = 0.6;

        $client = $this->cliente('ZZC170 Padron lento');

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertStringContainsString(PadronDeArcaIaHelper::MENSAJE_FUERA_DE_TIEMPO, $r['aviso']);

        $cambios = json_decode(json_encode(AiMessageAction::find($r['tarjeta_id'])->datos), true)['cambios'];

        $this->assertArrayNotHasKey('razon_social', $cambios);
    }

    /**
     * Un padrón que tira timeout (lo que hace la alarma con pcntl, o el SOAP) sigue el mismo camino.
     *
     * @test
     */
    public function un_padron_que_tira_timeout_sigue_como_falla()
    {
        $this->padron->tira = new PadronFueraDeTiempoException('timeout');

        $client = $this->cliente('ZZC170 Padron timeout');

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertStringContainsString(PadronDeArcaIaHelper::MENSAJE_FUERA_DE_TIEMPO, $r['aviso']);
        $this->assertSame(8.0, (float) PadronDeArcaIaHelper::TOPE_SEGUNDOS);
    }
}
