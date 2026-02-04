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
                    <th>欄位</th>
                    <th>AI 匹配結果</th>
                    <th>用戶提交</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php
                        $matches = !empty($row['matches']);
                        $aiType = $row['ai_type'] ?? 'empty';
                        // AI 欄位背景色：匹配(綠)、建議(黃)、空(無)
                        $aiTypeClass = match($aiType) {
                            'matched' => 'ai-diff-matched',
                            'suggested' => 'ai-diff-suggested',
                            default => '',
                        };
                        // 比較結果：相同→綠色，不同→警告色
                        $matchClass = $matches ? 'table-success' : 'table-warning';
                    @endphp
                    <tr>
                        <td>
                            {{ $row['field'] }}
                            @if($aiType === 'matched')
                                <span class="badge badge-success badge-sm" title="AI 確認匹配">匹配</span>
                            @elseif($aiType === 'suggested')
                                <span class="badge badge-warning badge-sm" title="AI 建議">建議</span>
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
    <p class="text-muted small">沒有比對紀錄</p>
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
