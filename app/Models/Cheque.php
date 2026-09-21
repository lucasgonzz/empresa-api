<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cheque extends Model
{
    protected $guarded = [];

    public $dates = [
        'fecha_emision',
        'fecha_pago',
    ];

    /*
     * `cheque_banco` y `endosado_en_expense.expense_concept` se suman desde la misión
     * cheques-endoso-y-bancos (21/9/2026): la SPA muestra el nombre del banco del catálogo y, en la
     * solapa Endosados, el gasto (número y concepto) cuando el cheque se endosó en un gasto y no a un
     * proveedor.
     */
    function scopeWithAll($q) {
        $q->with('client', 'provider', 'cobrado_por', 'rechazado_por', 'endosado_a_provider', 'endosado_desde_client', 'cheque_banco', 'endosado_en_expense.expense_concept');
    }

    function endosado_desde_client() {
        return $this->belongsTo(Client::class, 'endosado_desde_client_id');
    }

    /**
     * En la copia EMITIDA que nace de un endoso, el cheque recibido del que salió.
     */
    function endosado_desde_cheque() {
        return $this->belongsTo(Cheque::class, 'endosado_desde_cheque_id');
    }

    function current_acount() {
        return $this->belongsTo(CurrentAcount::class);
    }

    /**
     * El gasto en el que se cargó este cheque (nuevo o endosado).
     */
    function expense() {
        return $this->belongsTo(Expense::class);
    }

    /**
     * En un cheque RECIBIDO, el gasto en el que se endosó. Es la segunda forma de salir de cartera
     * (la primera es `endosado_a_provider`): por eso "sigue en cartera" se pregunta siempre por
     * ChequeHelper::en_cartera() / sin_endosar(), nunca mirando una sola de las dos columnas.
     */
    function endosado_en_expense() {
        return $this->belongsTo(Expense::class, 'endosado_en_expense_id');
    }

    /**
     * 🔴 La relación se llama `cheque_banco` y NUNCA `banco`: toArray() mergea las relaciones sobre
     * los atributos, y una relación `banco` pisaría el texto legacy `cheques.banco` en el JSON que
     * lee la SPA anterior, el Excel y el mostrador.
     */
    function cheque_banco() {
        return $this->belongsTo(ChequeBanco::class);
    }

    function cobrado_por() {
        return $this->belongsTo(User::class, 'cobrado_por_id');
    }

    function rechazado_por() {
        return $this->belongsTo(User::class, 'rechazado_por_id');
    }

    function client() {
        return $this->belongsTo(Client::class);
    }

    function provider() {
        return $this->belongsTo(Provider::class);
    }

    function endosado_a_provider() {
        return $this->belongsTo(Provider::class, 'endosado_a_provider_id');
    }

    function caja() {
        return $this->belongsTo(Caja::class);
    }
}
