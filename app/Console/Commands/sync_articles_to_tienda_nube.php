<?php

namespace App\Console\Commands;

use App\Models\SyncToTNArticle;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class sync_articles_to_tienda_nube extends Command
{
    protected $signature = 'sync_articles_to_tienda_nube';

    protected $description = 'Sincroniza los artículos pendientes hacia Tienda Nube';

    public function handle()
    {
        $userId = (int) config('app.USER_ID');
        $lockKey = 'tn-sync:'.sha1(DB::connection()->getDatabaseName().':'.$userId);
        if ((int) DB::selectOne('SELECT GET_LOCK(?, 0) AS tomado', [$lockKey])->tomado !== 1) {
            return 0;
        }

        try {
            if (SyncToTNArticle::owner_rate_limited($userId)) {
                return 0;
            }

            $syncs = SyncToTNArticle::pendingForOwner($userId)->orderBy('id')->get();
            $this->info(count($syncs).' sincronizaciones');

            $service = new TiendaNubeSyncArticleService();
            foreach ($syncs as $sync) {
                if (!$service->sync_article($sync)) {
                    $this->comment('Tienda Nube limitó las consultas. Se detuvo este lote.');
                    return 0;
                }
            }

            $this->comment('Listo');

            return 0;
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lockKey]);
        }
    }
}
