<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Models\Article;
use App\Models\InventoryLinkage;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * D2-M (vinculacion-iva-copia, 21/11/2026) — herramientas del Operador para las copias vinculadas
 * en costo neto. Se corre a mano con OK de Lucas, NUNCA desde el scheduler. Simula por defecto y
 * sólo escribe con `--aplicar`.
 *
 *  - `--activar-vinculacion=<id>` / `--desactivar-vinculacion=<id>`: el interruptor por vinculación.
 *  - E1: `--cliente=<user_id> --ids=<archivo>`: convierte copias "expuestas" (cost = costo_real = bruto)
 *    a costo neto con marca 1, sólo si el precio no se mueve.
 *  - `--revertir-todas`: vuelve al bruto TODAS las filas con marca 1 de la base (clases A, B, C1, C2 y X).
 *  - `--verificar`: sólo lectura; detecta lo que un escritor fuera del helper haya pisado.
 *
 * La simulación nunca usa `setFinalPrice(..., false)` como si fuera pura: escribe pivotes, colas de TN y
 * ML, `price_changes` e historial. Por eso corre SIEMPRE dentro de un savepoint que se descarta, y la
 * escritura real es por query builder (sólo `cost` y la marca), nunca un `save()` del modelo simulado.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class ConvertirCopiasVinculadasACostoNeto extends Command
{
    /**
     * @var string
     */
    protected $signature = 'vinculacion:costo-neto-de-copias
                            {--activar-vinculacion= : Id de la vinculación a prender}
                            {--desactivar-vinculacion= : Id de la vinculación a apagar}
                            {--cliente= : user_id (comercio_city_user_id) del cliente de las copias}
                            {--ids= : Archivo con ids de artículos (uno por línea)}
                            {--revertir-todas : Vuelve al bruto todas las filas con marca 1 de la base}
                            {--verificar : Sólo lectura: informa anomalías}
                            {--aplicar : Escribe (sin esta opción sólo simula)}
                            {--salida= : Carpeta del TSV de auditoría de E1}';

    /**
     * @var string
     */
    protected $description = 'Interruptor, conversión (E1), reversión global y verificación de las copias vinculadas en costo neto';

    /** Tolerancia para comparar costos (DECIMAL(22,6)). */
    const TOLERANCIA_COSTO = 0.000001;

    /** @var array Filas excluidas de la corrida: [id, clase, motivo]. */
    protected $excluidas = [];

    /** @var array Anomalías de --verificar: [tipo, id]. */
    protected $anomalias = [];

    /**
     * Despacha según las opciones.
     *
     * @return int Código de salida.
     */
    public function handle()
    {
        // La instancia del comando se reutiliza entre llamadas en el mismo proceso (Artisan::call):
        // el estado de una corrida no puede contaminar la siguiente.
        $this->excluidas = [];
        $this->anomalias = [];

        if (!is_null($this->option('activar-vinculacion'))) {
            return $this->cambiar_interruptor((int) $this->option('activar-vinculacion'), true);
        }

        if (!is_null($this->option('desactivar-vinculacion'))) {
            return $this->cambiar_interruptor((int) $this->option('desactivar-vinculacion'), false);
        }

        if ($this->option('verificar')) {
            return $this->verificar();
        }

        if (PreciosEnLote::esta_activo()) {
            $this->error('PreciosEnLote está activo: con el modo lote setFinalPrice difiere escrituras fuera de la transacción. Se aborta.');
            return 1;
        }

        if ($this->option('revertir-todas')) {
            return $this->revertir_todas();
        }

        if (!is_null($this->option('cliente')) && !is_null($this->option('ids'))) {
            return $this->convertir();
        }

        $this->error('Falta una acción: --activar-vinculacion, --desactivar-vinculacion, --cliente + --ids (E1), --revertir-todas o --verificar.');
        return 1;
    }

    /* ------------------------------------------------------------------------------------------
     * Interruptor
     * ---------------------------------------------------------------------------------------- */

    /**
     * Prende o apaga el interruptor de una vinculación. Escribe SÓLO `inventory_linkages.copia_en_costo_neto`.
     * Se niega a prender si a la vinculación le falta contexto (cliente, usuario o proveedor).
     *
     * @param int  $id
     * @param bool $prender
     * @return int
     */
    protected function cambiar_interruptor($id, $prender)
    {
        $vinculacion = InventoryLinkage::find($id);

        if (is_null($vinculacion)) {
            $this->error('No existe la vinculación '.$id.'.');
            return 1;
        }

        if ($prender) {
            $motivo = $this->motivo_sin_contexto($vinculacion);

            if (!is_null($motivo)) {
                $this->error('No se activa la vinculación '.$id.': '.$motivo.'.');
                return 1;
            }
        }

        DB::table('inventory_linkages')->where('id', $id)->update(['copia_en_costo_neto' => $prender ? 1 : 0]);

        $this->info('Vinculación '.$id.': interruptor '.($prender ? 'PRENDIDO' : 'APAGADO').' ('.date('Y-m-d H:i:s').').');

        return 0;
    }

    /**
     * Qué le falta a una vinculación para tener contexto completo, o null si lo tiene.
     *
     * @param InventoryLinkage $vinculacion
     * @return string|null
     */
    protected function motivo_sin_contexto($vinculacion)
    {
        $cliente = $vinculacion->client;

        if (is_null($cliente)) {
            return 'el cliente no existe o está en la papelera';
        }

        if (is_null($cliente->comercio_city_user_id) || is_null($cliente->comercio_city_user)) {
            return 'el cliente no tiene un usuario de ComercioCity válido';
        }

        if (is_null(User::find($vinculacion->user_id))) {
            return 'el usuario proveedor no existe';
        }

        $proveedor = Provider::where('user_id', $cliente->comercio_city_user_id)
                                ->where('comercio_city_user_id', $vinculacion->user_id)
                                ->first();

        if (is_null($proveedor)) {
            return 'el cliente no tiene un Provider hacia el proveedor';
        }

        return null;
    }

    /* ------------------------------------------------------------------------------------------
     * Utilidades compartidas
     * ---------------------------------------------------------------------------------------- */

    /**
     * Vinculaciones del dueño de la fuente que alcanzan a la copia, con contexto completo.
     *
     * @param object $copia  Fila de la copia (user_id).
     * @param object $fuente Fila de la fuente (user_id).
     * @return array InventoryLinkage[]
     */
    protected function vinculaciones_que_alcanzan($copia, $fuente)
    {
        $alcanzan = [];

        foreach (InventoryLinkage::where('user_id', $fuente->user_id)->get() as $vinculacion) {
            $cliente = $vinculacion->client;

            if (is_null($cliente) || $cliente->comercio_city_user_id != $copia->user_id) {
                continue;
            }

            if (!is_null($this->motivo_sin_contexto($vinculacion))) {
                continue;
            }

            $alcanzan[] = $vinculacion;
        }

        return $alcanzan;
    }

    /**
     * Simula la cadena de precios de una copia dentro de un savepoint que SIEMPRE se descarta (nunca
     * `save()` del modelo simulado). Se llama dentro de la transacción T de la fila.
     *
     * @param int        $id    Id de la copia.
     * @param float|null $costo Costo propuesto en memoria (null = el que tiene la base).
     * @return array ['final_price' => float, 'costo_real' => float] a 2 decimales.
     */
    protected function simular($id, $costo = null)
    {
        DB::beginTransaction();

        try {
            $modelo = Article::withTrashed()->find($id);

            if (!is_null($costo)) {
                $modelo->cost = $costo;
            }

            $usuario = User::find($modelo->user_id);

            ArticleHelper::setFinalPrice($modelo, $modelo->user_id, $usuario, $modelo->user_id, false);

            return [
                'final_price' => round((float) $modelo->final_price, 2),
                'costo_real'  => round((float) $modelo->costo_real, 2),
            ];
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Formato de dinero de los mensajes (coma decimal).
     *
     * @param float $valor
     * @return string
     */
    protected function dinero($valor)
    {
        return number_format($valor, 2, ',', '');
    }

    /**
     * Registra una fila excluida y la imprime.
     *
     * @param int    $id
     * @param string $clase
     * @param string $motivo
     * @return void
     */
    protected function excluir($id, $clase, $motivo)
    {
        $this->excluidas[] = [$id, $clase, $motivo];
        $this->line('Excluida id='.$id.' clase='.$clase.' motivo='.$motivo);
    }

    /**
     * Conteo global de filas con marca 1 (sin filtro de usuario ni de papelera).
     *
     * @return int
     */
    protected function conteo_global()
    {
        return (int) DB::table('articles')->where('costo_neto_de_vinculacion', 1)->count();
    }

    /**
     * Ids de un archivo (uno por línea).
     *
     * @param string $ruta
     * @return array|null null si no se puede leer.
     */
    protected function leer_ids($ruta)
    {
        if (!is_file($ruta)) {
            return null;
        }

        $ids = [];

        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            if (is_numeric(trim($linea))) {
                $ids[] = (int) trim($linea);
            }
        }

        return $ids;
    }

    /* ------------------------------------------------------------------------------------------
     * E1: conversión de copias expuestas
     * ---------------------------------------------------------------------------------------- */

    /**
     * E1. Exige el interruptor prendido. Por fila, convierte sólo si se cumple todo (ver PLAN D2-M,
     * Diseño 6); lo que mueve el precio un centavo queda excluido con su motivo.
     *
     * @return int
     */
    protected function convertir()
    {
        $cliente_user_id = (int) $this->option('cliente');
        $aplicar = (bool) $this->option('aplicar');
        $ids = $this->leer_ids($this->option('ids'));

        if (is_null($ids)) {
            $this->error('No se puede leer el archivo de ids.');
            return 1;
        }

        $prendidas = 0;

        foreach (InventoryLinkage::where('copia_en_costo_neto', 1)->get() as $vinculacion) {
            if (!is_null($vinculacion->client) && $vinculacion->client->comercio_city_user_id == $cliente_user_id) {
                $prendidas++;
            }
        }

        if ($prendidas < 1) {
            $this->error('E1 se niega: ninguna vinculación hacia el cliente '.$cliente_user_id.' tiene el interruptor prendido.');
            return 1;
        }

        $tsv = null;

        if ($aplicar) {
            $carpeta = $this->option('salida') ?: storage_path('app/vinculacion-iva');

            if (!is_dir($carpeta)) {
                mkdir($carpeta, 0775, true);
            }

            $tsv = $carpeta.'/e1-'.date('Ymd-His').'.tsv';
            file_put_contents($tsv, "id\tcost_antes\tcosto_real\tfinal_price\tupdated_at\tcost_neto\n");
        }

        $convertidas = 0;

        foreach ($ids as $id) {
            if ($this->convertir_fila($id, $cliente_user_id, $aplicar, $tsv)) {
                $convertidas++;
            }
        }

        $this->line(($aplicar ? 'Convertidas: ' : 'Convertibles (simulación): ').$convertidas.'. Excluidas: '.count($this->excluidas).'.');

        if ($aplicar) {
            $this->verificar_ids($ids);
        }

        return count($this->excluidas) > 0 || count($this->anomalias) > 0 ? 1 : 0;
    }

    /**
     * Evalúa y, con `--aplicar`, convierte una copia. Todo dentro de la transacción T con la fila bloqueada.
     *
     * @param int         $id
     * @param int         $cliente_user_id
     * @param bool        $aplicar
     * @param string|null $tsv
     * @return bool True si la fila es convertible (y se convirtió, con --aplicar).
     */
    protected function convertir_fila($id, $cliente_user_id, $aplicar, $tsv)
    {
        DB::beginTransaction();

        try {
            // La foto con el mismo candado de fila que pide el helper.
            $fila = DB::table('articles')->where('id', $id)->lockForUpdate()->first();

            if (is_null($fila) || $fila->user_id != $cliente_user_id) {
                $this->excluir($id, 'A', 'no existe o no es del cliente');
                DB::rollBack();
                return false;
            }

            if (is_null($fila->provider_article_id)) {
                $this->excluir($id, 'B', 'sin provider_article_id');
                DB::rollBack();
                return false;
            }

            if ((int) $fila->costo_neto_de_vinculacion === 1) {
                $this->excluir($id, 'A', 'ya tiene la marca');
                DB::rollBack();
                return false;
            }

            $fuente = DB::table('articles')->where('id', $fila->provider_article_id)->first();
            $vinculaciones = is_null($fuente) ? [] : $this->vinculaciones_que_alcanzan($fila, $fuente);
            $prendida = false;

            foreach ($vinculaciones as $vinculacion) {
                if ((int) $vinculacion->copia_en_costo_neto === 1) {
                    $prendida = true;
                }
            }

            if (count($vinculaciones) < 1 || !$prendida) {
                $this->excluir($id, 'A', 'sin vinculación vigente con el interruptor prendido y contexto completo');
                DB::rollBack();
                return false;
            }

            $usuario = User::find($fila->user_id);
            $articulo = Article::withTrashed()->find($id);
            $articulo->load('iva');

            if (!$fila->aplicar_iva) {
                $this->excluir($id, 'A', 'aplicar_iva en 0');
                DB::rollBack();
                return false;
            }

            if (!ArticlePricesHelper::hasIva($articulo) || (float) $articulo->iva->percentage <= 0) {
                $this->excluir($id, 'A', 'sin alícuota de IVA mayor a 0');
                DB::rollBack();
                return false;
            }

            if (!ArticlePricesHelper::iva_va_al_costo($usuario)) {
                $this->excluir($id, 'A', 'la cuenta no lleva el IVA al costo');
                DB::rollBack();
                return false;
            }

            if (abs((float) $fila->costo_real - (float) $fila->cost) >= self::TOLERANCIA_COSTO) {
                $this->excluir($id, 'A', 'inflada (costo_real distinto de cost)');
                DB::rollBack();
                return false;
            }

            $neto = round(ArticlePricesHelper::back_out_iva($articulo, $fila->cost), 6);

            $simulado = $this->simular($id, $neto);

            if (abs($simulado['final_price'] - round((float) $fila->final_price, 2)) > 0.0001) {
                $this->excluir($id, 'A', 'el precio cambiaría ('.$this->dinero($simulado['final_price']).')');
                DB::rollBack();
                return false;
            }

            if (abs($simulado['costo_real'] - round((float) $fila->costo_real, 2)) > 0.0001) {
                $this->excluir($id, 'A', 'el costo_real cambiaría ('.$this->dinero($simulado['costo_real']).')');
                DB::rollBack();
                return false;
            }

            if ($aplicar) {
                file_put_contents($tsv, implode("\t", [
                    $fila->id, $fila->cost, $fila->costo_real, $fila->final_price, $fila->updated_at, $neto,
                ])."\n", FILE_APPEND);

                $afectadas = DB::table('articles')
                                ->where('id', $id)
                                ->where('cost', $fila->cost)
                                ->where('costo_neto_de_vinculacion', 0)
                                ->update(['cost' => $neto, 'costo_neto_de_vinculacion' => 1]);

                if ($afectadas !== 1) {
                    $this->excluir($id, 'A', 'concurrente (la próxima corrida la vuelve a evaluar)');
                    DB::rollBack();
                    return false;
                }
            }

            DB::commit();

            return true;
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->excluir($id, 'A', 'error: '.$e->getMessage());

            return false;
        }
    }

    /* ------------------------------------------------------------------------------------------
     * --revertir-todas
     * ---------------------------------------------------------------------------------------- */

    /**
     * Vuelve al bruto todas las filas con marca 1 de la base (clases A, B, C1, C2 y X). Precondición:
     * ningún interruptor prendido. Con `--cliente` sólo simula.
     *
     * @return int
     */
    protected function revertir_todas()
    {
        $aplicar = (bool) $this->option('aplicar');

        if ($aplicar && !is_null($this->option('cliente'))) {
            $this->error('--cliente es sólo un filtro de la simulación: con --aplicar se niega (la reversión es global).');
            return 1;
        }

        $prendidas = InventoryLinkage::where('copia_en_costo_neto', 1)->get();

        if (count($prendidas) > 0) {
            $this->error('Se niega: hay vinculaciones con el interruptor prendido. Apagalas primero (--desactivar-vinculacion).');

            foreach ($prendidas as $vinculacion) {
                $this->line('Vinculación prendida id='.$vinculacion->id.' (user_id='.$vinculacion->user_id.', client_id='.$vinculacion->client_id.')');
            }

            return 1;
        }

        $consulta = DB::table('articles')->where('costo_neto_de_vinculacion', 1)->orderBy('id');

        if (!is_null($this->option('cliente'))) {
            $consulta->where('user_id', (int) $this->option('cliente'));
        }

        $ids = $consulta->pluck('id')->all();
        $por_clase = ['A' => 0, 'B' => 0, 'C1' => 0, 'C2' => 0, 'X' => 0];
        $suma_antes = 0.0;
        $suma_despues = 0.0;

        foreach ($ids as $id) {
            list($clase, $antes, $despues) = $this->revertir_fila($id, $aplicar);
            $por_clase[$clase]++;
            $suma_antes += $antes;
            $suma_despues += $despues;
        }

        foreach ($por_clase as $clase => $cantidad) {
            $this->line('Clase '.$clase.': '.$cantidad);
        }

        $this->line('Suma de final_price antes: '.$this->dinero($suma_antes).' · después: '.$this->dinero($suma_despues));

        $conteo = $this->conteo_global();
        $this->line('Conteo global de marca 1: '.$conteo);

        if ($aplicar) {
            $this->verificar_ids($ids);
        }

        if (count($this->excluidas) > 0 || ($aplicar && $conteo !== 0) || count($this->anomalias) > 0) {
            if ($aplicar && $conteo !== 0) {
                $this->error('El rollback de código queda BLOQUEADO hasta una decisión: quedan filas con marca 1.');
            }

            return 1;
        }

        return 0;
    }

    /**
     * Clasifica y, con `--aplicar`, revierte una fila con marca 1 (transacción T con candado de fila).
     *
     * @param int  $id
     * @param bool $aplicar
     * @return array [clase (A|B|C1|C2|X), final_price antes, final_price después]
     */
    protected function revertir_fila($id, $aplicar)
    {
        DB::beginTransaction();

        try {
            $fila = DB::table('articles')->where('id', $id)->lockForUpdate()->first();
            $final_antes = (float) $fila->final_price;

            // B: sin provider_article_id. El código viejo no la recalcula: sólo la marca.
            if (is_null($fila->provider_article_id)) {
                $this->limpiar_marca($fila, $aplicar);
                DB::commit();
                return ['B', $final_antes, $this->final_actual($id)];
            }

            $fuente = DB::table('articles')->where('id', $fila->provider_article_id)->first();

            // C2: sin fuente (ni en la papelera). Sólo la marca.
            if (is_null($fuente)) {
                $this->limpiar_marca($fila, $aplicar);
                DB::commit();
                return ['C2', $final_antes, $this->final_actual($id)];
            }

            $vinculaciones = $this->vinculaciones_que_alcanzan($fila, $fuente);
            $clase = count($vinculaciones) > 0 ? 'A' : 'C1';

            // Bruto: lo que escribiría el código viejo.
            if ($clase === 'A') {
                $brutos = [];
                $modelo_fuente = Article::withTrashed()->find($fuente->id);

                foreach ($vinculaciones as $vinculacion) {
                    $ayuda = new InventoryLinkageHelper($vinculacion, $fuente->user_id);
                    $ayuda->inventory_linkage = $vinculacion;
                    $ayuda->client = $vinculacion->client;
                    $brutos[] = round((float) $ayuda->getClientArticlePrice($modelo_fuente), 6);
                }

                if (count(array_unique($brutos)) > 1) {
                    return $this->excluir_reversion($id, $clase, 'ambigua (dos vinculaciones dan brutos distintos)', $final_antes);
                }

                $bruto = $brutos[0];

                if (abs($bruto - (float) $fuente->final_price) > 0.005) {
                    return $this->excluir_reversion($id, $clase, 'R6/listas (el bruto de la vinculación difiere de fuente.final_price)', $final_antes);
                }
            } else {
                $bruto = round((float) $fuente->final_price, 6);
            }

            // g2: copia sincronizada con su fuente.
            if (abs((float) $fila->costo_real - $bruto) >= 0.005) {
                return $this->excluir_reversion($id, $clase, 'desincronizada', $final_antes);
            }

            // g3: la simulación del estado actual (marca 1) da el final_price guardado.
            $simulado = $this->simular($id, null);

            if (abs($simulado['final_price'] - round($final_antes, 2)) > 0.0001) {
                return $this->excluir_reversion($id, $clase, 'incoherente (la simulación no da el final_price guardado)', $final_antes);
            }

            if ($aplicar) {
                $afectadas = DB::table('articles')
                                ->where('id', $id)
                                ->where('costo_neto_de_vinculacion', 1)
                                ->update(['cost' => $bruto, 'costo_neto_de_vinculacion' => 0]);

                // g4: nada de precio cambió.
                $despues = DB::table('articles')->where('id', $id)->first();

                if ($afectadas !== 1 || $this->cambio_el_precio($fila, $despues)) {
                    DB::rollBack();
                    $this->excluir($id, $clase, 'g4 (el precio cambió al escribir)');
                    return ['X', $final_antes, $final_antes];
                }
            }

            DB::commit();

            return [$clase, $final_antes, $this->final_actual($id)];
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->excluir($id, 'A', 'error: '.$e->getMessage());

            return ['X', 0.0, 0.0];
        }
    }

    /**
     * Deshace T (sin escribir), registra la exclusión y devuelve la clase X.
     *
     * @param int    $id
     * @param string $clase
     * @param string $motivo
     * @param float  $final
     * @return array
     */
    protected function excluir_reversion($id, $clase, $motivo, $final)
    {
        DB::rollBack();
        $this->excluir($id, $clase, $motivo);

        return ['X', $final, $final];
    }

    /**
     * B y C2: sólo la marca (con g4 implícito: no se toca nada más).
     *
     * @param object $fila
     * @param bool   $aplicar
     * @return void
     */
    protected function limpiar_marca($fila, $aplicar)
    {
        if ($aplicar) {
            DB::table('articles')->where('id', $fila->id)->where('costo_neto_de_vinculacion', 1)->update(['costo_neto_de_vinculacion' => 0]);
        }
    }

    /**
     * @param int $id
     * @return float
     */
    protected function final_actual($id)
    {
        return (float) DB::table('articles')->where('id', $id)->value('final_price');
    }

    /**
     * g4: ¿cambió alguna columna de precio entre la foto y lo escrito?
     *
     * @param object $antes
     * @param object $despues
     * @return bool
     */
    protected function cambio_el_precio($antes, $despues)
    {
        foreach (['final_price', 'costo_real', 'previus_final_price', 'final_price_updated_at'] as $columna) {
            if ($antes->$columna != $despues->$columna) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------------------------------
     * --verificar
     * ---------------------------------------------------------------------------------------- */

    /**
     * Sólo lectura. Informa anomalías sobre las filas con marca 1 y las de `--ids`.
     *
     * @return int
     */
    protected function verificar()
    {
        $ids = [];

        if (!is_null($this->option('ids'))) {
            $leidos = $this->leer_ids($this->option('ids'));
            $ids = is_null($leidos) ? [] : $leidos;
        }

        $this->verificar_ids($ids);

        return count($this->anomalias) > 0 ? 1 : 0;
    }

    /**
     * Revisa las filas con marca 1 más las ids dadas e imprime las anomalías y los conteos.
     *
     * @param array $ids
     * @return void
     */
    protected function verificar_ids(array $ids)
    {
        $anteriores = count($this->anomalias);
        $universo = DB::table('articles')->where('costo_neto_de_vinculacion', 1)->pluck('id')->all();
        $universo = array_values(array_unique(array_merge($universo, $ids)));

        foreach ($universo as $id) {
            $this->verificar_fila($id);
        }

        $nuevas = array_slice($this->anomalias, $anteriores);

        foreach ($nuevas as $anomalia) {
            $this->line('Anomalía: '.$anomalia[0].' id='.$anomalia[1]);
        }

        $this->line('Conteo global de marca 1: '.$this->conteo_global());
        $this->line('Interruptores prendidos: '.InventoryLinkage::where('copia_en_costo_neto', 1)->count());
    }

    /**
     * Revisa una fila (lectura; la simulación va en un savepoint que se descarta).
     *
     * @param int $id
     * @return void
     */
    protected function verificar_fila($id)
    {
        $fila = DB::table('articles')->where('id', $id)->first();

        if (is_null($fila)) {
            return;
        }

        $articulo = Article::withTrashed()->find($id);
        $articulo->load('iva');
        $usuario = User::find($fila->user_id);
        $marca = (int) $fila->costo_neto_de_vinculacion === 1;

        // marca 1 / inflada: cuenta legacy con la tilde, costo_real distinto de cost·(1+alícuota).
        if ($marca && $fila->aplicar_iva && ArticlePricesHelper::hasIva($articulo) && (float) $articulo->iva->percentage > 0
            && ArticlePricesHelper::iva_va_al_costo($usuario)) {
            $esperado = (float) $fila->cost * (1 + (float) $articulo->iva->percentage / 100);

            if (abs((float) $fila->costo_real - $esperado) > 0.01) {
                $this->anomalias[] = ['marca 1 / inflada', $id];
            }
        }

        // marca 1 / bruto y marca 0 / neto: contra el bruto que escribiría el helper (clase A).
        if (!is_null($fila->provider_article_id)) {
            $fuente = Article::withTrashed()->find($fila->provider_article_id);
            $vinculaciones = is_null($fuente) ? [] : $this->vinculaciones_que_alcanzan($fila, $fuente);

            if (count($vinculaciones) > 0) {
                $vinculacion = $vinculaciones[0];
                $ayuda = new InventoryLinkageHelper($vinculacion, $fuente->user_id);
                $ayuda->inventory_linkage = $vinculacion;
                $ayuda->client = $vinculacion->client;
                $bruto = (float) $ayuda->getClientArticlePrice($fuente);

                if ($marca) {
                    $esperado = (float) $ayuda->costo_para_la_copia($articulo, $bruto);

                    if (abs((float) $fila->cost - $esperado) > self::TOLERANCIA_COSTO) {
                        $this->anomalias[] = ['marca 1 / bruto', $id];
                    }
                } elseif (abs((float) $fila->cost - $bruto) > self::TOLERANCIA_COSTO && abs((float) $fila->costo_real - $bruto) > 0.005
                    && $this->fue_escrita_como_neto($fila, $bruto, $articulo)) {
                    $this->anomalias[] = ['marca 0 / neto', $id];
                }
            }
        }

        // incoherente: la simulación del estado actual (con marca 1) no da el final_price guardado.
        // Una fila con marca 0 queda afuera a propósito: una copia "expuesta" siempre se infla al
        // simularla (es el estado de hoy de las 596) y no por eso está incoherente.
        if (!$marca) {
            return;
        }

        $simulado = $this->simular($id, null);

        if (abs($simulado['final_price'] - round((float) $fila->final_price, 2)) > 0.0001) {
            $this->anomalias[] = ['incoherente', $id];
        }
    }

    /**
     * ¿Una fila con marca 0 tiene el costo neto (cost·(1+alícuota) = bruto) en vez del bruto? Es el
     * estado "marca 0 / neto" que dejaría un modelo viejo pisando la reversión.
     *
     * @param object  $fila
     * @param float   $bruto
     * @param Article $articulo
     * @return bool
     */
    protected function fue_escrita_como_neto($fila, $bruto, $articulo)
    {
        if (!ArticlePricesHelper::hasIva($articulo)) {
            return false;
        }

        $neto = round(ArticlePricesHelper::back_out_iva($articulo, $bruto), 6);

        return abs((float) $fila->cost - $neto) <= self::TOLERANCIA_COSTO;
    }
}
