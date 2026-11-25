<?php

namespace Tests\Feature\OnboardingConversacional;

use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConocimientoDelNegocioIaHelper;
use Illuminate\Support\Facades\DB;

/**
 * Misión onboarding-conversacional-whatsapp — CA3: el índice de conocimiento del negocio. Los 32 temas, los 13
 * esenciales, a qué archivo de la memoria va cada uno y cuándo un tema «sabido» se vuelve «viejo» (la vigencia
 * se calcula, no se guarda).
 */
class Catalogo_y_estado_Test extends OnboardingTestCase
{
    /** Fila de un tema marcado `$dias` días atrás. */
    protected function marcado_hace($clave, $dias, $estado = 'sabido')
    {
        DB::table('ai_conocimiento_del_negocio')->insert([
            'owner_id'   => $this->comercio->id,
            'clave'      => $clave,
            'estado'     => $estado,
            'fuente'     => 'agente',
            'marcado_at' => now()->subDays($dias)->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_catalogo_32_temas_13_esenciales_destinos_validos()
    {
        $catalogo = ConocimientoDelNegocioIaHelper::catalogo();

        $this->assertSame(self::TEMAS, array_keys($catalogo), 'Las 32 claves, en el orden en que se pregunta.');

        $esenciales = [];

        foreach ($catalogo as $clave => $tema) {
            if (!empty($tema['esencial'])) {
                $esenciales[] = $clave;
            }

            $this->assertNotEmpty($tema['pregunta'], 'El tema ' . $clave . ' no tiene pregunta guía.');

            $destino = $tema['destino'];
            $en_la_plantilla = array_key_exists($destino, AgenteContextoHelper::plantilla());
            $es_nueva_valida = (bool) preg_match(AgenteContextoHelper::PATRON_RUTA_NUEVA, $destino);

            $this->assertTrue($en_la_plantilla || $es_nueva_valida, 'El destino de ' . $clave . ' (' . $destino . ') no existe en la plantilla ni cumple el patrón de rutas nuevas.');
        }

        sort($esenciales);
        $esperados = self::ESENCIALES;
        sort($esperados);

        $this->assertSame($esperados, $esenciales, 'Los 13 esenciales.');
    }

    /**
     * Sin filas, los 32 temas faltan y nada está viejo.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_estado_sin_filas_son_32_faltantes()
    {
        $estado = ConocimientoDelNegocioIaHelper::estado($this->comercio);

        $this->assertCount(32, $estado['temas']);

        foreach ($estado['temas'] as $clave => $tema) {
            $this->assertSame('falta', $tema['estado'], $clave);
        }

        $this->assertSame(0, $estado['cuentas']['sabidos']);
        $this->assertSame(32, $estado['cuentas']['faltan']);
        $this->assertSame(13, $estado['cuentas']['esenciales_total']);
        $this->assertSame(0, $estado['cuentas']['esenciales_listos']);
    }

    /**
     * La vigencia: prioridad_hoy vale 90 días, meta_del_anio 365 y historia no vence.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_viejo_por_vigencia()
    {
        $this->marcado_hace('prioridad_hoy', 91);
        $this->marcado_hace('meta_del_anio', 366);
        $this->marcado_hace('historia', 2000);
        $this->marcado_hace('competencia', 89);

        $estado = ConocimientoDelNegocioIaHelper::estado($this->comercio)['temas'];

        $this->assertSame('viejo', $estado['prioridad_hoy']['estado'], '91 días con vigencia de 90.');
        $this->assertSame('viejo', $estado['meta_del_anio']['estado'], '366 días con vigencia de 365.');
        $this->assertSame('sabido', $estado['historia']['estado'], 'La historia no vence nunca.');
        $this->assertSame('sabido', $estado['competencia']['estado'], '89 días con vigencia de 365.');

        $cuentas = ConocimientoDelNegocioIaHelper::estado($this->comercio)['cuentas'];

        $this->assertSame(2, $cuentas['viejos']);
        $this->assertSame(4, $cuentas['sabidos'], 'Un tema viejo sigue contando como sabido: no reabre el onboarding.');
    }

    /**
     * Un tema omitido cuenta aparte y tampoco falta.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_omitido_no_falta()
    {
        $this->marcado_hace('competencia', 1, 'omitido');

        $estado = ConocimientoDelNegocioIaHelper::estado($this->comercio);

        $this->assertSame('omitido', $estado['temas']['competencia']['estado']);
        $this->assertSame(1, $estado['cuentas']['omitidos']);
        $this->assertSame(31, $estado['cuentas']['faltan']);
    }
}
