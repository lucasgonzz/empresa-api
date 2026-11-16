<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiMessageAction;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261116 (rama C, 16/11/2026), ítem 30/10 #9 — un CUIT nuevo no deja la razón social
 * vieja sin avisar.
 *
 * EL CASO REAL (Ferre Total, conversación 29, 30/10/2026): Pablo pidió cambiarle el CUIT a un cliente para
 * facturarle; el agente cambió el CUIT y dejó la razón social vieja sin decir nada.
 *
 * 🔴 El asistente NO consulta el padrón de ARCA (decisión de la revisión: el WSAA de la pantalla, adentro del
 * worker, puede pisar el token de facturación y hace exit() ante un SOAP fault). Lo que protege este archivo:
 *
 *  - un CUIT nuevo sin razón social dictada: la tarjeta avisa que la razón social y la condición de IVA quedan
 *    como estaban, sugiere el botón de AFIP de Clientes y espera el sí aun en "directo";
 *  - en un alta, el aviso dice que quedan vacías;
 *  - lo que la persona dicta gana (mayúsculas incluidas) y no hay aviso;
 *  - un CUIT que no cambia no avisa nada.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T170_Cuit_nuevo_avisa_la_razon_social_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    /** Un CUIT con dígito verificador válido (sociedad). */
    const CUIT_NUEVO = '30712345671';

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        // En "resuelto" una edición o un alta quedan como tarjeta: se ve qué trae la propuesta.
        $this->dueno->agente_confianza = 'resuelto';
        $this->dueno->save();
    }

    /**
     * @param  int  $client_id
     * @param  array  $cambios
     * @return array  Lo que ve el modelo.
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
     * 🔴 El caso de Ferre Total: el CUIT cambia y la tarjeta dice que la razón social queda como estaba.
     *
     * @test
     */
    public function el_cuit_nuevo_sin_razon_social_avisa_y_sugiere_el_boton_de_afip()
    {
        $client = $this->cliente('ZZC170 Transfarmaco');
        $client->razon_social = 'Otra Razon Vieja SRL';
        $client->save();

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertStringContainsString('quedan como estaban', $r['aviso']);
        $this->assertStringContainsString('botón de AFIP', $r['aviso']);

        $accion = AiMessageAction::find($r['tarjeta_id']);
        $cambios = json_decode(json_encode($accion->datos), true)['cambios'];

        $this->assertSame(['cuit'], array_keys($cambios), 'No se inventa ninguna razón social.');
        $this->assertStringContainsString('quedan como estaban', json_encode($accion->presentacion, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 🔴 En "directo" esa edición NO se aplica sola: espera el sí, así el CUIT no cambia sin que la persona lo vea.
     *
     * @test
     */
    public function en_directo_el_cuit_sin_razon_social_espera_el_si()
    {
        $this->dueno->agente_confianza = 'directo';
        $this->dueno->save();

        $client = $this->cliente('ZZC170 Directo');

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($r['tarjeta_id'])->estado);
        $this->assertNotSame(self::CUIT_NUEVO, (string) $client->fresh()->cuit);
    }

    /**
     * Lo cosmético que dicta el dueño gana: «todo en mayúscula» queda así y no hay aviso.
     *
     * @test
     */
    public function la_razon_social_dictada_en_mayusculas_se_respeta()
    {
        $client = $this->cliente('ZZC170 Mayusculas');

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO, 'razon_social' => 'TRANSFARMACO S.A.']);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertArrayNotHasKey('aviso', $r);

        $cambios = json_decode(json_encode(AiMessageAction::find($r['tarjeta_id'])->datos), true)['cambios'];

        $this->assertSame('TRANSFARMACO S.A.', $cambios['razon_social']);
    }

    /**
     * Un cambio que no toca el CUIT (o lo repite igual) no avisa nada.
     *
     * @test
     */
    public function sin_cuit_nuevo_no_avisa()
    {
        $client = $this->cliente('ZZC170 Mismo cuit');
        $client->cuit = self::CUIT_NUEVO;
        $client->save();

        $r = $this->editar_cliente($client->id, ['cuit' => self::CUIT_NUEVO, 'phone' => '3415550000']);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertArrayNotHasKey('aviso', $r);
    }

    /**
     * El alta de un proveedor con CUIT y sin razón social avisa que queda vacía.
     *
     * @test
     */
    public function el_alta_de_un_proveedor_con_cuit_avisa_que_queda_vacia()
    {
        list($conversation, $assistant) = $this->vuelta('dá de alta al proveedor transfarmaco');

        $r = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'provider',
            'datos'   => ['name' => 'ZZC170 Transfarmaco proveedor', 'cuit' => self::CUIT_NUEVO],
        ]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertStringContainsString('quedan vacías', $r['aviso']);
        $this->assertTrue(!empty($r['requiere_confirmacion']));

        $payload = json_decode(json_encode(AiMessageAction::find($r['tarjeta_id'])->datos), true)['payload'];

        $this->assertTrue(empty($payload['razon_social']));
    }
}
