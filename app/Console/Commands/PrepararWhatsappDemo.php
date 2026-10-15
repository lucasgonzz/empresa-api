<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\WhatsappDemoHelper;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Deja el módulo de WhatsApp de una instancia de DEMO ya armada en modo de ENVÍO SIMULADO
 * (misión whatsapp-envio-simulado-demos, 15/10/2026): conexión activa con clave ficticia,
 * plantillas estándar aprobadas y chats de ejemplo. Es lo mismo que hace el demo setup al armar
 * una demo de cero (`WhatsappDemoHelper::preparar()`), para las instancias que no deben o no
 * pueden pasar por él:
 *
 *   - `demo` y `demo2`, que están en uso y no se rearman para esto;
 *   - `demo3`, que es una COPIA de la base de un cliente real (`cc-refrescar-demo3.sh`) y nunca
 *     pasa por el demo setup. Ahí se corre con `--telefonos-de-ficcion`.
 *
 * 🔴 SE NIEGA FUERA DE UNA INSTANCIA DE DEMO (`FOR_USER=demo`): pisa la clave de Kapso, aprueba
 * plantillas y reescribe teléfonos. Corrido por error contra el sistema de un cliente real, sale
 * con código 1 sin tocar nada.
 *
 * Es idempotente. `--renovar-chats` es la excepción a propósito: borra los chats de ejemplo y los
 * vuelve a sembrar, para repetir una toma (la ventana de 24 h de los chats «abiertos» se cierra al
 * día siguiente, y un recordatorio ya mandado frena el siguiente por 24 h). `--solo-medir` no escribe nada: cuenta los teléfonos (cuántos hay, cuántos no
 * son de ficción, cuántos están repetidos) para decidir antes de correrlo de verdad.
 */
class PrepararWhatsappDemo extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'whatsapp:demo-simulado
        {--telefonos-de-ficcion : Reemplaza los teléfonos de los clientes que no son de ficción (para bases copiadas de un cliente real, como demo3)}
        {--sin-chats : No siembra los chats de ejemplo}
        {--renovar-chats : Borra los chats de ejemplo que sembró este comando y los vuelve a sembrar (fechas de «hace un rato», sin los mensajes que se mandaron filmando)}
        {--solo-medir : No escribe nada: informa cuántos teléfonos hay, cuántos no son de ficción y cuántos están repetidos}';

    /**
     * Descripción del comando para php artisan list.
     *
     * @var string
     */
    protected $description = 'Deja WhatsApp de una instancia de demo en modo de envío simulado (nada sale a Kapso), con plantillas aprobadas y chats de ejemplo';

    /**
     * Ejecuta el comando.
     *
     * @return int  0 si salió bien, 1 si no se pudo (fuera de una demo, sin dueño o error).
     */
    public function handle()
    {
        if (! DemoSetupHelper::es_instancia_de_demo()) {
            $this->error('Esta instalación no es una instancia de demo (falta FOR_USER=demo en el .env): no se toca nada.');

            return 1;
        }

        $owner = $this->dueno();

        if (is_null($owner)) {
            $this->error('No se pudo resolver el dueño de la instancia: USER_ID del .env no existe y hay más de un dueño (o ninguno). No se toca nada.');

            return 1;
        }

        $this->line('Dueño: ' . $owner->id . ' (' . $owner->name . ')');

        try {
            $resumen = WhatsappDemoHelper::preparar($owner, [
                'chats'                => ! $this->option('sin-chats'),
                'telefonos_de_ficcion' => (bool) $this->option('telefonos-de-ficcion'),
                'solo_medir'           => (bool) $this->option('solo-medir'),
                'renovar_chats'        => (bool) $this->option('renovar-chats'),
            ]);
        } catch (\Throwable $e) {
            $this->error('No se pudo preparar WhatsApp: ' . $e->getMessage());

            return 1;
        }

        foreach ($resumen as $clave => $valor) {
            if (is_array($valor)) {
                $valor = count($valor) === 0 ? '—' : implode(' | ', $valor);
            } elseif (is_bool($valor)) {
                $valor = $valor ? 'sí' : 'no';
            } elseif (is_null($valor)) {
                $valor = '—';
            }

            $this->line(str_pad($clave, 24) . $valor);
        }

        return 0;
    }

    /**
     * El dueño de la instancia: el de `USER_ID` del `.env` si existe, y si no el único usuario con
     * `owner_id` nulo. Con más de uno (o ninguno) no se adivina: devuelve null.
     *
     * @return User|null
     */
    private function dueno()
    {
        $id = (int) config('app.USER_ID');

        if ($id > 0) {
            $usuario = User::where('id', $id)->whereNull('owner_id')->first();

            if (! is_null($usuario)) {
                return $usuario;
            }
        }

        $duenos = User::whereNull('owner_id')->limit(2)->get();

        return $duenos->count() === 1 ? $duenos->first() : null;
    }
}
