<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * NormalizarMargenCero (mision 44, 12/8/2026)
 *
 * Pone en null los articles.percentage_gain que estan guardados en 0.
 *
 * Un margen en 0 conviviendo con un precio manual es el estado que dejaba los DOS
 * inputs de la ficha del articulo bloqueados y sin salida: el back no limpiaba el
 * precio (0 no es > 0) y el front bloqueaba los dos campos ("0.00" != '' es verdadero
 * en JS). La mision arregla la interfaz y el guardado para que ese estado no se vuelva
 * a generar, pero los articulos que YA estan asi siguen trabados hasta que corre este
 * comando: por eso es un paso de despliegue POR CLIENTE, no algo opcional.
 *
 * No cambia ningun precio. La unica linea que usa percentage_gain en el modo normal de
 * ArticleHelper::setFinalPrice() es:
 *
 *     if (!is_null($article->percentage_gain)) { $final_price += $final_price * $article->percentage_gain / 100; }
 *
 * y sumar 0% es lo mismo que no sumar nada.
 *
 * Es idempotente: la segunda corrida no encuentra nada para tocar y lo informa. Por
 * defecto es de SOLO LECTURA (mismo criterio que normalizar_api_url): hay que pasar
 * --aplicar para que escriba.
 *
 *     php artisan articles:normalizar-margen-cero              # reporta
 *     php artisan articles:normalizar-margen-cero --aplicar    # escribe
 *     php artisan articles:normalizar-margen-cero --aplicar --user_id=500
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class NormalizarMargenCero extends Command
{
    /**
     * Nombre y firma del comando.
     *
     * @var string
     */
    protected $signature = 'articles:normalizar-margen-cero
                            {--aplicar : Escribe los cambios. Sin este flag el comando solo reporta.}
                            {--user_id= : Limita el barrido a un solo tenant.}';

    /**
     * Descripcion del comando.
     *
     * @var string
     */
    protected $description = 'Pone en null los margenes de ganancia guardados en 0, que dejan trabados el precio manual y el margen en la ficha del articulo.';

    /**
     * @return int  0 siempre que el comando haya podido correr.
     */
    public function handle()
    {
        $aplicar = (bool) $this->option('aplicar');
        $user_id = $this->option('user_id');

        /*
         * Se compara contra 0 y no con un LIKE sobre el texto: la columna es
         * decimal(8,2), asi que "0", "0.00" y 0 son el mismo valor para MySQL. Los
         * negativos NO entran: no son el estado que traba la ficha y descartarlos en
         * silencio seria tirar un dato del usuario.
         */
        $query = Article::whereNotNull('percentage_gain')
                        ->where('percentage_gain', '=', 0);

        if (!is_null($user_id) && $user_id !== '') {
            $query->where('user_id', (int) $user_id);
            $this->line('Limitado al user_id ' . (int) $user_id . '.');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No hay articulos con margen de ganancia en 0. Nada para hacer.');
            return 0;
        }

        $this->line($total . ' articulo(s) con margen de ganancia en 0.');

        /* Muestra unos pocos para que quede claro sobre que se esta por escribir. */
        $ejemplos = (clone $query)
                        ->select('id', 'name', 'price', 'percentage_gain')
                        ->orderBy('id')
                        ->limit(5)
                        ->get();

        foreach ($ejemplos as $ejemplo) {
            $this->line(
                '  id ' . $ejemplo->id
                . ' | ' . $ejemplo->name
                . ' | precio manual: ' . (is_null($ejemplo->price) ? 'null' : $ejemplo->price)
            );
        }

        if ($total > $ejemplos->count()) {
            $this->line('  ... y ' . ($total - $ejemplos->count()) . ' mas.');
        }

        if (!$aplicar) {
            $this->warn('Modo reporte: no se escribio nada. Volve a correrlo con --aplicar para persistir.');
            return 0;
        }

        /*
         * UPDATE masivo y no un foreach de save(): son hasta decenas de miles de filas
         * por tenant y no hay ningun evento de modelo que dependa de este cambio (el
         * final_price no se mueve, ver el comentario de cabecera).
         *
         * Dos cosas que este UPDATE tiene que hacer igual que el conteo de arriba, y que
         * no salen solas por escribir sobre DB::table():
         *
         *  - `whereNull('deleted_at')`: Article usa SoftDeletes, asi que el conteo del modo
         *    reporte NO ve los borrados logicos. Sin esta linea, --aplicar tocaria mas filas
         *    de las que reporto y el "M articulos normalizados" no cerraria con el numero
         *    que el usuario acaba de leer, que es justo lo que sostiene el modo reporte.
         *  - `updated_at`: la SPA sincroniza incrementalmente por updated_at
         *    (ArticleController@index). Sin tocarlo, los articulos que este comando destraba
         *    no bajan al cliente hasta una recarga completa, y el usuario sigue viendo la
         *    ficha trabada -- o sea, el comando corre y para el que mira no pasa nada.
         */
        $tocados = DB::table('articles')
                        ->whereNull('deleted_at')
                        ->whereNotNull('percentage_gain')
                        ->where('percentage_gain', '=', 0)
                        ->when(!is_null($user_id) && $user_id !== '', function ($q) use ($user_id) {
                            return $q->where('user_id', (int) $user_id);
                        })
                        ->update([
                            'percentage_gain' => null,
                            'updated_at'      => now(),
                        ]);

        $this->info($tocados . ' articulo(s) normalizados: percentage_gain 0 -> null.');

        return 0;
    }
}
