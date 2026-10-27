<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Lanza la búsqueda masiva de peso y medidas del catálogo desde la consola (misión
 * medidas-masivas-catalogo, 27/10/2026): el equivalente del botón «Buscar peso y medidas de todo el
 * catálogo» de Alertas → Catálogo → Medidas, para correrlo por SSH en el servidor de un cliente.
 *
 *     php artisan medidas:buscar --dry-run                 # solo muestra la previa (no gasta nada)
 *     php artisan medidas:buscar --solo-con-stock          # los artículos con stock
 *     php artisan medidas:buscar --solo-con-stock --limite=200
 *
 * Toma al dueño de `config('app.USER_ID')` (igual que los demás comandos por negocio): en esta
 * arquitectura cada instalación tiene UN dueño, y este comando solo itera ese. Valida que haya clave de
 * Serper y de IA, crea la corrida de catálogo (origen `catalogo`, sin persona que la lance) y despacha el
 * job por tramos. Eso es todo lo que hace: el worker de la cola hace el resto, y el resultado se revisa en
 * Alertas → Catálogo → Medidas.
 *
 * `--dry-run` imprime la previa (cuántos entran, cuántos no y por qué, tiempo, búsquedas y costo estimados)
 * y NO crea ni despacha nada.
 *
 * Qué NO hace: no escribe medidas en ningún artículo por sí mismo (lo hace el motor, solo con código en la
 * página y coincidencia exacta; el resto queda a revisar) y no corre sin worker: con la cola `database`
 * hace falta que `queue:work` esté vivo.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class MedidasBuscar extends Command
{
    /**
     * @var string
     */
    protected $signature = 'medidas:buscar
                            {--solo-con-stock : Solo los artículos con stock mayor a cero}
                            {--limite= : Máximo de artículos de esta corrida (además del tope de la configuración)}
                            {--dry-run : Solo muestra la previa: no crea ni despacha nada}';

    /**
     * @var string
     */
    protected $description = 'Busca en internet el peso y las medidas de los artículos del catálogo que no los tienen (se revisan en Alertas → Catálogo → Medidas)';

    /**
     * Ejecuta el comando.
     *
     * @return int  0 si se lanzó o se mostró la previa; 1 si no se pudo lanzar.
     */
    public function handle()
    {
        $limite = $this->option('limite');

        // Una opción numérica vacía o con basura es un error, no «sin límite».
        if (!is_null($limite) && (!ctype_digit((string) $limite) || (int) $limite <= 0)) {
            $this->error('--limite tiene que ser un número entero mayor a cero.');

            return 1;
        }

        $owner_id = (int) config('app.USER_ID');
        $owner    = $owner_id > 0 ? User::find($owner_id) : null;

        if (is_null($owner)) {
            $this->error('No se encontró al dueño de la instalación (config app.USER_ID = '.$owner_id.').');

            return 1;
        }

        $solo_con_stock = (bool) $this->option('solo-con-stock');

        $previa = DimensionSearchRunHelper::previa_del_catalogo($owner, $solo_con_stock);

        $this->imprimir_previa($previa, $limite);

        if ($this->option('dry-run')) {
            $this->info('Es un --dry-run: no se creó ni se despachó nada.');

            return 0;
        }

        $resultado = DimensionSearchRunHelper::crear_del_catalogo($owner, null, $solo_con_stock, is_null($limite) ? null : (int) $limite);

        if ($resultado['status'] !== 201) {
            $this->error($resultado['message']);

            return 1;
        }

        $run = $resultado['run'];

        $this->info('Corrida #'.$run->id.' creada con '.$run->total.' artículos y despachada a la cola. Se revisa en Alertas → Catálogo → Medidas.');

        return 0;
    }

    /**
     * Imprime la previa en la consola.
     *
     * @param  array       $previa  La de DimensionSearchRunHelper::previa_del_catalogo().
     * @param  string|null $limite  El --limite pedido (null = sin límite).
     * @return void
     */
    protected function imprimir_previa(array $previa, $limite)
    {
        $a_buscar = is_null($limite) ? $previa['a_buscar'] : min((int) $limite, $previa['a_buscar']);

        $this->line('Artículos activos:                          '.$previa['activos']);
        $this->line('  con peso y medidas completos (no entran): '.$previa['con_medidas_completas']);
        $this->line('  a los que les falta alguna medida:        '.$previa['sin_medidas']);

        if ($previa['solo_con_stock']) {
            $this->line('  sin stock (no entran, por --solo-con-stock): '.$previa['excluidos_sin_stock']);
        }

        $this->line('  con una propuesta esperando revisión:     '.$previa['excluidos_pendientes_de_revision']);
        $this->line('  ya buscados sin éxito (últimos '.DimensionSearchRunHelper::DIAS_SIN_VOLVER_A_BUSCAR.' días):   '.$previa['excluidos_ya_buscados']);
        $this->line('  en otra corrida en curso:                 '.$previa['excluidos_en_otra_corrida']);
        $this->line('A buscar en esta corrida:                   '.$a_buscar.' (tope '.$previa['tope'].')');
        $this->line('Estimado: ~'.$previa['estimacion']['busquedas'].' búsquedas, ~'.$previa['estimacion']['minutos'].' min, USD '.number_format($previa['estimacion']['usd_busquedas'] + $previa['estimacion']['usd_ia'], 2).' (búsquedas '.number_format($previa['estimacion']['usd_busquedas'], 2).' + IA '.number_format($previa['estimacion']['usd_ia'], 2).')');
        $this->line('Serper configurado: '.($previa['proveedor_configurado'] ? 'sí' : 'NO').' · IA configurada: '.($previa['ia_configurada'] ? 'sí' : 'NO'));

        if (!$previa['ia_configurada'] && !empty($previa['ia_motivo'])) {
            $this->warn($previa['ia_motivo']);
        }

        if (!is_null($previa['corrida_activa'])) {
            $this->warn('Ya hay una corrida de catálogo en curso (#'.$previa['corrida_activa']['id'].').');
        }
    }
}
