<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Image;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class set_article_images extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_article_images';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $articles = Article::all();

        foreach ($articles as $article) {
            $url = $this->obtener_url_imagen_producto($article);

            if ($url) {
                $this->crear_imagen($article, $url);
                $this->info('imagen creada para article id '.$article->id);
            }
        }
        $this->comment('TERMINO');
        return 0;
    }

    function crear_imagen($article, $url) {
        Image::create([
            'imageable_id'  => $article->id,
            'imageable_type'  => 'article',
            'hosting_url'   => config('app.APP_URL').'/public'.$url,
        ]); 
    }

    function obtener_url_imagen_producto($article) {
        $extensiones = ['jpg', 'jpeg', 'png', 'webp'];
        $carpeta = "public/articles_images/zip/{$article->descripcion}";

        foreach ($extensiones as $ext) {
            $path = "{$carpeta}/{$article->descripcion}.{$ext}";
            if (Storage::exists($path)) {
                return Storage::url($path); // Devuelve URL pública (ej: /storage/product_images/1234/1234.jpg)
            }
        }

        return null; // No se encontró imagen
    }
}
