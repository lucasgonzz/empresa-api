<?php

namespace App\Console\Commands;

use App\Models\OnlineConfiguration;
use Illuminate\Console\Command;

/**
 * Comando para setear el texto de precio pausado
 * en la configuración online del usuario definido por config('user_id').
 */
class set_text_precio_pausado extends Command
{
    /**
     * Firma del comando.
     *
     * @var string
     */
    protected $signature = 'set_text_precio_pausado';

    /**
     * Descripción del comando.
     *
     * @var string
     */
    protected $description = 'Setea text_precio_pausado con "Precio pausado" para el user de config(user_id).';

    /**
     * Ejecuta el comando.
     *
     * @return int
     */
    public function handle()
    {
        // ID de usuario configurado para tareas internas.
        $user_id = config('app.USER_ID');

        // Si no hay user_id configurado, se informa y se corta el proceso.
        if (empty($user_id)) {
            $this->error("No se encontró config('user_id').");
            return 1;
        }

        // Se busca la configuración online del usuario indicado.
        $online_configuration = OnlineConfiguration::where('user_id', $user_id)->first();

        // Si no existe configuración online, se informa y se corta el proceso.
        if (is_null($online_configuration)) {
            $this->error('No se encontró OnlineConfiguration para user_id='.$user_id.'.');
            return 1;
        }

        // Texto fijo solicitado para precio pausado.
        $text_precio_pausado = 'Precio pausado';

        // Se setea y persiste el nuevo texto de precio pausado.
        $online_configuration->text_precio_pausado = $text_precio_pausado;
        $online_configuration->save();

        // Mensaje final para confirmar ejecución correcta.
        $this->info('Listo: text_precio_pausado actualizado para user_id='.$user_id.'.');

        return 0;
    }
}
