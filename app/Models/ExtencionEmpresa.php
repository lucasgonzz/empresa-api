<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExtencionEmpresa extends Model
{
    use HasFactory;

    /**
     * Sin el cast, `en_desuso` llega como 0/1 y la vista tendría que acordarse de comparar
     * flojo en cada uso (misión 54).
     *
     * @var array
     */
    protected $casts = [
        'en_desuso' => 'boolean',
    ];
}
