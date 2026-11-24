@props(['url'])
<tr>
<td class="header">
<table class="inner-header" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto; width: 100%; max-width: 600px;">
<tr>
<td style="padding: 0 28px;">
<a href="{{ $url }}" style="display: inline-block;">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
