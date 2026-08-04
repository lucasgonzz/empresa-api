<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * NormalizarApiUrl
 *
 * Comando de diagnostico y reparacion para limpiar valores corruptos ya persistidos en
 * "users.api_url" (por ejemplo, con el sufijo "/public" duplicado). El prompt 01 (grupo 237)
 * evita que se sigan generando valores corruptos hacia adelante mediante
 * ApiUrlHelper::canonical_public_url(); este comando barre los valores viejos que ya quedaron
 * mal en instalaciones existentes.
 *
 * Se corre a mano (no esta agendado en el scheduler, ver Kernel::schedule()). Por defecto es de
 * SOLO LECTURA: unicamente reporta que cambiaria. Para persistir los cambios hay que pasar el
 * flag --aplicar de forma explicita. Esta decision (modo seguro por default) es el punto central
 * del prompt: no se invierte la logica con un --dry-run opcional.
 */
class NormalizarApiUrl extends Command
{
    /**
     * Nombre y firma del comando.
     *
     * --aplicar: si se pasa, persiste los valores normalizados. Sin este flag, el comando
     * solo reporta que haria, sin escribir nada en la base de datos.
     *
     * @var string
     */
    protected $signature = 'normalizar_api_url {--aplicar : Escribe los cambios. Sin este flag el comando solo reporta.}';

    /**
     * Descripcion del comando.
     *
     * @var string
     */
    protected $description = 'Reporta y (opcionalmente, con --aplicar) corrige valores corruptos de users.api_url.';

    /**
     * Ejecuta el barrido de normalizacion sobre users.api_url.
     *
     * @return int  0 si el comando pudo correr (incluso si encontro valores para corregir),
     *              1 solo ante un error real que impida seguir de forma segura.
     */
    public function handle()
    {
        // Si esta instalacion necesita agregar "/public" (hosting compartido fuera de local) y
        // APP_URL esta vacia, needs_public_segment() no tiene forma de resolver la URL correcta:
        // abortamos antes de tocar nada para no persistir valores sin dominio.
        if (ApiUrlHelper::needs_public_segment() && ApiUrlHelper::base() === '') {
            $this->error('APP_URL esta vacia. Cargala en el .env antes de correr este comando.');
            return 1;
        }

        // Flag --aplicar: determina si se persisten los cambios o solo se reportan.
        $aplicar = (bool) $this->option('aplicar');

        // Contadores del resumen final.
        $total = 0;
        $ya_correcto = 0;
        $a_corregir = 0;
        $sin_dominio = 0;

        // Recorremos en chunks de 200 para no cargar toda la tabla users en memoria de una vez.
        // Solo nos interesan los usuarios con api_url cargada (no nula y no vacia).
        User::query()
            ->whereNotNull('api_url')
            ->where('api_url', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($users) use (&$total, &$ya_correcto, &$a_corregir, &$sin_dominio, $aplicar) {
                foreach ($users as $user) {
                    $total++;

                    // Valor canonico calculado a partir del valor actual, posiblemente corrupto.
                    $canonico = ApiUrlHelper::canonical_public_url($user->api_url);

                    // canonical_public_url() devuelve cadena vacia cuando el valor de entrada no
                    // tiene dominio resoluble (por ejemplo, solo estaba compuesto por "/public"
                    // repetidos). Sobrescribir con vacio seria peor que dejarlo: lo dejamos para
                    // revision manual y no lo tocamos.
                    if ($canonico === '') {
                        $sin_dominio++;
                        $this->warn("[sin_dominio] id={$user->id} | {$user->name} | valor_actual={$user->api_url}");
                        continue;
                    }

                    // Si el valor canonico es identico al actual, no hay nada que corregir.
                    if ($canonico === $user->api_url) {
                        $ya_correcto++;
                        continue;
                    }

                    // El valor difiere: lo reportamos siempre, y solo lo persistimos si vino
                    // el flag --aplicar.
                    $a_corregir++;
                    $this->line("id={$user->id} | {$user->name} | {$user->api_url} -> {$canonico}");

                    if ($aplicar) {
                        $user->api_url = $canonico;
                        $user->save();
                    }
                }
            });

        // Resumen final con los cuatro contadores pedidos.
        $this->info('--- Resumen ---');
        $this->info("total: {$total}");
        $this->info("ya_correcto: {$ya_correcto}");
        $this->info("a_corregir: {$a_corregir}");
        $this->info("sin_dominio: {$sin_dominio}");

        // Si corrio en modo reporte y encontro algo para corregir, recordamos que hay que
        // volver a correrlo con --aplicar para que se escriba de verdad.
        if (!$aplicar && $a_corregir > 0) {
            $this->comment('Corriste sin --aplicar: no se escribio nada. Volve a correr con --aplicar para persistir los cambios.');
        }

        // Es una herramienta de diagnostico y reparacion, no un check de CI: siempre devuelve 0
        // salvo el abort temprano por APP_URL vacia de mas arriba.
        return 0;
    }
}
