@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- The logo travels inside the email (see EmbedMailLogo), so it shows even when the app URL is not public. --}}
<img src="cid:{{ \App\Listeners\EmbedMailLogo::CONTENT_ID }}" class="logo" width="180" alt="{{ $slot }}">
</a>
</td>
</tr>
