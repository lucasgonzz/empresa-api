@php
    $accent = config('commerciocity.header_background', '#0ea5e9');
@endphp

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="padding-bottom:8px;">
            <h1 style="margin:0;font-size:22px;line-height:1.35;font-weight:700;color:#111827;">{{ $payload->title }}</h1>
        </td>
    </tr>
    @foreach($payload->paragraphs as $paragraph)
    <tr>
        <td style="padding-top:16px;">
            <p style="margin:0;font-size:15px;line-height:1.6;color:#374151;">{{ $paragraph }}</p>
        </td>
    </tr>
    @endforeach
    @if(count($payload->detail_lines) > 0)
    <tr>
        <td style="padding-top:20px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                @foreach($payload->detail_lines as $line)
                @php
                    $label = $line['label'] ?? '';
                    $value = $line['value'] ?? '';
                    $boldLabel = array_key_exists('bold_label', $line) ? (bool) $line['bold_label'] : true;
                @endphp
                <tr>
                    <td style="padding:6px 0;font-size:15px;line-height:1.55;color:#374151;">
                        <span style="color:#9ca3af;">- </span>
                        @if($boldLabel)
                            <strong style="color:#111827;">{{ $label }}:</strong>
                        @else
                            <span style="color:#111827;">{{ $label }}:</span>
                        @endif
                        {{ $value }}
                    </td>
                </tr>
                @endforeach
            </table>
        </td>
    </tr>
    @endif
    @if(count($payload->links) > 0)
    <tr>
        <td style="padding-top:24px;">
            @foreach($payload->links as $link)
                @php
                    $text = $link['text'] ?? '';
                    $url = $link['url'] ?? '#';
                @endphp
                <p style="margin:8px 0 0 0;font-size:15px;line-height:1.6;">
                    <a href="{{ $url }}" style="color:{{ $accent }};text-decoration:underline;font-weight:500;">{{ $text }}</a>
                </p>
            @endforeach
        </td>
    </tr>
    @endif
    @if(!empty($payload->closing))
    <tr>
        <td style="padding-top:28px;">
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 20px 0;" />
            <p align="center" style="margin:0;font-size:15px;line-height:1.6;color:#374151;">{{ $payload->closing }}</p>
        </td>
    </tr>
    @endif
</table>
