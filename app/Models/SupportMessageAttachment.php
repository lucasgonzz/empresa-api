<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SupportMessageAttachment extends Model
{
    /**
     * Los documentos que el chat de Soporte acepta además de audios e imágenes (misión
     * prueba-e2e-campana-imperio, 11/11/2026): la lista de precios del negocio. Extensión => mime con el
     * que se guarda y viaja al admin. `finfo` no sirve para decidirlo: a un .xlsx lo ve muchas veces como
     * `application/zip` y a un .csv como `text/plain`.
     */
    const DOCUMENTOS = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls'  => 'application/vnd.ms-excel',
        'csv'  => 'text/csv',
        'pdf'  => 'application/pdf',
    ];

    /** Tope de un documento, en bytes (10 MB). El mismo número que valida el SPA antes de mandar. */
    const TOPE_DE_DOCUMENTO = 10485760;

    /** @var bool|null Si la columna `original_name` existe, memoizado por proceso. */
    private static $guarda_nombre_original = null;

    /**
     * Campos asignables masivamente para guardado de adjuntos.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $hidden = ['remote_id'];

    /**
     * Scope estándar para mantener compatibilidad con fullModel.
     */
    public function scopeWithAll($query)
    {
        $query->with('message');
    }

    /**
     * Mensaje al que pertenece el recurso adjunto.
     */
    public function message()
    {
        return $this->belongsTo(SupportMessage::class, 'support_message_id');
    }

    /**
     * La extensión de documento aceptada con la que llegó el archivo, o null si no es un documento (es un
     * audio, una imagen, o cualquier otra cosa: esos siguen por el camino de siempre).
     *
     * @param \Illuminate\Http\UploadedFile $archivo
     * @return string|null `xlsx` | `xls` | `csv` | `pdf`
     */
    public static function extension_de_documento(UploadedFile $archivo)
    {
        $extension = strtolower((string) $archivo->getClientOriginalExtension());

        if (!array_key_exists($extension, self::DOCUMENTOS)) {
            return null;
        }

        $mime = (string) $archivo->getMimeType();

        // Un audio o una imagen renombrados no se tratan como documento.
        if (strpos($mime, 'image/') === 0 || strpos($mime, 'audio/') === 0 || strpos($mime, 'video/') === 0) {
            return null;
        }

        return $extension;
    }

    /**
     * El nombre original del archivo, sin carpetas ni caracteres de control, acotado a la columna.
     *
     * @param mixed $nombre
     * @return string|null
     */
    public static function nombre_original_limpio($nombre)
    {
        if (!is_string($nombre)) {
            return null;
        }

        $nombre = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $nombre)));
        $nombre = trim((string) $nombre);

        return $nombre === '' ? null : Str::limit($nombre, 255, '');
    }

    /**
     * Suma `original_name` a los datos de un adjunto nuevo, solo si la columna ya existe.
     *
     * 🔴 La guarda no es cosmética: un deploy de empresa SUBE LOS ARCHIVOS ANTES DE MIGRAR. En esa ventana
     * el cliente tiene este código y no tiene la columna, y con `$guarded = []` Eloquent la metería en el
     * INSERT: se caería el envío de TODO adjunto (audios e imágenes incluidos).
     *
     * @param array<string, mixed> $datos
     * @param mixed $nombre
     * @return array<string, mixed>
     */
    public static function con_nombre_original(array $datos, $nombre)
    {
        $nombre = self::nombre_original_limpio($nombre);

        if ($nombre !== null && self::guarda_nombre_original()) {
            $datos['original_name'] = $nombre;
        }

        return $datos;
    }

    /**
     * @return bool
     */
    public static function guarda_nombre_original()
    {
        if (self::$guarda_nombre_original === null) {
            try {
                self::$guarda_nombre_original = Schema::hasColumn('support_message_attachments', 'original_name');
            } catch (\Throwable $e) {
                return false;
            }
        }

        return self::$guarda_nombre_original;
    }

    /**
     * Vacía la memoria del proceso (tests, y el worker después de migrar).
     *
     * @return void
     */
    public static function olvidar_esquema()
    {
        self::$guarda_nombre_original = null;
    }
}
