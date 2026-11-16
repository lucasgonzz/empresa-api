<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), P12 — detalles de tono, en el prompt de todos los canales.
 *
 * Los casos reales:
 *   - V25 (Truvari, 13/11, 488-489): «cada vez que emitas una venta… consultame si quiero facturarla» → «No puedo…
 *     cada respuesta mía termina el turno». Una preferencia de cómo trabajar se guarda, no se discute.
 *   - V26 (Truvari, 12/11, 75-76): la autorrespuesta de Lookout Tecnología entró por el chat del dueño y el
 *     asistente la contestó retomando un pendiente.
 *   - V27 (Pesca, 13/11, 350-353): «COMO GUARDO EL NOMBRE EN UN ARTICULO NUEVO» dos veces, y dos veces la misma
 *     explicación.
 *   - V21 / V23: mandar a la pantalla lo que una herramienta hace; repetir como pendiente lo ya hecho.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types, readonly ni enum.
 *
 * @group chat-ia
 */
class P144_Tono_preferencias_y_autorrespuestas_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @test
     */
    public function el_prompt_trae_las_reglas_de_tono_en_el_sistema_y_en_whatsapp()
    {
        $dueno = User::create([
            'name'         => 'Comercio tono P117',
            'company_name' => 'Bebidas P117',
            'email'        => 'tono-p117-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $conversation = AiConversation::create(['user_id' => $dueno->id, 'auth_user_id' => $dueno->id]);

        foreach ([[true, false], [true, true], [false, false]] as $variante) {

            $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $dueno, $variante[0], $variante[1]);

            $this->assertStringContainsString('no le discutas si se puede', $prompt, 'V25');
            $this->assertStringContainsString('respuesta automática de OTRA empresa', $prompt, 'V26');
            $this->assertStringContainsString('la MISMA pregunta de cómo se usa una pantalla', $prompt, 'V27');
            $this->assertStringContainsString('fijate si alguna de tus herramientas lo hace', $prompt, 'V21');
            $this->assertStringContainsString('lo que ya está hecho no se repite como pendiente', $prompt, 'V23');
        }
    }
}
