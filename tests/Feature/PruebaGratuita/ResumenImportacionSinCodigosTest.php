<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\AiAvisoDelAsistente;
use App\Models\ImportacionDePrueba;
use App\Models\ImportConflict;
use App\Models\ImportHistory;
use App\Services\Importacion\ImportacionDePruebaService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

class ResumenImportacionSinCodigosTest extends EmpresaTestCase
{
    /** @dataProvider incidencias */
    public function test_el_aviso_del_ticket_distingue_nombres_validos_de_incidencias(bool $con_incidencias): void
    {
        Queue::fake();
        $dueno = auth()->user();
        $historial = ImportHistory::create([
            'user_id' => $dueno->id, 'employee_id' => $dueno->id, 'model_name' => 'article',
            'articles_match' => 0, 'created_models' => 34, 'updated_models' => 0,
            'status' => 'terminado', 'processed_chunks' => 1, 'total_chunks' => 1,
            'matching_counts_json' => json_encode(['creado_nuevo' => 34, 'merge_fila_repetida' => 2]),
        ]);
        $run = ImportacionDePrueba::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $dueno->id, 'support_ticket_id' => 107,
            'clave' => Str::random(64), 'archivo' => 'no-se-reimporta.xlsx',
            'nombre' => 'mis productos.xlsx', 'origen' => 'soporte', 'estado' => 'importando',
            'modo_soporte' => true, 'import_history_id' => $historial->id,
        ]);
        for ($fila = 1; $fila <= 36; $fila++) {
            ImportConflict::create(['import_history_id' => $historial->id,
                'fila' => $fila, 'tipo' => 'sin_identificador', 'nombre_excel' => 'Producto ' . $fila]);
        }
        if ($con_incidencias) {
            ImportConflict::create(['import_history_id' => $historial->id,
                'fila' => 37, 'tipo' => 'ambiguo', 'nombre_excel' => 'Dos candidatos reales']);
            ImportConflict::create(['import_history_id' => $historial->id,
                'fila' => 38, 'tipo' => 'numero_invalido', 'nombre_excel' => 'Precio consultar']);
        }

        (new ImportacionDePruebaService())->avanzar($run);

        $aviso = AiAvisoDelAsistente::where('owner_id', $dueno->id)
            ->where('support_ticket_id', 107)->firstOrFail();
        $this->assertStringContainsString('34 artículos creados', $aviso->texto);
        if ($con_incidencias) {
            $this->assertStringContainsString('1 fila quedó sin importar.', $aviso->texto);
            $this->assertStringContainsString('1 fila quedó con datos para revisar.', $aviso->texto);
        } else {
            $this->assertStringNotContainsString('revisar', $aviso->texto);
            $this->assertStringNotContainsString('sin importar', $aviso->texto);
        }
        $this->assertSame('terminado', $run->fresh()->estado);
    }

    public function incidencias(): array
    {
        return ['nombres_validos' => [false], 'ambiguas_y_datos_invalidos' => [true]];
    }
}
