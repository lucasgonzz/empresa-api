<?php

namespace Tests\Feature\Excel;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Crear_nuevo_articulo_Test extends TestCase
{
    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_crear_articulo_desde_excel()
    {

        $user = User::find(500);

        $this->actingAs($user, 'web');

        // Simula el sistema de archivos para pruebas
        // Storage::fake('local');

        // Obtén la ruta del archivo Excel real
        $pathToFile = storage_path('app/test/crear_articulos.xlsx');

        // Asegúrate de que el archivo existe antes de continuar
        $this->assertFileExists($pathToFile);

        // Crea un archivo UploadedFile a partir del archivo real
        $file = new UploadedFile(
            $pathToFile, // Ruta del archivo
            'crear_articulos.xlsx', // Nombre del archivo
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // MIME Type
            null, // Tamaño del archivo (Laravel lo calcula automáticamente)
            true // ¿Es el archivo válido?
        );

        $data = [
            'models'            => $file,
            'start_row'         => 2,
            'create_and_edit'   => true,
        ];

        $data = $this->add_columns($data);

        // Realiza una solicitud POST al endpoint de carga de archivos
        $response = $this->postJson('/api/article/excel/import', $data);

        $response->assertStatus(200);

        $this->artisan('queue:work --stop-when-empty');

        $this->assertDatabaseHas('articles', [
            'name'  => 'Articulo nuevo excel',
            'stock' => 10,
        ]);

        $this->assertDatabaseHas('categories', [
            'name'  => 'Nueva Categoria',
        ]);

        $this->assertDatabaseHas('sub_categories', [
            'name'  => 'Nueva Sub Categoria',
        ]);

    }

    function add_columns($columns) {
        $props = [
            'prop_numero' => 1,
            'prop_codigo_de_barras' => 2,
            'prop_codigo_de_proveedor' => 3,
            'prop_nombre' => 4,
            'prop_categoria' => 5,
            'prop_sub_categoria' => 6,
            'prop_stock_actual' => 7,
            'prop_stock_minimo' => 8,
            'prop_iva' => 9,
            'prop_proveedor' => 10,
            'prop_costo' => 11,
            'prop_margen_de_ganancia' => 12,
            'prop_descuentos' => 13,
            'prop_recargos' => 14,
            'prop_precio' => 15,
            'prop_moneda' => 16,
            'prop_unidad_medida' => 17,
        ];

        return array_merge($columns, $props);

        // $addresses = Address::where('user_id', config('app.APP_ENV'))
        //                         ->orderBy('id', 'ASC')
        //                         ->get();

        // $position = count($columns);

        // foreach ($addresses as $address) {
            
        //     $position++;

        //     $columns[$address->street] = $position;
        // }

        return $columns;

    }
}
