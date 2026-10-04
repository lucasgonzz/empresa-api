<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\BalanzaHelper;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pasa a los comercios de las extensiones de balanza a la configuración nueva (misión
 * balanzas-configurables, 3/10/2026). Se publica como comando de la versión, con
 * `run_scope: per_database`, y corre en el despliegue.
 *
 *   php artisan balanzas:migrar-desde-extensiones            -> migra
 *   php artisan balanzas:migrar-desde-extensiones --simular  -> solo dice qué haría
 *
 * Recorre SOLO dueños (`owner_id IS NULL`, de a 200 con chunkById) y, por cada uno, aplica
 * BalanzaHelper::migrar_dueno_desde_extensiones():
 *
 *   - ya tiene `tickets_de_balanza`                -> no se toca (idempotente; respeta lo elegido);
 *   - tiene `plu_balanza_bar_code`                 -> 'plu' (aunque tenga también la de importe:
 *                                                     caso La Martina, se informa);
 *   - tiene solo `balanza_bar_code`                -> 'balanzas' + balanza '22' (importe) apuntando
 *                                                     al artículo que hoy usa el código, si es de
 *                                                     ese dueño (caso Panchito);
 *   - ninguna de las dos                           -> no se toca.
 *
 * 🔴 NO BORRA LAS FILAS DE LAS EXTENSIONES (`extencion_empresa_user`). El pipeline del admin corre
 * los comandos ANTES de rotar el frente (`step_run_commands` -> … -> `step_update_default_version`):
 * mientras tanto atiende el código viejo sobre la MISMA base, y ese código lee las extensiones.
 * Borrarlas acá rompería la balanza del frente activo durante el despliegue. Quedan como basura
 * inofensiva (el código nuevo no las mira); limpiarlas es otra misión, con todos en esta versión.
 *
 * 🔴 Y A DIFERENCIA DE OTROS COMANDOS DEL DESPLIEGUE, ESTE NO SE TRAGA LOS ERRORES: si algo
 * inesperado falla, sale con exit distinto de 0. Así el despliegue frena ANTES de rotar y el
 * cliente sigue con el código viejo, que todavía lee sus extensiones y le anda la balanza. Al revés
 * (tragarse el error y rotar), quedaría en la versión nueva sin `tickets_de_balanza` y VENDER
 * dejaría de leer sus tickets sin que nadie se entere. Es seguro volver a correrlo: lo ya migrado
 * se saltea.
 */
class MigrarBalanzasDesdeExtensiones extends Command
{
    protected $signature = 'balanzas:migrar-desde-extensiones
                            {--simular : Solo muestra lo que haría, sin escribir nada}';

    protected $description = 'Pasa a los dueños con las extensiones de balanza (plu_balanza_bar_code, balanza_bar_code) a la configuración tickets_de_balanza, y crea la balanza 22 de quien usaba la de importe. No borra las extensiones.';

    /**
     * Recorre los dueños, migra cada uno e imprime una línea por dueño con algo que decir y un
     * resumen al final.
     *
     * @return int  0 si terminó. Una excepción sale con exit distinto de 0 (ver el docblock).
     */
    public function handle()
    {
        $simular = (bool) $this->option('simular');

        // Contadores del resumen.
        $contadores = array(
            'revisados'         => 0,
            'plu'               => 0,
            'balanzas'          => 0,
            'balanza_creada'    => 0,
            'balanza_existente' => 0,
            'sin_balanza'       => 0,
            'ya_configurados'   => 0,
            'sin_extensiones'   => 0,
        );

        if ($simular) {
            $this->warn('balanzas:migrar-desde-extensiones: SIMULACIÓN, no se escribe nada.');
        }

        User::query()
            ->whereNull('owner_id')
            ->with('extencions')
            ->chunkById(200, function ($owners) use ($simular, &$contadores) {

                foreach ($owners as $owner) {

                    $contadores['revisados']++;

                    $resultado = BalanzaHelper::migrar_dueno_desde_extensiones($owner, $simular);

                    $this->contar($resultado, $contadores);

                    $linea = $this->describir($owner, $resultado, $simular);

                    if (is_null($linea)) {
                        continue;
                    }

                    if ($resultado['balanza'] === 'sin_articulo') {
                        $this->warn($linea);
                    } else {
                        $this->line($linea);
                    }

                    if (!$simular && $resultado['accion'] !== 'ya_configurado') {
                        Log::info('balanzas:migrar-desde-extensiones: ' . $linea);
                    }
                }
            });

        $resumen = 'Dueños revisados: ' . $contadores['revisados'] . '.'
            . ' A \'plu\': ' . $contadores['plu'] . '.'
            . ' A \'balanzas\': ' . $contadores['balanzas']
            . ' (balanza 22 ' . ($simular ? 'a crear' : 'creada') . ': ' . $contadores['balanza_creada']
            . ', ya existía: ' . $contadores['balanza_existente']
            . ', sin balanza: ' . $contadores['sin_balanza'] . ').'
            . ' Ya configurados (no se tocan): ' . $contadores['ya_configurados'] . '.'
            . ' Sin extensiones de balanza: ' . $contadores['sin_extensiones'] . '.'
            . ' Las filas de las extensiones no se borran.'
            . ($simular ? ' SIMULACIÓN: no se escribió nada.' : '');

        $this->info($resumen);

        Log::info('balanzas:migrar-desde-extensiones: ' . $resumen);

        return 0;
    }

