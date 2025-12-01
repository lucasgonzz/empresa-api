<?php

namespace App\Http\Controllers;

use App\Models\ImportStatus;
use Illuminate\Http\Request;

class ImportStatusController extends Controller
{
    function index() {
        $model = ImportStatus::where('user_id', $this->userId())
                            ->orderBy('id', 'DESC')
                            ->first();

        return response()->json(['model'    => $model], 200);
    }
}
