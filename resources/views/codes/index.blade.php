@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('nav.all_tables') }}</h3>
            <div class="card-tools">
                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                </button>
            </div>
        </div>
        <div class="card-body">
            {{-- 即時搜尋 --}}
            <div style="margin-bottom: 12px;">
                <input type="text"
                       id="table-search"
                       class="form-control form-control-sm"
                       placeholder="{{ __('codes.search_tables') }}"
                       style="max-width: 400px;"
                       autocomplete="off">
            </div>
            <div class="table-responsive p-0">
                <table class="table table-hover table-sm" id="codes-index-table">
                    <thead>
                    <tr>
                        <th data-col="0" style="cursor: pointer; user-select: none;">
                            {{ __('codes.table_name') }}
                            <span class="sort-icon" aria-hidden="true">⇅</span>
                        </th>
                        <th data-col="1" style="cursor: pointer; user-select: none;">
                            {{ __('codes.description') }}
                            <span class="sort-icon" aria-hidden="true">⇅</span>
                        </th>
                    </tr>
                    </thead>
                    <tbody id="codes-index-body">
                    @foreach($data as $item)
                        <tr>
                            <td><a href="/codes/{{ $item['name'] }}">{{ $item['name'] }}</a></td>
                            <td>{{ $item['description'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p id="no-match-msg" style="display:none; color:#999; padding: 8px 0;">
                {{ __('common.no_data') }}
            </p>
        </div>
    </div>
@endsection

@section('js')
<script>
// 必須等 Vue 掛載（app.mount('#app')）後再綁定事件：
// Vue 會把 #app 內的伺服器渲染節點整批重新建立，掛載前用 addEventListener
// 綁定的監聽器會隨舊節點被丟棄，導致搜尋／排序失效。onViteReady 的回呼在
// app.mount('#app') 之後才執行，可確保監聽器綁在最終節點上。
onViteReady(function () {
    const tbody       = document.getElementById('codes-index-body');
    const searchInput = document.getElementById('table-search');
    const noMatchMsg  = document.getElementById('no-match-msg');
    const headers     = document.querySelectorAll('#codes-index-table thead th[data-col]');

    const allRows      = Array.from(tbody.querySelectorAll('tr'));
    const originalOrder = [...allRows];

    let sortState = { col: null, dir: 'asc' };

    function updateIcons() {
        headers.forEach(th => {
            const icon = th.querySelector('.sort-icon');
            const col  = parseInt(th.dataset.col, 10);
            if (sortState.col === col) {
                icon.textContent = sortState.dir === 'asc' ? '▲' : '▼';
            } else {
                icon.textContent = '⇅';
            }
        });
    }

    function applyFilter() {
        const q = searchInput.value.toLowerCase();
        let visibleCount = 0;
        allRows.forEach(row => {
            const match = !q || row.textContent.toLowerCase().includes(q);
            row.style.display = match ? '' : 'none';
            if (match) visibleCount++;
        });
        noMatchMsg.style.display = visibleCount === 0 ? '' : 'none';
    }

    function applySortAndFilter() {
        if (sortState.col === null) {
            originalOrder.forEach(r => tbody.appendChild(r));
        } else {
            allRows.length = 0;
            originalOrder.forEach(r => allRows.push(r));

            const colIdx = sortState.col;
            allRows.sort((a, b) => {
                const aText = (a.cells[colIdx]?.textContent ?? '').trim().toLowerCase();
                const bText = (b.cells[colIdx]?.textContent ?? '').trim().toLowerCase();
                return sortState.dir === 'asc'
                    ? aText.localeCompare(bText)
                    : bText.localeCompare(aText);
            });
            allRows.forEach(r => tbody.appendChild(r));
        }
        applyFilter();
        updateIcons();
    }

    headers.forEach(th => {
        th.addEventListener('click', function () {
            const col = parseInt(this.dataset.col, 10);
            if (sortState.col !== col) {
                sortState = { col, dir: 'asc' };
            } else if (sortState.dir === 'asc') {
                sortState.dir = 'desc';
            } else {
                sortState = { col: null, dir: 'asc' };
            }
            applySortAndFilter();
        });
    });

    searchInput.addEventListener('input', applyFilter);

    applyFilter();
});
</script>
@endsection
