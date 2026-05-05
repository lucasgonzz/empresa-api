<?php

namespace App\Console\Commands;

use App\Models\SaleSenderInfo;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Crea un registro SaleSenderInfo por defecto para el usuario indicado en config (USER_ID).
 */
class set_sale_sender_info extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'set_sale_sender_info';

    /**
     * Descripción mostrada en php artisan list.
     *
     * @var string
     */
    protected $description = 'Crea un SaleSenderInfo para el usuario de config(app.USER_ID) (env USER_ID), si aún no tiene ninguno.';

    /**
     * Ejecuta la creación del remitente usando datos básicos del User.
     *
     * @return int Código de salida (0 éxito, 1 error).
     */
    public function handle()
    {
        // Misma convención que otros comandos internos (set_text_precio_pausado, set_costo_ventas, etc.).
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

        // Evita duplicar remitentes por defecto en ejecuciones repetidas del comando.
        if (SaleSenderInfo::where('user_id', $user_id)->exists()) {
            $this->warn('El usuario user_id='.$user_id.' ya tiene al menos un SaleSenderInfo. No se creó otro.');
            return 0;
        }

        // Nombre comercial o nombre de usuario; fallback genérico.
        $name = $user->company_name ?: $user->name;
        if ($name === null || trim((string) $name) === '') {
            $name = 'Remitente';
        }

        SaleSenderInfo::create([
            'user_id' => $user_id,
            'name' => $name,
            'mail' => '2r.racing.p@gmail.com',
            'cuit' => '33716718919',
            'localidad' => 'Adrogue',
            'postal_code' => '1846',
        ]);

        $this->info('SaleSenderInfo creado para user_id='.$user_id.' (nombre: '.$name.').');

        return 0;
    }
}
