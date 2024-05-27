<?php
namespace App\Jobs;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\ArticlePrice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Article;

class ProcessArchivoDeIntercambioPrecios implements ShouldQueue
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
        $this->deleteArticles(); // Eliminar artículos antes de procesar
        $this->leer_archivo();
    }

    private function deleteArticles()
    {
        Article::where('user_id', $this->user_id)->delete();
        Log::info('Se eliminaron todos los artículos del usuario ' . $this->user_id);
    }

    function leer_archivo()
    {
        $file = Storage::get('/archivos-de-intercambio/PRECIOS.txt');
        $lines = explode("\n", $file);

        foreach ($lines as $line) {
            $data = explode("\t", $line);

            if ($data[0] != 'Codigo' && $data[0] != '') {

                Log::info('Entro con $data:');
                Log::info($data);

                $article_info = [
                    'provider_code' => $data[0],
                    'price_type_id' =>  $data[1], 
                    'price' => GeneralHelper::get_decimal_value($data[3]),
                    'user_id' => $this->user_id,
                ];

                $article_price_ya_creado = $this->articulo_precio_registrado($article_info);

                if (!is_null($article_price_ya_creado)) {
                    $article_price_ya_creado->update($article_info);

                    Log::info('Se actualizó precio de articulo con código ' . $article_price_ya_creado->provider_code);
                } else {
                    $article_info['user_id'] = $this->user_id;

                    Log::info('Se va a crear precio de articulo con:');
                    Log::info($article_info);

                    ArticlePrice::create($article_info);

                    Log::info('Se CREO precio de articulo con código ' . $article_info['provider_code']);
                }
            }
        }
    }

    function articulo_precio_registrado($article_info)
    {
        $article_price = ArticlePrice::where('user_id', $this->user_id)
            ->where('provider_code', $article_info['provider_code'])
            ->first();

        return $article_price;
    }
}
