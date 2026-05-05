<?php

namespace App\Console\Commands;

use App\Models\ArticlePdf;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Inicializa una plantilla por defecto `ArticlePdf` para el usuario definido en config (`USER_ID` / `app.USER_ID`).
 */
class init_article_pdf extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'init_article_pdf';

    /**
     * Descripción mostrada en php artisan list.
     *
     * @var string
     */
    protected $description = 'Crea una plantilla ArticlePdf "Oferta" para config(app.USER_ID) si aún no existe con ese nombre.';

    /**
     * Crea el registro con datos fijos de oferta o informa si ya existía.
     *
     * @return int Código de salida (0 éxito, 1 error).
     */
    public function handle()
    {
        $user_id = config('app.USER_ID');

        if (empty($user_id)) {
            $this->error('No está definido config(\'app.USER_ID\'). Configurá USER_ID en .env.');
            return 1;
        }

        $user = User::find($user_id);

        if (is_null($user)) {
            $this->error('No existe User con id='.$user_id.'.');
            return 1;
        }

        $nombre_plantilla = 'Oferta';

        if (ArticlePdf::where('user_id', $user_id)->where('nombre', $nombre_plantilla)->exists()) {
            $this->warn('El usuario user_id='.$user_id.' ya tiene una plantilla ArticlePdf con nombre "'.$nombre_plantilla.'". No se creó otra.');
            return 0;
        }

        ArticlePdf::create([
            'user_id' => $user_id,
            'nombre' => $nombre_plantilla,
            'titulo' => 'OFERTA',
            'mostrar_precio_anterior' => 1,
            'texto_personalizado' => 'Oferta hasta agotar stock',
            'motrar_fecha_impresion' => 1,
        ]);

        $this->info('ArticlePdf "'.$nombre_plantilla.'" creado para user_id='.$user_id.'.');

        return 0;
    }
}
