<?php

namespace Tests\Unit\Php84;

use App\Http\Controllers\CommonLaravel\ImageController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Misión php84-errores-fatales (1/11/2026), caso B9: borrar un pedido respondía 500 en PHP 8 porque
 * `deleteModelImages()` hacía `substr()` del atributo YA CASTEADO, y un atributo `array`/`json` llega
 * como array (TypeError). El registro se borraba igual, pero la respuesta era un error.
 *
 * Sin base de datos: un modelo suelto con atributos crudos.
 */
class BorrarImagenesDeUnModeloTest extends TestCase
{
    private function modelo(array $crudos)
    {
        $modelo = new class extends Model {
            protected $guarded = [];
            protected $casts = ['extras' => 'array', 'ubicacion' => 'object'];
        };
        $modelo->setRawAttributes($crudos);

        return $modelo;
    }

    /** @test */
    public function un_atributo_casteado_a_array_no_tira_typeerror_y_se_borran_las_imagenes()
    {
        Storage::fake('public');
        Storage::disk('public')->put('portada.jpg', 'x');
        Storage::disk('public')->put('imagen-1.jpg', 'x');
        Storage::disk('public')->put('otro.txt', 'x');

        $modelo = $this->modelo([
            'id'        => 7,
            'foto'      => 'https://cliente.test/storage/portada.jpg',
            'image_url' => 'imagen-1.jpg',
            'extras'    => json_encode(['a' => 1, 'b' => [1, 2]]),
            'ubicacion' => json_encode(['lat' => -27.4]),
            'notas'     => null,
            'cantidad'  => 3,
            'otro'      => 'otro.txt',
        ]);

        $this->assertIsArray($modelo->extras, 'el cast array es lo que rompía en PHP 8');

        ImageController::deleteModelImages($modelo);

        Storage::disk('public')->assertMissing('portada.jpg');
        Storage::disk('public')->assertMissing('imagen-1.jpg');
        Storage::disk('public')->assertExists('otro.txt');
    }

    /** @test */
    public function un_modelo_nulo_no_hace_nada()
    {
        ImageController::deleteModelImages(null);
        $this->assertTrue(true);
    }

    /** @test */
    public function una_columna_foto_vacia_o_nula_no_rompe()
    {
        Storage::fake('public');
        ImageController::deleteModelImages($this->modelo(['foto' => null, 'foto_2' => '', 'extras' => json_encode([])]));
        $this->assertTrue(true);
    }
}
