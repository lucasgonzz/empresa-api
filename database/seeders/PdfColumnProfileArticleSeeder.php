<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\PdfColumnArticleSetupHelper;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Perfil PDF por defecto para listado tabular de artículos (por owner).
 */
class PdfColumnProfileArticleSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        PdfColumnArticleSetupHelper::sync_catalog_options();

        User::query()
            ->whereNull('owner_id')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    PdfColumnArticleSetupHelper::apply_for_owner(
                        $user->id,
                        false,
                        true
                    );
                }
            });
    }
}
