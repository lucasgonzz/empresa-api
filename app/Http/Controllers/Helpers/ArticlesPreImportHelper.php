<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\ArticlesPreImport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticlesPreImportHelper {
	
	private $provider_id;
	private $current_pre_import;

	function __construct($provider_id) {
		$this->provider_id = $provider_id;
		$this->set_current_pre_import();
	}

	function set_current_pre_import() {
        Log::info('______________________ set_current_pre_import ______________________');
        $this->current_pre_import = ArticlesPreImport::where('user_id', UserHelper::userId())
                                                ->where('employee_id', UserHelper::userId(false))
                                                ->where('updated_at', '>=', Carbon::now()->subMinutes(3))
                                                ->first();

        if (is_null($this->current_pre_import)) {
            $this->current_pre_import = ArticlesPreImport::create([
                'user_id'           => UserHelper::userId(),
                'employee_id'       => UserHelper::userId(false),
                'provider_id'    	=> $this->provider_id,
            ]);
            Log::info('No hay ArticlesPreImport, se creo con updated_at = '.$import_history->updated_at);
        } else {
            Log::info('Habia ArticlesPreImport');
        }
	}

	function add_article($article, $data) {
		$this->current_pre_import->articles()->attach($article->id, [
			'costo_actual'	=> $article->cost,
			'costo_nuevo'	=> $data['cost'],
		]);
		Log::info('add_article. Se agrego '.$article->name);
	}

}