<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\UnidadMedida;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;

class ArticleImportHelper {

	static function enviar_notificacion($user) {
	    $functions_to_execute = [];
	    
        if ($user->download_articles) {
	        $functions_to_execute = [
	        	[
	        		'btn_text'		=> 'Actualizar lista de articulos',
	        		'function_name'	=> 'update_articles_after_import',
	        		'btn_variant'	=> 'primary',
	        	],
	        ];
        }

        $user->notify(new GlobalNotification(
		    'Importacion de Excel finalizada correctamente',
		    'success',
		    $functions_to_execute,
		    $user->id,
		    false,
        ));
	}

    static function create_import_history($user, $auth_user_id, $provider_id, $created_models, $updated_models, $columns, $archivo_excel_path) {
        ImportHistory::create([
            'user_id'           => $user->id,
            'employee_id'       => $auth_user_id,
            'model_name'        => 'article',
            'provider_id'       => $provider_id,
            'created_models'    => $created_models,
            'updated_models'    => $updated_models,
            'observations'      => Self::get_observations($columns),
            'excel_url'			=> $archivo_excel_path,
        ]);
        Log::info('Se creo ImportHistory con '.$created_models.' creados y '.$updated_models.' actualizados con provider_id: '.$provider_id);
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
			$article->num = $ct->num('articles', null, 'user_id', $user->id);
			$article->save();
		}
	}

	static function get_articulo_encontrado($user, $row, $columns) {

        $num 			= ImportHelper::getColumnValue($row, 'numero', $columns);
        $bar_code 		= ImportHelper::getColumnValue($row, 'codigo_de_barras', $columns);
        $provider_code 	= ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $columns);
        $name 			= ImportHelper::getColumnValue($row, 'nombre', $columns);

        $article = Article::where('user_id', $user->id)
                            ->where('status', 'active');

        if (!is_null($num)) {

            $article = $article->where('num', $num);

        } else if (!is_null($provider_code) && env('FILTRAR_CON_PROVIDER_CODE_EN_IMPORTACION', true)) {
                
            $article = $article->where('provider_code', $provider_code);

        } else if (!is_null($bar_code)) {

            $article = $article->where('bar_code', $bar_code);

        } else if (!is_null($name)) {

            $article = $article->where('name', $name);
            
        }

        $article = $article->first();

        return $article;

	}

    static function set_existing_articles($user, $props_para_actualizar, $provider_id) {
        $_existing_articles = Article::where('user_id', $user->id)
                                            ->where('status', 'active');

		if (!is_null($provider_id) && $provider_id != 0) {
			$_existing_articles = $_existing_articles->where('provider_id', $provider_id);
			Log::info('Filtrando por provider_id de '.$provider_id);
		}

		$_existing_articles = $_existing_articles->get($props_para_actualizar);

		Log::info(count($_existing_articles).' filtrados');

        return $_existing_articles;

        // $existing_articles = [];
        // foreach ($_existing_articles as $existing_article) {
        //     $existing_articles[$existing_article->num] = $existing_article->toArray();
        // }
        // return $existing_articles;
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