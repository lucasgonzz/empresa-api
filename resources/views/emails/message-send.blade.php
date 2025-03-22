@component('mail::layout')

@slot('header')

@isset($commerce)

@component('mail::header', ['url' => $commerce->online])
<img src="{{ $logo_url }}" class="logo" alt="Logo">
@endcomponent

@endisset

@endslot

@isset($commerce)
<p>
	{{ $message }}
</p>
@endisset


@foreach($messages as $_message)
<p>
	{{ $_message }}
</p>
@endforeach

@slot('footer')
@component('mail::footer')
© {{ date('Y') }} ComercioCity
@endcomponent
@endslot

@endcomponent
