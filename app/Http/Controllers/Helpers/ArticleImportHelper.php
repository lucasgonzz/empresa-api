<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\UnidadMedida;
use Illuminate\Support\Facades\Log;

class ArticleImportHelper {

	static function enviar_notificacion($user) {
		$ct = new Controller();
        $ct->sendUpdateModelsNotification('article', false, $user);
	}

    static function create_import_history($user, $provider_id, $created_models, $updated_models, $columns) {
        ImportHistory::create([
            'user_id'           => $user->id,
            'employee_id'       => UserHelper::userId(false),
            'model_name'        => 'article',
            'provider_id'       => $provider_id,
            'created_models'    => $created_models,
            'updated_models'    => $updated_models,
            'observations'      => Self::get_observations($columns),
        ]);
        Log::info('Se creo ImportHistory');
    }

    static function get_observations($columns) {
        $observations = 'Columnas para importar: ';
        foreach ($columns as $nombre_columna => $key) {
            $observations .= $nombre_columna . ' ' . ' en la posicion '. $key . '. ';
        }
        return $observations;
    }

	static function set_articles_num($user, $ct) {
		$articles = Article::where('user_id', $user->id)
							->whereNull('num')
							->orderBy('id', 'ASC')
							->get();

		foreach ($articles as $article) {
			$article->num = $ct->num('articles');
			$article->save();
		}
	}

    static function set_existing_articles($user, $props_para_actualizar, $provider_id) {
        $_existing_articles = Article::where('user_id', $user->id)
                                            ->where('status', 'active');

		if (!is_null($provider_id) && $provider_id != 0) {
			$_existing_articles = $_existing_articles->where('provider_id', $provider_id);
		}

		$_existing_articles = $_existing_articles->get($props_para_actualizar);

        $existing_articles = [];
        foreach ($_existing_articles as $existing_article) {
            $existing_articles[$existing_article->num] = $existing_article->toArray();
        }
        return $existing_articles;
    }
	
	static function addAddressesColumns($columns) {
		$addresses = Address::where('user_id', UserHelper::userId())
							->orderBy('created_at', 'ASC')
							->get();
		$column_position = count($columns);

		// Le sumo 3 por las 2 columnas de creado y actualizado y 1 de precio final
		$column_position += 3;
		foreach ($addresses as $address) {
			$columns[$address->street] = $column_position;
			$column_position++;
		}
		return $columns;
	}

	static function get_unidad_medida_id($unidad_medida_excel) {
		$unidad_medida = null;

		if ($unidad_medida_excel == 'Rol') {
			$unidad_medida = 'Rollo';
		} else if ($unidad_medida_excel == 'UN') {
			$unidad_medida = 'Unidad';
		} else if ($unidad_medida_excel == 'C/U') {
			$unidad_medida = 'Unidad';
		} else if ($unidad_medida_excel == 'Mts') {
			$unidad_medida = 'Metro';
		}

		if (is_null($unidad_medida)) {
			$unidad_medida = $unidad_medida_excel;
		}
		
		$unidad_medida_store = UnidadMedida::where('name', $unidad_medida)
											->first();

		if (!is_null($unidad_medida_store)) {
			return $unidad_medida_store->id;
		}

		return 1;

	}
}