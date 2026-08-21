@php
    $profile = array_slice($document['header'], 8);
@endphp
<table class="business-form-table business-generic-details"><tbody>
@foreach(array_chunk($profile, 2) as $pair)
    <tr>
        @foreach([0, 1] as $index)
            @php($item = $pair[$index] ?? ['', ''])
            <td>{{ $item[0] }}</td><td>{{ filled($item[1]) ? $item[1] : '—' }}</td>
        @endforeach
    </tr>
@endforeach
</tbody></table>
@foreach($document['sections'] as $section)
    <table class="business-form-table"><tbody><tr class="business-section-bar"><th>{{ $section['title'] }}</th></tr></tbody></table>
    @if($section['kind'] === 'narrative')
        <div class="business-remarks-box">{{ $section['text'] }}</div>
    @elseif($section['kind'] === 'details')
        <table class="business-form-table business-generic-details"><tbody>
            @foreach($section['rows'] as $row)
                <tr><td>{{ $row[0] }}</td><td colspan="3">{{ $row[1] }}</td></tr>
            @endforeach
        </tbody></table>
    @else
        <table class="business-form-table business-generic-table">
            <thead><tr>@foreach($section['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse($section['rows'] as $row)
                    <tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>
                @empty
                    <tr><td colspan="{{ count($section['columns']) }}">No saved entries.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
@endforeach
