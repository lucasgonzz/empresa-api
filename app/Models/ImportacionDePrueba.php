<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportacionDePrueba extends Model
{
    protected $table = 'importaciones_de_prueba';

    protected $guarded = [];

    protected $casts = [
        'hojas' => 'array',
        'preguntas' => 'array',
        'decisiones' => 'array',
        'hitos' => 'array',
        // Mapeo de columnas ya confirmado por soporte: lista de {hoja, columna, campo}; campo null = ignorar.
        'columnas_previas' => 'array',
        'modo_soporte' => 'boolean',
    ];

    public const EN_CURSO = ['analizar', 'preparar', 'importando', 'imagenes', 'indexar', 'indexando', 'stock_analizar', 'stock_preparar'];

    public const ESPERANDO_RESPUESTA = ['columnas', 'repetidos', 'confirmacion', 'stock_repetidos', 'stock_confirmacion'];

    public function estado_para(string $estado): string
    {
        // Un worker anterior no debe interpretar un conteo como un alta de catálogo.
        return $this->alcance === 'stock_existente' ? 'stock_' . $estado : $estado;
    }

    public function estado_publico(): string
    {
        return strpos($this->estado, 'stock_') === 0 ? substr($this->estado, 6) : $this->estado;
    }

    public function alcance_valido_para_avanzar(): bool
    {
        return (strpos($this->estado, 'stock_') === 0) === ($this->alcance === 'stock_existente');
    }

    public function scopeWithAll($query)
    {
        return $query;
    }

    public function aviso(string $etapa, string $texto): void
    {
        AiAvisoDelAsistente::firstOrCreate([
            'uuid' => $this->uuid_del_aviso($etapa),
            'owner_id' => $this->user_id,
            'tipo' => AiAvisoDelAsistente::TIPO_IMPORTACION_LISTA,
        ], [
            'support_ticket_id' => $this->support_ticket_id,
            'modo_soporte' => (bool) $this->modo_soporte,
            'texto' => $texto,
        ]);
    }

    public function retirar_pregunta(): void
    {
        AiAvisoDelAsistente::where('owner_id', $this->user_id)
            ->where('support_ticket_id', $this->support_ticket_id)
            ->where('uuid', $this->uuid_del_aviso('pregunta-' . $this->revision))
            ->whereNull('avisado_por_whatsapp_at')->delete();
    }

    private function uuid_del_aviso(string $etapa): string
    {
        return \Ramsey\Uuid\Uuid::uuid5($this->uuid, $etapa)->toString();
    }

    public function hito(string $clave, array $datos): void
    {
        $hitos = $this->hitos ?? [];
        $hitos[$clave] = $datos + [
            'id' => $this->uuid . '-' . $clave,
            'visto' => false,
            'implementacion' => $clave === 'agente' ? 'agente' : 'sistema',
        ];
        $this->hitos = $hitos;
    }
}
