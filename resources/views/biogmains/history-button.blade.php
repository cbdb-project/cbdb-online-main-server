@php
    use App\Support\BasicInformationHistory;

    $currentRoute = request()->route()->getName();
    $historyConfig = BasicInformationHistory::resolveFromRoute($currentRoute);
    $historyUrl = null;

    if (
        $historyConfig !== null
        && !empty($basicinformation->c_personid)
        && !empty($historyConfig['tables'])
        && Auth::check()
        && Auth::user()->canViewAuditLogs()
    ) {
        $historyUrl = route('admin.audit-logs', [
            'c_personid' => $basicinformation->c_personid,
            'history_page' => $historyConfig['page'],
        ], false);
    }
@endphp

@if($historyUrl)
    @push('page-bottom-actions')
        <a href="{{ $historyUrl }}" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
            <i class="fas fa-history" aria-hidden="true"></i> 查看本頁歷史記錄
        </a>
    @endpush
@endif
