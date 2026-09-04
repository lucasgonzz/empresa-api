<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ConceptoStockMovement;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Siembra el historial de movimientos de stock que necesita el clip 4.4 (Trazabilidad de cada
 * articulo) de la demo.
 *
 * POR QUE EXISTE
 *
 * Medido el 28/8/2026 barriendo los primeros 120 article_id de la instancia `demo`: solo 5
 * articulos tienen mas de un concepto de movimiento, y NINGUNO de los 120 tiene un movimiento
 * con concepto "Compra a proveedor", "Importacion de excel", "Mov entre depositos" ni
 * "Ingreso manual". El guion del clip promete textualmente "cada compra, cada venta, cada
 * correccion a mano, cada movimiento entre depositos, cada importacion de Excel", y el bloque
 * 0:52 -el mejor del clip- filtra por "Importacion de excel". Con el catalogo como esta, ese
 * filtro devuelve VACIO en camara mientras la voz cuenta el caso.
 *
 * Y la columna Empleado: el 100% de los movimientos de la demo tienen employee_id = el ingreso
 * maestro, asi que el bloque 1:24 -que pide "distintos nombres en las filas"- no tiene con que.
 * Por eso el comando tambien crea empleados.
 *
 * COMO SE CORRE
 *
 *   php artisan demo:sembrar-trazabilidad --article_id=43
 *   php artisan demo:sembrar-trazabilidad --article_id=43 --user_id=400
 *   php artisan demo:sembrar-trazabilidad --article_id=43 --limpiar   (borra lo sembrado y sale)
 *
 * Es IDEMPOTENTE: cada movimiento sembrado se marca en `observations` con MARCA, y si ya hay
 * movimientos marcados para ese articulo el comando no hace nada. Correrlo dos veces no duplica
 * el historial, que es justo lo que arruinaria el plano.
 *
 * 🔴 ESCRIBE SOBRE LA BASE DE LA DEMO: crea usuarios empleado y movimientos de stock, y deja el
 * stock del articulo en el resultante de la cadena. No es un seeder de testing.
 */
class sembrar_trazabilidad_demo extends Command
{
    protected $signature = 'demo:sembrar-trazabilidad
                            {--article_id= : Articulo sobre el que se siembra el historial}
                            {--user_id= : Owner (dueño) de la instancia. Por defecto, el primer owner}
                            {--limpiar : Borra los movimientos sembrados por este comando y termina}';

    protected $description = 'Siembra movimientos de stock variados (conceptos y empleados) para el clip 4.4 de la demo';

    /**
     * Marca que identifica lo sembrado por este comando.
     *
     * Va en `observations` porque es el unico campo de texto libre del movimiento, y permite las
     * dos cosas que hacen falta: no duplicar al recorrer de nuevo, y poder limpiar sin tocar los
     * movimientos reales de la instancia.
     */
    const MARCA = '[demo-trazabilidad]';

    /**
     * Empleados que se crean para que la columna "Empleado" del listado tenga nombres distintos.
     *
     * El `doc_number` es con lo que se ingresa al sistema, asi que se eligen valores que no
     * puedan chocar con un cliente real de la demo.
     */
    const EMPLEADOS = [
        ['name' => 'Marcela Ríos', 'doc_number' => 'demo-emp-1'],
        ['name' => 'Diego Ferreyra', 'doc_number' => 'demo-emp-2'],
        ['name' => 'Sofía Navarro', 'doc_number' => 'demo-emp-3'],
    ];

