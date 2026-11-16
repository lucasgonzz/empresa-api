<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorDeLaFacturaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Provider;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261116, rama B — A7 (14/11): proveedor duplicado desde la foto de una factura.
 *
 * EL CASO REAL (Ferre Total, conv 40/44/55): el agente propuso dar de alta como nuevo a «Martín Cárdenas»
 * cuando ya existía, y creó «Distribuidora de Sanitarios Franco Fia» existiendo FRANCO FIA S.R.L. El
 * reconocimiento exigía las palabras en el mismo orden y escritas igual: «Martin Cardenas» no encontraba a
 * «Cárdenas Martín» y un «Franko» leído de la factura no encontraba a «Franco». Lo que protege:
 *
 *  - un nombre que se PARECE a un proveedor guardado (otro orden, una letra cambiada, palabras genéricas
 *    de más) vuelve como candidato ANTES de proponer un alta;
 *  - nunca se arma la compra con un proveedor nuevo si hay un parecido sin confirmar: recién en un turno
 *    POSTERIOR al que ofreció los candidatos vale `proveedor_nuevo: true`;
 *  - un nombre sin parecidos sigue siendo un alta, y el CUIT sigue reconociendo primero.
 *
 * Ningún test sale a la red. PHP 7.4.
 *
 * @group chat-ia
 */
