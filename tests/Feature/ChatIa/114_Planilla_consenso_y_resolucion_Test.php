<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Http\Controllers\Helpers\asistente_ia\LecturaDePlanillaIaHelper as Lectura;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/** La doble lectura controla títulos, cantidades fraccionarias y la imagen que realmente se leyó. */
class Planilla_consenso_y_resolucion_Test extends PlanillaTestCase
{
    public function test_dos_lecturas_vacias_no_generan_avisos_al_comparar_titulos()
    {
        $combinar = new \ReflectionMethod(Lectura::class, 'combinar');
        $combinar->setAccessible(true);
        $vacia = ['encabezado' => null, 'filas' => []];
        $this->assertNull($combinar->invoke(null, [$vacia, $vacia]));
    }

    protected function titulos_con_importe($titulo)
    {
        return ['COMPROBANTE', $titulo, 'FECHA', 'CODIGO', 'DESCRIPCION', 'OPERACION', 'CANTIDAD', 'TIPO', 'DOCUMENTO', 'NOMBRE', 'DIRECCION'];
    }

    protected function leer_con_lecturas(array $a, array $b, array $c)
    {
        $this->lecturas = [
            'claude-sonnet-5-5' => $a,
            'claude-haiku-5-5' => $b,
            'claude-opus-5-5' => $c,
        ];
        $this->falsear_ia();
        $conversacion = $this->conversacion();
        return Lectura::leer($this->foto($this->del_dueno($conversacion, 'Cargá estas ventas')), $conversacion);
    }

    public function test_el_encabezado_del_importe_tambien_necesita_mayoria()
    {
        $filas = $this->filas_de_prueba();
        $resultado = $this->leer_con_lecturas(
            $this->lectura($filas, $this->titulos_con_importe('P. UNIT')),
            $this->lectura($filas, $this->titulos_con_importe('IMPORTE TOTAL')),
            $this->lectura($filas, $this->titulos_con_importe('IMPORTE TOTAL'))
        );
        $this->assertSame(Lectura::LEIDA, $resultado['resultado']);
        $this->assertCount(1, $this->lecturas_de('claude-opus-5-5'));
        $this->assertSame('IMPORTE TOTAL', $resultado['tabla']['encabezado'][1]);
    }

    public function test_sin_mayoria_en_los_titulos_no_se_elige_el_primer_modelo()
    {
        $filas = $this->filas_de_prueba();
        $resultado = $this->leer_con_lecturas(
            $this->lectura($filas, $this->titulos_con_importe('P. UNIT')),
            $this->lectura($filas, $this->titulos_con_importe('IMPORTE TOTAL')),
            $this->lectura($filas, $this->titulos_con_importe('IMPORTE'))
        );
        $this->assertSame(Lectura::NO_SE_PUDO, $resultado['resultado']);
        $this->assertArrayNotHasKey('tabla', $resultado);
    }

    public function test_un_encabezado_solo_no_se_toma_como_lectura_confirmada()
    {
        $filas = $this->filas_de_prueba();
        $resultado = $this->leer_con_lecturas(
            $this->lectura($filas, $this->titulos_con_importe('IMPORTE TOTAL')),
            $this->lectura($filas),
            $this->lectura($filas)
        );
        $this->assertSame(Lectura::LEIDA, $resultado['resultado']);
        $this->assertNull($resultado['tabla']['encabezado']);
        $this->assertCount(1, $this->lecturas_de('claude-opus-5-5'));
    }

    public function test_cantidades_con_tres_decimales_distintas_disparan_desempate()
    {
        $a = $this->filas_de_prueba();
        $b = $a;
        $a[0][6] = '0.125';
        $b[0][6] = '0.126';
        $resultado = $this->leer_con_lecturas($this->lectura($a), $this->lectura($b), $this->lectura($a));
        $this->assertSame(Lectura::LEIDA, $resultado['resultado']);
        $this->assertCount(1, $this->lecturas_de('claude-opus-5-5'));
        $this->assertSame('0.125', $resultado['tabla']['filas'][0][6]);
    }

    public function test_sin_mayoria_en_cantidades_fraccionarias_la_celda_queda_en_duda()
    {
        $a = $this->filas_de_prueba();
        $b = $a;
        $c = $a;
        $a[0][6] = '0.125';
        $b[0][6] = '0.126';
        $c[0][6] = '0.127';
        $resultado = $this->leer_con_lecturas($this->lectura($a), $this->lectura($b), $this->lectura($c));
        $this->assertSame(Lectura::LEIDA, $resultado['resultado']);
        $this->assertSame(['0.125', '0.126', '0.127'], $resultado['tabla']['dudas'][0][6]);
    }

    public function test_la_resolucion_se_mide_sobre_el_webp_si_el_original_no_se_pudo_usar()
    {
        $filas = array_fill(0, 20, $this->filas_de_prueba()[0]);
        $this->todos_leen($filas);
        $this->falsear_ia();
        $conversacion = $this->conversacion();
        $foto = $this->foto($this->del_dueno($conversacion, 'Cargá estas ventas'), 1568, 48);
        $original = (string) (new ImageManager())->canvas(13000, 400, '#ffffff')->encode('png');
        Storage::disk('local')->put($foto->path . AsistenteImagenHelper::SUFIJO_DEL_ORIGINAL, $original);

        $resultado = Lectura::leer($foto, $conversacion);

        $this->assertSame(Lectura::NO_SE_PUDO, $resultado['resultado']);
        $this->assertSame(Lectura::MOTIVO_DE_RESOLUCION, $resultado['motivo']);
        $this->assertArrayNotHasKey('tabla', $resultado);
    }
}
