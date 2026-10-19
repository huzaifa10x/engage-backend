@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
{{-- PNG (mail clients do not show SVG), served from the app domain. The alt text stands in when images are blocked. --}}
<img src="{{ rtrim((string) config('app.url'), '/') }}/brand/engage-logo-email.png" class="brand-logo" width="150" height="37" alt="10X Engage">
</a>
</td>
</tr>