    public function handle()
    {
        $article_id = $this->option('article_id');

        if (!$article_id) {
            $this->error('Falta --article_id. Es el articulo que se va a filmar; no se elige solo a proposito.');
            return 1;
        }

        $article = Article::find($article_id);

        if (!$article) {
            $this->error('No existe el articulo '.$article_id.' en esta base.');
            return 1;
        }

        $this->info('Articulo: '.$article->name.' (id '.$article->id.')');

        if ($this->option('limpiar')) {
            return $this->limpiar($article);
        }

        // El owner es quien "posee" los datos: todos los movimientos van con su user_id, igual que
        // los que crea el sistema.
        $owner = $this->get_owner();

        if (!$owner) {
            $this->error('No encontre ningun owner (usuario con owner_id null) en esta base.');
            return 1;
        }

        $this->info('Owner: '.$owner->name.' (id '.$owner->id.')');

        $ya_sembrados = StockMovement::where('article_id', $article->id)
                                        ->where('observations', 'like', '%'.self::MARCA.'%')
                                        ->count();

        if ($ya_sembrados > 0) {
            $this->warn('Este articulo ya tiene '.$ya_sembrados.' movimiento(s) sembrado(s) por este comando.');
            $this->warn('No se siembra de nuevo para no duplicar el historial. Usa --limpiar si querés rehacerlo.');
            return 0;
        }

        $empleados = $this->crear_empleados($owner);

        $movimientos = $this->sembrar($article, $owner, $empleados);

        $this->info('');
        $this->info('Listo: '.$movimientos.' movimiento(s) sembrado(s).');
        $this->info('Stock del articulo: '.$article->fresh()->stock);
        $this->info('');
        $this->info('Verificalo en el sistema: Listado -> abrir el articulo -> Movimientos de stock.');

        return 0;
    }

    /**
     * Devuelve el owner de la instancia: el que pasaron por --user_id, o el primero que exista.
     *
     * @return \App\Models\User|null
     */
    private function get_owner()
    {
        $user_id = $this->option('user_id');

        if ($user_id) {
            return User::whereNull('owner_id')->where('id', $user_id)->first();
        }

        return User::whereNull('owner_id')->orderBy('id')->first();
    }

    /**
     * Crea los empleados si no existen, y devuelve sus ids.
     *
     * Se los busca por `doc_number` dentro de los empleados de ESE owner: si el comando ya corrio
     * antes (o corre sobre otro articulo de la misma instancia), se reusan en vez de duplicarlos.
     *
     * @param  \App\Models\User  $owner
     * @return array  ids de los empleados, en el mismo orden que self::EMPLEADOS
     */
    private function crear_empleados($owner)
    {
        $ids = [];

        foreach (self::EMPLEADOS as $datos) {

            $empleado = User::where('owner_id', $owner->id)
                                ->where('doc_number', $datos['doc_number'])
                                ->first();

            if (!$empleado) {

                $empleado = User::create([
                    'name'              => $datos['name'],
                    'doc_number'        => $datos['doc_number'],
                    'owner_id'          => $owner->id,
                    'company_name'      => $owner->company_name,
                    'password'          => Hash::make('1234'),
                    'visible_password'  => '1234',
                ]);

                $this->line('  empleado creado: '.$datos['name'].' (id '.$empleado->id.')');

            } else {
                $this->line('  empleado ya existía: '.$datos['name'].' (id '.$empleado->id.')');
            }

            $ids[] = $empleado->id;
        }

        return $ids;
    }

