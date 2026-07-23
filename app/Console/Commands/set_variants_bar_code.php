<?php

namespace App\Console\Commands;

use App\Models\ArticleVariant;
use Illuminate\Console\Command;

class set_variants_bar_code extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_variants_bar_code {user_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill bar_code column in article_variants with "0" + variant id. Optional: filtrar por user_id.';

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
     * Itera ArticleVariant cuyos bar_code sean null o vacío, y setea bar_code = '0'.$variant->id.
     * Idempotente: si ya tiene bar_code, no lo modifica. No actualiza timestamps.
     *
     * @return int
     */
    public function handle()
    {
        // Obtener parámetro opcional user_id.
        $user_id = $this->argument('user_id');

        // Construir query base: articulos asociados a usuario (si se especifica).
        $query = ArticleVariant::query();

        if (!empty($user_id)) {
            // Filtrar solo variantes de artículos del usuario especificado.
            $query->whereHas('article', function ($q) use ($user_id) {
                $q->where('user_id', $user_id);
            });
        }

        // Obtener variantes con bar_code null o vacío.
        $query->where(function ($q) {
            $q->whereNull('bar_code')
              ->orWhere('bar_code', '');
        });

        // Procesar en chunks para evitar overhead de memoria.
        $count = 0;
        $query->chunkById(500, function ($variants) use (&$count) {
            foreach ($variants as $variant) {
                // Construir bar_code: '0' + id.
                $bar_code = '0' . $variant->id;

                // Deshabilitar actualización de timestamps.
                $variant->timestamps = false;

                // Asignar y guardar.
                $variant->bar_code = $bar_code;
                $variant->save();

                $count++;
            }
        });

        // Mensaje de éxito.
        $this->info('Se actualizaron ' . $count . ' variantes con bar_code = "0" + id.');

        return 0;
    }
}
