<?php

namespace App\Http\Controllers\Helpers;

use App\Models\RoadMapClientObservation;

class RoadMapHelper {
	
	static function agrupar_clientes($road_maps) {

		$road_maps->each(function ($road_map) {
		    $groupedByClient = $road_map->sales->groupBy('client_id')->map(function ($sales) {
		        $client = $sales->first()->client;
		        return [
		            'client' => $client,
		            'sales' => $sales->values()
		        ];
		    })->values();

		    $road_map->clientes = $groupedByClient;
		});

		$road_maps = Self::agregar_client_observations($road_maps);

		return $road_maps;
	}

	static function agregar_client_observations($road_maps) {
		foreach ($road_maps as $road_map) {
			$road_map->clientes = $road_map->clientes->map(function ($client) use ($road_map) {
				$client_observations = RoadMapClientObservation::where('road_map_id', $road_map->id)
					->where('client_id', $client['client']->id)
					->orderBy('created_at', 'ASC')
					->get();

				$client['client_observations'] = $client_observations;

				return $client;
			});
		}
		return $road_maps;
	}

}