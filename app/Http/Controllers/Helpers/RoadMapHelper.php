<?php

namespace App\Http\Controllers\Helpers;

use App\Models\RoadMapClientObservation;
use App\Models\RoadMapClientPosition;

class RoadMapHelper {

	static function attach_client_positions($road_map, $client_positions) {
		RoadMapClientPosition::where('road_map_id', $road_map->id)
							->delete();

		foreach ($client_positions as $client_position) {
			RoadMapClientPosition::create([
				'road_map_id'	=> $road_map->id,
				'client_id'		=> $client_position['client']['id'],
				'position'		=> $client_position['position'],
			]);
		}
	}

	static function agrupar_clientes($road_maps)
	{
	    $road_maps->each(function ($road_map) {
	        // Obtener posiciones de clientes para este roadmap
	        $client_positions = $road_map->client_positions->keyBy('client_id');

	        // Agrupar ventas por cliente
	        $groupedByClient = $road_map->sales->groupBy('client_id')->map(function ($sales, $client_id) use ($client_positions) {
	            $client = $sales->first()->client;
	            $position = $client_positions[$client_id]->position ?? 9999; // Si no tiene posición, va al final

	            return [
	                'client' => $client,
	                'sales' => $sales->values(),
	                'position' => $position
	            ];
	        });

	        // Ordenar por la posición definida
	        $orderedClients = $groupedByClient->sortBy('position')->values();

	        // Agregar al roadmap
	        $road_map->clientes = $orderedClients;
	    });

	    $road_maps = self::agregar_client_observations($road_maps);

	    return $road_maps;
	}
	
	// static function agrupar_clientes($road_maps) {

	// 	$road_maps->each(function ($road_map) {
	// 	    $groupedByClient = $road_map->sales->groupBy('client_id')->map(function ($sales) {
	// 	        $client = $sales->first()->client;
	// 	        return [
	// 	            'client' => $client,
	// 	            'sales' => $sales->values()
	// 	        ];
	// 	    })->values();

	// 	    $road_map->clientes = $groupedByClient;
	// 	});

	// 	$road_maps = Self::agregar_client_observations($road_maps);

	// 	return $road_maps;
	// }

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