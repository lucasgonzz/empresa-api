<?php

namespace App\Console\Commands;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Comando de mantenimiento para trasladar el contenido de una columna hacia otra
 * dentro de cualquier modelo Eloquent, con filtros opcionales por user_id y provider_id.
 *
 * Ejemplo: mover bar_code → provider_code en artículos del proveedor 5:
 *   php artisan model:transfer-property article bar_code provider_code 5
 *
 * Simulación:
 *   php artisan model:transfer-property article bar_code provider_code 5 --dry-run
 */
class TransferModelProperty extends Command
{
    /**
     * Columnas que no deben usarse como origen ni destino por seguridad.
     *
     * @var array<int, string>
     */
    private const BLOCKED_COLUMNS = [
        'id',
        'user_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Firma del comando Artisan con modelo y tres argumentos de datos.
     *
     * @var string
     */
    protected $signature = 'model:transfer-property
                            {model_name : Nombre del modelo (ej. article)}
                            {source_property : Columna origen (ej. bar_code)}
                            {destination_property : Columna destino (ej. provider_code)}
                            {provider_id : ID del proveedor para filtrar (si el modelo tiene provider_id)}
                            {--dry-run : Simula el traslado sin persistir cambios}';

    /**
     * Descripción visible en php artisan list.
     *
     * @var string
     */
    protected $description = 'Traslada el valor de una columna a otra dentro de un modelo Eloquent, vaciando la columna origen';

    /**
     * Ejecuta el traslado de valores entre columnas del modelo indicado.
     *
     * @return int Código de salida (0 = éxito, 1 = error de validación).
     */
    public function handle(): int
    {
        // Nombre del modelo recibido como primer argumento (ej. article).
        $model_name_input = Str::snake((string) $this->argument('model_name'));

        // Normalizar nombres de columnas a snake_case (ej. Bar_code → bar_code).
        $source_property = Str::snake((string) $this->argument('source_property'));
        $destination_property = Str::snake((string) $this->argument('destination_property'));

        // ID del proveedor recibido como cuarto argumento.
        $provider_id = (int) $this->argument('provider_id');

        if ($provider_id <= 0) {
            $this->error('El provider_id debe ser un entero mayor a cero.');
            return 1;
        }

        // Resolver clase Eloquent a partir del nombre del modelo del proyecto.
        $model_class = GeneralHelper::getModelName($model_name_input);

        if (! class_exists($model_class)) {
            $this->error(sprintf('No se encontró el modelo "%s" (%s).', $model_name_input, $model_class));
            return 1;
        }

        if (! is_subclass_of($model_class, Model::class)) {
            $this->error(sprintf('La clase "%s" no es un modelo Eloquent válido.', $model_class));
            return 1;
        }

        /** @var Model $model_instance Instancia temporal para obtener tabla y etiqueta. */
        $model_instance = new $model_class();
        $table_name = $model_instance->getTable();
        $model_label = class_basename($model_class);

        // Columnas reales de la tabla del modelo.
        $table_columns = Schema::getColumnListing($table_name);

        // Modo simulación: lista cambios sin guardar.
        $dry_run = (bool) $this->option('dry-run');

        // Validar columnas origen, destino y existencia de provider_id en la tabla.
        $validation_error = $this->validate_properties(
            $source_property,
            $destination_property,
            $table_columns,
            $table_name
        );

        if (! is_null($validation_error)) {
            $this->error($validation_error);
            return 1;
        }

        if (! in_array('provider_id', $table_columns, true)) {
            $this->error(sprintf(
                'El modelo "%s" no tiene columna provider_id. Este filtro no aplica a la tabla %s.',
                $model_label,
                $table_name
            ));
            return 1;
        }

        // ID del dueño de la instancia definido en .env (si la tabla tiene user_id).
        $user_id = config('app.USER_ID');
        $filter_by_user_id = in_array('user_id', $table_columns, true);

        if ($filter_by_user_id && empty($user_id)) {
            $this->error('No hay USER_ID definido en el .env. Comando cancelado.');
            return 1;
        }

        if ($dry_run) {
            $this->warn('Modo dry-run: no se guardarán cambios.');
        }

        $this->info(sprintf(
            'Trasladando "%s" → "%s" en %s con provider_id = %d%s',
            $source_property,
            $destination_property,
            $model_label,
            $provider_id,
            $filter_by_user_id ? sprintf(' (user_id = %s)', $user_id) : ''
        ));

        // Contadores de resumen al finalizar.
        $processed_count = 0;
        $skipped_empty_count = 0;

        // Consulta base sobre el modelo indicado.
        $records_query = $model_class::query()
            ->where('provider_id', $provider_id)
            ->orderBy('id');

        // Filtrar por owner cuando la tabla lo soporta.
        if ($filter_by_user_id) {
            $records_query->where('user_id', $user_id);
        }

        // Total previo para informar alcance del comando.
        $total_candidates = (clone $records_query)->count();
        $this->comment(sprintf('%s encontrados: %d', $model_label, $total_candidates));

        // Procesar en lotes para no cargar todos los registros en memoria.
        $records_query->chunkById(200, function ($records) use (
            $source_property,
            $destination_property,
            $dry_run,
            $model_label,
            &$processed_count,
            &$skipped_empty_count
        ) {
            foreach ($records as $record) {
                // Valor actual de la columna origen.
                $source_value = $record->{$source_property};

                // Solo trasladar cuando origen tiene contenido no vacío.
                if ($this->is_empty_value($source_value)) {
                    $skipped_empty_count++;
                    continue;
                }

                if ($dry_run) {
                    $this->line(sprintf(
                        '[dry-run] %s #%d: "%s" = "%s" → "%s", origen quedaría vacío',
                        $model_label,
                        $record->id,
                        $source_property,
                        $source_value,
                        $destination_property
                    ));
                } else {
                    // Mover valor: destino recibe el contenido y origen queda en null.
                    $record->{$destination_property} = $source_value;
                    $record->{$source_property} = null;
                    $record->save();

                    $this->comment(sprintf(
                        '%s #%d: trasladado "%s" → "%s"',
                        $model_label,
                        $record->id,
                        $source_property,
                        $destination_property
                    ));
                }

                $processed_count++;
            }
        });

        $this->info(sprintf(
            'Listo. Trasladados: %d. Omitidos (origen vacío): %d.',
            $processed_count,
            $skipped_empty_count
        ));

        return 0;
    }

    /**
     * Valida que las columnas origen y destino existan en la tabla y sean seguras de modificar.
     *
     * @param string $source_property Columna origen normalizada.
     * @param string $destination_property Columna destino normalizada.
     * @param array<int, string> $table_columns Columnas de la tabla del modelo.
     * @param string $table_name Nombre de la tabla para mensajes de error.
     *
     * @return string|null Mensaje de error o null si la validación es correcta.
     */
    private function validate_properties(
        string $source_property,
        string $destination_property,
        array $table_columns,
        string $table_name
    ): ?string {
        if ($source_property === $destination_property) {
            return 'La propiedad origen y destino no pueden ser la misma.';
        }

        if (! in_array($source_property, $table_columns, true)) {
            return sprintf('La columna origen "%s" no existe en la tabla %s.', $source_property, $table_name);
        }

        if (! in_array($destination_property, $table_columns, true)) {
            return sprintf('La columna destino "%s" no existe en la tabla %s.', $destination_property, $table_name);
        }

        if (in_array($source_property, self::BLOCKED_COLUMNS, true)) {
            return sprintf('La columna origen "%s" no está permitida para este comando.', $source_property);
        }

        if (in_array($destination_property, self::BLOCKED_COLUMNS, true)) {
            return sprintf('La columna destino "%s" no está permitida para este comando.', $destination_property);
        }

        return null;
    }

    /**
     * Determina si un valor se considera vacío para omitir el traslado.
     *
     * @param mixed $value Valor de la columna origen.
     *
     * @return bool
     */
    private function is_empty_value($value): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return false;
    }
}
