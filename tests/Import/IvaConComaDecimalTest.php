<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\import\article\ProcessRow;
use App\Models\Iva;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El IVA de la columna «IVA» de un Excel de artículos, en los dos motores (misión prueba-e2e-campana-imperio,
 * ronda 6, E8.1, 14/11/2026).
 *
 * «stock corralon noviembre.xlsx» trae «10,5%»: ProcessRow::get_iva_id() (el motor por chunks) creaba un Iva nuevo
 * «10,5» y LocalImportHelper::getIvaId() (el de ArticleImport) también. Los dos usan ahora
 * LocalImportHelper::normalizar_porcentaje_de_iva(). Lo que ya se leía bien tiene que dar el MISMO id que antes.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class IvaConComaDecimalTest extends TestCase
{
    use DatabaseTransactions;

    private function id_de(string $porcentaje): int
    {
        $id = Iva::where('percentage', $porcentaje)->value('id');
        $this->assertNotNull($id, 'La tabla de IVAs tiene que traer «' . $porcentaje . '».');

        return (int) $id;
    }

    /** Celda del Excel => porcentaje de la tabla que le corresponde. null = el de siempre (id 2, 21 %). */
    private function casos(): array
    {
        return [
            // Igual que antes.
            ['10.5', '10.5'], [10.5, '10.5'], ['21', '21'], ['21%', '21'], ['0', '0'], ['27', '27'],
            ['Exento', 'Exento'], ['10.5%', '10.5'],
            // Coma decimal: el IVA estándar, no uno nuevo.
            ['10,5', '10.5'], ['10,5%', '10.5'], [' 10,5 % ', '10.5'], ['2,5', '2.5'],
            // Sin dato: el de siempre.
            ['', null], [null, null],
        ];
    }

    private function esperado($porcentaje): int
    {
        return $porcentaje === null ? 2 : $this->id_de($porcentaje);
    }

    public function test_process_row_lee_la_coma_decimal_sin_crear_un_iva_nuevo(): void
    {
        $motor = (new \ReflectionClass(ProcessRow::class))->newInstanceWithoutConstructor();
        $columnas = new \ReflectionProperty(ProcessRow::class, 'columns');
        $columnas->setAccessible(true);
        $columnas->setValue($motor, ['iva' => 0]);
        $cache = new \ReflectionMethod(ProcessRow::class, 'set_iva_cache');
        $cache->setAccessible(true);
        $cache->invoke($motor);

        $antes = Iva::count();
        foreach ($this->casos() as $caso) {
            [$celda, $porcentaje] = $caso;
            $this->assertSame($this->esperado($porcentaje), (int) $motor->get_iva_id([$celda]), json_encode($celda));
        }
        $this->assertSame($antes, Iva::count(), 'No se creó ningún IVA.');
        $this->assertSame(0, Iva::where('percentage', 'like', '%,%')->count());
    }

    public function test_get_iva_id_de_article_import_usa_la_misma_lectura(): void
    {
        $antes = Iva::count();
        foreach ($this->casos() as $caso) {
            [$celda, $porcentaje] = $caso;
            $this->assertSame($this->esperado($porcentaje), (int) LocalImportHelper::getIvaId($celda), json_encode($celda));
        }
        $this->assertSame($antes, Iva::count(), 'No se creó ningún IVA.');
        $this->assertSame(0, Iva::where('percentage', 'like', '%,%')->count());
    }

    public function test_la_normalizacion(): void
    {
        $this->assertSame('10.5', LocalImportHelper::normalizar_porcentaje_de_iva(' 10,5 % '));
        $this->assertSame('21', LocalImportHelper::normalizar_porcentaje_de_iva('21%'));
        $this->assertSame('Exento', LocalImportHelper::normalizar_porcentaje_de_iva('Exento'));
        $this->assertSame('', LocalImportHelper::normalizar_porcentaje_de_iva(null));
        // Un texto con coma que no es un número no se toca.
        $this->assertSame('No, gravado', LocalImportHelper::normalizar_porcentaje_de_iva('No, gravado'));
    }
}
