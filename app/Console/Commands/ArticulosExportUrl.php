<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Comando para mostrar (o regenerar) la URL publica del export de articulos que consume
 * el flujo externo de n8n (grupo 211). La URL lleva la `articles_export_key` del owner en
 * el path, porque el endpoint (prompt 02) es publico y no tiene autenticacion.
 */
class ArticulosExportUrl extends Command
{
    /**
     * Nombre y firma del comando.
     *
     * --user_id: id del owner a resolver explicitamente (evita la resolucion automatica
     * cuando hay mas de un owner en la base).
     * --regenerar: invalida la key actual y genera una nueva, pidiendo confirmacion antes.
     *
     * @var string
     */
    protected $signature = 'articulos:export-url {--user_id=} {--regenerar}';

    /**
     * Descripcion del comando.
     *
     * @var string
     */
    protected $description = 'Muestra (o regenera) la URL publica de export de articulos para el flujo externo';

    /**
     * Ejecuta el comando: resuelve el owner, genera la key si hace falta y muestra la
     * URL publica final.
     *
     * @return int
     */
    public function handle()
    {
        // Owner sobre el que se va a mostrar/regenerar la key. Se resuelve en resolveOwner().
        $owner = $this->resolveOwner();

        if (is_null($owner)) {
            // resolveOwner() ya imprimio el motivo (ninguno, o ambiguo con varios owners).
            return 1;
        }

        if ($this->option('regenerar')) {
            // Confirmacion explicita porque regenerar corta el flujo externo hasta que se
            // le cargue la nueva URL en n8n.
            $confirmado = $this->confirm(
                'La URL actual va a dejar de funcionar de inmediato. El flujo externo se corta '
                . 'hasta que se le cargue la nueva URL. ¿Confirmar regeneracion?'
            );

            if (!$confirmado) {
                $this->comment('Operacion cancelada. La key actual no se modifico.');
                return 0;
            }

            $owner->articles_export_key = Str::random(48);
            $owner->timestamps = false;
            $owner->save();

            $this->info('Se regenero la articles_export_key del comercio.');
        } elseif (is_null($owner->articles_export_key)) {
            // El owner todavia no tiene key (por ejemplo, se creo despues del backfill de
            // la migracion). Se genera en este momento.
            $owner->articles_export_key = Str::random(48);
            $owner->timestamps = false;
            $owner->save();

            $this->info('El comercio no tenia articles_export_key, se genero una nueva.');
        }

        $this->mostrarUrl($owner);

        return 0;
    }

    /**
     * Resuelve el owner sobre el que operar: usa --user_id si se paso, o el unico owner
     * de la base si solo hay uno. Si hay mas de un owner y no se paso --user_id, lista
     * los candidatos y pide que se especifique.
     *
     * @return User|null
     */
    private function resolveOwner()
    {
        $user_id = $this->option('user_id');

        if (!is_null($user_id) && $user_id !== '') {
            $owner = User::whereNull('owner_id')->where('id', $user_id)->first();

            if (is_null($owner)) {
                $this->error('No se encontro un owner (owner_id NULL) con id ' . $user_id . '.');
                return null;
            }

            return $owner;
        }

        // Sin --user_id: solo se resuelve automaticamente si hay exactamente un owner.
        $owners = User::whereNull('owner_id')->get(['id', 'company_name']);

        if ($owners->count() === 0) {
            $this->error('No hay ningun usuario owner (owner_id NULL) en la base.');
            return null;
        }

        if ($owners->count() > 1) {
            $this->error('Hay mas de un owner en la base. Especifica --user_id con el id correspondiente:');

            foreach ($owners as $owner_candidato) {
                $this->line($owner_candidato->id . ' - ' . $owner_candidato->company_name);
            }

            return null;
        }

        return User::find($owners->first()->id);
    }

    /**
     * Imprime la URL publica completa del export, mas un ejemplo de llamada incremental
     * con `updated_since`. Si APP_URL no esta configurada (vacia o en el default de
     * Laravel), avisa que hay que completarla igual mostrando el path.
     *
     * @param User $owner
     * @return void
     */
    private function mostrarUrl($owner)
    {
        $app_url = config('app.url');
        $path = '/api/integraciones/articulos/' . $owner->articles_export_key;

        if (empty($app_url) || $app_url === 'http://localhost') {
            $this->warn('APP_URL no esta configurada correctamente en el .env (vacia o en el default de Laravel).');
            $this->warn('Completa APP_URL para tener la URL absoluta. Path del endpoint:');
            $this->line($path);
            return;
        }

        $url_completa = rtrim($app_url, '/') . $path;

        $this->info('URL publica de export de articulos:');
        $this->line($url_completa);
        $this->line('');
        $this->info('Ejemplo de llamada incremental (solo articulos modificados desde una fecha):');
        $this->line($url_completa . '?updated_since=2026-07-01 00:00:00');
    }
}
