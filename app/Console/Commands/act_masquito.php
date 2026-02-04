<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Console\Command;

class act_masquito extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'act_masquito {article_id?}';

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
        $articles = Article::where('provider_id', 4)
                            ->where(function ($query) {
                                $query->whereNotIn('category_id', [69, 70])
                                      ->orWhereNull('category_id');
                            });

        if ($this->argument('article_id')) {
            $articles->where('id', '>=', $this->argument('article_id'));
        }
        $articles = $articles->get();

        $this->info(count($articles).' articles');
        sleep(5);

        $percentage = 3;
        
        $user = User::find(config('app.USER_ID'));

        foreach ($articles as $article) {
            $cost = $article->cost;
            $cost += $cost * $percentage / 100; 
            $article->cost = $cost;
            $article->save();
            ArticleHelper::setFinalPrice($article, $user->id, $user, $user->id);
            $this->info($article->id.' ok');
        }
        $this->info('Listo');
        return 0;
    }
}
