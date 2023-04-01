@component('mail::layout')

@slot('header')

@component('mail::header', ['url' => $user->online])
<img src="{{ $logo_url }}" class="logo" alt="{{ $user->company_name }} Logo">
@endcomponent

@endslot
<p>
    Hola! Queriamos avisarte que ya ingreso nuevo stock para el artículo: {{ $article->name }}.
</p>
<p>
	Puedes ingresar a ver el artículo presionando el botón de abajo.
</p>

@component('mail::button', ['url' => $article_url])
Ver artículo
@endcomponent

@slot('footer')
@component('mail::footer')
© {{ date('Y') }} ComercioCity
@endcomponent
@endslot

@endcomponent
