<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Console\Command;

class set_articles_prices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_articles_prices {user_id?}';

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
        $user_id = env('USER_ID');
        if ($this->argument('user_id')) {
            $user_id = $this->argument('user_id');
        }

        $user = User::find($user_id);

        $articles = Article::where('user_id', $user_id)
                            ->get();

        $price_types = PriceType::where('user_id', $user_id)
                                    ->orderBy('position', 'ASC')
                                    ->get();

        $this->info(count($articles).' articulos');
        foreach ($articles as $article) {
            ArticleHelper::setFinalPrice($article, $user->id, $user, $user->id, true, $price_types);
            $this->info($article->name.' listo');
        }
        $this->info('Termino');
        return 0;
    }
}
