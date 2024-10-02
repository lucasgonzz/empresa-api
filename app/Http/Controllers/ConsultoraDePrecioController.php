<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Request;

class ConsultoraDePrecioController extends Controller
{
    function buscador($codigo) {

        $article = Article::where('bar_code', $codigo)
                            ->orWhere('provider_code', $codigo)
                            ->first();

        return response()->json(['article' => $article], 200);
    }
}
