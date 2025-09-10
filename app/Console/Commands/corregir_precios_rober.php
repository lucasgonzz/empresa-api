<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;

class corregir_precios_rober extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'corregir_precios_rober';

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
            
            $article->cost = $article->price;
            $article->price = null;
            $article->timestamps = false;
            $article->save();
            $this->comment('Corregido '.$article->name);
        }
        $this->info('Listo');
        return 0;
    }
}
