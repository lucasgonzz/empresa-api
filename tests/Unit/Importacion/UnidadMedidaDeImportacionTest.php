<?php

namespace Tests\Unit\Importacion;

use App\Http\Controllers\Helpers\import\article\ProcessRow;
use App\Models\Article;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Regresión del ticket 95: UN llegaba al CSV, pero no coincidía con Unidad.
 * No usa base ni red; ejecuta el resolvedor y la comparación reales de ProcessRow.
 */
class UnidadMedidaDeImportacionTest extends TestCase
{
    private function procesador(array $unidades = [], array $columnas = ['unidad_medida' => 0]): ProcessRow
    {
        $reflexion = new \ReflectionClass(ProcessRow::class);
        $procesador = $reflexion->newInstanceWithoutConstructor();
        foreach ([
            'columns' => $columnas,
            'unidad_medidas' => new Collection($unidades ?: [
                (object) ['id' => 1, 'name' => 'Unidad'],
                (object) ['id' => 3, 'name' => 'Kilo'],
            ]),
        ] as $nombre => $valor) {
            $propiedad = $reflexion->getProperty($nombre);
            $propiedad->setAccessible(true);
            $propiedad->setValue($procesador, $valor);
        }

        return $procesador;
    }

    /** @dataProvider unidades_conocidas */
    public function test_resuelve_el_nombre_y_las_abreviaturas_sin_crear_unidades($valor, $esperado): void
    {
        $this->assertSame($esperado, $this->procesador()->get_unidad_medida_id([$valor]));
    }

    public function unidades_conocidas(): array
    {
        return [
            ['Unidad', 1], ['UN', 1], [' un ', 1], ['UND', 1], ['U', 1],
            ['UNIDAD', 1], ['Kilo', 3], [' kilo ', 3],
            ['desconocida', null], ['', null], [null, null],
        ];
    }

    public function test_el_nombre_literal_existente_tiene_prioridad_sobre_un_alias(): void
    {
        $procesador = $this->procesador([
            (object) ['id' => 1, 'name' => 'Unidad'],
            (object) ['id' => 8, 'name' => 'UN'],
        ]);
        $this->assertSame(8, $procesador->get_unidad_medida_id(['UN']));
    }

    public function test_no_inventa_una_unidad_si_el_destino_del_alias_no_existe(): void
    {
        $procesador = $this->procesador([(object) ['id' => 3, 'name' => 'Kilo']]);
        $this->assertNull($procesador->get_unidad_medida_id(['UN']));
    }

    public function test_sin_columna_no_modifica_la_unidad(): void
    {
        $this->assertNull($this->procesador([], [])->get_unidad_medida_id(['UN']));
    }

    /** @dataProvider unidades_para_actualizar */
    public function test_actualiza_la_unidad_y_conserva_la_existente_ante_datos_desconocidos($anterior, $valor, array $esperado): void
    {
        $procesador = $this->procesador();
        $articulo = new Article();
        $articulo->setRawAttributes(['unidad_medida_id' => $anterior]);
        $metodo = new \ReflectionMethod(ProcessRow::class, 'get_modified_fields');
        $metodo->setAccessible(true);
        $cambios = $metodo->invoke($procesador, $articulo, [
            'unidad_medida_id' => $procesador->get_unidad_medida_id([$valor]),
        ]);
        $this->assertSame($esperado, $cambios);
    }

    public function unidades_para_actualizar(): array
    {
        return [
            [null, 'UN', ['unidad_medida_id' => 1, '__diff__unidad_medida_id' => ['old' => null, 'new' => 1]]],
            [3, 'UN', ['unidad_medida_id' => 1, '__diff__unidad_medida_id' => ['old' => 3, 'new' => 1]]],
            [1, 'UN', []], [3, 'desconocida', []], [3, '', []], [3, null, []],
        ];
    }
}
