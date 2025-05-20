<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoadMap extends Model
{
    protected $guarded = [];

    protected $dates = ['fecha_entrega'];

    function scopeWithAll($q) {
        $q->with('employee', 'sales.articles', 'sales.promocion_vinotecas', 'sales.current_acount', 'sales.client', 'client_positions.client');
    }

    function employee() {
        return $this->belongsTo(User::class, 'employee_id');
    }

    function sales() {
        return $this->belongsToMany(Sale::class);
    }

    public function client_positions() {
        return $this->hasMany(RoadMapClientPosition::class)->orderBy('position', 'ASC');
    }
}
