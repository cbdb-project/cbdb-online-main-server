@php
    $rows = $rows ?? [];
@endphp

@if (!empty($rows))
    <div class="table-responsive">
        <table class="table table-bordered table-striped table-condensed">
            <colgroup>
                <col style="width:15%;">
                <col style="width:42.5%;">
                <col style="width:42.5%;">
            </colgroup>
            <thead>
                <tr>
                    <th>{{ __('operations.diff_field') }}</th>
                    <th>{{ __('operations.ai_match_result') }}</th>
                    <th>{{ __('operations.user_submitted') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php
                        $matches = !empty($row['matches']);
                        $aiType = $row['ai_type'] ?? 'empty';
                        $aiTypeClass = match($aiType) {
                            'matched' => 'ai-diff-matched',
                            'suggested' => 'ai-diff-suggested',
                            default => '',
                        };
                        $matchClass = $matches ? 'table-success' : 'table-warning';
                    @endphp
                    <tr>
                        <td>
                            {{ $row['field'] }}
                            @if($aiType === 'matched')
                                <span class="badge badge-success badge-sm" title="{{ __('operations.ai_matched_badge_title') }}">{{ __('operations.ai_matched_badge') }}</span>
                            @elseif($aiType === 'suggested')
                                <span class="badge badge-warning badge-sm" title="{{ __('operations.ai_suggested_badge_title') }}">{{ __('operations.ai_suggested_badge') }}</span>
                            @endif
                        </td>
                        <td class="{{ $aiTypeClass }}">{{ $row['ai_value'] !== '' ? $row['ai_value'] : '-' }}</td>
                        <td class="{{ $matchClass }}">{{ $row['user_value'] !== '' ? $row['user_value'] : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p class="text-muted small">{{ __('operations.no_diff_records') }}</p>
@endif

<style>
    .ai-diff-matched {
        background-color: #d4edda !important;
    }
    .ai-diff-suggested {
        background-color: #fff3cd !important;
    }
    .badge-sm {
        font-size: 0.7em;
        padding: 2px 4px;
    }
</style>
