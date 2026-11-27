<?php

namespace Tests\Feature\OnboardingConversacional;

use App\Http\Controllers\Helpers\asistente_ia\OnboardingDelNegocioIaHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\Client;
use App\Models\Provider;
use App\Services\AsistenteIa\AsistenteIaService;

/**
 * Misión asistente-diario-recuperacion-20261127, CA5 (A6, M17 y M18 del informe de la semana).
 *
 * LOS CASOS REALES.
 *  - A6 (San Blas, msgs 83-92 y 109-128): una respuesta que no encajaba con la pregunta se anotó igual
 *    («me lo anoto»); la política de fiado se fijó sin confirmarla; se agregaron datos que el dueño no dijo
 *    («sin cargo», «reparto propio»); y después de cerrar la tanda el asistente se despedía en cada mensaje.
 *  - M17: «la otra vez te preguntaba…» de algo que nunca se preguntó. El bloque afirmaba «Se la hiciste el
 *    dd/mm» con `ia_onboarding_cursor_desde`, que es la fecha en que el cursor CAMBIÓ, no la de la pregunta.
 *  - M18 (Plazoleta, msgs 3-6, canal sistema): el asistente propuso cargar el catálogo «de cero» sin mirar
 *    que ya estaba importado.
 *
 * Lo que se fija es el TEXTO que le llega al modelo (el efecto sobre el modelo no se prueba en phpunit):
 * las cuatro reglas nuevas, el cursor sin afirmar lo que no se sabe, lo último que contó el dueño, lo que ya
 * está cargado en el sistema y la regla de consultar antes de proponer altas.
 *
 * 🔴 Datos ficticios. PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class Respuestas_que_no_encajan_y_estado_real_Test extends OnboardingTestCase
{
    /**
     * CA5.a: el bloque en curso trae las cuatro reglas: encaje de la respuesta, reglas de plata con
     * confirmación en una frase, no agregar datos y cortesías.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function el_bloque_trae_las_reglas_de_encaje_plata_datos_y_cortesias()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $bloque = $this->bloque_de_onboarding();

        $this->assertNotSame('', $bloque, 'Precondición: viaja el bloque de onboarding.');

        // 1) Encaje de la respuesta con la pregunta.
        $this->assertStringContainsString('Si la respuesta no encaja con la pregunta', $bloque);
        $this->assertStringContainsString('repreguntá', $bloque);

        // 2) Reglas de plata: se repiten en UNA frase antes de guardarlas; lo ambiguo se repregunta con opciones.
        $this->assertStringContainsString('repetí en UNA frase lo que entendiste', $bloque);
        $this->assertStringContainsString('nunca un «no te entendí» solo', $bloque);

        // 3) No agregar datos que el dueño no dijo.
        $this->assertStringContainsString('Anotá y repetí sólo lo que dijo', $bloque);

        // 4) Cortesías después de cerrar la tanda.
        $this->assertStringContainsString('contestá una sola vez', $bloque);
        $this->assertStringContainsString('no te despidas en cada mensaje', $bloque);
    }

    /**
     * 🔴 CA5.b (M17): con el cursor movido ayer y sin saber si la pregunta se hizo, el bloque NO afirma «Se la
     * hiciste» ni manda a decir «la otra vez te preguntaba»; dice desde cuándo está pendiente. Y la tarea de
     * seguimiento ya no le pide «en qué quedaron».
     *
     * @group onboarding-conversacional
     * @test
     */
    public function no_afirma_una_pregunta_que_no_se_hizo()
    {
        // Lunes 10:00: el primer mensaje deja el cursor en la primera pregunta.
        $this->el_dueno_escribe('Hola, ¿andás?');

        $this->assertSame('historia', $this->onboarding()->ia_onboarding_cursor);

        // Al día siguiente el dueño vuelve con otra cosa.
        $this->ahora('2026-11-24 10:00:00');
        $this->el_dueno_escribe('perdón, estuve a full');

        $bloque = $this->bloque_de_onboarding();

        $this->assertStringContainsString('Pregunta en curso: historia', $bloque);
        $this->assertStringContainsString('Pendiente desde el 23/11', $bloque);
        $this->assertStringNotContainsString('Se la hiciste', $bloque);
        $this->assertStringNotContainsString('la otra vez te preguntaba', $bloque);

        // La instrucción de la tarea de seguimiento no pide recordar «en qué quedaron» (no tiene ese dato).
        $this->assertStringNotContainsString('en qué quedaron', OnboardingDelNegocioIaHelper::INSTRUCCION);
        $this->assertStringContainsString('lo último que te contó', OnboardingDelNegocioIaHelper::INSTRUCCION);
    }

    /**
     * CA5.b (M17): con un tema marcado, el bloque dice lo último que contó el dueño y cuándo, así el
     * «en qué quedaron» sale de un dato.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function dice_lo_ultimo_que_conto()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        // Sin nada marcado todavía, no hay «lo último».
        $this->assertStringNotContainsString('Lo último que te contó', $this->bloque_de_onboarding());

        $this->ahora('2026-11-24 10:00:00');
        $this->el_agente_guarda(['historia'], 'dueno/perfil.md');

        $this->ahora('2026-11-25 10:00:00');
        $this->el_dueno_escribe('Hola de nuevo');

        $bloque = $this->bloque_de_onboarding();

        $this->assertStringContainsString('Lo último que te contó: historia (24/11)', $bloque);
    }

    /**
     * CA5.c (M18): el bloque trae lo ya cargado en el sistema (artículos activos, clientes, proveedores) con los
     * números reales del negocio.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function trae_lo_ya_cargado_en_el_sistema()
    {
        foreach (['Artículo activo uno', 'Artículo activo dos'] as $nombre) {
            Article::create(['name' => $nombre, 'user_id' => $this->comercio->id, 'status' => 'active']);
        }

        Article::create(['name' => 'Artículo inactivo', 'user_id' => $this->comercio->id, 'status' => 'inactive']);

        foreach (['Cliente ficticio uno', 'Cliente ficticio dos'] as $nombre) {
            Client::create(['name' => $nombre, 'user_id' => $this->comercio->id]);
        }

        Provider::create(['name' => 'Proveedor ficticio', 'user_id' => $this->comercio->id]);

        // Los números esperados salen de la base, no de lo que el test creó (el comercio puede traer datos).
        $articulos = Article::where('user_id', $this->comercio->id)->where('status', 'active')->count();
        $clientes = Client::where('user_id', $this->comercio->id)->count();
        $proveedores = Provider::where('user_id', $this->comercio->id)->count();

        $this->assertGreaterThanOrEqual(2, $articulos);

        $this->el_dueno_escribe('Hola, ¿andás?');

        $this->assertStringContainsString(
            'Ya cargado: ' . $articulos . ' artículos activos, ' . $clientes . ' clientes, ' . $proveedores . ' proveedores',
            $this->bloque_de_onboarding()
        );
    }

    /**
     * CA5.d (M18): el system prompt con acciones pide consultar cuántos hay antes de proponer altas «de cero».
     *
     * @group onboarding-conversacional
     * @test
     */
    public function el_prompt_con_acciones_pide_consultar_antes_de_proponer_altas()
    {
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->comercio->fresh(), true, false);

        $this->assertStringContainsString('antes de proponer cargar artículos, clientes o proveedores «de cero», consultá cuántos hay', $prompt);
        $this->assertStringContainsString('si ya hay importados, no propongas altas', $prompt);
    }
}
