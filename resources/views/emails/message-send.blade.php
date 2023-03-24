@component('mail::layout')

@slot('header')
@component('mail::header', ['url' => $commerce->online])
<img src="{{ $logo_url }}" class="logo" alt="Logo">
@endcomponent
@endslot
<p>
	{{ $message }}
</p>

@slot('footer')
@component('mail::footer')
© {{ date('Y') }} ComercioCity
@endcomponent
@endslot

@endcomponent
