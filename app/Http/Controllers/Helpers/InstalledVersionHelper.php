<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Models\InstalledVersion;
use App\Models\SyncedVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Registro e historial de las versiones instaladas en el sistema del cliente.
 *
 * Regla única: una fila por (comercio, versión); la primera observación real gana y no se pisa. La única
 * sobreescritura permitida es reemplazar una fila `historial_previo` (fecha aproximada de publicación) por una
 * observación real.
 */
class InstalledVersionHelper
{
    /** Orígenes que son una observación real (no una fecha aproximada). */
    const FUENTES_REALES = ['instalacion', 'sincronizacion', 'detectada'];

    /**
     * Versión que corre el código, leída del archivo VERSION del paquete del release.
     *
     * @return string|null Número de versión válido, o null si el archivo no existe o no valida.
     */
    public static function code_version()
    {
        // Ruta del archivo; los tests la apuntan a un temporal o a una ruta inexistente.
        $file = config('app.version_file', base_path('VERSION'));

        if (empty($file) || !is_file($file)) {
            return null;
        }

        $content = trim((string) @file_get_contents($file));

        return self::es_version_valida($content) ? $content : null;
    }

    /**
     * Indica si el texto tiene forma de número de versión (X.Y.Z o más segmentos).
     *
     * @param  mixed $version
     * @return bool
     */
    public static function es_version_valida($version)
    {
        return is_string($version) && preg_match('/^\d+(\.\d+){2,}$/', $version) === 1;
    }

    /**
     * Dueño del comercio de esta instancia (sin sesión), o null si no se puede decidir.
     *
     * @return int|null
     */
    public static function owner_id_de_la_instancia()
    {
        $dueno = AsistenteCanalHelper::dueno();

        return $dueno ? (int) $dueno->id : null;
    }

    /**
     * Registra una versión instalada para un comercio, sin duplicar ni pisar la fecha.
     *
     * @param  int         $owner_id Dueño del comercio.
     * @param  string      $version  Número de versión.
     * @param  string      $source   instalacion | sincronizacion | detectada | historial_previo
     * @param  array       $extra    Opcional: installed_at (Carbon), synced_version_uuid, title.
     * @return \App\Models\InstalledVersion|null
     */
    public static function register($owner_id, $version, $source, array $extra = [])
    {
        if (!self::es_version_valida($version)) {
            return null;
        }

        // Fecha de la observación: la indicada o el momento actual.
        $installed_at = isset($extra['installed_at']) ? $extra['installed_at'] : Carbon::now();

        $row = InstalledVersion::where('owner_id', $owner_id)->where('version', $version)->first();

        if (!$row) {
            try {
                return InstalledVersion::create([
                    'owner_id'            => $owner_id,
                    'version'             => $version,
                    'synced_version_uuid' => isset($extra['synced_version_uuid']) ? $extra['synced_version_uuid'] : null,
                    'title'               => isset($extra['title']) ? $extra['title'] : null,
                    'installed_at'        => $installed_at,
                    'source'              => $source,
                ]);
            } catch (QueryException $e) {
                // Doble pedido simultáneo: clave duplicada = "ya estaba", se relee.
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                $row = InstalledVersion::where('owner_id', $owner_id)->where('version', $version)->first();
                if (!$row) {
                    return null;
                }
            }
        }

        // Única sobreescritura: la fecha aproximada cede ante una observación real.
        if ($row->source === 'historial_previo' && in_array($source, self::FUENTES_REALES, true)) {
            $row->installed_at = $installed_at;
            $row->source = $source;
        }

        // uuid y título se completan sólo si estaban vacíos.
        if (empty($row->synced_version_uuid) && !empty($extra['synced_version_uuid'])) {
            $row->synced_version_uuid = $extra['synced_version_uuid'];
        }
        if (empty($row->title) && !empty($extra['title'])) {
            $row->title = $extra['title'];
        }

        if ($row->isDirty()) {
            $row->save();
        }

        return $row;
    }

