<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\Helpers\ArticleImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Models\ColumnPosition;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Cell;

class InitExcelImport {

	function importar($data) {
        
        $this->import_uuid    = $data['import_uuid']; 
        $this->archivo_excel  = $data['archivo_excel']; 
        $this->columns    = $data['columns']; 
        $this->create_and_edit    = $data['create_and_edit']; 
        $this->no_actualizar_articulos_de_otro_proveedor  = $data['no_actualizar_articulos_de_otro_proveedor']; 
        $this->actualizar_proveedor  = $data['actualizar_proveedor']; 
        $this->start_row  = $data['start_row']; 
        $this->finish_row = $data['finish_row']; 
        $this->provider_id    = $data['provider_id']; 
        $this->user   = $data['user']; 
        $this->auth_user_id   = $data['auth_user_id']; 
        $this->archivo_excel_path = $data['archivo_excel_path']; 

        $this->chunkSize = env('ARTICLE_EXCEL_CHUNK_SIZE', 3500);
        
        $this->start = $this->start_row;

        $this->chain = [];



        $this->armar_archivo_csv();

        $this->calcular_chunck();

        $this->crear_import_status();

        $this->crear_import_history();

        $this->armar_cadena_de_chunks();

        Bus::chain($this->chain)->dispatch();
	}

    function calcular_chunck() {

        $this->total_rows = $this->finish_row - $this->start_row + 1;
        $this->total_chunks = (int) ceil($this->total_rows / $this->chunkSize);
    }

    function armar_archivo_csv() {

        // --- INICIO: CONVERSIÓN DE XLSX a CSV ---
        $csv_relative_path = 'imported_files/' . pathinfo($this->archivo_excel_path, PATHINFO_FILENAME) . '_' . time() . '.csv';

        $this->csv_full_path = storage_path('app/' . $csv_relative_path);

        try {

            $conversion_inicio = microtime(true);
            
            Log::info("Iniciando conversión de XLSX a CSV. Origen: ".$this->archivo_excel);

            $reader = ReaderEntityFactory::createXLSXReader();

            $reader->open($this->archivo_excel);

            $writer = WriterEntityFactory::createCSVWriter();

            $writer->openToFile($this->csv_full_path);

            foreach ($reader->getSheetIterator() as $sheet) {

                // Remplazo el codigo de Leo por el de abajo para convertir los datos de las celdas a string plano
                // foreach ($sheet->getRowIterator() as $row) {
                //     $writer->addRow($row);
                // }

                
                foreach ($sheet->getRowIterator() as $row) {

                    $cells = [];

                    foreach ($row->getCells() as $cell) {
                        $value = $cell->getValue();

                        if ($value instanceof \DateTime) {
                            $value = $value->format('Y-m-d H:i:s');
                        }

                        if ($value === null) {
                            $value = '';
                        }

                        $cells[] = new Cell((string)$value);
                    }

                    $new_row = new Row($cells, null);
                    $writer->addRow($new_row);
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
    }

    function armar_cadena_de_chunks() {

        $this->chunk_number = 1;

        while ($this->start <= $this->finish_row) {

            $this->end = min($this->start + $this->chunkSize - 1, $this->finish_row);

            Log::info("Se mandó chunk desde $this->start hasta $this->end");

            $this->chain[] = new ProcessArticleChunk(
                $this->import_uuid,
                $this->csv_full_path,
                $this->columns,
                $this->create_and_edit,
                $this->no_actualizar_articulos_de_otro_proveedor,
                $this->actualizar_proveedor,
                $this->start,
                $this->end,
                $this->provider_id,
                $this->user,
                $this->auth_user_id, 
                $this->import_status->id,
                $this->import_history->id,
                $this->chunk_number,
            );

            $this->chunk_number++;

            $this->start = $this->end + 1;
        }


        
        Log::info("Terminaron chunck. Se va a agregar a FinalizeArticleImport");

        $this->chain[] = new FinalizeArticleImport(
            $this->import_uuid,
            'article',
            $this->columns,
            $this->user,
            $this->auth_user_id,
            $this->provider_id,
            $this->archivo_excel_path,
            $this->create_and_edit,
            $this->no_actualizar_articulos_de_otro_proveedor,
            $this->actualizar_proveedor,
            $this->import_history->id,
        );
    }

    function crear_import_status() {


        $this->import_status = ImportStatus::create([
            'user_id'           => $this->user->id,
            'total_chunks'      => $this->total_chunks,
            'processed_chunks'  => 0,
            'articles_match'    => 0,
            'created_models'    => 0,
            'updated_models'    => 0,
            'status'            => 'pendiente',
        ]);
    }

    function crear_import_history() {

        $this->import_history = ImportHistory::create([
            'created_models'  => 0,
            'updated_models'  => 0,
            'status'          => 'en_preparacion',
            'operacion_a_realizar'  => $this->create_and_edit ? 'Crear y actualizar' : 'Solo actualizar',
            'no_actualizar_otro_proveedor' => $this->no_actualizar_articulos_de_otro_proveedor,
            'user_id'         => $this->user ? $this->user->id : null,
            'employee_id'     => $this->auth_user_id,
            'model_name'      => 'article',
            'provider_id'     => $this->provider_id && $this->provider_id !== 'null' ? (int)$this->provider_id : null,
            'columnas'        => json_encode(ArticleImportHelper::convertirPosicionesAColumnas($this->columns), JSON_PRETTY_PRINT),
            // 'observations'    => ArticleImportHelper::get_observations($this->columns ?? []),
            'excel_url'       => $this->archivo_excel_path,
        ]);

        
    }

}