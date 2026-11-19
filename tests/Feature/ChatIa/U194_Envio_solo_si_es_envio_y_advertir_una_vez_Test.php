<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\EnvioSinPropuestaIaHelper;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261119, rama B — B3 y B2.
 *
 * 🔴 B3 (Ferre Total, conv 72, 717-721). «La foto es la paleta… es una carta de colores, no la foto del ENVASE» → «Si»
 * → «Todavía no armé el envío… Decime «dale» y te lo muestro completo (a quién le llega, por dónde y el texto
 * exacto)». No había ningún envío: la raíz `env\w*` agarraba «envase» y la guarda de los envíos a clientes se
 * prendía. Ahora solo cuentan las formas de mandar/enviar.
 *
 * 🔴 B2 (Ferre Total, conv 72, 661-669). Tres veces «la foto no es de un drywall» antes de usarla: el prompt dice que
 * una advertencia va una sola vez.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados.
 *
 * @group chat-ia
 */
class U194_Envio_solo_si_es_envio_y_advertir_una_vez_Test extends TestCase
{
    use DatabaseTransactions;

    /** El 717 de Ferre Total, recortado. */
    const PALETA = 'La foto es la paleta de ACCIÓN MÚLTIPLE con 27 colores. Es una carta de colores, no la foto del envase, así que conviene definir si la querés como imagen de los 145.';

    /**
     * 🔴 «Si» a un mensaje que habla del ENVASE no es un sí a una oferta de mandar.
     *
     * @test
     */
    public function el_envase_no_es_un_envio()
    {
        $this->assertFalse(EnvioSinPropuestaIaHelper::el_dueno_pidio_mandar('Si', self::PALETA));

        $this->assertFalse(EnvioSinPropuestaIaHelper::pide_confirmar_un_envio(
            'La paleta es una carta de colores, no el envase. ¿Confirmás los 145 con las dos fotos?'
        ));
    }

    /**
     * Los envíos de verdad se siguen viendo: el pedido de mandar, el sí a una oferta de mandar y la pregunta de
     * confirmar un envío.
     *
     * @test
     */
    public function los_envios_de_verdad_se_siguen_viendo()
    {
        $this->assertTrue(EnvioSinPropuestaIaHelper::el_dueno_pidio_mandar('mandaselo por mail y por whatsapp así lo ve'));
        $this->assertTrue(EnvioSinPropuestaIaHelper::el_dueno_pidio_mandar('Enviale el presupuesto a Marcos'));
        $this->assertTrue(EnvioSinPropuestaIaHelper::el_dueno_pidio_mandar('Mandales un recordatorio a los que deben'));
        $this->assertTrue(EnvioSinPropuestaIaHelper::el_dueno_pidio_mandar('Si', '¿Querés que le mande el presupuesto a Marcos?'));

        $this->assertTrue(EnvioSinPropuestaIaHelper::pide_confirmar_un_envio(
            'Lo mando por WhatsApp y por mail a Marcos Perez con el PDF del presupuesto 900006. ¿Confirmás el envío?'
        ));
        $this->assertTrue(EnvioSinPropuestaIaHelper::pide_confirmar_un_envio('¿Se lo mando a Marcos por WhatsApp?'));
    }

    /**
     * B2: el prompt dice que una advertencia va una sola vez.
     *
     * @test
     */
    public function el_prompt_dice_advertir_una_sola_vez()
    {
        $comercio = User::create([
            'name'         => 'Comercio U194',
            'company_name' => 'Ferretería U194',
            'email'        => 'advertir-u194-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $conversation = AiConversation::create(['user_id' => $comercio->id, 'auth_user_id' => $comercio->id]);

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $comercio, true, true);

        $this->assertStringContainsString('Una advertencia se dice UNA sola vez', $prompt);
        $this->assertStringContainsString('no la repitas en los mensajes siguientes', $prompt);
    }
}