    /**
     * Suma el resultado de un dueño a los contadores del resumen.
     *
     * @param  array  $resultado   Lo que devolvió BalanzaHelper::migrar_dueno_desde_extensiones().
     * @param  array  $contadores  Por referencia.
     * @return void
     */
    protected function contar($resultado, &$contadores)
    {
        if ($resultado['accion'] === 'ya_configurado') {
            $contadores['ya_configurados']++;
            return;
        }

        if ($resultado['accion'] === 'sin_extensiones') {
            $contadores['sin_extensiones']++;
            return;
        }

        if ($resultado['accion'] === 'plu') {
            $contadores['plu']++;
            return;
        }

        $contadores['balanzas']++;

        if ($resultado['balanza'] === 'creada') {
            $contadores['balanza_creada']++;
        } else if ($resultado['balanza'] === 'existente') {
            $contadores['balanza_existente']++;
        } else {
            $contadores['sin_balanza']++;
        }
    }

    /**
     * La línea legible de un dueño, o null si no hay nada que decir (sin extensiones de balanza:
     * son casi todos y solo se cuentan).
     *
     * @param  \App\Models\User  $owner
     * @param  array  $resultado
     * @param  bool   $simular
     * @return string|null
     */
    protected function describir($owner, $resultado, $simular)
    {
        $quien = 'Dueño ' . $owner->id . ' (' . trim($owner->name . ' / ' . $owner->company_name, ' /') . ')';

        if ($resultado['accion'] === 'ya_configurado') {
            return $quien . ': ya tiene tickets_de_balanza = \'' . $resultado['modo'] . '\', no se toca.';
        }

        if ($resultado['accion'] === 'plu') {

            $linea = $quien . ': tiene plu_balanza_bar_code -> ' . ($simular ? 'pasaría' : 'pasa') . ' a \'plu\'.';

            if ($resultado['tambien_importe']) {
                $linea .= ' También tiene balanza_bar_code: se ignora y queda solo la lectura por PLU, que es la que usa'
                    . ' (con las dos, un código 22 desconocido se le cobraba al artículo hardcodeado de la de importe).';
            }

            return $linea;
        }

        if ($resultado['accion'] === 'balanzas') {

            $linea = $quien . ': tiene solo balanza_bar_code -> ' . ($simular ? 'pasaría' : 'pasa') . ' a \'balanzas\'';

            if ($resultado['balanza'] === 'creada') {

                return $linea . ($simular ? ' y se crearía' : ' y se creó')
                    . ' la balanza \'22\' (importe) -> artículo ' . $resultado['article_id'] . ' "' . $resultado['article_name'] . '"'
                    . ($simular ? '' : ' (balanza ' . $resultado['balanza_id'] . ')') . '.';
            }

            if ($resultado['balanza'] === 'existente') {
                return $linea . '; ya tenía la balanza \'22\' (id ' . $resultado['balanza_id'] . '), no se duplica.';
            }

            return $linea . ' SIN balanza: ' . $resultado['motivo'] . '. Hay que cargarla en ABM -> Balanzas.';
        }

        return null;
    }
}