    /**
     * Copia synced_versions como historial previo la primera vez que se mira un comercio sin filas.
     * Si se conoce la versión del código, sólo copia las que no la superan.
     *
     * @param  int $owner_id Dueño del comercio.
     * @return void
     */
    public static function ensure_history($owner_id)
    {
        if (InstalledVersion::where('owner_id', $owner_id)->exists()) {
            return;
        }

        $code_version = self::code_version();

        foreach (SyncedVersion::orderBy('id')->get() as $synced_version) {
            if (!self::es_version_valida($synced_version->version)) {
                continue;
            }
            if ($code_version && version_compare($synced_version->version, $code_version, '>')) {
                continue;
            }

            self::register($owner_id, $synced_version->version, 'historial_previo', [
                'installed_at'        => $synced_version->published_at ?: $synced_version->created_at,
                'synced_version_uuid' => $synced_version->uuid,
                'title'               => $synced_version->title,
            ]);
        }
    }

    /**
     * Disparador 1: cierre de una actualización. Nunca lanza: no puede alterar la respuesta al admin.
     *
     * @return void
     */
    public static function register_from_update_closing()
    {
        try {
            $code_version = self::code_version();
            $owner_id = self::owner_id_de_la_instancia();

            if (!$code_version || !$owner_id) {
                return;
            }

            self::ensure_history($owner_id);
            self::register($owner_id, $code_version, 'instalacion');
        } catch (\Throwable $e) {
            Log::warning('InstalledVersion cierre de actualización: ' . $e->getMessage());
        }
    }

    /**
     * Disparador 2: versión publicada desde el admin. Nunca lanza.
     *
     * @param  \App\Models\SyncedVersion $synced_version Versión ya guardada.
     * @return void
     */
    public static function register_from_publication(SyncedVersion $synced_version)
    {
        try {
            $owner_id = self::owner_id_de_la_instancia();

            if (!$owner_id || !self::es_version_valida($synced_version->version)) {
                return;
            }

            $code_version = self::code_version();

            self::ensure_history($owner_id);

            $extra = ['synced_version_uuid' => $synced_version->uuid, 'title' => $synced_version->title];

            // El código manda: si corre otra versión, sólo se completan uuid/título de una fila ya existente.
            if ($code_version && $code_version !== $synced_version->version) {
                $row = InstalledVersion::where('owner_id', $owner_id)->where('version', $synced_version->version)->first();
                if ($row) {
                    self::register($owner_id, $synced_version->version, $row->source, $extra);
                }
                return;
            }

            self::register($owner_id, $synced_version->version, 'sincronizacion', $extra);
        } catch (\Throwable $e) {
            Log::warning('InstalledVersion publicación: ' . $e->getMessage());
        }
    }

    /**
     * Disparador 3 y consulta: arma la respuesta de GET api/installed-version.
     *
     * @param  int $owner_id Dueño del comercio del usuario logueado.
     * @return array
     */
    public static function summary($owner_id)
    {
        $code_version = self::code_version();

        self::ensure_history($owner_id);

        // Detección: el código corre una versión que todavía no figura.
        if ($code_version) {
            self::register($owner_id, $code_version, 'detectada');
        }

        $rows = InstalledVersion::where('owner_id', $owner_id)
            ->orderByRaw('installed_at IS NULL')
            ->orderBy('installed_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $history = [];
        foreach ($rows as $row) {
            $history[] = self::serialize_row($row, true);
        }

        $current = null;
        if ($code_version) {
            foreach ($rows as $row) {
                if ($row->version === $code_version) {
                    $current = self::serialize_row($row, false);
                    break;
                }
            }
        }
        if (!$current && count($history) > 0) {
            $current = self::serialize_row($rows[0], false);
        }
        if (!$current) {
            $owner = User::find($owner_id);
            if ($owner && !empty($owner->version_name)) {
                $current = [
                    'version'      => (string) $owner->version_name,
                    'installed_at' => null,
                    'source'       => 'sin_registro',
                    'title'        => null,
                ];
            }
        }

        return [
            'timezone'     => config('app.timezone'),
            'code_version' => $code_version,
            'current'      => $current,
            'history'      => $history,
        ];
    }

    /**
     * Fila lista para el JSON, con la fecha en la zona de la app (offset -03:00, nunca Z).
     *
     * @param  \App\Models\InstalledVersion $row
     * @param  bool $with_id Incluir el id (sólo en el historial).
     * @return array
     */
    protected static function serialize_row(InstalledVersion $row, $with_id)
    {
        $data = [];
        if ($with_id) {
            $data['id'] = $row->id;
        }
        $data['version'] = $row->version;
        $data['installed_at'] = $row->installed_at
            ? $row->installed_at->copy()->setTimezone(config('app.timezone'))->toIso8601String()
            : null;
        $data['source'] = $row->source;
        $data['title'] = $row->title;

        return $data;
    }
}
