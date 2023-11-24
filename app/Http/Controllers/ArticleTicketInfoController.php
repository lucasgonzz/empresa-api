<?php

namespace App\Http\Controllers;

use App\Models\ArticleTicketInfo;
use Illuminate\Http\Request;

class ArticleTicketInfoController extends Controller
{
    function index() {
        $models = ArticleTicketInfo::all();
        return response()->json(['models' => $models], 200);
    }
}
