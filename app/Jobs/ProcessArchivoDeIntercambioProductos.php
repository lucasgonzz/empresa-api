<?php

namespace App\Jobs;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\Article;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessArchivoDeIntercambioProductos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    public $user_id;
    public $timeout = 9999999;
  
    public function __construct($user_id)
    {
        $this->user_id = $user_id;
    }

   
    public function handle()
    {
        $this->leer_archivo();
    }

    function leer_archivo() {
        $file = Storage::get('/archivos-de-intercambio/PRODUCTOS.txt');
        $lines = explode("\n", $file);

        foreach ($lines as $line) {
            $data = explode("\t", $line);

            if ($data[0] != 'Codigo' && $data[0] != '') {

                Log::info('Entro con $data:');
                Log::info($data);

                $article_info = [
                    'provider_code'         => $data[0],
                    'name'                  => utf8_encode($data[1]),
                    'stock'                 => GeneralHelper::get_decimal_value($data[9]),
                    'bar_code'              => $data[11],
                    'stock_min'             => GeneralHelper::get_decimal_value($data[13]),
                ];

                $article_ya_creado = $this->articulo_registrado($article_info);

                if (!is_null($article_ya_creado)) {

                    $article_ya_creado->update($article_info);

                    Log::info('Se actualizo articulo con provider_code '.$article_ya_creado->provider_code);

                } else {

                    $article_info['user_id']    = $this->user_id;

                    Log::info('Se va a crear article con:');
                    Log::info($article_info);

                    $article = Article::create($article_info);
                    Log::info('Se CREO articulo con provider_code '.$article->provider_code);

                }
            }

        }
    }

    function articulo_registrado($article_info) {
        $article = Article::where('user_id', $this->user_id);

        if (!is_null($article_info['provider_code'])) {

            $article = $article->where('provider_code', $article_info['provider_code']);

        } else if (!is_null($article_info['bar_code'])) {

            $article = $article->where('bar_code', $article_info['bar_code']);

        } else if (!is_null($article_info['name'])) {

            $article = $article->where('name', $article_info['name']);

        }

        $article = $article->first();

        return $article;
    }
}
