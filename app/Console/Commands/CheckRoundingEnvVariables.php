<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Migra flags legacy de redondeo definidos en .env hacia columnas de users.
 */
class CheckRoundingEnvVariables extends Command
{
    /**
     * Nombre y firma del comando.
     *
     * @var string
     */
    protected $signature = 'check_rounding_env_variables';

    /**
     * Descripción del comando.
     *
     * @var string
     */
    protected $description = 'Copia variables de redondeo del .env a los nuevos flags del usuario';

    /**
     * Ejecuta la migración de variables legacy de redondeo.
     *
     * @return int
     */
    public function handle()
    {
        /**
         * ID del usuario objetivo tomado del .env.
         *
         * @var mixed $user_id_from_env
         */
        $user_id_from_env = env('USER_ID', null);

        if (is_null($user_id_from_env) || $user_id_from_env === '') {
            $this->error('La variable USER_ID no está definida en .env. Proceso abortado.');
            return 1;
        }

        /**
         * Usuario a actualizar con los flags migrados.
         *
         * @var User|null $user
         */
        $user = User::find((int) $user_id_from_env);

        if (is_null($user)) {
            $this->error('No se encontró un usuario con USER_ID='.$user_id_from_env.'. Proceso abortado.');
            return 1;
        }

        /**
         * Mapa de variables legacy del .env hacia columnas nuevas en users.
         *
         * @var array<string, string>
         */
        $env_to_user_fields = [
            'REDONDEAR_PRECIOS_EN_DECENAS' => 'redondear_precios_en_decenas',
            'REDONDEAR_DE_A_50' => 'redondear_de_a_50',
            'REDONDEAR_PRECIOS_EN_CENTAVOS' => 'redondear_precios_en_centavos',
        ];

        /**
         * Payload de columnas a actualizar en users.
         *
         * Solo incluye variables que realmente estén definidas en .env.
         *
         * @var array<string, int>
         */
        $updates = [];

        foreach ($env_to_user_fields as $env_key => $user_field) {
            /**
             * Valor crudo de la variable de entorno.
             *
             * Nota: usamos default null para detectar "no definida" y no
             * confundirlo con false explícito.
             *
             * @var mixed $env_value
             */
            $env_value = env($env_key, null);

            if (!is_null($env_value)) {
                $updates[$user_field] = (int) ((bool) $env_value);
            }
        }

        if (empty($updates)) {
            $this->info('No hay variables de redondeo definidas en .env. No se realizaron cambios.');
            return 0;
        }

        $user->update($updates);

        $this->info('Se detectaron variables de redondeo en .env y se migraron al usuario objetivo.');
        $this->info('USER_ID actualizado: '.$user->id);
        $this->info('Campos actualizados: '.implode(', ', array_keys($updates)));
        $this->info('Registros afectados: 1');

        return 0;
    }
}
