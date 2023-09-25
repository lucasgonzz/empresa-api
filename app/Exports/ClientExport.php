<?php

namespace App\Exports;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Client;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ClientExport implements FromCollection, WithHeadings, WithMapping
{

    public function map($client): array
    {
        return [
            $client->num,
            $client->name,
            $client->phone,
            $client->address,
            GeneralHelper::getRelation($client, 'location'),
            $client->email,
            GeneralHelper::getRelation($client, 'iva_condition'),
            $client->razon_social,
            $client->cuit,
            $client->saldo,
            $client->description,
            GeneralHelper::getRelation($client, 'seller'),
            GeneralHelper::getRelation($client, 'price_type'),
        ];
    }

    public function headings(): array
    {
        return [
            'Numero',
            'Nombre',
            'Telefono',
            'Direccion',
            'Localidad',
            'Email',
            'Condicion de iva',
            'Razon social',
            'Cuit',
            'Saldo',
            'Descripcion',
            'Vendedor',
            'Tipo de precio',
        ];
    }


    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $models = Client::where('user_id', UserHelper::userId())
                        ->orderBy('created_at', 'DESC')
                        ->get();
        return $models;
    }
}
