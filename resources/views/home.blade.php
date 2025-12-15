@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-primary">
        <div class="card-header">
            <h3 class="card-title">歡迎回來</h3>
        </div>
        <div class="card-body">
            <p class="mb-3">請從左側選單進入對應功能，或使用上方搜尋前往目標頁面。</p>
            <ul class="mb-0">
                <li>若需要管理帳號，請前往「用戶管理」或「管理工具」。</li>
                <li>資料查詢可使用「檢視表」或「代碼表」。</li>
                <li>最近操作紀錄可在「操作紀錄」中查看與復原。</li>
            </ul>
        </div>
    </div>
@endsection
