<?php

namespace App\Exports;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Provider;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProviderExport implements FromCollection, WithHeadings, WithMapping
{

    public function map($provider): array
    {
        return [
            $provider->num,
            $provider->name,
            $provider->phone,
            $provider->address,
            GeneralHelper::getRelation($provider, 'location'),
            $provider->email,
            GeneralHelper::getRelation($provider, 'iva_condition'),
            $provider->razon_social,
            $provider->cuit,
            $provider->observations,
            $provider->saldo,
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
            'Condicion frente al iva',
            'Razon social',
            'Cuit',
            'Observaciones',
            'Saldo actual',
        ];
    }

    public function collection()
    {
        $models = Provider::where('user_id', UserHelper::userId())
                        ->orderBy('created_at', 'DESC')
                        ->get();
        return $models;
    }
}
