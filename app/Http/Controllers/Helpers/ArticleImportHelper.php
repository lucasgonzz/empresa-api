<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\import\ArticleImportHistoryHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\ImportHistory;
use App\Models\PriceType;
use App\Models\UnidadMedida;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\Log;

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

	static function error_notification($user) {

		Log::info('Enviando notificacion de error');

        $functions_to_execute = [
            [
                'btn_text'      => 'Entendido',
                // 'function_name' => 'close_notification_modal',
                'btn_variant'   => 'primary',
            ],
        ];

        $user->notify(new GlobalNotification(
            'Hubo un error durante la importacion de articulos',
            'danger',
            $functions_to_execute,
            $user->id,
            true,
        ));
	}

    static function set_unidades_por_bulto($article, $columns, $row) {

    	$unidades_x_bulto = ImportHelper::getColumnValue($row, 'u_x_bulto', $columns);

    	if (!is_null($unidades_x_bulto)) {
    		
    		$article->unidades_por_bulto = $unidades_x_bulto;
    		$article->save();
    	}

    }

    static function set_contenido($article, $columns, $row) {

    	$contenido = ImportHelper::getColumnValue($row, 'contenido', $columns);

    	if (!is_null($contenido)) {
    		
    		$article->contenido = $contenido;
    		$article->save();
    	}

    }

    static function set_tipo_de_envase($article, $columns, $row, $ct, $user) {

    	$tipo_de_envase = ImportHelper::getColumnValue($row, 'tipo_de_envase', $columns);

        if (!is_null($tipo_de_envase)) {

        	$data = [
                'name'      => $tipo_de_envase,
                'user_id'   => $user->id,
            ];

	        $ct->createIfNotExist('tipo_envases', 'name', $tipo_de_envase, $data, true, $user->id);

            $tipo_envase_id = $ct->getModelBy('tipo_envases', 'name', $tipo_de_envase, true, 'id', false, $user->id);

            $article->tipo_envase_id = $tipo_envase_id;
        	
        	$article->save();
        }
    }

    static function create_import_history($user, $auth_user_id, $provider_id, $created_models, $updated_models, $columns, $archivo_excel_path, $error_message = null, $articulos_creados, $articulos_actualizados, $updated_props) {
    	
        $import_history = ImportHistory::create([
            'user_id'           => $user->id,
            'employee_id'       => $auth_user_id,
            'model_name'        => 'article',
            'provider_id'       => $provider_id,
            'created_models'    => count($articulos_creados),
            'updated_models'    => count($articulos_actualizados),
            'observations'      => Self::get_observations($columns),
            'excel_url'			=> $archivo_excel_path,
            'error_message'		=> $error_message,
        ]);

        // ArticleImportHistoryHelper::attach_articulos_creados($import_history, $articulos_creados);

        // ArticleImportHistoryHelper::attach_articulos_actualizados($import_history, $articulos_actualizados, $updated_props);

        Log::info('Se creo ImportHistory con '.count($articulos_creados).' creados y '.count($articulos_actualizados).' actualizados con provider_id: '.$provider_id);
    }

    static function guardar_proveedor($columns, $row, $ct, $user) {

        if (!ImportHelper::isIgnoredColumn('proveedor', $columns)) {
            LocalImportHelper::saveProvider(ImportHelper::getColumnValue($row, 'proveedor', $columns), $ct, $user);
        }
    }

    static function get_unidad_medida($data, $columns, $row) {
    	$column_value = ImportHelper::getColumnValue($row, 'unidad_medida', $columns);

        if (ImportHelper::usa_columna($column_value)) {
            $data['unidad_medida_id'] = Self::get_unidad_medida_id($column_value);
        }

        return $data;
    }

    static function get_iva_id($data, $columns, $row, $articulo_existente) {

        if (!ImportHelper::isIgnoredColumn('iva', $columns)) {
            $data['iva_id'] = LocalImportHelper::getIvaId(ImportHelper::getColumnValue($row, 'iva', $columns));

        } else if (is_null($articulo_existente)) {
            $data['iva_id'] = 2;
        }

        return $data;
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
        	// Log::info('-> Filtrando por num');

        } else if (!is_null($provider_code) && env('FILTRAR_CON_PROVIDER_CODE_EN_IMPORTACION', true)) {

        	// Log::info('-> Filtrando por provider_code');
                
            $article = $article->where('provider_code', $provider_code);

        } else if (!is_null($bar_code)) {

        	// Log::info('-> Filtrando por bar_code');
            $article = $article->where('bar_code', $bar_code);

        } else if (!is_null($name)) {

        	// Log::info('-> Filtrando por name');
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
	
	static function add_columns($columns) {
		$column_position = count($columns);

		// Le sumo 3 por las 2 columnas de creado y actualizado y 1 de precio final
		$column_position += 3;


		if (UserHelper::hasExtencion('articulos_con_propiedades_de_distribuidora')) {

			$columns['tipo_de_envase'] = $column_position;
			$column_position++;

			$columns['contenido'] = $column_position;
			$column_position++;

			$columns['u_x_bulto'] = $column_position;
			$column_position++;
		}


		$addresses = Address::where('user_id', UserHelper::userId())
							->orderBy('created_at', 'ASC')
							->get();

		foreach ($addresses as $address) {
			$columns[$address->street] = $column_position;
			$column_position++;
		}

		if (UserHelper::hasExtencion('articulo_margen_de_ganancia_segun_lista_de_precios')) {

			$price_types = PriceType::where('user_id', UserHelper::userId())
									->orderBy('position', 'ASC')
									->get();


			foreach ($price_types as $price_type) {
				$columns['% '.$price_type->name] = $column_position;
				$column_position += 3;
				// Sumo de a 3, porque una columna tiene el % y otra el precio calculado y despues el precio final con iva
			}
		}


		if (UserHelper::hasExtencion('articulos_precios_en_blanco')) {

			$columns['descuentos_en_blanco'] = $column_position;
			$column_position++;

			$columns['recargos_en_blanco'] = $column_position;
			$column_position++;
			
			$columns['margen_de_ganancia_en_blanco'] = $column_position;
			$column_position++;
			
			$columns['precio_final_en_blanco'] = $column_position;
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