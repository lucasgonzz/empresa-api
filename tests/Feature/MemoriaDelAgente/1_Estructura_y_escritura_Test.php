<?php

namespace Tests\Feature\MemoriaDelAgente;

use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use App\Models\AgenteContextoArchivo;
use App\Models\AgenteContextoRevision;
use App\Models\AiConversation;

/**
 * Misión app-ia-y-memoria (E2) — la estructura de la memoria y su único camino de escritura.
 *
 * Protege: que asegurar() cree la plantilla calcada de claude-comerciocity una sola vez (idempotente);
 * que cada escritura que cambia algo suba la versión y deje su revisión entera (nada se pierde), y la
 * que no cambia nada no escriba; que el índice se regenere solo y no se pueda escribir a mano; los
 * topes (archivo, maestro, cantidad de archivos); y la tenencia entre dos comercios de la misma base.
 */
class Estructura_y_escritura_Test extends MemoriaTestCase
{
    /** @test */
    public function asegurar_crea_la_plantilla_una_sola_vez()
    {
        $primera = AgenteContextoHelper::asegurar($this->dueno);

        $cantidad = count(AgenteContextoHelper::plantilla());

        $this->assertTrue($primera['creada']);
        $this->assertSame($cantidad, $primera['archivos']);
        $this->assertSame(16, $cantidad, 'La plantilla son 16 archivos: maestro, índice y los 14 por tema.');

        $rutas = AgenteContextoArchivo::where('user_id', $this->dueno->id)->pluck('ruta')->all();

        foreach (['contexto/contexto_maestro.md', 'contexto/INDICE.md', 'dueno/perfil.md', 'dueno/como_responder.md',
                  'negocio/resumen.md', 'negocio/objetivos.md', 'negocio/prioridades.md', 'areas/ventas.md',
                  'areas/compras_y_proveedores.md', 'areas/stock.md', 'areas/clientes_y_cobranzas.md',
                  'areas/finanzas.md', 'areas/equipo.md', 'areas/tienda_online.md', 'decisiones/decisiones.md',
                  'aprendizajes/correcciones.md'] as $ruta) {
            $this->assertContains($ruta, $rutas);
        }

        // Cada archivo de la plantilla (menos el índice, que es generado) deja su versión 1 en el historial.
        $ids = AgenteContextoArchivo::where('user_id', $this->dueno->id)->pluck('id')->all();
        $this->assertSame($cantidad - 1, AgenteContextoRevision::whereIn('archivo_id', $ids)->count());

        $segunda = AgenteContextoHelper::asegurar($this->dueno);

        $this->assertFalse($segunda['creada'], 'La segunda vez no crea nada.');
        $this->assertSame($cantidad, $segunda['archivos']);
        $this->assertSame($cantidad, AgenteContextoArchivo::where('user_id', $this->dueno->id)->count());
        $this->assertSame($cantidad - 1, AgenteContextoRevision::whereIn('archivo_id', $ids)->count());

        $maestro = AgenteContextoHelper::leer($this->dueno, 'contexto/contexto_maestro.md');

        foreach (AgenteContextoHelper::secciones_del_maestro() as $seccion) {
            $this->assertStringContainsString('## ' . $seccion, $maestro['contenido']);
        }

        $this->assertStringContainsString($this->dueno->company_name, $maestro['contenido']);
        $this->assertStringContainsString('(todavía vacío)', AgenteContextoHelper::leer($this->dueno, 'areas/stock.md')['contenido']);
    }

    /** @test */
    public function un_empleado_escribe_en_la_memoria_de_su_dueno()
    {
        $empleado = $this->empleado(1);

        AgenteContextoHelper::asegurar($empleado);

        $this->assertSame(0, AgenteContextoArchivo::where('user_id', $empleado->id)->count(), 'La memoria es del comercio, nunca de la persona.');
        $this->assertSame(16, AgenteContextoArchivo::where('user_id', $this->dueno->id)->count());
    }

