<?php

use App\Models\Platform;
use App\Models\PlatformConnector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copia filas legacy de mercado_libre_tokens a platform_connectors (una sola vez).
 */
class MigrateMercadoLibreTokensToPlatformConnectors extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('mercado_libre_tokens') || !Schema::hasTable('platform_connectors')) {
            return;
        }

        $platform = Platform::query()
            ->where('slug', Platform::SLUG_MERCADO_LIBRE)
            ->first();
        if (!$platform) {
            return;
        }

        $legacy_rows = DB::table('mercado_libre_tokens')->get();
        foreach ($legacy_rows as $row) {
            if (empty($row->user_id)) {
                continue;
            }

            $existing = PlatformConnector::query()
                ->where('user_id', $row->user_id)
                ->where('platform_id', $platform->id)
                ->first();

            if ($existing && !empty($existing->access_token)) {
                continue;
            }

            PlatformConnector::query()->updateOrCreate(
                [
                    'user_id'     => $row->user_id,
                    'platform_id' => $platform->id,
                ],
                [
                    'status'           => PlatformConnector::STATUS_CONECTADO,
                    'platform_user_id' => $row->meli_user_id ?? null,
                    'access_token'     => $row->access_token ?? null,
                    'refresh_token'    => $row->refresh_token ?? null,
                    'expires_at'       => $row->expires_at ?? null,
                ]
            );
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // Sin rollback: los datos en platform_connectors pueden haberse actualizado después.
    }
}
