<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261119 (rama A: A2, A3, B1 y M2) — las reglas del prompt de listados, conteos,
 * Excel y links de archivos (AsistenteIaService::reglas_de_listados_conteos_y_archivos()).
 *
 * 🔴 LOS CASOS REALES (Ferre Total, 18/11/2026): «no hay remaches de medida 6» con una lista topeada (había 9),
 * «son 70 artículos» con una lista de 64, un Excel armado a mano con el precio manual vacío y «te lo vuelvo a
 * armar» con el mismo link roto.
 *
 * Lo que se cuida: que el bloque esté en el prompt de TODOS los canales, con y sin acciones, y una sola vez.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class T182_Reglas_de_listados_conteos_y_archivos_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @test
     */
    public function las_reglas_van_en_todos_los_canales_una_sola_vez()
    {
        $comercio = User::create([
            'name'     => 'Ferreteria T182',
            'email'    => 't182-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $conversation = AiConversation::create(['user_id' => $comercio->id, 'auth_user_id' => $comercio->id]);

        $service = new AsistenteIaService();

        // [con_acciones, es_whatsapp, es_app]
        foreach ([[true, false, false], [false, false, false], [true, true, false], [true, false, true]] as $caso) {

            $prompt = $service->build_system_prompt($conversation, $comercio, $caso[0], $caso[1], $caso[2]);

            $etiqueta = json_encode($caso);

            // B1: los conteos.
            $this->assertStringContainsString('Las CANTIDADES que decís', $prompt, $etiqueta);
            // A3: con hay_mas no se afirma que algo no existe.
            $this->assertStringContainsString('NUNCA afirmes que algo', $prompt, $etiqueta);
            // M2: el Excel de artículos y el precio de venta.
            $this->assertStringContainsString('Un Excel de ARTÍCULOS sale de exportar_articulos_excel', $prompt, $etiqueta);
            $this->assertStringContainsString('`price` es el precio manual', $prompt, $etiqueta);
            // A2: los links de archivos.
            $this->assertStringContainsString('lo pasás SOLO si te lo devolvió una herramienta', $prompt, $etiqueta);
            $this->assertStringContainsString('no digas', $prompt, $etiqueta);

            $this->assertSame(1, substr_count($prompt, 'Las CANTIDADES que decís'), 'Una sola vez. ' . $etiqueta);
        }
    }
}
