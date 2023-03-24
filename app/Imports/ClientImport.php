<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Client;
use App\Models\CurrentAcount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ClientImport implements ToCollection {

    function __construct($columns, $start_row, $finish_row) {
        $this->columns = $columns;
        Log::info($this->columns);
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->ct = new Controller();
    }

    function checkRow($row) {
        return !is_null(ImportHelper::getColumnValue($row, 'nombre', $this->columns));
    }

    public function collection(Collection $rows) {
        $this->num_row = 1;
        if (is_null($this->finish_row) || $this->finish_row == '') {
            $this->finish_row = count($rows);
        } 
        foreach ($rows as $row) {
            Log::info('this->num_row: '.$this->num_row.' - start_row '.$this->start_row);
            if ($this->num_row >= $this->start_row && $this->num_row <= $this->finish_row) {
                if ($this->checkRow($row)) {
                    $client = Client::where('user_id', $this->ct->userId());
                    if (!is_null(ImportHelper::getColumnValue($row, 'codigo', $this->columns))) {
                        $client = $client->where('num', ImportHelper::getColumnValue($row, 'codigo', $this->columns));
                    } else {
                        $client = $client->where('name', ImportHelper::getColumnValue($row, 'nombre', $this->columns));
                    }
                    $client = $client->first();
                    $this->saveModel($row, $client);
                }
            } else if ($this->num_row > $this->finish_row) {
                break;
            }
            $this->num_row++;
        }
    }

    function saveModel($row, $client) {
        LocalImportHelper::saveLocation(ImportHelper::getColumnValue($row, 'localidad', $this->columns), $this->ct);
        LocalImportHelper::savePriceType(ImportHelper::getColumnValue($row, 'tipo_de_precio', $this->columns), $this->ct);
        $data = [
            'name'              => ImportHelper::getColumnValue($row, 'nombre', $this->columns),
            'phone'             => ImportHelper::getColumnValue($row, 'telefono', $this->columns),
            'address'           => ImportHelper::getColumnValue($row, 'direccion', $this->columns),
            'location_id'       => $this->ct->getModelBy('locations', 'name', ImportHelper::getColumnValue($row, 'localidad', $this->columns), true, 'id'),
            'email'             => ImportHelper::getColumnValue($row, 'email', $this->columns),
            'iva_condition_id'  => $this->ct->getModelBy('iva_conditions', 'name', ImportHelper::getColumnValue($row, 'condicion_frente_al_iva', $this->columns), false, 'id'),
            'razon_social'      => ImportHelper::getColumnValue($row, 'razon_social', $this->columns),
            'cuit'              => ImportHelper::getColumnValue($row, 'cuit', $this->columns),
            'description'       => ImportHelper::getColumnValue($row, 'descripcion', $this->columns),
            'price_type_id'     => $this->ct->getModelBy('price_types', 'name', ImportHelper::getColumnValue($row, 'tipo_de_precio', $this->columns), true, 'id'),
        ];
        if (!is_null($client)) {
            Log::info('actualizando cliente '.$client->name);
            $client->update($data);
        } else {
            if (isset($row['codigo']) && $row['codigo'] != '') {
                $data['num'] = $row['codigo'];
            } else {
                $data['num'] = $this->ct->num('clients');
            }
            $data['user_id'] = $this->ct->userId();
            $data['created_at'] = Carbon::now()->subSeconds($this->finish_row - $this->num_row);
            $client = Client::create($data);
            Log::info('creando cliente '.$client->name);
        }
        if (!is_null(ImportHelper::getColumnValue($row, 'saldo_actual', $this->columns))) {
            $current_acounts = CurrentAcount::where('client_id', $client->id)
                                            ->get();
            if (count($current_acounts) == 0) {
                $is_for_debe = false;
                $saldo_inicial = (float)ImportHelper::getColumnValue($row, 'saldo_actual', $this->columns);
                if ($saldo_inicial >= 0) {
                    $is_for_debe = true;
                }
                $current_acount = CurrentAcount::create([
                    'detalle'   => 'Saldo inicial',
                    'status'    => $is_for_debe ? 'sin_pagar' : 'pago_from_client',
                    'client_id' => $client->id,
                    'debe'      => $is_for_debe ? $saldo_inicial : null,
                    'haber'     => !$is_for_debe ? $saldo_inicial : null,
                    'saldo'     => $saldo_inicial,
                ]);
            }
        }
    }
}
