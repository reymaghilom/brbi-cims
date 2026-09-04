@props(['row'])
<span @class(['inline-flex rounded-full px-2.5 py-1 text-xs font-bold', 'bg-progress-soft text-progress' => $row->statusValue === 'pending', 'bg-brand-soft text-brand-primary' => $row->statusValue === 'scheduled', 'bg-[#fff0e7] text-[#c85b12]' => $row->statusValue === 'follow_up', 'bg-success-soft text-success' => $row->statusValue === 'completed'])>{{ $row->statusLabel }}</span>
@if($row->isOverdue)
    <span class="mt-1 block text-xs font-semibold text-danger">Overdue by {{ $row->overdueDays }}d</span>
@endif
