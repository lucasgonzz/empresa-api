<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;

class set_article_provider_codes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_article_provider_codes {article_id?}';

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
        $user_id = config('app.USER_ID');
        if (!$user_id) {
            $this->comment('No hay user_id definido en el .env. Comando cancelado');
            return;
        }
        $articles = Article::where('user_id', $user_id)
                            ->whereDoesntHave('providers')
                            ->orWhereHas('providers', function ($query) {
                                $query->whereNull('article_provider.provider_code')
                                      ->orWhere('article_provider.provider_code', '');
                            })
                            ->orderBy('id', 'ASC');

        if (!is_null($this->argument('article_id'))) {

            $articles->where('id', '>', $this->argument('article_id'));
        }
        $articles = $articles->get();

        $this->info(count($articles).' articulos');

        foreach ($articles as $article) {

            if (count($article->providers) == 1
                && $article->provider_code
            ) {

                foreach ($article->providers as $article_provider) {
                    
                    $article->providers()->updateExistingPivot($article_provider->pivot->provider_id, [
                        'provider_code' => $article->provider_code,
                    ]);
                    $this->comment('Act '.$article->id);
                }
            } else if (
                count($article->providers) == 0
                && $article->provider_code
                && $article->provider_id
            ) {

                $article->providers()->attach($article->provider_id, [
                    'provider_code' => $article->provider_code,
                ]);
                $this->comment('Agrego '.$article->id);
            }
        }

        $this->info('Listo');

        return 0;
    }
}
