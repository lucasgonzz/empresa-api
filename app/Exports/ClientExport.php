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
    /**
     * Colección precargada para exportar un subconjunto (null = todos del owner).
     *
     * @var \Illuminate\Support\Collection|null
     */
    public $models = null;

    /**
     * ID del usuario owner; en cola no hay sesión.
     *
     * @var int|null
     */
    public $user_id = null;

    /**
     * Configura el lote y el dueño de los datos a exportar.
     *
     * @param \Illuminate\Support\Collection|null $models
     * @param int|null $user_id
     */
    public function __construct($models = null, $user_id = null)
    {
        $this->models = $models;
        $this->user_id = $user_id;
    }

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
            $client->saldo_pesos,
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
     * Devuelve los clientes a exportar (precargados o consulta por owner).
     *
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        if (!is_null($this->models)) {
            return $this->models;
        }

        $owner_user_id = $this->user_id ? $this->user_id : UserHelper::userId();

        return Client::where('user_id', $owner_user_id)
                        ->orderBy('created_at', 'DESC')
                        ->get();
    }
}
