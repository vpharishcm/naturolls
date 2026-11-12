<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1E1B2E; }
    h1 { font-size: 18px; margin: 0; color: #B3164A; }
    p { margin: 2px 0 12px; color: #5B4B63; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #FFF7EC; text-align: left; border-bottom: 2px solid #B3164A; padding: 5px; }
    td { border-bottom: 1px solid #EFE4D4; padding: 5px; }
</style>
</head>
<body>
<h1>{{ $report['title'] }}</h1>
<p>Naturolls · {{ $report['from'] }} to {{ $report['to'] }}</p>
<table>
    <thead><tr>@foreach ($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse ($rows as $row)
        <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($headers) }}">No data for this period.</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
