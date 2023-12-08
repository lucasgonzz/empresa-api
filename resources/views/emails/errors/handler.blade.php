@component('mail::message')
# Error con la empresa: {{ $owner_user->company_name }}.

Usuario: {{ $auth_user->name }}. 
Dni {{ $auth_user->doc_number }}.

# Error:

@component('mail::panel')
{{ $error }}
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
