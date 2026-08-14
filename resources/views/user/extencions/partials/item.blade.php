@php
    $marcada     = in_array($extencion->id, $user_extencion_ids);
    $descripcion = trim((string) $extencion->description);

    /*
     * Las descripciones de la misión 53 van de 367 a 2470 caracteres: mostradas enteras las 91
     * a la vez, el formulario vuelve a ser el muro de texto que este cambio viene a evitar. Se
     * recortan a tres líneas con un "ver más" — el buscador igual busca sobre el texto completo.
     */
    $larga = mb_strlen($descripcion) > 220;
@endphp

<div class="ext" data-buscar="{{ $extencion->name . ' ' . $extencion->slug . ' ' . $descripcion }}">
    <input
        type="checkbox"
        name="extencions[]"
        id="ext-{{ $extencion->id }}"
        value="{{ $extencion->id }}"
        data-original="{{ $marcada ? '1' : '0' }}"
        {{ $marcada ? 'checked' : '' }}
    >
    <div class="ext-cuerpo">
        <label class="ext-nombre" for="ext-{{ $extencion->id }}">{{ $extencion->name }}</label>
        <div class="ext-slug">{{ $extencion->slug }}</div>

        @if($descripcion !== '')
            <div class="ext-desc {{ $larga ? 'recortada' : '' }}" id="desc-{{ $extencion->id }}">{{ $descripcion }}</div>
            @if($larga)
                <button type="button" class="ver-mas" data-para="desc-{{ $extencion->id }}">ver más</button>
            @endif
        @else
            <div class="ext-sin-desc">Sin descripción cargada.</div>
        @endif
    </div>
</div>
