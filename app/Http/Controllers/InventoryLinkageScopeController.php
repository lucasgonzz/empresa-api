<?php

namespace App\Http\Controllers;

use App\Models\InventoryLinkageScope;
use Illuminate\Http\Request;

class InventoryLinkageScopeController extends Controller
{
    function index() {
        $models = InventoryLinkageScope::all();
        return response()->json(['models' => $models], 200);
    }
}
