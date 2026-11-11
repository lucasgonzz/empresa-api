<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * Una importación de clientes o proveedores pedida desde un ticket de soporte, con código de confirmación y
 * deshacer (misión importaciones-de-martin, 11/11/2026). La lógica vive en
 * App\Services\Importacion\ImportacionDeSoporteService.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class ImportacionDeSoporte extends Model
{
    protected $table = 'importaciones_de_soporte';

    protected $guarded = [];

    protected $casts = [
        'columnas' => 'array',
        'resumen' => 'array',
        'resultado' => 'array',
        'avisar_por_whatsapp' => 'boolean',
        'confirmada_at' => 'datetime',
        'terminada_at' => 'datetime',
        'revertida_at' => 'datetime',
    ];

    const MODELOS = ['client', 'provider'];

    /** Estados en los que todavía trabaja el job. */
    const EN_CURSO = ['analizar', 'importar'];

    public function scopeWithAll($query)
    {
        return $query;
    }

    public function cambios()
    {
        return $this->hasMany(ImportacionDeSoporteCambio::class, 'importacion_de_soporte_id');
    }

    /**
     * Deja un aviso por WhatsApp para el dueño, por el mismo camino que los de la importación de artículos
     * desde soporte (`importacion_lista`, con el ticket y sin link). Uno por etapa: idempotente.
     *
     * @param  string $etapa
     * @param  string $texto
     * @return void
     */
    public function aviso(string $etapa, string $texto): void
    {
        if (!$this->avisar_por_whatsapp) {
            return;
        }

        AiAvisoDelAsistente::firstOrCreate([
            'uuid' => $this->uuid_del_aviso($etapa),
            'owner_id' => $this->user_id,
            'tipo' => AiAvisoDelAsistente::TIPO_IMPORTACION_LISTA,
        ], [
            'support_ticket_id' => $this->support_ticket_id,
            'modo_soporte' => true,
            'texto' => $texto,
        ]);
    }

    /**
     * Retira la pregunta vigente si todavía no salió (el dueño ya respondió, o la corrida cambió).
     *
     * @return void
     */
    public function retirar_pregunta(): void
    {
        AiAvisoDelAsistente::where('owner_id', $this->user_id)
            ->where('support_ticket_id', $this->support_ticket_id)
            ->where('uuid', $this->uuid_del_aviso('pregunta-' . $this->revision))
            ->whereNull('avisado_por_whatsapp_at')->delete();
    }

    public function uuid_del_aviso(string $etapa): string
    {
        return Uuid::uuid5($this->uuid, $etapa)->toString();
    }

    public function etiqueta_plural(): string
    {
        return $this->modelo === 'provider' ? 'proveedores' : 'clientes';
    }
}
