<?php

namespace Tests\Feature\OnboardingConversacional;

use App\Http\Controllers\Helpers\asistente_ia\OnboardingDelNegocioIaHelper;
use App\Models\AiConversation;
use Illuminate\Support\Facades\DB;

/**
 * Misión onboarding-pausa-y-temas-20261125 (corrección de la revisión de merge) — el bloque del PRIMER turno,
 * con el formulario de la app completo (4 temas sembrados) y la tarea de seguimiento con id largo (5 a 7 dígitos),
 * no puede perder el tema en curso con su archivo, ni la lista completa de claves, ni «Ya te contó en la app».
 * Medido: con id 1234567 el bloque pasaba de 2500 sin detalle; el recorte del final se llevaba «- historia → archivo».
 *
 * 🔴 Datos ficticios. PHP 7.4.
 */
class Bloque_con_formulario_e_id_largo_Test extends OnboardingTestCase
{
    /**
     * @return array<int,array<int,int>>
     */
    public function ids_largos()
    {
        return [[10000], [99999], [123456], [1234567], [9999999]];
    }

    /**
     * @group onboarding-conversacional
     * @test
     * @dataProvider ids_largos
     */
    public function test_primer_turno_con_formulario_e_id_largo_conserva_el_tema_en_curso_y_las_listas($id_largo)
    {
        $this->actingAs($this->comercio, 'sanctum')->postJson('api/ia-app/cuestionario', [
            'negocio_frase'   => 'Distribuidora ficticia de golosinas para kioscos.',
            'objetivos'       => ['vender más'],
            'objetivos_texto' => 'Abrir un segundo depósito.',
            'areas'           => ['stock'],
            'importa_hoy'     => 'Cobrar a tiempo.',
            'largo'           => 'breve',
            'forma'           => 'explicado',
            'confianza'       => 'cauteloso',
            'extra'           => null,
        ])->assertStatus(200);

        $this->el_dueno_escribe('Hola, ¿andás?');

        $tarea = $this->tareas_de_onboarding('activa')[0];

        DB::table('ai_tareas_del_asistente')->where('id', $tarea->id)->update(['id' => $id_largo]);

        $conversacion = AiConversation::where('user_id', $this->comercio->id)->orderBy('id', 'desc')->first();

        $bloque = OnboardingDelNegocioIaHelper::bloque_para_prompt($conversacion, $this->comercio, true);

        $this->assertStringContainsString('PRIMER mensaje', $bloque, 'Precondición: es el bloque del primer turno.');
        $this->assertStringContainsString('#' . $id_largo, $bloque);
        $this->assertLessThanOrEqual(2500, mb_strlen($bloque), 'El bloque entra en 2500 (largo ' . mb_strlen($bloque) . ').');
        $this->assertStringEndsWith('</onboarding_del_negocio>', $bloque);

        // El tema en curso (el primero que falta) conserva su archivo.
        $this->assertRegExp('/^- historia → \S+/m', $bloque, 'El tema en curso conserva su línea «- clave → archivo».');

        // Lista completa de claves que faltan: todas menos las 4 sembradas por el formulario.
        $sembradas = ['como_responder', 'meta_del_anio', 'prioridad_hoy', 'que_vende_y_a_quien'];

        preg_match('/^Claves que faltan \(usalas en temas\): (.+)$/m', $bloque, $m);

        $this->assertNotEmpty($m, 'Falta la línea de claves que faltan.');

        $linea = $m[1];

        foreach (self::TEMAS as $clave) {

            if (in_array($clave, $sembradas, true)) {

                $this->assertStringNotContainsString($clave . ',', $linea . ',', $clave . ' ya está sabida.');

            } else {

                $this->assertStringContainsString($clave, $linea, 'La clave ' . $clave . ' tiene que estar en la lista.');
            }
        }

        // «Ya te contó en la app» intacta, con las 4 claves y su cierre.
        $this->assertRegExp('/^Ya te contó en la app: .+\. No se lo vuelvas a preguntar\.$/m', $bloque);

        foreach ($sembradas as $clave) {

            $this->assertStringContainsString($clave, substr($bloque, strpos($bloque, 'Ya te contó en la app')));
        }
    }
}
