<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Models\ColumnPosition;
use App\Models\ImportStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

class InitExcelImport {

	static function importar($import_uuid, $archivo_excel, $columns, $create_and_edit, $no_actualizar_articulos_de_otro_proveedor, $start_row, $finish_row, $provider_id, $user, $auth_user_id, $archivo_excel_path) {

        // --- INICIO: CONVERSIÓN DE XLSX a CSV ---
        $csv_relative_path = 'imported_files/' . pathinfo($archivo_excel_path, PATHINFO_FILENAME) . '_' . time() . '.csv';
        $csv_full_path = storage_path('app/' . $csv_relative_path);

        try {
            $conversion_inicio = microtime(true);
            Log::info("Iniciando conversión de XLSX a CSV. Origen: ".$archivo_excel);

			$reader = ReaderEntityFactory::createXLSXReader();

            $reader->open($archivo_excel);

            $writer = WriterEntityFactory::createCSVWriter();
            $writer->openToFile($csv_full_path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $writer->addRow($row);
                }
                break; // Solo procesar la primera hoja
            }

            $writer->close();
            $reader->close();
            $conversion_fin = microtime(true);
            $conversion_duracion = $conversion_fin - $conversion_inicio;
            Log::info("Conversión a CSV completada en ".number_format($conversion_duracion, 3)." segundos. Nuevo archivo: ".$csv_full_path);
        } catch (\Exception $e) {
            Log::error("Error al convertir XLSX a CSV: " . $e->getMessage());
            // Opcional: notificar al usuario del error de conversión
            return;
        }
        // --- FIN: CONVERSIÓN DE XLSX a CSV ---


        $chunkSize = env('ARTICLE_EXCEL_CHUNK_SIZE', 3500);

		Log::warning('chunksize: '.$chunkSize);

        $start = $start_row;

        $chain = [];


		$total_rows = $finish_row - $start_row + 1;
		$total_chunks = (int) ceil($total_rows / $chunkSize);


        $import_status = ImportStatus::create([
		    'user_id' 			=> $user->id,
		    'total_chunks' 		=> $total_chunks,
		    'processed_chunks' 	=> 0,
		    'status' 			=> 'pendiente',
		]);


        $chunk_number = 1;

        while ($start <= $finish_row) {

            $end = min($start + $chunkSize - 1, $finish_row);

            Log::info("Se mandó chunk desde $start hasta $end");

            $chain[] = new ProcessArticleChunk(
                $import_uuid,
                $csv_relative_path,
                $columns,
                $create_and_edit,
                $no_actualizar_articulos_de_otro_proveedor,
                $start,
                $end,
                $provider_id,
                $user,
                $auth_user_id,
                $import_status->id,
                $chunk_number,
            );

            $chunk_number++;

            $start = $end + 1;
        }

        Log::info("Terminaron chunck. Se va a agregar a FinalizeArticleImport");

        $chain[] = new FinalizeArticleImport(
            $import_uuid,
            'article',
            $columns,
            $user,
            $auth_user_id,
            $provider_id,
            $archivo_excel_path,
            $create_and_edit,
            $no_actualizar_articulos_de_otro_proveedor,
        );

        Bus::chain($chain)->dispatch();
	}

}