<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Request;

class PowerBiController extends Controller
{
    function articulos() {
        $models = Article::where('user_id', 138)
                        ->get();
        return $models;
    }
}
