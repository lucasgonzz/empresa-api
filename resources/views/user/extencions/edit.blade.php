<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configurador de extenciones</title>
    {{--
        Misión 54. Antes de esto la vista era una lista plana de 91 checkboxes con el nombre y
        nada más, y había pares con el nombre visible EXACTAMENTE igual y comportamiento opuesto
        (`check_article_stock_en_vender` impide agregar el artículo, `warn_article_stock_en_vender`
        solo avisa). Ahora cada checkbox trae su descripción debajo, el listado está agrupado por
        módulo, hay buscador, y las que ningún código lee viven en una sección colapsada al final.
    --}}
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            background-color: #f0f2f5;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0 0 90px 0;
            color: #333;
        }

        .container {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
            padding: 20px 12px;
        }

        h1 {
            text-align: center;
            margin: 10px 0 6px;
            font-size: 24px;
        }

        .subtitulo {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin: 0 0 18px;
        }

        .form-box {
            background-color: #ffffff;
            padding: 16px;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }

        /* --- avisos --- */
        .status {
            text-align: center;
            margin: 0 0 16px;
            padding: 10px;
            border-radius: 6px;
            font-size: 15px;
        }

        .status.ok {
            color: #14532d;
            background-color: #dcfce7;
        }

        .status.warn {
            color: #7c2d12;
            background-color: #ffedd5;
            text-align: left;
        }

        /* --- buscador --- */
        .buscador {
            position: sticky;
            top: 0;
            background-color: #ffffff;
            padding: 12px 0;
            z-index: 5;
            border-bottom: 1px solid #e5e7eb;
        }

        .buscador input {
            width: 100%;
            padding: 10px 12px;
            font-size: 16px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
        }

        .buscador input:focus {
            outline: 2px solid #93c5fd;
            border-color: #93c5fd;
        }

        .resultado-busqueda {
            font-size: 13px;
            color: #666;
            margin-top: 6px;
            min-height: 18px;
        }

        /* --- grupos --- */
        .modulo {
            margin-top: 22px;
        }

        .modulo > h2 {
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #475569;
            margin: 0 0 6px;
            padding-bottom: 6px;
            border-bottom: 2px solid #e5e7eb;
        }

        .modulo > h2 .cuenta {
            font-weight: normal;
            text-transform: none;
            letter-spacing: 0;
            color: #94a3b8;
            font-size: 13px;
        }

        /* --- una extensión --- */
        .ext {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 8px;
            border-bottom: 1px solid #f1f5f9;
        }

        .ext:hover {
            background-color: #f8fafc;
        }

        .ext input[type="checkbox"] {
            margin: 2px 0 0;
            width: 18px;
            height: 18px;
            flex: 0 0 auto;
        }

        .ext-cuerpo {
            min-width: 0;
        }

        /*
         * Solo el nombre tildea la extensión, no la fila entera: la descripción ocupa varios
         * renglones y clickearla para leerla no tiene que cambiar lo que está guardado.
         */
        .ext-nombre {
            display: block;
            font-size: 15px;
            font-weight: bold;
            line-height: 1.3;
            cursor: pointer;
        }

        .ext-slug {
            font-family: Consolas, Monaco, monospace;
            font-size: 12px;
            color: #64748b;
            word-break: break-all;
        }

        .ext-desc {
            font-size: 13px;
            color: #475569;
            line-height: 1.5;
            margin: 4px 0 0;
        }

        .ext-desc.recortada {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .ext-sin-desc {
            font-size: 13px;
            color: #94a3b8;
            font-style: italic;
            margin: 4px 0 0;
        }

        .ver-mas {
            background: none;
            border: none;
            color: #2563eb;
            font-size: 13px;
            padding: 2px 0;
            cursor: pointer;
            text-decoration: underline;
        }

        /* --- en desuso --- */
        .desuso {
            margin-top: 28px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background-color: #fafafa;
        }

        .desuso > summary {
            padding: 12px 14px;
            cursor: pointer;
            font-weight: bold;
            color: #475569;
        }

        .desuso .aclaracion {
            font-weight: normal;
            font-size: 13px;
            color: #64748b;
            padding: 0 14px 8px;
            margin: 0;
        }

        .desuso .ext {
            opacity: 0.85;
        }

        .desuso-cuerpo {
            padding: 0 8px 8px;
        }

        /* --- barra de guardado --- */
        .barra {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: #ffffff;
            border-top: 1px solid #e2e8f0;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.08);
            padding: 10px 12px;
            z-index: 10;
        }

        .barra-interna {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .contador {
            font-size: 14px;
            color: #475569;
            white-space: nowrap;
        }

        button.guardar {
            flex: 1 1 auto;
            background-color: #007bff;
            color: white;
            padding: 12px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }

        button.guardar:hover {
            background-color: #0056b3;
        }

        .sin-resultados {
            text-align: center;
            color: #64748b;
            padding: 30px 10px;
            display: none;
        }

        @media (max-width: 480px) {
            .container {
                padding: 12px 8px;
            }

            .form-box {
                padding: 12px 10px;
            }

            h1 {
                font-size: 20px;
            }

            .barra-interna {
                flex-direction: column;
                align-items: stretch;
                gap: 6px;
            }

            .contador {
                text-align: center;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Extenciones de {{ $user->name }}</h1>
    <p class="subtitulo">{{ $extencions->count() }} extensiones en total</p>

    <div class="form-box">

        @if(session('success'))
            <p class="status ok">{{ session('success') }}</p>
        @endif

        @if(session('warning'))
            <p class="status warn">{{ session('warning') }}</p>
        @endif

        <form method="POST" action="{{ route('users.extencions.update', $user->id) }}" id="form-extencions">
            @csrf

            @if($sin_parametro)
                <input type="hidden" name="volver_a_ruta_propia" value="1">
            @endif

            {{-- Lo pone en 1 el javascript cuando el usuario confirma que quiere quitar extensiones. --}}
            <input type="hidden" name="confirmar_quitar" id="confirmar_quitar" value="">

            <div class="buscador">
                <input
                    type="text"
                    id="buscador"
                    autocomplete="off"
                    placeholder="Buscar por nombre, slug o descripción..."
                >
                <div class="resultado-busqueda" id="resultado-busqueda"></div>
            </div>

            @foreach ($grupos as $modulo => $del_modulo)
                <section class="modulo" data-modulo="{{ $modulo }}">
                    <h2>
                        {{ $modulo }}
                        <span class="cuenta">({{ $del_modulo->count() }})</span>
                    </h2>

                    @foreach ($del_modulo as $extencion)
                        @include('user.extencions.partials.item', ['extencion' => $extencion, 'user_extencion_ids' => $user_extencion_ids])
                    @endforeach
                </section>
            @endforeach

            @if($en_desuso->count() > 0)
                <details class="desuso" id="detalle-desuso">
                    <summary>En desuso ({{ $en_desuso->count() }})</summary>
                    <p class="aclaracion">
                        Ningún código del sistema las lee: encenderlas o apagarlas no cambia nada.
                        Se muestran porque las asignaciones de cada comercio siguen existiendo —
                        marcar en desuso no es borrar.
                    </p>
                    <div class="desuso-cuerpo">
                        @foreach ($en_desuso as $extencion)
                            @include('user.extencions.partials.item', ['extencion' => $extencion, 'user_extencion_ids' => $user_extencion_ids])
                        @endforeach
                    </div>
                </details>
            @endif

            <p class="sin-resultados" id="sin-resultados">Ninguna extensión coincide con la búsqueda.</p>

            <div class="barra">
                <div class="barra-interna">
                    <span class="contador" id="contador"></span>
                    <button type="submit" class="guardar">Guardar extenciones</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        var form       = document.getElementById('form-extencions');
        var buscador   = document.getElementById('buscador');
        var resultado  = document.getElementById('resultado-busqueda');
        var contador   = document.getElementById('contador');
        var sinResult  = document.getElementById('sin-resultados');
        var detalle    = document.getElementById('detalle-desuso');
        var items      = Array.prototype.slice.call(document.querySelectorAll('.ext'));
        var total      = items.length;

        /* Sin acentos y en minúscula, para que "produccion" encuentre "Producción". */
        function normalizar(texto) {
            return texto.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        /* El índice se arma una vez: buscar sobre el DOM en cada tecla con 91 items se nota. */
        items.forEach(function (item) {
            item.indiceBusqueda = normalizar(item.getAttribute('data-buscar') || '');
        });

        function actualizarContador() {
            var encendidas = form.querySelectorAll('input[name="extencions[]"]:checked').length;
            contador.textContent = encendidas + ' de ' + total + ' encendidas';
        }

        function filtrar() {
            var texto = normalizar(buscador.value.trim());
            var visibles = 0;

            items.forEach(function (item) {
                var coincide = texto === '' || item.indiceBusqueda.indexOf(texto) !== -1;
                item.style.display = coincide ? '' : 'none';
                if (coincide) {
                    visibles++;
                }
            });

            /*
             * Un módulo sin ninguna coincidencia no deja el encabezado colgado, y el que sí
             * tiene muestra cuántas coinciden: con el filtro puesto, un "PRECIOS (9)" arriba de
             * dos filas dice algo que no es cierto.
             */
            Array.prototype.forEach.call(document.querySelectorAll('.modulo'), function (seccion) {
                var deLaSeccion = seccion.querySelectorAll('.ext');
                var visiblesAca = Array.prototype.filter.call(deLaSeccion, function (item) {
                    return item.style.display !== 'none';
                }).length;

                seccion.style.display = visiblesAca > 0 ? '' : 'none';

                var cuenta = seccion.querySelector('h2 .cuenta');
                if (cuenta) {
                    cuenta.textContent = (texto === '' || visiblesAca === deLaSeccion.length)
                        ? '(' + deLaSeccion.length + ')'
                        : '(' + visiblesAca + ' de ' + deLaSeccion.length + ')';
                }
            });

            if (detalle) {
                var enDesusoVisibles = Array.prototype.filter.call(
                    detalle.querySelectorAll('.ext'),
                    function (item) { return item.style.display !== 'none'; }
                ).length;

                detalle.style.display = (texto !== '' && enDesusoVisibles === 0) ? 'none' : '';

                /* Si lo buscado solo está entre las en desuso, se abre solo: si no, parece que no hay nada. */
                if (texto !== '' && enDesusoVisibles > 0) {
                    detalle.open = true;
                }
            }

            if (texto === '') {
                resultado.textContent = '';
                sinResult.style.display = 'none';
                return;
            }

            resultado.textContent = visibles + (visibles === 1 ? ' extensión coincide' : ' extensiones coinciden');
            sinResult.style.display = visibles === 0 ? 'block' : 'none';
        }

        buscador.addEventListener('input', filtrar);

        form.addEventListener('change', function (evento) {
            if (evento.target && evento.target.name === 'extencions[]') {
                actualizarContador();
            }
        });

        /* "ver más" de cada descripción larga. */
        Array.prototype.forEach.call(document.querySelectorAll('.ver-mas'), function (boton) {
            boton.addEventListener('click', function (evento) {
                evento.preventDefault();
                evento.stopPropagation();
                var desc = document.getElementById(boton.getAttribute('data-para'));
                var recortada = desc.classList.contains('recortada');
                desc.classList.toggle('recortada');
                boton.textContent = recortada ? 'ver menos' : 'ver más';
            });
        });

        /*
         * La red de seguridad del lado del navegador. La de verdad está en el controlador, que
         * sin `confirmar_quitar` no guarda: esto es para que el aviso llegue antes del guardado
         * y no después.
         */
        form.addEventListener('submit', function (evento) {
            var quitadas = items.filter(function (item) {
                var check = item.querySelector('input[name="extencions[]"]');
                return check.getAttribute('data-original') === '1' && !check.checked;
            });

            if (quitadas.length === 0) {
                return;
            }

            var nombres = quitadas.slice(0, 5).map(function (item) {
                return '- ' + item.querySelector('.ext-nombre').textContent.trim();
            }).join('\n');

            if (quitadas.length > 5) {
                nombres += '\n- ...y ' + (quitadas.length - 5) + ' más';
            }

            var mensaje = 'Vas a QUITARLE ' + quitadas.length + (quitadas.length === 1 ? ' extensión' : ' extensiones')
                + ' a ' + @json($user->name) + ':\n\n' + nombres + '\n\n¿Confirmás?';

            if (window.confirm(mensaje)) {
                document.getElementById('confirmar_quitar').value = '1';
                return;
            }

            evento.preventDefault();
        });

        actualizarContador();
    })();
</script>
</body>
</html>
