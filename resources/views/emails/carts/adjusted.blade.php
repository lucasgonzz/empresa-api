<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{{ $is_restored ? 'Volvió el stock' : 'Actualizamos tu carrito' }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; -webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f5;">
	<tr>
		<td align="center" style="padding:32px 16px;">

			<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:14px; overflow:hidden;">

				<!-- Logo -->
				<tr>
					<td align="center" style="padding:32px 32px 8px 32px;">
						@if (!is_null($logo))
							<img src="{{ $message->embedData($logo['bytes'], $logo['name'], $logo['mime']) }}" alt="{{ $company_name }}" style="max-height:52px; max-width:220px; display:block; border:0;">
						@else
							<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:20px; font-weight:600; color:#111827;">{{ $company_name }}</div>
						@endif
					</td>
				</tr>

				<!-- Titulo y explicacion -->
				<tr>
					<td style="padding:16px 32px 0 32px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
						@if ($is_restored)
							<h1 style="margin:0; font-size:24px; line-height:1.3; font-weight:600; color:#111827; letter-spacing:-0.02em;">Volvió el stock</h1>
							<p style="margin:8px 0 0 0; font-size:15px; line-height:1.6; color:#6b7280;">
								@if (!empty($buyer_name))Hola {{ $buyer_name }}. @endif
								Volvió a haber stock de {{ $article_name }}: tu carrito vuelve a tener {{ $new_text }} (de las {{ $requested_number }} que habías agregado).
							</p>
						@else
							<h1 style="margin:0; font-size:24px; line-height:1.3; font-weight:600; color:#111827; letter-spacing:-0.02em;">Actualizamos tu carrito</h1>
							<p style="margin:8px 0 0 0; font-size:15px; line-height:1.6; color:#6b7280;">
								@if (!empty($buyer_name))Hola {{ $buyer_name }}. @endif
								Ya no tenemos stock suficiente de {{ $article_name }}. Agregaste {{ $requested_text }} y ahora tu carrito tiene {{ $new_number }}.
							</p>
							<p style="margin:12px 0 0 0; font-size:15px; line-height:1.6; color:#6b7280;">
								Si dejaste marcada la opción «Completar mi carrito cuando vuelva a haber stock», te avisamos cuando lo repongamos.
							</p>
						@endif
					</td>
				</tr>

				<!-- Card del articulo -->
				<tr>
					<td style="padding:24px 32px 0 32px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
						<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
							<tr>
								@if (!is_null($image))
								<td width="112" valign="top" style="padding:16px 0 16px 16px;">
									<img src="{{ $message->embedData($image['bytes'], 'article.png', $image['mime']) }}" alt="{{ $article_name }}" width="96" height="96" style="width:96px; height:96px; object-fit:cover; border-radius:10px; border:1px solid #f3f4f6; display:block;">
								</td>
								@endif
								<td valign="middle" style="padding:16px;">
									<div style="font-size:16px; line-height:1.4; font-weight:600; color:#111827;">{{ $article_name }}</div>
									@if (!is_null($variant_name) && $variant_name !== '')
									<div style="margin-top:4px; font-size:14px; color:#6b7280;">{{ $variant_name }}</div>
									@endif
									<div style="margin-top:6px; font-size:14px; color:#6b7280;">En tu carrito: {{ $new_text }}</div>
								</td>
							</tr>
						</table>
					</td>
				</tr>

				<!-- CTA -->
				<tr>
					<td align="center" style="padding:28px 32px 36px 32px;">
						@if (!is_null($cart_url))
							<a href="{{ $cart_url }}" style="display:inline-block; padding:13px 28px; background-color:{{ $accent_color }}; color:{{ $cta_text_color }}; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:15px; font-weight:600; text-decoration:none; border-radius:8px;">Ver mi carrito</a>
						@endif
					</td>
				</tr>

			</table>

			<!-- Footer -->
			<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%;">
				<tr>
					<td align="center" style="padding:20px 16px 0 16px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#9ca3af;">
						{{ $company_name }} &middot; {{ date('Y') }}<br>
						Enviado automaticamente por ComercioCity
					</td>
				</tr>
			</table>

		</td>
	</tr>
</table>
</body>
</html>