class T162_Proveedor_parecido_antes_del_alta_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    /** @var AiConversation */
    protected $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        $this->conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
    }

    protected function proveedor($nombre, array $extra = [])
    {
        return Provider::create(array_merge(['name' => $nombre, 'user_id' => $this->dueno->id], $extra));
    }

    /** Un turno: el mensaje de la persona y el assistant 'pendiente' que contesta. */
    protected function turno($texto)
    {
        AiMessage::create(['ai_conversation_id' => $this->conversation->id, 'rol' => 'user', 'contenido' => $texto, 'estado' => 'listo']);

        return AiMessage::create([
            'ai_conversation_id'   => $this->conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);
    }

    protected function resolver($nombre, AiMessage $mensaje, $cuit = '', $nuevo = false)
    {
        return ProveedorDeLaFacturaIaHelper::resolver(ContextoDeCargaIa::de_la_conversacion($this->conversation), $nombre, $cuit, $mensaje, $nuevo);
    }

    /**
     * El nombre en otro orden ya no es un alta: es un candidato.
     *
     * @test
     */
    public function el_mismo_proveedor_en_otro_orden_vuelve_como_candidato()
    {
        $cardenas = $this->proveedor('Cárdenas Martín');

        $respuesta = $this->resolver('Martin Cardenas', $this->turno('Cargame la factura de Martin Cardenas'));

        $this->assertIsArray($respuesta);
        $this->assertFalse($respuesta['ok'], 'No propone un alta: ' . json_encode($respuesta));
        $this->assertSame((int) $cardenas->id, (int) $respuesta['opciones']['proveedores'][0]['id']);
    }

    /**
     * Una letra cambiada y palabras genéricas de más encuentran a FRANCO FIA S.R.L. como candidato.
     *
     * @test
     */
    public function el_nombre_leido_con_una_letra_cambiada_y_palabras_genericas_encuentra_al_guardado()
    {
        $franco = $this->proveedor('FRANCO FIA S.R.L.');

        $respuesta = $this->resolver('Distribuidora de Sanitarios Franko Fia', $this->turno('Fijate en la factura'));

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertSame((int) $franco->id, (int) $respuesta['opciones']['proveedores'][0]['id']);
    }

    /**
     * El caso textual del 14/11 sigue preguntando (lo guardado está adentro de lo leído).
     *
     * @test
     */
    public function el_caso_textual_de_franco_fia_pregunta_antes_del_alta()
    {
        $franco = $this->proveedor('FRANCO FIA S.R.L.');

        $respuesta = $this->resolver('Distribuidora de Sanitarios Franco Fia', $this->turno('Fijate en la factura'));

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertSame((int) $franco->id, (int) $respuesta['opciones']['proveedores'][0]['id']);
    }

    /**
     * Revisión de la rama B, hallazgo 6: un parecido con OTRO CUIT cargado que el de la factura no es
     * candidato; uno sin CUIT cargado, sí.
     *
     * @test
     */
    public function un_parecido_con_otro_cuit_cargado_no_es_candidato()
    {
        $this->proveedor('Cárdenas Martín', ['cuit' => '20-11111111-2']);

        $con_otro_cuit = $this->resolver('Martin Cardenas', $this->turno('Fijate en la factura'), '30712345678');
        $this->assertSame(['nuevo' => 'Martin Cardenas'], $con_otro_cuit, 'La factura es de otra empresa (otro CUIT).');

        $sin_cuit = $this->proveedor('Franco Fia SRL');

        $respuesta = $this->resolver('Distribuidora Franko Fia', $this->turno('Fijate en la factura'), '30712345678');
        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertSame((int) $sin_cuit->id, (int) $respuesta['opciones']['proveedores'][0]['id']);
    }

    /**
     * 🔴 `proveedor_nuevo: true` de entrada NO saltea la pregunta; en el turno siguiente, sí.
     *
     * @test
     */
    public function el_alta_con_un_parecido_solo_vale_despues_de_preguntar()
    {
        $this->proveedor('Cárdenas Martín');

        $primero = $this->turno('Cargame la factura de Martin Cardenas');

        $de_entrada = $this->resolver('Martin Cardenas', $primero, '', true);
        $this->assertFalse($de_entrada['ok'], 'Sin haber ofrecido los candidatos, proveedor_nuevo no vale.');

        // En el MISMO turno tampoco: la persona todavía no contestó.
        $mismo_turno = $this->resolver('Martin Cardenas', $primero, '', true);
        $this->assertFalse($mismo_turno['ok']);

        $segundo = $this->turno('No, es otro, Martin Cardenas es nuevo');

        $despues = $this->resolver('Martin Cardenas', $segundo, '', true);
        $this->assertSame(['nuevo' => 'Martin Cardenas'], $despues);
    }

    /**
     * Sin parecidos es un alta (sin falsos candidatos), y el CUIT sigue reconociendo primero.
     *
     * @test
     */
    public function sin_parecidos_es_un_alta_y_el_cuit_reconoce_primero()
    {
        $this->proveedor('Cárdenas Martín');
        $this->proveedor('FRANCO FIA S.R.L.');
        $turco = $this->proveedor('El Turco', ['cuit' => '30-71234567-8']);

        $this->assertSame(['nuevo' => 'Global Sources S.A.'], $this->resolver('Global Sources S.A.', $this->turno('Fijate en la factura')));

        $por_cuit = $this->resolver('Distribuidora Anatolia SA', $this->turno('Fijate en la factura'), '30712345678');
        $this->assertInstanceOf(Provider::class, $por_cuit);
        $this->assertSame((int) $turco->id, (int) $por_cuit->id);
    }

    /**
     * 🔴 Por la herramienta: la compra sin artículos NO arma la tarjeta con un proveedor nuevo si hay uno
     * parecido; con el «es otro» del turno siguiente, sí.
     *
     * @test
     */
    public function la_compra_no_se_arma_con_un_proveedor_nuevo_si_hay_un_parecido_sin_confirmar()
    {
        $this->proveedor('Cárdenas Martín');

        $primero = $this->turno('Cargame una compra sin artículos de Martin Cardenas por 10000 sin iva');

        $resultado = HerramientasDeCarga::ejecutar('proponer_compra_sin_articulos', [
            'proveedor' => 'Martin Cardenas',
            'total'     => 10000,
            'iva'       => 'sin_iva',
        ], $this->conversation, $primero);

        $respuesta = json_decode($resultado['content'], true);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $this->conversation->id)->count(), 'Ninguna tarjeta con un proveedor nuevo.');
        $this->assertSame(1, Provider::where('user_id', $this->dueno->id)->count());

        $segundo = $this->turno('No, es otro proveedor, Martin Cardenas es nuevo');

        $resultado = HerramientasDeCarga::ejecutar('proponer_compra_sin_articulos', [
            'proveedor'       => 'Martin Cardenas',
            'total'           => 10000,
            'iva'             => 'sin_iva',
            'proveedor_nuevo' => true,
        ], $this->conversation, $segundo);

        $respuesta = json_decode($resultado['content'], true);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $this->conversation->id)->count());
    }
}
