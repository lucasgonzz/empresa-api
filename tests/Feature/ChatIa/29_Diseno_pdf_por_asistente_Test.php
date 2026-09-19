<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\PdfColumnProfileHelper;
use App\Http\Controllers\Helpers\Seeders\PdfColumnProfileSeederHelper;
use App\Http\Controllers\Helpers\asistente_ia\AccionIaException;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaDisenoPdfIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;
use App\Models\User;
use App\Services\PdfColumnService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-masivas-imagenes-y-remito (19/9/2026) — cambiar las columnas de un diseño
 * de PDF desde el asistente: la consulta, la resolución de columnas por nombre, la tarjeta con
 * el achique calculado, la escritura del pivot igual que el ABM, el 422 si el diseño cambió en
 * el medio, y que el PUT del ABM sigue validando la suma de anchos con la misma regla.
 *
 * El perfil "Sin Precios" se arma a mano igual que PdfColumnSinPreciosSeeder (Índice 8 · Num 15
 * · Cod. barras 30 · Nombre 132 con ajuste de texto · Cantidad 15, A4 210/210/5 → 200 mm).
 */
class Diseno_pdf_por_asistente_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'             => 'Comercio pdf P29',
            'company_name'     => 'Ferreteria P29',
            'email'            => 'pdf-p29-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado pdf P29',
            'email'    => 'pdf-p29-emp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        // El catálogo de columnas de venta, sincronizado desde el código (la base de testing no lo trae).
        PdfColumnService::get_options('sale');
    }

    /**
     * El perfil "Sin Precios" del comercio, con las mismas columnas que el seeder.
     *
     * @param  bool $nombre_con_ajuste  wrap_content de "Nombre del artículo".
     * @return PdfColumnProfile
     */
    protected function sin_precios($nombre_con_ajuste = true)
    {
        $profile = PdfColumnProfile::create([
            'user_id'                  => $this->comercio->id,
            'model_name'               => 'sale',
            'name'                     => 'Sin Precios',
            'is_default'               => false,
            'paper_width_mm'           => 210,
            'printable_width_mm'       => 210,
            'margin_mm'                => 5,
            'is_afip_ticket'           => 0,
            'show_totals_on_each_page' => false,
            'columns'                  => [],
        ]);

        PdfColumnProfileSeederHelper::assign_profile_options($profile, 'sale', [
            'Índice de fila',
            'Número de artículo',
            'Código de barras',
            ['name' => 'Nombre del artículo', 'width' => 132, 'wrap_content' => $nombre_con_ajuste],
            'Cantidad',
        ]);

        return $profile->fresh();
    }

    /**
     * @param  \App\Models\User|null $persona
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($persona = null)
    {
        $persona = is_null($persona) ? $this->comercio : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $persona->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Quiero que en los remitos sin precios aparezca la columna categoría',
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * El pivot del perfil como [nombre => [visible, order, width, wrap_content]].
     *
     * @param  PdfColumnProfile $profile
     * @return array
     */
    protected function pivot(PdfColumnProfile $profile)
    {
        $pivot = [];

        foreach ($profile->fresh()->pdf_column_options as $option) {
            $pivot[$option->name] = [
                'visible'      => (bool) $option->pivot->visible,
                'order'        => (int) $option->pivot->order,
                'width'        => (int) $option->pivot->width,
                'wrap_content' => (bool) $option->pivot->wrap_content,
            ];
        }

        return $pivot;
    }

    /**
     * El input de "agregá Categoria del articulo después de Cantidad".
     *
     * @param  PdfColumnProfile $profile
     * @return array
     */
    protected function agregar_categoria(PdfColumnProfile $profile)
    {
        return [
            'diseno_id' => $profile->id,
            'agregar'   => [['columna' => 'Categoria del articulo', 'posicion' => 'despues_de', 'columna_de_referencia' => 'Cantidad']],
        ];
    }

    /** @test */
    public function consultar_lista_el_diseno_con_sus_columnas_en_orden_y_las_disponibles()
    {
        $profile = $this->sin_precios();
        list($conversation) = $this->conversacion();

        $respuesta = PropuestaDisenoPdfIaHelper::consultar(ContextoDeCargaIa::de_la_conversacion($conversation), 'venta');

        $this->assertTrue($respuesta['ok']);
        $this->assertCount(1, $respuesta['disenos']);

        $diseno = $respuesta['disenos'][0];
        $this->assertSame((int) $profile->id, $diseno['id']);
        $this->assertSame('Sin Precios', $diseno['nombre']);
        $this->assertSame('venta', $diseno['tipo']);
        $this->assertFalse($diseno['predeterminado']);
        $this->assertSame(200, $diseno['ancho_disponible_mm']);
        $this->assertSame(200, $diseno['suma_de_anchos_mm']);
        $this->assertSame([
            ['nombre' => 'Índice de fila', 'ancho_mm' => 8, 'ajusta_texto' => false],
            ['nombre' => 'Número de artículo', 'ancho_mm' => 15, 'ajusta_texto' => false],
            ['nombre' => 'Código de barras', 'ancho_mm' => 30, 'ajusta_texto' => false],
            ['nombre' => 'Nombre del artículo', 'ancho_mm' => 132, 'ajusta_texto' => true],
            ['nombre' => 'Cantidad', 'ancho_mm' => 15, 'ajusta_texto' => false],
        ], $diseno['columnas']);

        $this->assertArrayHasKey('venta', $respuesta['columnas_disponibles']);
        $this->assertArrayNotHasKey('articulos', $respuesta['columnas_disponibles'], 'Con tipo venta no se listan las de artículos.');
        $this->assertContains(['nombre' => 'Categoria del articulo', 'ancho_por_defecto_mm' => 35], $respuesta['columnas_disponibles']['venta']);

        /* Sin tipo: los dos catálogos. */
        $respuesta = PropuestaDisenoPdfIaHelper::consultar(ContextoDeCargaIa::de_la_conversacion($conversation), null);
        $this->assertArrayHasKey('articulos', $respuesta['columnas_disponibles']);
    }

    /** @test */
    public function un_empleado_sin_admin_puede_consultar_pero_no_proponer()
    {
        $profile = $this->sin_precios();
        list($conversation, $assistant) = $this->conversacion($this->empleado);
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        // Leer qué columnas tiene un remito no cambia nada: no exige dueño (el ABM lo muestra igual).
        $respuesta = PropuestaDisenoPdfIaHelper::consultar($contexto, 'venta');
        $this->assertTrue($respuesta['ok']);
        $this->assertSame('Sin Precios', $respuesta['disenos'][0]['nombre']);

        // Cambiarlo, sí.
        $respuesta = PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant, $this->agregar_categoria($profile));
        $this->assertFalse($respuesta['ok']);
        $this->assertSame(PropuestaDisenoPdfIaHelper::MENSAJE_SIN_PERMISO, $respuesta['error']);
    }

    /** @test */
    public function sin_posicion_no_se_asume_al_final_se_pregunta()
    {
        $profile = $this->sin_precios();
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant, [
            'diseno_id' => $profile->id,
            'agregar'   => [['columna' => 'Categoria del articulo']],
        ]);

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(['dónde va Categoria del articulo: al final, al principio, antes o después de cuál columna'], $respuesta['faltan']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /** @test */
    public function la_columna_se_resuelve_por_nombre_sin_acentos_y_la_ambigua_pide_desambiguar()
    {
        $this->assertSame('Categoria del articulo', PdfColumnProfileHelper::resolver_columna('sale', 'categoría')->name);
        $this->assertSame('Categoria del articulo', PdfColumnProfileHelper::resolver_columna('sale', 'CATEGORIA')->name);
        $this->assertSame('Nombre del artículo', PdfColumnProfileHelper::resolver_columna('sale', 'nombre')->name);
        $this->assertSame('Cantidad', PdfColumnProfileHelper::resolver_columna('sale', 'cantidad')->name);
        /* Por el encabezado impreso también. */
        $this->assertSame('Código de barras', PdfColumnProfileHelper::resolver_columna('sale', 'cod. barras')->name);
        $this->assertSame('Subcategoria del articulo', PdfColumnProfileHelper::resolver_columna('sale', 'subcategoría')->name);

        /* "código": barras y proveedor, ninguna exacta → faltan con las dos. */
        $respuesta = PdfColumnProfileHelper::resolver_columna('sale', 'código');
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($respuesta));
        $this->assertCount(1, $respuesta['faltan']);
        $this->assertSame(['Código de barras', 'Código de proveedor'], array_column($respuesta['opciones']['columnas'], 'nombre'));

        /* Inexistente → error con el nombre. */
        $respuesta = PdfColumnProfileHelper::resolver_columna('sale', 'peso');
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($respuesta));
        $this->assertStringContainsString('peso', $respuesta['error']);
    }

    /** @test */
    public function proponer_arma_la_tarjeta_con_el_achique_y_ejecutar_deja_el_pivot_como_el_abm()
    {
        $profile = $this->sin_precios();
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant, $this->agregar_categoria($profile));

        $this->assertTrue($respuesta['ok']);
        $this->assertSame('diseno_pdf', $respuesta['tipo']);
        $this->assertSame([['columna' => 'Nombre del artículo', 'de' => 132, 'a' => 97]], $respuesta['ajustes']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado());
        $this->assertSame('diseno_pdf:' . $profile->id, $accion->clave);
        $this->assertSame('Diseño de PDF: Sin Precios', $accion->presentacion['titulo']);
        $this->assertSame(PropuestaDisenoPdfIaHelper::AVISO, $accion->presentacion['aviso']);
        $this->assertSame([
            ['etiqueta' => 'Se agrega', 'valor' => 'Categoria del articulo (35 mm) después de Cantidad'],
            ['etiqueta' => 'Se achica', 'valor' => 'Nombre del artículo de 132 a 97 mm'],
            ['etiqueta' => 'Columnas', 'valor' => '# · Num · Cod. barras · Nombre · Cant · Categoria — 200 de 200 mm'],
        ], $accion->presentacion['renglones']);
        $this->assertNotNull($accion->referencia_updated_at);

        /* Nada se persistió todavía. */
        $this->assertFalse($this->pivot($profile)['Categoria del articulo']['visible']);
        $this->assertSame(132, $this->pivot($profile)['Nombre del artículo']['width']);

        $resultado = PropuestaDisenoPdfIaHelper::ejecutar($contexto, $accion);

        $this->assertSame('Listo: agregué Categoria del articulo después de Cantidad y achiqué Nombre del artículo a 97 mm en el diseño Sin Precios.', $resultado['texto']);
        $this->assertSame(['name' => 'abm', 'params' => ['view' => 'impresion', 'sub_view' => 'diseño-de-pdf'], 'texto' => 'Ver diseños de PDF'], $resultado['ruta']);

        $pivot = $this->pivot($profile);

        $this->assertSame(['visible' => true, 'order' => 5, 'width' => 35, 'wrap_content' => false], $pivot['Categoria del articulo']);
        $this->assertSame(['visible' => true, 'order' => 4, 'width' => 15, 'wrap_content' => false], $pivot['Cantidad']);
        $this->assertSame(['visible' => true, 'order' => 3, 'width' => 97, 'wrap_content' => true], $pivot['Nombre del artículo']);
        $this->assertSame(0, $pivot['Índice de fila']['order']);
        $this->assertSame(1, $pivot['Número de artículo']['order']);
        $this->assertSame(2, $pivot['Código de barras']['order']);

        $suma = 0;
        foreach ($pivot as $nombre => $fila) {
            if ($fila['visible']) {
                $suma += $fila['width'];
            } else {
                $this->assertGreaterThanOrEqual(6, $fila['order'], $nombre . ' (no visible) tiene que ir después de las visibles.');
            }
        }
        $this->assertSame(200, $suma);
        $this->assertCount(6, array_filter($pivot, function ($fila) {
            return $fila['visible'];
        }));

        /* `columns` (la foto que deja el seeder helper) también quedó, y el render la lee del pivot. */
        $columns = $profile->fresh()->columns;
        $this->assertCount((int) PdfColumnOption::where('model_name', 'sale')->count(), $columns);
        $categoria = array_values(array_filter($columns, function ($c) {
            return $c['name'] === 'Categoria del articulo';
        }))[0];
        $this->assertTrue($categoria['visible']);
        $this->assertSame(35, $categoria['width']);
        $this->assertSame('item_category_name', $categoria['value_resolver']);
    }

    /** @test */
    public function sin_ajuste_de_texto_el_achique_igual_baja_nombre_hasta_su_default()
    {
        $profile = $this->sin_precios(false);

        $categoria = PdfColumnOption::where('model_name', 'sale')->where('name', 'Categoria del articulo')->first();
        $cantidad  = PdfColumnOption::where('model_name', 'sale')->where('name', 'Cantidad')->first();

        $aplicado = PdfColumnProfileHelper::aplicar_cambios($profile, [
            ['option_id' => $categoria->id, 'posicion' => 'despues_de', 'referencia_option_id' => $cantidad->id, 'ancho_mm' => null],
        ], [], []);

        $this->assertNull($aplicado['error']);
        $this->assertSame([['columna' => 'Nombre del artículo', 'de' => 132, 'a' => 97]], $aplicado['ajustes']);
        $this->assertSame(200, $aplicado['suma']);
    }

    /** @test */
    public function quitar_una_columna_la_deja_no_visible_y_no_achica_nada()
    {
        $profile = $this->sin_precios();
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant, ['diseno_id' => $profile->id, 'quitar' => ['Código de barras']]);

        $this->assertTrue($respuesta['ok']);
        $this->assertSame([], $respuesta['ajustes']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame([
            ['etiqueta' => 'Se saca', 'valor' => 'Código de barras'],
            ['etiqueta' => 'Columnas', 'valor' => '# · Num · Nombre · Cant — 170 de 200 mm'],
        ], $accion->presentacion['renglones']);

        $resultado = PropuestaDisenoPdfIaHelper::ejecutar($contexto, $accion);
        $this->assertSame('Listo: saqué Código de barras en el diseño Sin Precios.', $resultado['texto']);

        $pivot = $this->pivot($profile);
        $this->assertFalse($pivot['Código de barras']['visible']);
        $this->assertSame(30, $pivot['Código de barras']['width'], 'El ancho se conserva por si vuelve.');
        $this->assertSame(2, $pivot['Nombre del artículo']['order']);
        $this->assertSame(132, $pivot['Nombre del artículo']['width']);
        $this->assertSame(3, $pivot['Cantidad']['order']);
    }

    /** @test */
    public function con_todo_en_su_ancho_por_defecto_la_que_ajusta_texto_baja_del_default_y_se_le_prende_el_ajuste()
    {
        // Hoja angosta (150 mm útiles) con todas las columnas en su ancho por defecto y el nombre
        // SIN ajuste: 8 + 15 + 30 + 72 + 15 = 140. Categoria (35) no entra: faltan 25.
        $profile = $this->sin_precios(false);
        $profile->update(['printable_width_mm' => 160, 'margin_mm' => 5]);
        PdfColumnProfileSeederHelper::assign_profile_options($profile, 'sale', [
            'Índice de fila', 'Número de artículo', 'Código de barras', 'Nombre del artículo', 'Cantidad',
        ]);
        $profile = $profile->fresh();
        $this->assertSame(72, $this->pivot($profile)['Nombre del artículo']['width']);

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant, [
            'diseno_id' => $profile->id,
            'agregar'   => [['columna' => 'Categoria del articulo', 'posicion' => 'al_final']],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $renglones = [];
        foreach ($accion->presentacion['renglones'] as $renglon) {
            $renglones[$renglon['etiqueta']] = $renglon['valor'];
        }
        $this->assertSame('Nombre del artículo de 72 a 47 mm', $renglones['Se achica']);

        PropuestaDisenoPdfIaHelper::ejecutar($contexto, $accion);

        $pivot = $this->pivot($profile);
        $this->assertSame(47, $pivot['Nombre del artículo']['width']);
        $this->assertTrue($pivot['Nombre del artículo']['wrap_content'], 'Al bajar del default se le prende el ajuste de texto: el nombre pasa a dos renglones en vez de cortarse.');
        $this->assertTrue($pivot['Categoria del articulo']['visible']);
        $this->assertSame(150, 8 + 15 + 30 + 47 + 15 + 35);
    }

    /** @test */
    public function un_agregado_que_no_entra_ni_achicando_es_un_error_legible()
    {
        $profile = $this->sin_precios();
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant, [
            'diseno_id' => $profile->id,
            'agregar'   => [['columna' => 'Proveedor del articulo', 'posicion' => 'al_final', 'ancho_mm' => 200]],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringStartsWith('No entra:', $respuesta['error']);
        $this->assertStringContainsString('Sacá una columna o pedime un ancho más chico', $respuesta['error']);

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'Un cambio que no entra no deja tarjeta.');
        $this->assertSame(132, $this->pivot($profile)['Nombre del artículo']['width']);
    }

    /** @test */
    public function si_falta_la_posicion_o_la_referencia_no_esta_en_el_diseno_se_pregunta()
    {
        $profile = $this->sin_precios();
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        /* despues_de sin decir de cuál → faltan. */
        $respuesta = PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant, [
            'diseno_id' => $profile->id,
            'agregar'   => [['columna' => 'Categoria del articulo', 'posicion' => 'despues_de']],
        ]);
        $this->assertFalse($respuesta['ok']);
        $this->assertCount(1, $respuesta['faltan']);

        /* Referencia que no está en el diseño → error que lo dice. */
        $respuesta = PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant, [
            'diseno_id' => $profile->id,
            'agregar'   => [['columna' => 'Categoria del articulo', 'posicion' => 'despues_de', 'columna_de_referencia' => 'Precio unitario']],
        ]);
        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('Precio unitario no está en el diseño', $respuesta['error']);

        /* Sin ningún cambio → faltan. */
        $respuesta = PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant, ['diseno_id' => $profile->id]);
        $this->assertFalse($respuesta['ok']);
        $this->assertCount(1, $respuesta['faltan']);

        /* Diseño de otro comercio → no existe. */
        $ajeno = PdfColumnProfile::create([
            'user_id' => $this->empleado->id + 100000, 'model_name' => 'sale', 'name' => 'zz ajeno',
            'paper_width_mm' => 210, 'printable_width_mm' => 210, 'margin_mm' => 5, 'columns' => [],
        ]);
        $respuesta = PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant, $this->agregar_categoria($ajeno));
        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('No encontré ese diseño', $respuesta['error']);
    }

    /** @test */
    public function si_el_diseno_cambio_entre_proponer_y_ejecutar_da_422()
    {
        $profile = $this->sin_precios();
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaDisenoPdfIaHelper::proponer($contexto, $assistant, $this->agregar_categoria($profile));
        $accion    = AiMessageAction::find($respuesta['tarjeta_id']);

        /* Alguien editó el diseño desde el ABM después de la propuesta. */
        PdfColumnProfile::where('id', $profile->id)->update(['updated_at' => Carbon::now()->addSeconds(5)]);

        try {
            PropuestaDisenoPdfIaHelper::ejecutar($contexto, $accion);
            $this->fail('Con el diseño cambiado tiene que dar 422.');
        } catch (AccionIaException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(PropuestaDisenoPdfIaHelper::MENSAJE_DISENO_CAMBIADO, $e->getMessage());
        }

        $this->assertFalse($this->pivot($profile)['Categoria del articulo']['visible']);

        /* Y un empleado sin admin tampoco ejecuta. */
        try {
            PropuestaDisenoPdfIaHelper::ejecutar(ContextoDeCargaIa::de_la_conversacion($conversation, $this->empleado), $accion);
            $this->fail('Un empleado sin admin no puede ejecutar.');
        } catch (AccionIaException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    /** @test */
    public function el_put_del_abm_sigue_validando_la_suma_de_anchos_con_la_misma_regla()
    {
        $profile = $this->sin_precios();

        $this->actingAs($this->comercio, 'web');

        $opciones = [];
        foreach ($profile->pdf_column_options as $option) {
            $opciones[$option->name] = (int) $option->id;
        }

        $pivot = function ($ancho_nombre) use ($opciones) {
            return [
                ['id' => $opciones['Índice de fila'], 'pivot' => ['visible' => true, 'order' => 0, 'width' => 8, 'wrap_content' => false]],
                ['id' => $opciones['Número de artículo'], 'pivot' => ['visible' => true, 'order' => 1, 'width' => 15, 'wrap_content' => false]],
                ['id' => $opciones['Código de barras'], 'pivot' => ['visible' => true, 'order' => 2, 'width' => 30, 'wrap_content' => false]],
                ['id' => $opciones['Nombre del artículo'], 'pivot' => ['visible' => true, 'order' => 3, 'width' => $ancho_nombre, 'wrap_content' => true]],
                ['id' => $opciones['Cantidad'], 'pivot' => ['visible' => true, 'order' => 4, 'width' => 15, 'wrap_content' => false]],
            ];
        };

        /* 8+15+30+133+15 = 201 > 200 → 422. */
        $this->putJson('api/pdf-column-profiles/' . $profile->id, ['pdf_column_options' => $pivot(133)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pdf_column_options']);

        /* Justo 200 → 200. */
        $this->putJson('api/pdf-column-profiles/' . $profile->id, ['pdf_column_options' => $pivot(132)])
            ->assertStatus(200);

        $this->assertSame(200, PdfColumnProfileHelper::ancho_disponible_mm(210, 5));
        $this->assertSame(0, PdfColumnProfileHelper::ancho_disponible_mm(8, 5), 'Nunca negativo.');
    }
}
