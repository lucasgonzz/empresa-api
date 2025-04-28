<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Console\Command;

class set_articles_prices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_articles_prices {user_id}';

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
        $user = User::find($this->argument('user_id'));

        $articles = Article::where('user_id', $this->argument('user_id'))
                            ->get();

        $this->info(count($articles).' articulos');
        foreach ($articles as $article) {
            ArticleHelper::setFinalPrice($article, $user->id, $user, $user->id);
        }
        $this->info('Termino');
        return 0;
    }
}
