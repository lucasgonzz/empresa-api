<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\ExpenseController;
use Carbon\Carbon;

class PendingCompletedHelper {

	static function set_pending_completada($pending_completed) {

		if (!$pending_completed->pending->es_recurrente) {

			$pending_completed->pending->completado = 1;
			$pending_completed->pending->save();
		}
	}
	
	static function check_expense_concept($pending_completed) {

		if (!is_null($pending_completed->pending->expense_concept_id)) {

			$ct = new ExpenseController();

            $request = new \Illuminate\Http\Request();
            $request->expense_concept_id                    = $pending_completed->pending->expense_concept_id;
            $request->amount                                = null;
            $request->current_acount_payment_method_id      = 0;
            $request->observations                          = 'Creado desde la AGENDA';
            $request->created_at                            = Carbon::now();

			$ct->store($request);
		}
	} 

}