<?php

namespace App\Models;

use App\Models\OrderProductionStatus;
use App\Models\ProductionBatchMovement;
use App\Models\ProductionBatchMovementType;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ProductionBatch extends Model
{
    protected $guarded = [];

    protected $appends = ['amounts_by_status', 'amounts_by_provider'];
    
    function scopeWithAll($query)
    {
        $query->with(
            'article.images',
            'recipe.article',
            'production_batch_status',
            'recipe_route',
            'production_batch_movements.production_batch_movement_type',
            'production_batch_movements.provider',
            'production_batch_movements.from_order_production_status',
            'production_batch_movements.to_order_production_status',
            'production_batch_movements.inputs.article',
            'production_batch_movements.inputs.address',
        );
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function recipe_route()
    {
        return $this->belongsTo(RecipeRoute::class);
    }

    public function production_batch_status()
    {
        return $this->belongsTo(ProductionBatchStatus::class);
    }

    public function production_batch_movements()
    {
        return $this->hasMany(ProductionBatchMovement::class);
    }


    public function getAmountsByProviderAttribute()
    {
        // buscamos ids de movement types por slug (más robusto que hardcodear IDs)
        $send_type_id = ProductionBatchMovementType::where('slug', 'send_to_provider')->value('id');
        $receive_type_id = ProductionBatchMovementType::where('slug', 'receive_from_provider')->value('id');

        // si todavía no seedaste estos tipos, devolvemos vacío
        if (is_null($send_type_id) || is_null($receive_type_id)) {
            return [];
        }

        // SUM envíos por proveedor
        $sent = ProductionBatchMovement::select('provider_id', DB::raw('SUM(amount) as total_sent'))
            ->where('production_batch_id', $this->id)
            ->whereNotNull('provider_id')
            ->where('production_batch_movement_type_id', $send_type_id)
            ->groupBy('provider_id')
            ->pluck('total_sent', 'provider_id');

        // SUM recepciones por proveedor
        $received = ProductionBatchMovement::select('provider_id', DB::raw('SUM(amount) as total_received'))
            ->where('production_batch_id', $this->id)
            ->whereNotNull('provider_id')
            ->where('production_batch_movement_type_id', $receive_type_id)
            ->groupBy('provider_id')
            ->pluck('total_received', 'provider_id');

        $provider_ids = collect($sent)->keys()->merge(collect($received)->keys())->unique()->values();

        if ($provider_ids->isEmpty()) {
            return [];
        }

        $providers = Provider::whereIn('id', $provider_ids)->get(['id', 'name'])->keyBy('id');

        $result = [];

        foreach ($provider_ids as $provider_id) {
            $total_sent = (float)($sent[$provider_id] ?? 0);
            $total_received = (float)($received[$provider_id] ?? 0);

            $result[] = [
                'provider_id'     => (int)$provider_id,
                'provider_name'   => $providers[$provider_id]->name ?? null,
                'total_sent'      => $total_sent,
                'total_received'  => $total_received,
                'current_amount'  => $total_sent - $total_received,
            ];
        }

        // opcional: orden por current_amount desc
        usort($result, function ($a, $b) {
            return $b['current_amount'] <=> $a['current_amount'];
        });

        return $result;
    }

    public function getAmountsByStatusAttribute()
    {
        // Estados en orden
        $query = OrderProductionStatus::where('user_id', $this->user_id);

        // Con grupos, la solapa "Cantidades en cada Estado" muestra SOLO los estados del grupo de
        // la ruta de este lote. Sin grupo (o sin ruta), se comporta como siempre: todos los estados
        // de la cuenta. El filtro por user_id es un arreglo aparte: hasta hoy esta query no filtraba
        // por cuenta y una base multi-comercio mostraba los estados de todos.
        //
        // ⚠️ $this->user_id y no UserHelper::userId(): este accessor esta en $appends, o sea que
        // corre en cada serializacion del lote, tambien desde una cola o un comando donde no hay
        // sesion. Y $this->recipe_route no agrega una query en el camino normal porque
        // scopeWithAll() ya la trae con with('recipe_route'). No agregar mas queries que esa.
        $route = $this->recipe_route;

        if (!is_null($route) && !is_null($route->order_production_status_group_id) && $route->order_production_status_group_id != 0) {
            $query = $query->where('order_production_status_group_id', $route->order_production_status_group_id);
        }

        $statuses = $query->orderBy('position', 'ASC')
            ->get(['id', 'name', 'position']);

        // Entradas por estado (to)
        $ins = ProductionBatchMovement::select('to_order_production_status_id', DB::raw('SUM(amount) as total_in'))
            ->where('production_batch_id', $this->id)
            ->groupBy('to_order_production_status_id')
            ->pluck('total_in', 'to_order_production_status_id');

        // Salidas por estado (from)
        $outs = ProductionBatchMovement::select('from_order_production_status_id', DB::raw('SUM(amount) as total_out'))
            ->where('production_batch_id', $this->id)
            ->whereNotNull('from_order_production_status_id')
            ->groupBy('from_order_production_status_id')
            ->pluck('total_out', 'from_order_production_status_id');

        $result = [];

        foreach ($statuses as $status) {
            $in = (float)($ins[$status->id] ?? 0);
            $out = (float)($outs[$status->id] ?? 0);

            $result[] = [
                'order_production_status_id' => $status->id,
                'name'                       => $status->name,
                'position'                   => $status->position,
                'total_in'                   => $in,
                'total_out'                  => $out,
                'current_amount'             => $in - $out,
            ];
        }

        return $result;
    }

}