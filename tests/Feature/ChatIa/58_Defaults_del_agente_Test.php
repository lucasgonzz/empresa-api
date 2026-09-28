<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión agente-ia-default-deepseek-directo (28/9/2026): un negocio NUEVO —cualquiera que sea el
 * camino que lo crea, `UserSetupHelper`, `DemoSetupHelper` o un `User::create()` derecho— nace con
 * el agente en DeepSeek, pensamiento ágil (= Flash con DeepSeek) y confianza directa, sin que nadie
 * lo elija.
 *
 * 🔴 Protege el DEFAULT DE COLUMNA, no un valor puesto a mano por el Helper: ninguno de los dos
 * Setup Helpers setea estas tres columnas explícitamente al crear el `User` — nacen así porque la
 * migración `2026_09_28_090000_cambiar_defaults_agente_ia_deepseek_directo` les cambió el default,
 * y `ProveedorIaHelper::PROVEEDOR_POR_DEFECTO` / `ConfianzaDelAgenteIaHelper::POR_DEFECTO` tienen
 * que coincidir (son el fallback cuando la columna viene vacía o inválida). Por eso este test crea
 * el `User` derecho, sin tocar las tres columnas, y no llama a ningún Setup Helper.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Defaults_del_agente_Test extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function un_user_nuevo_sin_tocar_las_tres_columnas_nace_en_deepseek_agil_y_directo()
    {
        $dueno = User::create([
            'name'         => 'Comercio defaults del agente P58',
            'company_name' => 'Ferretería P58',
            'email'        => 'defaults-agente-p58-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $fresco = $dueno->fresh();

        $this->assertSame('deepseek', (string) $fresco->agente_proveedor);
        $this->assertSame('agil', (string) $fresco->agente_pensamiento);
        $this->assertSame('directo', (string) $fresco->agente_confianza);

        /* Y los helpers, que son lo que el resto del asistente efectivamente consulta, resuelven igual. */
        $this->assertSame(ProveedorIaHelper::DEEPSEEK, ProveedorIaHelper::proveedor_elegido($fresco));
        $this->assertSame('agil', ProveedorIaHelper::pensamiento_de($fresco, ProveedorIaHelper::DEEPSEEK));
        $this->assertSame(ConfianzaDelAgenteIaHelper::DIRECTO, ConfianzaDelAgenteIaHelper::con_default($fresco));
        $this->assertTrue(ConfianzaDelAgenteIaHelper::es_directo($fresco));
    }

    /**
     * Los tres const `*_POR_DEFECTO` de los helpers tienen que coincidir con el default real de
     * columna — es lo que el docblock de cada uno promete, y es lo que hace que un dueño con la
     * columna en null (una fila vieja, un escritor que no la conoce) caiga en el mismo lugar que
     * uno nuevo.
     *
     * @test
     */
    public function las_constantes_por_defecto_de_los_helpers_coinciden_con_el_nuevo_default()
    {
        $this->assertSame('deepseek', ProveedorIaHelper::PROVEEDOR_POR_DEFECTO);
        $this->assertSame('agil', ProveedorIaHelper::PENSAMIENTO_POR_DEFECTO);
        $this->assertSame('directo', ConfianzaDelAgenteIaHelper::POR_DEFECTO);
    }
}
