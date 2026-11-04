<?php

namespace Tests\Feature\AdminSync\PruebaImportacion;

use App\Http\Controllers\Helpers\asistente_ia\AvisosDeImportacionHelper;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDelAsistenteHelper;
use App\Jobs\RunExcelAnalysisJob;
use App\Models\AiAvisoDelAsistente;
use App\Models\ExcelAnalysisRun;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * El aviso por WhatsApp «ya leí tu Excel» (`importacion_lista`) de una importación pedida en segundo
 * plano desde el ticket de soporte (misión cruzada prueba-martin-importacion, 3/11/2026).
 *
 * Lo que protege:
 *  1. Una corrida que pidió el aviso deja UNA fila en `ai_avisos_del_asistente` al terminar (listo o
 *     error), y una que no lo pidió no deja ninguna: el dueño que sube desde el modal no recibe WhatsApps.
 *  2. El aviso sale por `avisos-pendientes` con la forma del contrato (`id`, `tipo`, `uuid`, `texto`,
 *     `resumen`, `ruta`, `url`) y se sella con `marcar_avisado()` sin volver a ofrecerse.
 */
class AvisoImportacionListaTest extends EmpresaTestCase
{
    /**
     * @param  array<string, mixed>  $payload_extra
     * @return ExcelAnalysisRun
     */
    protected function corrida(array $payload_extra = []): ExcelAnalysisRun
    {
        $dueno = auth()->user();

        return ExcelAnalysisRun::create([
            'uuid'         => Str::uuid()->toString(),
            'user_id'      => $dueno->id,
            'auth_user_id' => $dueno->id,
            'tipo'         => 'analisis',
            'estado'       => 'pendiente',
            /* Ruta inexistente a propósito: el job termina por el camino de error sin llamar a la IA. */
            'excel_path'   => 'imported_files/no_existe_' . Str::random(8) . '.xlsx',
            'payload'      => array_merge([
                'model'             => 'article',
                'original_filename' => 'Lista_Proveedor.xlsx',
                'origen'            => 'martin',
            ], $payload_extra),
        ]);
    }

    /** @test */
    public function la_corrida_que_pidio_el_aviso_deja_una_sola_fila_y_sale_por_pendientes()
    {
        Notification::fake();

        $run   = $this->corrida(['avisar_por_whatsapp' => true]);
        $dueno = auth()->user();

        (new RunExcelAnalysisJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('error', $run->estado);

        $filas = AiAvisoDelAsistente::where('owner_id', $dueno->id)->where('tipo', 'importacion_lista')->where('uuid', $run->uuid)->get();
        $this->assertCount(1, $filas, 'Una fila por corrida.');
        $this->assertStringContainsString('No pude leer "Lista_Proveedor.xlsx"', (string) $filas->first()->texto);

        /* Un segundo cierre (el failed() del job, un reintento) no duplica. */
        AvisosDeImportacionHelper::dejar($run);
        $this->assertSame(1, AiAvisoDelAsistente::where('owner_id', $dueno->id)->where('tipo', 'importacion_lista')->where('uuid', $run->uuid)->count());

        $pendientes = AvisosDelAsistenteHelper::pendientes($dueno, 'https://negocio.test');
        $aviso      = null;
        foreach ($pendientes as $candidato) {
            if ($candidato['id'] === 'importacion_lista:' . $run->uuid) {
                $aviso = $candidato;
            }
        }

        $this->assertNotNull($aviso, 'El aviso tiene que salir por avisos-pendientes.');
        $this->assertSame('importacion_lista', $aviso['tipo']);
        $this->assertSame($run->uuid, $aviso['uuid']);
        $this->assertSame($aviso['texto'], $aviso['resumen']);
        $this->assertNull($aviso['ai_conversation_id']);
        $this->assertSame('/listado-de-articulos?importacion=' . $run->uuid, $aviso['ruta']);
        $this->assertSame('https://negocio.test/listado-de-articulos?importacion=' . $run->uuid, $aviso['url']);

        $this->assertTrue(AvisosDelAsistenteHelper::marcar_avisado($dueno, 'importacion_lista:' . $run->uuid, null, 'wamid.IMPORT-1'));

        $fila = AiAvisoDelAsistente::where('uuid', $run->uuid)->first();
        $this->assertNotNull($fila->avisado_por_whatsapp_at);
        $this->assertSame('wamid.IMPORT-1', $fila->whatsapp_message_id);

        /* Idempotente, y ya no se ofrece. */
        $this->assertTrue(AvisosDelAsistenteHelper::marcar_avisado($dueno, 'importacion_lista:' . $run->uuid));

        $ids = array_map(function ($a) {
            return $a['id'];
        }, AvisosDelAsistenteHelper::pendientes($dueno));
        $this->assertNotContains('importacion_lista:' . $run->uuid, $ids);
    }

    /** @test */
    public function la_corrida_que_no_pidio_el_aviso_no_deja_nada()
    {
        Notification::fake();

        $run   = $this->corrida();
        $dueno = auth()->user();

        (new RunExcelAnalysisJob($run->id))->handle();

        $this->assertSame(0, AiAvisoDelAsistente::where('owner_id', $dueno->id)->where('uuid', $run->uuid)->count());
    }

    /** @test */
    public function el_texto_de_una_corrida_lista_dice_cuantas_filas_y_que_sigue_en_el_sistema()
    {
        $run = $this->corrida(['avisar_por_whatsapp' => '1']);
        $run->update(['estado' => 'listo', 'resultado' => ['column_mapping' => [], 'row_count' => 412]]);

        $texto = AvisosDeImportacionHelper::texto_del_aviso($run->fresh());

        $this->assertStringContainsString('Ya leí "Lista_Proveedor.xlsx"', $texto);
        $this->assertStringContainsString('412 filas', $texto);
        $this->assertStringContainsString('información de ejemplo', $texto);
    }

    /** @test */
    public function un_id_de_otro_dueno_o_desconocido_no_sella_nada()
    {
        $dueno = auth()->user();

        $this->assertFalse(AvisosDelAsistenteHelper::marcar_avisado($dueno, 'importacion_lista:' . Str::uuid()->toString()));
        $this->assertFalse(AvisosDelAsistenteHelper::marcar_avisado($dueno, 'importacion_lista:'));
    }
}
