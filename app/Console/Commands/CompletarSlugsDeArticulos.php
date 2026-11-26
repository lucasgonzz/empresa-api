<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ArticleSlugBackfillHelper;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * CompletarSlugsDeArticulos (misión tienda-articulos-sin-slug, 26/11/2026)
 *
 * Completa `articles.slug` en los artículos que lo tienen NULL o vacío. La tienda arma la URL de la
 * ficha con ese slug: sin él, entrar a un artículo (por ejemplo desde "artículos relacionados")
 * muestra "No pudimos cargar este producto". Es un paso de despliegue POR CLIENTE, después de
 * `artisan up`; no es una migración ni un seeder.
 *
 * Usa la misma regla que el alta/edición del ABM (`ArticleHelper::slug`, único por dueño) y NO toca
 * `updated_at`. Es idempotente: la segunda corrida no encuentra nada. Por defecto es de SOLO
 * LECTURA: hay que pasar --aplicar para que escriba. La lógica vive en ArticleSlugBackfillHelper.
 *
 *     php artisan articles:completar-slugs                    # reporta
 *     php artisan articles:completar-slugs --aplicar          # escribe en todos los dueños
 *     php artisan articles:completar-slugs --aplicar --user_id=500
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class CompletarSlugsDeArticulos extends Command
{
    /**
     * Nombre y firma del comando.
     *
     * @var string
     */
    protected $signature = 'articles:completar-slugs
                            {--aplicar : Escribe los slugs. Sin este flag el comando solo reporta.}
                            {--user_id= : Limita el barrido a un solo dueño.}';

    /**
     * Descripción del comando.
     *
     * @var string
     */
    protected $description = 'Completa el slug de los articulos que lo tienen vacio (la tienda no puede abrir su ficha sin slug).';

    /**
     * @return int 0 si el comando pudo correr; 1 si el --user_id es inválido (no se toca nada).
     */
    public function handle()
    {
        // true si se pidió escribir; false = modo reporte.
        $aplicar = (bool) $this->option('aplicar');

        // Valor crudo de --user_id (null si no se escribió o si se escribió pelado).
        $user_id = $this->option('user_id');

        /*
         * "Se escribió --user_id" y "option() devolvió un valor" no son lo mismo: `--user_id` pelado
         * llega como null y `--user_id=` como ''. Si "sin valor" se tomara como "sin filtro", un
         * `--aplicar --user_id $ID` con $ID vacío en el shell escribiría en TODOS los dueños. Por eso se
         * mira también la línea de comandos con hasParameterOption() (mismo criterio que
         * articles:normalizar-margen-cero).
         */
        $acotar = !is_null($user_id) || $this->input->hasParameterOption('--user_id');

        if ($acotar) {
            // Sólo un entero positivo: (int) "500x" daría 500 y escribiría sobre un dueño que nadie pidió.
            if (!ctype_digit((string) $user_id) || (int) $user_id < 1) {
                $this->error('--user_id tiene que ser un numero entero positivo (recibi "' . (string) $user_id . '"). No se hizo nada.');
                return 1;
            }

            // Tiene que existir y ser DUEÑO: los artículos son del dueño, no de sus empleados.
            $dueno = User::find((int) $user_id);

            if (is_null($dueno)) {
                $this->error('No existe el usuario ' . (int) $user_id . '. No se hizo nada.');
                return 1;
            }

            if (!is_null($dueno->owner_id)) {
                $this->error('El usuario ' . (int) $user_id . ' es un empleado (owner_id ' . $dueno->owner_id . '); pasá el id de su dueño. No se hizo nada.');
                return 1;
            }

            $this->line('Limitado al user_id ' . (int) $user_id . '.');
        }

        $resultado = ArticleSlugBackfillHelper::procesar($aplicar, $acotar ? (int) $user_id : null);

        // Muestra unos pocos para que quede claro sobre qué se está por escribir (o se escribió).
        foreach ($resultado['ejemplos'] as $ejemplo) {
            $this->line('  id ' . $ejemplo['id'] . ' | ' . $ejemplo['name'] . ' | slug: ' . $ejemplo['slug']);
        }

        if (!$aplicar) {
            $this->line('Pendientes de completar: ' . $resultado['pendientes']);
            $this->line('Sin nombre utilizable: ' . $resultado['sin_nombre']);
            $this->warn('Modo reporte: no se escribio nada. Volve a correrlo con --aplicar para persistir.');
            return 0;
        }

        $this->info('Completados: ' . $resultado['completados'] . ' · Sin nombre utilizable: ' . $resultado['sin_nombre']);

        return 0;
    }
}