    /** @test */
    public function agregar_reemplaza_el_vacio_y_despues_suma_con_su_revision()
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        $r = AgenteContextoHelper::guardar($this->dueno, 'areas/compras_y_proveedores.md', 'agregar',
            '- Le compra la pintura a Pinturerías Rex porque le da 60 días.', 'Proveedores clave y condiciones.',
            'El dueño contó con quién trabaja la pintura', 'agente', $conversation->id);

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['sin_cambios']);
        $this->assertSame(2, $r['archivo']['version']);
        $this->assertSame('agente', $r['archivo']['origen']);
        $this->assertSame('Proveedores clave y condiciones.', $r['archivo']['resumen']);
        $this->assertStringContainsString('Pinturerías Rex', $r['archivo']['contenido']);
        $this->assertStringNotContainsString('(todavía vacío)', $r['archivo']['contenido'], 'El primer dato ocupa el lugar del vacío.');

        $archivo = AgenteContextoArchivo::where('user_id', $this->dueno->id)->where('ruta', 'areas/compras_y_proveedores.md')->first();

        $revision = AgenteContextoRevision::where('archivo_id', $archivo->id)->where('version', 2)->first();

        $this->assertNotNull($revision, 'La escritura deja su revisión.');
        $this->assertSame('agente', $revision->origen);
        $this->assertSame('El dueño contó con quién trabaja la pintura', $revision->motivo);
        $this->assertSame($conversation->id, $revision->ai_conversation_id);
        $this->assertSame($archivo->contenido, $revision->contenido, 'La revisión guarda el contenido completo de su versión.');

        $r2 = AgenteContextoHelper::guardar($this->dueno, 'areas/compras_y_proveedores.md', 'agregar',
            '- La ferretería grande se la compra a Distribuidora Sur.', null, 'Otro proveedor', 'agente', $conversation->id);

        $this->assertSame(3, $r2['archivo']['version']);
        $this->assertStringContainsString("60 días.\n- La ferretería grande", $r2['archivo']['contenido'], 'Un renglón de lista va en el renglón siguiente.');
        $this->assertSame('Proveedores clave y condiciones.', $r2['archivo']['resumen'], 'Sin resumen nuevo queda el que había.');

        // La versión 1 (la plantilla) sigue en el historial: nada se pierde.
        $v1 = AgenteContextoRevision::where('archivo_id', $archivo->id)->where('version', 1)->first();
        $this->assertStringContainsString('(todavía vacío)', $v1->contenido);
    }

    /** @test */
    public function reemplazar_pisa_y_lo_identico_no_escribe()
    {
        AgenteContextoHelper::guardar($this->dueno, 'areas/stock.md', 'agregar', '- La lavandina no puede faltar nunca.', null, 'm1', 'agente');

        $r = AgenteContextoHelper::guardar($this->dueno, 'areas/stock.md', 'reemplazar', "# Stock\n\n- Repone los lunes.", null, 'Corrige', 'dueno');

        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['archivo']['version']);
        $this->assertSame("# Stock\n\n- Repone los lunes.\n", $r['archivo']['contenido']);
        $this->assertSame('dueno', $r['archivo']['origen']);

        $archivo = AgenteContextoArchivo::where('user_id', $this->dueno->id)->where('ruta', 'areas/stock.md')->first();
        $revisiones_antes = AgenteContextoRevision::where('archivo_id', $archivo->id)->count();

        $igual = AgenteContextoHelper::guardar($this->dueno, 'areas/stock.md', 'reemplazar', "# Stock\n\n- Repone los lunes.", null, 'Lo mismo', 'dueno');

        $this->assertTrue($igual['ok']);
        $this->assertTrue($igual['sin_cambios']);
        $this->assertSame(3, $igual['archivo']['version'], 'Lo idéntico no sube la versión.');
        $this->assertSame($revisiones_antes, AgenteContextoRevision::where('archivo_id', $archivo->id)->count(), 'Ni deja revisión.');

        // La versión del medio (lo que escribió el agente) sigue recuperable.
        $this->assertStringContainsString('lavandina', AgenteContextoRevision::where('archivo_id', $archivo->id)->where('version', 2)->first()->contenido);
    }

    /** @test */
    public function una_seccion_se_escribe_sola_y_el_maestro_la_exige_para_agregar()
    {
        $sin_seccion = AgenteContextoHelper::guardar($this->dueno, 'contexto/contexto_maestro.md', 'agregar', 'Algo', null, 'm', 'agente');

        $this->assertFalse($sin_seccion['ok']);
        $this->assertSame('seccion_requerida', $sin_seccion['code']);

        $r = AgenteContextoHelper::guardar($this->dueno, 'contexto/contexto_maestro.md', 'agregar', '- Se llama Raúl y abre a las 8.', null, 'm', 'agente', null, 'Quién es el dueño');

        $this->assertTrue($r['ok']);

        $contenido = $r['archivo']['contenido'];

        $this->assertStringContainsString("## Quién es el dueño\n\n- Se llama Raúl y abre a las 8.\n\n## El negocio\n\n(todavía vacío)", $contenido, 'Solo cambió esa sección.');
        $this->assertStringContainsString('## Dónde buscar cada cosa', $contenido);

        $r2 = AgenteContextoHelper::guardar($this->dueno, 'contexto/contexto_maestro.md', 'reemplazar', 'Vende pinturería al por mayor.', null, 'm', 'agente', null, 'El negocio');

        $this->assertStringContainsString("## El negocio\n\nVende pinturería al por mayor.\n\n## Cómo quiere", $r2['archivo']['contenido']);
        $this->assertStringContainsString('Se llama Raúl', $r2['archivo']['contenido'], 'Reemplazar una sección no toca las otras.');

        // Una sección que no existe se crea al final.
        $r3 = AgenteContextoHelper::guardar($this->dueno, 'areas/equipo.md', 'agregar', '- Marta atiende el mostrador.', null, 'm', 'agente', null, 'Mostrador');
        $this->assertStringContainsString("## Mostrador\n\n- Marta atiende el mostrador.\n", $r3['archivo']['contenido']);
    }

    /** @test */
    public function el_indice_se_regenera_solo_y_no_se_escribe_a_mano()
    {
        AgenteContextoHelper::asegurar($this->dueno);

        $indice_antes = AgenteContextoHelper::leer($this->dueno, 'contexto/INDICE.md');

        $this->assertStringContainsString('areas/stock.md — Stock', $indice_antes['contenido']);
        $this->assertStringContainsString('(todavía vacío)', $indice_antes['contenido']);
        $this->assertStringNotContainsString('contexto/INDICE.md —', $indice_antes['contenido'], 'El índice no se lista a sí mismo.');

        AgenteContextoHelper::guardar($this->dueno, 'areas/stock.md', 'agregar', '- La lavandina no puede faltar.', 'Lo que no puede faltar.', 'm', 'agente');

        $indice = AgenteContextoHelper::leer($this->dueno, 'contexto/INDICE.md');

        $this->assertGreaterThan($indice_antes['version'], $indice['version']);
        $this->assertStringContainsString('areas/stock.md — Stock: Lo que no puede faltar. (actualizado el ' . now()->format('d/m/Y') . ')', $indice['contenido']);
        $this->assertSame('sistema', $indice['origen']);

        $a_mano = AgenteContextoHelper::guardar($this->dueno, 'contexto/INDICE.md', 'reemplazar', 'basura', null, 'm', 'agente');

        $this->assertFalse($a_mano['ok']);
        $this->assertSame('archivo_generado', $a_mano['code']);
        $this->assertSame($indice['contenido'], AgenteContextoHelper::leer($this->dueno, 'contexto/INDICE.md')['contenido']);

        // El índice no deja revisiones: se reconstruye.
        $id_indice = AgenteContextoArchivo::where('user_id', $this->dueno->id)->where('ruta', 'contexto/INDICE.md')->value('id');
        $this->assertSame(0, AgenteContextoRevision::where('archivo_id', $id_indice)->count());
    }

    /** @test */
    public function los_topes_frenan_sin_escribir()
    {
        AgenteContextoHelper::asegurar($this->dueno);

        $largo = str_repeat('a', AgenteContextoHelper::TOPE_ARCHIVO + 1);

        $r = AgenteContextoHelper::guardar($this->dueno, 'areas/ventas.md', 'agregar', $largo, null, 'm', 'agente');

        $this->assertFalse($r['ok']);
        $this->assertSame('contexto_muy_largo', $r['code']);
        $this->assertStringContainsString((string) AgenteContextoHelper::TOPE_ARCHIVO, $r['error'], 'El mensaje dice el tope, para que el agente sepa qué hacer.');
        $this->assertSame(1, AgenteContextoHelper::leer($this->dueno, 'areas/ventas.md')['version'], 'No se escribió nada.');

        $maestro_largo = str_repeat('b', AgenteContextoHelper::TOPE_MAESTRO);

        $m = AgenteContextoHelper::guardar($this->dueno, 'contexto/contexto_maestro.md', 'agregar', $maestro_largo, null, 'm', 'agente', null, 'El negocio');

        $this->assertFalse($m['ok']);
        $this->assertSame('contexto_muy_largo', $m['code'], 'El maestro tiene su tope propio, más chico.');

        // Justo en el tope del archivo, entra.
        $justo = AgenteContextoHelper::guardar($this->dueno, 'areas/finanzas.md', 'reemplazar', str_repeat('c', AgenteContextoHelper::TOPE_ARCHIVO - 1), null, 'm', 'agente');
        $this->assertTrue($justo['ok']);
    }

    /** @test */
    public function rutas_nuevas_solo_en_carpetas_validas_y_hasta_el_tope()
    {
        $nueva = AgenteContextoHelper::guardar($this->dueno, 'areas/proveedor_pinturas.md', 'agregar', '- Rex entrega los martes.', 'Rex', 'm', 'agente');

        $this->assertTrue($nueva['ok']);
        $this->assertTrue($nueva['creado']);
        $this->assertSame(1, $nueva['archivo']['version']);
        $this->assertSame('Proveedor pinturas', $nueva['archivo']['titulo']);
        $this->assertStringContainsString('areas/proveedor_pinturas.md — Proveedor pinturas', AgenteContextoHelper::leer($this->dueno, 'contexto/INDICE.md')['contenido']);

        foreach (['contexto/otro.md', '../etc/passwd', 'areas/Con Espacios.md', 'areas/stock.txt', 'secreto/x.md', ''] as $mala) {
            $r = AgenteContextoHelper::guardar($this->dueno, $mala, 'agregar', 'x', null, 'm', 'agente');
            $this->assertFalse($r['ok'], 'La ruta "' . $mala . '" no tendría que valer.');
            $this->assertSame('ruta_invalida', $r['code']);
        }

        $mala_op = AgenteContextoHelper::guardar($this->dueno, 'areas/stock.md', 'borrar', 'x', null, 'm', 'agente');
        $this->assertSame('operacion_invalida', $mala_op['code']);

        // Hasta TOPE_ARCHIVOS.
        $hay = AgenteContextoArchivo::where('user_id', $this->dueno->id)->count();

        for ($i = $hay; $i < AgenteContextoHelper::TOPE_ARCHIVOS; $i++) {
            $this->assertTrue(AgenteContextoHelper::guardar($this->dueno, 'decisiones/tema_' . $i . '.md', 'agregar', 'x', null, 'm', 'agente')['ok']);
        }

        $pasado = AgenteContextoHelper::guardar($this->dueno, 'decisiones/uno_mas.md', 'agregar', 'x', null, 'm', 'agente');

        $this->assertFalse($pasado['ok']);
        $this->assertSame('demasiados_archivos', $pasado['code']);
    }

    /** @test */
    public function dos_comercios_de_la_misma_base_no_se_ven_la_memoria()
    {
        $otro = $this->nuevo_dueno('Otro negocio', true);

        AgenteContextoHelper::guardar($this->dueno, 'areas/stock.md', 'agregar', '- Dato del primero.', null, 'm', 'agente');
        AgenteContextoHelper::guardar($otro, 'areas/stock.md', 'agregar', '- Dato del segundo.', null, 'm', 'agente');

        $del_primero = AgenteContextoHelper::leer($this->dueno, 'areas/stock.md')['contenido'];
        $del_segundo = AgenteContextoHelper::leer($otro, 'areas/stock.md')['contenido'];

        $this->assertStringContainsString('Dato del primero', $del_primero);
        $this->assertStringNotContainsString('Dato del segundo', $del_primero);
        $this->assertStringContainsString('Dato del segundo', $del_segundo);
        $this->assertStringNotContainsString('Dato del primero', $del_segundo);

        $this->assertStringNotContainsString('Dato del segundo', AgenteContextoHelper::bloque_para_prompt($this->dueno));
    }

    /** @test */
    public function el_estado_del_cuestionario_arranca_en_falso()
    {
        $this->assertSame(
            ['completado' => false, 'salteado' => false, 'completado_en' => null],
            AgenteContextoHelper::estado_cuestionario($this->dueno)
        );

        $this->assertSame(
            ['completado' => false, 'salteado' => false, 'completado_en' => null],
            AgenteContextoHelper::estado_cuestionario(null)
        );
    }
}