    /**
     * Inserta la tanda de movimientos.
     *
     * 🔴 POR QUE NO PASA POR StockMovementController::crear()
     *
     * Ese metodo resuelve el empleado y el owner desde la sesion autenticada (UserHelper::userId),
     * y estampa `created_at` con Carbon::now(). Acá hacen falta las tres cosas al revés: empleados
     * explicitos y distintos entre si, un owner que se pasa por parametro, y fechas repartidas en
     * el tiempo. Por eso se insertan derecho y se calcula el `stock_resultante` a mano, con la
     * misma cuenta que hace SetStockResultante (el resultante anterior mas el amount).
     *
     * 🔴 LAS FECHAS VAN HACIA ATRAS DESDE HOY, NO A UN PASADO LEJANO. El listado del modal ordena
     * por id DESC, no por fecha: si se sembraran fechas mas viejas que los movimientos que ya
     * existen, las filas nuevas saldrian arriba de todo con las fechas mas viejas de la tabla, y
     * en camara se leeria como un error del sistema.
     *
     * @param  \App\Models\Article  $article
     * @param  \App\Models\User     $owner
     * @param  array                $empleados  ids de empleado
     * @return int  cantidad de movimientos creados
     */
    private function sembrar($article, $owner, $empleados)
    {
        /*
            La tanda cubre los cuatro conceptos que el guion nombra y que no existian en ninguna
            instancia, mas una venta y una nota de credito para que la lista se lea como un
            historial de verdad y no como una lista de casos de prueba.

            `dias_atras` va de mayor a menor: se recorre en orden cronologico, que es el orden en
            que se encadena el stock_resultante.
        */
        $tanda = [
            ['dias_atras' => 38, 'concepto' => 'Ingreso manual',       'amount' => 24,  'empleado' => 0],
            ['dias_atras' => 31, 'concepto' => 'Compra a proveedor',   'amount' => 60,  'empleado' => 1],
            ['dias_atras' => 24, 'concepto' => 'Venta',                'amount' => -8,  'empleado' => 2],
            ['dias_atras' => 19, 'concepto' => 'Mov entre depositos',  'amount' => -12, 'empleado' => 1],
            ['dias_atras' => 14, 'concepto' => 'Venta',                'amount' => -5,  'empleado' => 0],
            ['dias_atras' => 9,  'concepto' => 'Importacion de excel', 'amount' => 30,  'empleado' => 2],
            ['dias_atras' => 5,  'concepto' => 'Nota de credito',      'amount' => 3,   'empleado' => 1],
            ['dias_atras' => 2,  'concepto' => 'Ingreso manual',       'amount' => -4,  'empleado' => 0],
        ];

        // Resultante del ultimo movimiento que ya existe: la cadena nueva arranca de ahi para que
        // la columna no muestre un salto entre el historial viejo y el sembrado.
        $ultimo = StockMovement::where('article_id', $article->id)
                                ->orderBy('id', 'DESC')
                                ->first();

        $resultante = $ultimo ? (float)$ultimo->stock_resultante : (float)$article->stock;

        $creados = 0;

        foreach ($tanda as $item) {

            $concepto = ConceptoStockMovement::where('name', $item['concepto'])->first();

            if (!$concepto) {
                // Sin fallback de id, mismo criterio que SetConcepto: etiquetar el movimiento con
                // un concepto ajeno corrompe el dato en silencio.
                $this->warn('  no existe el concepto "'.$item['concepto'].'" en esta base: se saltea ese movimiento.');
                continue;
            }

            $resultante += (float)$item['amount'];

            $fecha = Carbon::now()->subDays($item['dias_atras'])->setTime(9 + ($creados % 8), 15);

            StockMovement::create([
                'article_id'                    => $article->id,
                'amount'                        => $item['amount'],
                'concepto_stock_movement_id'    => $concepto->id,
                'stock_resultante'              => $resultante,
                'employee_id'                   => $empleados[$item['empleado']],
                'user_id'                       => $owner->id,
                'observations'                  => self::MARCA,
                'created_at'                    => $fecha,
                'updated_at'                    => $fecha,
            ]);

            $this->line('  '.$fecha->format('d/m/Y').'  '.str_pad($item['concepto'], 22).'  '
                .str_pad($item['amount'], 5, ' ', STR_PAD_LEFT).'  -> '.$resultante);

            $creados++;
        }

        // El stock del articulo queda en el resultante de la cadena: si no, la ficha muestra un
        // numero y el ultimo movimiento otro, y esa contradiccion se ve en camara.
        $article->stock = $resultante;
        $article->save();

        return $creados;
    }

    /**
     * Borra lo que sembro este comando, para poder rehacerlo.
     *
     * No toca los movimientos reales de la instancia: filtra por la marca. El stock del articulo
     * se recalcula contra el ultimo movimiento que quede.
     *
     * @param  \App\Models\Article  $article
     * @return int  codigo de salida
     */
    private function limpiar($article)
    {
        $borrados = StockMovement::where('article_id', $article->id)
                                    ->where('observations', 'like', '%'.self::MARCA.'%')
                                    ->delete();

        $ultimo = StockMovement::where('article_id', $article->id)
                                ->orderBy('id', 'DESC')
                                ->first();

        if ($ultimo) {
            $article->stock = $ultimo->stock_resultante;
            $article->save();
        }

        $this->info('Borrados '.$borrados.' movimiento(s) sembrado(s). Stock del articulo: '.$article->fresh()->stock);
        $this->warn('Los usuarios empleado NO se borran: los puede estar usando otro articulo ya sembrado.');

        return 0;
    }
}
