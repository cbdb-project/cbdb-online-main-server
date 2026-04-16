<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand -->
    <div class="brand-link d-flex justify-content-center">
        <span class="brand-text font-weight-light text-center w-100">{{ config('app.name', 'CBDB') }}</span>
    </div>

    <!-- Sidebar -->
    <div class="sidebar">
        <!-- Sidebar Menu -->
        @php
            $hasPendingProposals = false;
            if (Auth::check() && Auth::user()->canManageUsers()) {
                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('operations')) {
                        $hasPendingProposals = \App\Models\Operation::where('crowdsourcing_status', 0)
                            ->whereIn('op_type', [
                                \App\Models\Operation::TYPE_PROPOSAL_CREATE,
                                \App\Models\Operation::TYPE_PROPOSAL_UPDATE,
                            ])
                            ->where('resource_data', 'like', '%"__review_status":"pending"%')
                            ->exists();
                    }
                } catch (\Throwable $e) {
                    $hasPendingProposals = false;
                }
            }
            $activePage = $page_title_key ?? ($page_title ?? '');
        @endphp

        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="true">
                <!-- Add icons to the links using the .nav-icon class
                     with font-awesome or any other icon font library -->
                <li class="nav-item">
                    <a href="{{ route('dashboard') }}" class="nav-link {{ $activePage == '系統總覽' ? 'active' : '' }}">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>系統總覽</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('basicinformation.index') }}" class="nav-link {{ $activePage == 'Basicinformation' ? 'active' : '' }}">
                        <i class="nav-icon fas fa-landmark"></i>
                        <p>人物編輯</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('app.person-browser.index') }}" class="nav-link {{ request()->routeIs('app.person-browser.*') ? 'active' : '' }}">
                        <i class="nav-icon fas fa-user-friends"></i>
                        <p>人物瀏覽</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('operations.index') }}" class="nav-link {{ $activePage == 'NewUpdate' ? 'active' : '' }}">
                        <i class="nav-icon fas fa-clipboard-list"></i>
                        <p>最近操作記錄</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('operations.index', ['proposals_only' => 1]) }}" class="nav-link {{ $activePage == 'OperationsProposals' ? 'active' : '' }}">
                        <i class="nav-icon fas fa-clipboard-check"></i>
                        <p>
                            最近提案列表
                            @if($hasPendingProposals)
                                <span class="badge badge-warning float-right">待審核</span>
                            @endif
                        </p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('crowdsourcing.index') }}" class="nav-link {{ $activePage == 'Crowdsourcing' ? 'active' : '' }}">
                        <i class="nav-icon fas fa-users-cog"></i>
                        <p>最近眾包錄入記錄</p>
                    </a>
                </li>

                @php
                    $codesPages = [
                        'Codes',
                        '全部表格',
                        'ALTNAME_CODES',
                        'APPOINTMENT_CODES',
                        'TEXT_CODES',
                        'ADDR_CODES',
                        'OFFICE_CODES',
                        'SOCIAL_INSTITUTION_CODES',
                        'ADDR_BELONGS_DATA',
                        'TEXT_INSTANCE_DATA',
                    ];
                    $codesMenuOpen = in_array($activePage, $codesPages, true);
                @endphp
                <li class="nav-item {{ $codesMenuOpen ? 'menu-open' : '' }}">
                    <a href="/codes" class="nav-link {{ $codesMenuOpen ? 'active' : '' }}">
                        <i class="nav-icon fa fa-database"></i>
                        <p>
                            全部表格
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/codes" class="nav-link {{ in_array($activePage, ['Codes', '全部表格'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-th-list"></i>
                                <p>全部表格首頁</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/ADDR_BELONGS_DATA" class="nav-link {{ in_array($activePage, ['ADDR_BELONGS_DATA'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-sitemap"></i>
                                <p>地址從屬表 <small>(ADDR_BELONGS_DATA)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/ADDR_CODES" class="nav-link {{ in_array($activePage, ['ADDR_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map-marker-alt"></i>
                                <p>地址編碼表 <small>(ADDR_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/ALTNAME_CODES" class="nav-link {{ in_array($activePage, ['ALTNAME_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-user-tag"></i>
                                <p>別名編碼表 <small>(ALTNAME_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/APPOINTMENT_CODES" class="nav-link {{ in_array($activePage, ['APPOINTMENT_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-briefcase"></i>
                                <p>任命類型編碼表 <small>(APPOINTMENT_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/OFFICE_CODES" class="nav-link {{ in_array($activePage, ['OFFICE_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-id-badge"></i>
                                <p>任官編碼表 <small>(OFFICE_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/SOCIAL_INSTITUTION_CODES" class="nav-link {{ in_array($activePage, ['SOCIAL_INSTITUTION_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-university"></i>
                                <p>社會機構編碼表 <small>(SOCIAL_INSTITUTION_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/TEXT_CODES" class="nav-link {{ in_array($activePage, ['TEXT_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-book"></i>
                                <p>著作編碼表 <small>(TEXT_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/TEXT_INSTANCE_DATA" class="nav-link {{ in_array($activePage, ['TEXT_INSTANCE_DATA'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-book-open"></i>
                                <p>著作版本表 <small>(TEXT_INSTANCE_DATA)</small></p>
                            </a>
                        </li>
                    </ul>
                </li>

                @php
                    $viewPages = [
                        '檢視表總覽',
                        '地址層級檢視',
                        '別名資料檢視',
                        '社會關係資料檢視',
                        '人物地址資料檢視',
                        '人物/社會機構/地址資料檢視',
                        '人物社會機構資料檢視',
                        '人物來源資料檢視',
                        '人物著作資料檢視',
                        '人物入仕資料檢視',
                        '人物事件地址檢視',
                        '人物事件資料檢視',
                        '人物親屬資料檢視',
                        '人物基本資料檢視',
                        '人物索引地址檢視',
                        '人物財產地址檢視',
                        '人物財產資料檢視',
                        '任官地址資料檢視',
                        '任官職務資料檢視',
                        '人物身份資料檢視',
                    ];
                    $viewsMenuOpen = in_array($activePage, $viewPages, true);
                @endphp
                <li class="nav-item {{ $viewsMenuOpen ? 'menu-open' : '' }}">
                    <a href="{{ route('view.index') }}" class="nav-link {{ $viewsMenuOpen ? 'active' : '' }}">
                        <i class="nav-icon fa fa-th-list"></i>
                        <p>
                            檢視表
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="{{ route('app.view.index') }}" class="nav-link">
                                <i class="nav-icon fas fa-layer-group"></i>
                                <p>檢視表總覽（新版）</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.index') }}" class="nav-link {{ $activePage == '檢視表總覽' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-list-ul"></i>
                                <p>檢視表總覽</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'altname-data') }}" class="nav-link {{ $activePage == '別名資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-user-tag"></i>
                                <p>別名資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'assoc-data') }}" class="nav-link {{ $activePage == '社會關係資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-project-diagram"></i>
                                <p>社會關係資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'biog-addr-data') }}" class="nav-link {{ $activePage == '人物地址資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map-marked-alt"></i>
                                <p>人物地址資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'biog-inst-addr-data') }}" class="nav-link {{ $activePage == '人物/社會機構/地址資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-network-wired"></i>
                                <p>人物/社會機構/地址</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'biog-inst-data') }}" class="nav-link {{ $activePage == '人物社會機構資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-people-arrows"></i>
                                <p>人物社會機構資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'biog-source-data') }}" class="nav-link {{ $activePage == '人物來源資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-bookmark"></i>
                                <p>人物來源資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'biog-text-data') }}" class="nav-link {{ $activePage == '人物著作資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-book-reader"></i>
                                <p>人物著作資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'entry-data') }}" class="nav-link {{ $activePage == '人物入仕資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-user-graduate"></i>
                                <p>人物入仕資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'event-addr-data') }}" class="nav-link {{ $activePage == '人物事件地址檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map"></i>
                                <p>人物事件地址檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'events-data') }}" class="nav-link {{ $activePage == '人物事件資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-history"></i>
                                <p>人物事件資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'kin-addr-data') }}" class="nav-link {{ $activePage == '人物親屬資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-users"></i>
                                <p>人物親屬資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'people-data') }}" class="nav-link {{ $activePage == '人物基本資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-id-card"></i>
                                <p>人物基本資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'people-addr-data') }}" class="nav-link {{ $activePage == '人物索引地址檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map-pin"></i>
                                <p>人物索引地址檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'posessions-addr-data') }}" class="nav-link {{ $activePage == '人物財產地址檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-coins"></i>
                                <p>人物財產地址檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'posessions-data') }}" class="nav-link {{ $activePage == '人物財產資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-piggy-bank"></i>
                                <p>人物財產資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'posting-addr-data') }}" class="nav-link {{ $activePage == '任官地址資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map-signs"></i>
                                <p>任官地址資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'posting-office-data') }}" class="nav-link {{ $activePage == '任官職務資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-briefcase"></i>
                                <p>任官職務資料檢視</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'status-data') }}" class="nav-link {{ $activePage == '人物身份資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-id-card-alt"></i>
                                <p>人物身份資料檢視</p>
                            </a>
                        </li>
                    </ul>
                </li>

                @if(Auth::check() and Auth::user()->isActive())
                    @php
                        $expertPages = [
                            'Query Playground',
                        ];
                        $expertMenuOpen = in_array($activePage, $expertPages, true) || request()->routeIs('app.query-playground.*');
                    @endphp
                    <li class="nav-item {{ $expertMenuOpen ? 'menu-open' : '' }}">
                        <a href="{{ route('app.query-playground.index') }}" class="nav-link {{ $expertMenuOpen ? 'active' : '' }}">
                            <i class="nav-icon fas fa-flask"></i>
                            <p>
                                專家工具
                                <i class="right fas fa-angle-left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ route('app.query-playground.index') }}" class="nav-link {{ $activePage == 'Query Playground' || request()->routeIs('app.query-playground.*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-terminal"></i>
                                    <p>SQL 查詢練習場</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                @endif

                @if(Auth::check() and Auth::user()->isSuperAdmin())
                    @php
                        $adminPages = [
                            '用戶管理',
                            'NL Query Logs',
                            'AI 填充日誌',
                            '審計日誌',
                            'SQL 執行計畫',
                            '批次匯入書稿資料',
                            '批次匯入官職',
                            '批次匯入社會機構',
                            'Wiki 對照資料維護',
                            'CBDB 內部表維護',
                            '單向關係修復',
                            'MergePreview',
                        ];
                        $adminMenuOpen = in_array($activePage, $adminPages, true);
                    @endphp
                    <li class="nav-item {{ $adminMenuOpen ? 'menu-open' : '' }}">
                        <a href="{{ route('manage.index') }}" class="nav-link {{ $adminMenuOpen ? 'active' : '' }}">
                            <i class="nav-icon fas fa-tools"></i>
                            <p>
                                管理員工具
                                <i class="right fas fa-angle-left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ route('manage.index') }}" class="nav-link {{ in_array($activePage, ['用戶管理'], true) ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-user-cog"></i>
                                    <p>用戶管理</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('query-playground.nl-query-logs') }}" class="nav-link {{ $activePage == 'NL Query Logs' ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-comments"></i>
                                    <p>自然語言查詢日誌</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.ai-fill-logs') }}" class="nav-link {{ $activePage == 'AI 填充日誌' ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-robot"></i>
                                    <p>AI 填充日誌</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.audit-logs') }}" class="nav-link {{ $activePage == '審計日誌' ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-clipboard-check"></i>
                                    <p>審計日誌</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.explainsql') }}" class="nav-link {{ $activePage == 'SQL 執行計畫' ? 'active' : '' }}">
                                    <i class="nav-icon fa fa-search"></i>
                                    <p>SQL EXPLAIN</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.batch-load-book-titles') }}" class="nav-link {{ $activePage == '批次匯入書稿資料' ? 'active' : '' }}">
                                    <i class="nav-icon fa fa-upload"></i>
                                    <p>批次匯入書稿</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.batch-load-offices') }}" class="nav-link {{ $activePage == '批次匯入官職' ? 'active' : '' }}">
                                    <i class="nav-icon fa fa-briefcase"></i>
                                    <p>批次匯入官職</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.batch-load-social-institutes') }}" class="nav-link {{ $activePage == '批次匯入社會機構' ? 'active' : '' }}">
                                    <i class="nav-icon fa fa-university"></i>
                                    <p>批次匯入社會機構</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.wiki-maintenance') }}" class="nav-link {{ $activePage == 'Wiki 對照資料維護' ? 'active' : '' }}">
                                    <i class="nav-icon fab fa-wikipedia-w"></i>
                                    <p>Wiki 對照資料維護</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.cbdb-table-maintenance') }}" class="nav-link {{ $activePage == 'CBDB 內部表維護' ? 'active' : '' }}">
                                    <i class="nav-icon fa fa-database"></i>
                                    <p>CBDB 內部表維護</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.unidirectional-relationship-repair') }}" class="nav-link {{ $activePage == '單向關係修復' ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-exchange-alt"></i>
                                    <p>單向關係修復</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('merge-preview.index') }}" class="nav-link {{ $activePage == 'MergePreview' ? 'active' : '' }}">
                                    <i class="nav-icon ion ion-shuffle"></i>
                                    <p>人物記錄合併</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                @endif
            </ul>
        </nav>
        <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
</aside>
