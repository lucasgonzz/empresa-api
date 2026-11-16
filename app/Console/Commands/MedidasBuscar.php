<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\DimensionReferenceSiteHelper;
use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Models\DimensionSearchRun;
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
 *     php artisan medidas:buscar --reintentar-sin-datos    # también los «sin datos» de una búsqueda anterior
 *     php artisan medidas:buscar --sitios=obi.de,verdefacile.eu   # guarda los sitios de referencia y lanza
 *     php artisan medidas:buscar --fichas --dry-run        # fichas del fabricante: cuántos entran, sin gastar nada
 *     php artisan medidas:buscar --fichas --solo-con-stock --limite=500
 *
 * `--fichas` (misión medidas-masquito-fichas, 14/11/2026): una corrida de FICHAS DEL FABRICANTE. Lee el peso de las
 * fichas públicas de distribuidores de Stihl, Husqvarna y Oregon, con la URL armada desde el código del artículo, SIN
 * Serper ni IA (no exige sus claves). Entran los artículos sin peso de esas marcas que no están en una corrida en
 * curso y cuyo último resultado no es un «no» del dueño (rechazada o quitada); sí entran los que esperan revisión o
 * quedaron sin datos. La corrida es por selección (origen `seleccion`), con `proveedor` = fichas y sin sitios de
 * referencia. Respeta `--solo-con-stock`, `--limite` y `--dry-run`; con `--sitios` o `--reintentar-sin-datos` es un
 * error (no aplican).
 *
 * `--sitios=` (misión medidas-sitios-de-referencia, 11/11/2026): REEMPLAZA la lista de sitios de referencia del
 * comercio (los mismos que se editan en Alertas → Catálogo → Medidas) antes de lanzar; acepta URLs o dominios
 * separados por comas y los guarda normalizados. Vacío (`--sitios=`) o sin la opción, no toca la lista. Con
 * `--dry-run` la lista se normaliza y se muestra pero NO se guarda (un dry-run no cambia nada).
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
                            {--reintentar-sin-datos : También busca de nuevo los que quedaron «sin datos» (no los rechazados, ni los que esperan revisión, ni los que ya tienen todas las medidas)}
                            {--sitios= : Sitios de referencia separados por comas (URLs o dominios): reemplazan la lista del comercio antes de lanzar}
                            {--fichas : Fichas del fabricante (Stihl, Husqvarna, Oregon): solo el peso, sin Serper ni IA}
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

        if ($this->option('fichas')) {
            return $this->lanzar_fichas($owner, $solo_con_stock, $limite);
        }

        $reintentar_sin_datos = (bool) $this->option('reintentar-sin-datos');

        // Los sitios de referencia: se validan siempre, y se guardan salvo en un --dry-run.
        $sitios_pedidos = $this->sitios_pedidos();
        $sitios_sin_guardar = null;

        if (!is_null($sitios_pedidos)) {
            if ($this->option('dry-run')) {
                $lista = DimensionReferenceSiteHelper::normalizar_lista($sitios_pedidos);

                if (!$lista['ok']) {
                    $this->error($lista['message']);

                    return 1;
                }

                $sitios_sin_guardar = $lista['sitios'];
            } else {
                $guardado = DimensionReferenceSiteHelper::guardar((int) $owner->id, $sitios_pedidos);

                if ($guardado['status'] !== 200) {
                    $this->error($guardado['message']);

                    return 1;
                }

                $this->info('Sitios de referencia guardados: '.implode(', ', $guardado['sitios']).'.');
            }
        }

        $previa = DimensionSearchRunHelper::previa_del_catalogo($owner, $solo_con_stock, $reintentar_sin_datos);

        if (!is_null($sitios_sin_guardar)) {
            $previa['sitios_de_referencia'] = $sitios_sin_guardar;
        }

        $this->imprimir_previa($previa, $limite);

        if (!is_null($sitios_sin_guardar)) {
            $this->line('(--dry-run: los sitios de referencia se muestran como quedarían, pero no se guardaron.)');
        }

        if ($this->option('dry-run')) {
            $this->info('Es un --dry-run: no se creó ni se despachó nada.');

            return 0;
        }

        $resultado = DimensionSearchRunHelper::crear_del_catalogo($owner, null, $solo_con_stock, is_null($limite) ? null : (int) $limite, $reintentar_sin_datos);

        if ($resultado['status'] !== 201) {
            $this->error($resultado['message']);

            return 1;
        }

        $run = $resultado['run'];

        $this->info('Corrida #'.$run->id.' creada con '.$run->total.' artículos y despachada a la cola. Se revisa en Alertas → Catálogo → Medidas.');

        return 0;
    }

    /**
     * `--fichas`: la previa y, salvo en un --dry-run, la corrida de fichas del fabricante.
     *
     * @param  \App\Models\User $owner
     * @param  bool             $solo_con_stock
     * @param  string|null      $limite
     * @return int
     */
    protected function lanzar_fichas(User $owner, $solo_con_stock, $limite)
    {
        if (!is_null($this->sitios_pedidos())) {
            $this->error('--fichas no usa sitios de referencia: sacá --sitios.');

            return 1;
        }

        if ($this->option('reintentar-sin-datos')) {
            $this->error('--fichas ya incluye los que quedaron sin datos: sacá --reintentar-sin-datos.');

            return 1;
        }

        $ids    = DimensionSearchRunHelper::ids_para_fichas((int) $owner->id, $solo_con_stock, is_null($limite) ? null : (int) $limite);
        $marcas = DimensionSearchRunHelper::marcas_de_fichas($ids);

        $this->line('Fichas del fabricante (solo el peso, de Stihl, Husqvarna y Oregon)'.($solo_con_stock ? ', solo con stock' : '').':');

        foreach ($marcas as $marca => $cantidad) {
            $this->line('  '.str_pad(ucfirst(strtolower($marca)).':', 12).$cantidad);
        }

        $this->line('A buscar en esta corrida:                   '.count($ids).(is_null($limite) ? '' : ' (--limite '.(int) $limite.')'));
        $this->line('Consultas a Serper: 0 · llamadas a la IA: 0 (las fichas se leen directo).');

        if ($this->option('dry-run')) {
            $this->info('Es un --dry-run: no se creó ni se despachó nada.');

            return 0;
        }

        if (empty($ids)) {
            $this->error('No hay artículos para buscar en las fichas: ninguno de Stihl, Husqvarna u Oregon'.($solo_con_stock ? ' con stock' : '').' está sin peso, fuera de otra corrida en curso y sin un rechazo del dueño.');

            return 1;
        }

        $run = DimensionSearchRunHelper::crear($owner, $ids, DimensionSearchRun::ORIGEN_SELECCION, null, [
            'solo_con_stock'       => $solo_con_stock,
            'sitios_de_referencia' => [],
            'proveedor'            => DimensionSearchRun::PROVEEDOR_FICHAS,
        ]);

        $this->info('Corrida de fichas #'.$run->id.' creada con '.$run->total.' artículos y despachada a la cola. Se revisa en Alertas → Catálogo → Medidas.');

        return 0;
    }

    /**
     * Las entradas de `--sitios=` (null si no vino o vino vacía: no se toca la lista).
     *
     * @return array|null
     */
    protected function sitios_pedidos()
    {
        $opcion = $this->option('sitios');

        if (!is_string($opcion) || trim($opcion) === '') {
            return null;
        }

        return array_map('trim', explode(',', $opcion));
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
        if (!empty($previa['reintentar_sin_datos'])) {
            $this->line('  (--reintentar-sin-datos: vuelven a entrar '.$previa['reintento_sin_datos'].' que habían quedado sin datos)');
        } elseif (!empty($previa['reintento_sin_datos'])) {
            $this->line('  (con --reintentar-sin-datos entrarían '.$previa['reintento_sin_datos'].' más, que habían quedado sin datos)');
        }

        $this->line('  ya buscados sin éxito (últimos '.DimensionSearchRunHelper::DIAS_SIN_VOLVER_A_BUSCAR.' días):   '.$previa['excluidos_ya_buscados']);
        $this->line('  en otra corrida en curso:                 '.$previa['excluidos_en_otra_corrida']);
        $this->line('A buscar en esta corrida:                   '.$a_buscar.' (tope '.$previa['tope'].')');
        $this->line('Estimado: ~'.$previa['estimacion']['busquedas'].' búsquedas, ~'.$previa['estimacion']['minutos'].' min, USD '.number_format($previa['estimacion']['usd_busquedas'] + $previa['estimacion']['usd_ia'], 2).' (búsquedas '.number_format($previa['estimacion']['usd_busquedas'], 2).' + IA '.number_format($previa['estimacion']['usd_ia'], 2).')');
        $this->line('Respaldo de lectura (páginas bloqueadas y PDF): ~'.number_format($previa['estimacion']['creditos_respaldo'], 0, ',', '.').' créditos de Serper (USD '.number_format($previa['estimacion']['usd_respaldo'], 2, ',', '.').'), aparte del costo de arriba. '.$previa['estimacion']['texto_respaldo']);
        $sitios = isset($previa['sitios_de_referencia']) && is_array($previa['sitios_de_referencia']) ? $previa['sitios_de_referencia'] : [];
        $this->line('Sitios de referencia (se buscan primero):   '.(empty($sitios) ? '(ninguno)' : implode(', ', $sitios)));
        $this->line('Serper configurado: '.($previa['proveedor_configurado'] ? 'sí' : 'NO').' · IA configurada: '.($previa['ia_configurada'] ? 'sí' : 'NO'));

        if (!$previa['ia_configurada'] && !empty($previa['ia_motivo'])) {
            $this->warn($previa['ia_motivo']);
        }

        if (!is_null($previa['corrida_activa'])) {
            $this->warn('Ya hay una corrida de catálogo en curso (#'.$previa['corrida_activa']['id'].').');
        }
    }
}
