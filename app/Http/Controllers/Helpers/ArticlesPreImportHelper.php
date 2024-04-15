<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\ArticlesPreImport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session; 

class ArticlesPreImportHelper {
	
	private $provider_id;
	private $current_pre_import;

	function __construct($provider_id, $pre_import_id) {
		$this->provider_id = $provider_id;
		$this->pre_import_id = $pre_import_id;
		$this->set_current_pre_import();
	}

	function set_current_pre_import() {
        $this->current_pre_import = ArticlesPreImport::find($this->pre_import_id);

        if (is_null($this->current_pre_import)) {
            $this->current_pre_import = ArticlesPreImport::create([
                'user_id'           => UserHelper::userId(),
                'employee_id'       => UserHelper::userId(false),
                'provider_id'    	=> $this->provider_id,
            ]);
            Log::info('No hay ArticlesPreImport, se creo');
        } else {
            Log::info('Habia ArticlesPreImport');
        }
        Log::info('pre_import_id: '.$this->current_pre_import->id);
        Session::put('pre_import_id', $this->current_pre_import->id);
	}

	function add_article($article, $data) {
		if ($article->cost != $data['cost']) {
			$this->current_pre_import->articles()->attach($article->id, [
				'costo_actual'	=> $article->cost,
				'costo_nuevo'	=> $data['cost'],
			]);
			// Log::info('add_article. Se agrego '.$article->name);
		}
	}

}