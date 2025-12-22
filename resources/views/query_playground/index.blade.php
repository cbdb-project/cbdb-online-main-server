@extends('layouts.dashboard-v3')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-terminal mr-1"></i> {{ $page_title }}</h3>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> {{ $page_description }}。每頁限制 20 筆。
                </div>
                
                <div class="form-group">
                    <label for="sqlInput">SQL Query:</label>
                    <textarea class="form-control" id="sqlInput" rows="5" style="font-family: monospace;">{{ $initial_sql ?? 'SELECT * FROM DYNASTIES' }}</textarea>
                </div>

                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <button class="btn btn-primary" id="btnRun">
                            <i class="fas fa-play"></i> 執行查詢 (Run)
                        </button>
                        <button class="btn btn-default ml-2" id="btnShare" title="複製分享連結">
                            <i class="fas fa-share-alt"></i> 複製連結
                        </button>
                    </div>
                    <div id="loadingIndicator" style="display:none;">
                        <div class="spinner-border text-primary spinner-border-sm" role="status"></div> 查詢中...
                    </div>
                </div>
            </div>
        </div>

        <div class="card" id="resultCard" style="display: none;">
            <div class="card-header">
                <h3 class="card-title">查詢結果</h3>
                <div class="card-tools">
                    <button class="btn btn-sm btn-secondary" id="btnPrev" disabled><i class="fas fa-chevron-left"></i> 上一頁</button>
                    <span class="px-2" id="pageDisplay">Page 1</span>
                    <button class="btn btn-sm btn-secondary" id="btnNext" disabled>下一頁 <i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
            <div class="card-body table-responsive p-0">
                <table class="table table-hover text-nowrap" id="resultTable">
                    <thead>
                        <tr id="tableHead"></tr>
                    </thead>
                    <tbody id="tableBody"></tbody>
                </table>
                <div id="noResults" class="p-3 text-center text-muted" style="display:none;">無資料 (No data found)</div>
            </div>
        </div>
        
        <div class="alert alert-danger mt-3" id="errorAlert" style="display: none;"></div>
    </div>
</div>
@endsection

@section('js')
<script>
onViteReady(function() {
    let currentPage = 1;

    // Check if initial SQL provided via URL param (already populated in UI by controller), maybe auto-run?
    // User didn't strictly ask for auto-run, but it's often expected. Let's stick to manual run for safety first.

    function updateUrl(sql) {
        const url = new URL(window.location);
        url.searchParams.set('sql', sql);
        window.history.pushState({path: url.href}, '', url.href);
    }

    function runQuery(page = 1) {
        const sql = $('#sqlInput').val();
        if (!sql.trim()) return;

        // Update URL on run
        updateUrl(sql);

        $('#btnRun').prop('disabled', true);
        $('#loadingIndicator').show();
        $('#errorAlert').hide();
        $('#resultCard').hide();
        
        // Reset table
        $('#tableHead').empty();
        $('#tableBody').empty();

        $.ajax({
            url: "{{ route('query-playground.run', [], false) }}",
            method: 'POST',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                sql: sql,
                page: page
            },
            success: function(response) {
                renderResults(response);
                currentPage = page;
                updatePagination(response);
                $('#resultCard').show();
            },
            error: function(xhr) {
                let msg = '發生錯誤';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    msg = xhr.responseJSON.error;
                } else if (xhr.status === 403) {
                    msg = '禁止的查詢 (403 Forbidden)';
                }
                const errorHtml = `<strong>Error:</strong> ${msg}`;
                $('#errorAlert').html(errorHtml).show();
            },
            complete: function() {
                $('#btnRun').prop('disabled', false);
                $('#loadingIndicator').hide();
            }
        });
    }

    function renderResults(data) {
        const rows = data.data;
        const columns = data.columns;

        if (!rows || rows.length === 0) {
            $('#noResults').show();
            $('#resultTable').hide();
            return;
        }

        $('#noResults').hide();
        $('#resultTable').show();

        // Render Head
        let theadHtml = '';
        columns.forEach(col => {
            theadHtml += `<th>${col}</th>`;
        });
        $('#tableHead').html(theadHtml);

        // Render Body
        let tbodyHtml = '';
        rows.forEach(row => {
            tbodyHtml += '<tr>';
            columns.forEach(col => {
                let val = row[col];
                let displayVal = '';

                if (val === null) {
                    displayVal = '<span class="text-muted">NULL</span>';
                } else {
                    // Basic XSS protection for display
                    displayVal = $('<div>').text(val).html(); 
                }

                tbodyHtml += `<td>${displayVal}</td>`;
            });
            tbodyHtml += '</tr>';
        });
        $('#tableBody').html(tbodyHtml);
    }

    function updatePagination(data) {
        $('#pageDisplay').text(`Page ${data.page}`);
        
        $('#btnPrev').prop('disabled', data.page <= 1);
        $('#btnNext').prop('disabled', !data.has_more);
        
        // Unbind previous clicks to avoid accumulation
        $('#btnPrev').off('click').on('click', function() {
            if (currentPage > 1) runQuery(currentPage - 1);
        });
        
        $('#btnNext').off('click').on('click', function() {
            if (data.has_more) runQuery(currentPage + 1);
        });
    }

    $('#btnRun').click(function() {
        runQuery(1);
    });

    $('#btnShare').click(function() {
        const sql = $('#sqlInput').val();
         updateUrl(sql);
        
        navigator.clipboard.writeText(window.location.href).then(function() {
            // Simple visual feedback
            const originalText = $('#btnShare').html();
            $('#btnShare').html('<i class="fas fa-check"></i> 已複製');
            setTimeout(() => {
                $('#btnShare').html(originalText);
            }, 2000);
        }, function(err) {
            console.error('Could not copy text: ', err);
            alert('複製失敗，請手動複製網址列');
        });
    });
});
</script>
@endsection
