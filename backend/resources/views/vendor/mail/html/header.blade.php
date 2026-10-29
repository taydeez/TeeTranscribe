@props(['url'])
<tr>
<td class="header">
<a href="{{ rtrim((string) config('app.frontend_url'), '/') }}" style="display: inline-block;">
{!! $slot !!}
</a>
</td>
</tr>
