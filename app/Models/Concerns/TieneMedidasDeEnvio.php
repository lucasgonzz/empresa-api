<?php

namespace App\Models\Concerns;

use App\Models\MedidasEnvioPaquete;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Espejo Empresa/Tienda. Las medidas nunca son atributos SQL del combo o de la promoción. */
trait TieneMedidasDeEnvio
{
    protected $medidas_envio_pendientes = [];

    public static function bootTieneMedidasDeEnvio()
    {
        static::saved(function ($modelo) {
            if (!$modelo->medidas_envio_pendientes) {
                return;
            }
            if (Schema::hasTable('medidas_envio_paquetes')) {
                DB::table('medidas_envio_paquetes')->updateOrInsert([
                    'tipo' => $modelo->tipo_medidas_envio(),
                    'paquete_id' => $modelo->id,
                ], $modelo->medidas_envio_pendientes);
            }
            $modelo->medidas_envio_pendientes = [];
            $modelo->unsetRelation('medidas_envio');
        });
    }

    protected function tipo_medidas_envio()
    {
        return $this->getTable() === 'combos' ? 'combo' : 'promocion_vinoteca';
    }

    public function medidas_envio()
    {
        return $this->hasOne(MedidasEnvioPaquete::class, 'paquete_id')->where('tipo', $this->tipo_medidas_envio());
    }

    protected function medida_de_envio($campo)
    {
        if (array_key_exists($campo, $this->medidas_envio_pendientes)) {
            return $this->medidas_envio_pendientes[$campo];
        }
        if (!$this->relationLoaded('medidas_envio')) {
            $this->setRelation('medidas_envio', $this->exists && Schema::hasTable('medidas_envio_paquetes')
                ? $this->medidas_envio()->first() : null);
        }
        $medidas = $this->getRelation('medidas_envio');
        return $medidas ? $medidas->{$campo} : null;
    }

    public function getPesoAttribute() { return $this->medida_de_envio('peso'); }
    public function getAltoAttribute() { return $this->medida_de_envio('alto'); }
    public function getAnchoAttribute() { return $this->medida_de_envio('ancho'); }
    public function getProfundidadAttribute() { return $this->medida_de_envio('profundidad'); }
    public function setPesoAttribute($valor) { $this->medidas_envio_pendientes['peso'] = $valor; }
    public function setAltoAttribute($valor) { $this->medidas_envio_pendientes['alto'] = $valor; }
    public function setAnchoAttribute($valor) { $this->medidas_envio_pendientes['ancho'] = $valor; }
    public function setProfundidadAttribute($valor) { $this->medidas_envio_pendientes['profundidad'] = $valor; }
}
