<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class TsvFileController extends Controller
{
    
    function leer_archivo_articulos() {

        $file = Storage::get('/public/productos.txt');
        $lines = explode("\n", $file);

        $this->user_id = 500;

        foreach ($lines as $line) {
            $data = explode("\t", $line);

            if ($data[0] != 'Codigo' && $data[0] != '') {

                Log::info('Entro con $data:');
                Log::info($data);

                $article_info = [
                    'num'                   => $data[0],
                    'name'                  => $data[1],
                    'stock'                 => $data[9],
                    'bar_code'              => $data[11],
                    'stock_min'             => $data[13],
                ];

                $article_ya_creado = $this->articulo_registrado($article_info);

                if (!is_null($article_ya_creado)) {

                    $article_ya_creado->update($article_info);
                    echo('Se actualizo articulo N° '.$article_ya_creado->num.' </br>');

                } else {
                    $article_info['user_id']    = $this->user_id;
                    Log::info('Se va a crear article con:');
                    Log::info($article_info);
                    $article = Article::create($article_info);
                    echo('Se CREO articulo N° '.$article->num.' </br>');
                }
            }

        }

    }

    function articulo_registrado($article_info) {
        $article = Article::where('user_id', $this->user_id);

        if (!is_null($article_info['num'])) {
            $article = $article->where('num', $article_info['num']);
        } else if (!is_null($article_info['bar_code'])) {
            $article = $article->where('bar_code', $article_info['bar_code']);
        } else if (!is_null($article_info['name'])) {
            $article = $article->where('name', $article_info['name']);
        }

        $article = $article->first();

        return $article;
    }

    //  Codigo  3107
    // Descripcion BAGGIO MULTIFRUTA 8 X 1000 CC.
    // A   1
    // Familia 4
    // NFamilia   JUGOS BRIK 
    // Rubro   30
    // NRubro  BRIK X 1000 CC.
    // Marca   1
    // NMarca  BEBIDAS
    // Stock   3041
    // Alicuota  1  
    // Barra   7790036559223
    // Peso    1
    // Minimo  4
    // Multiplo 4

}
