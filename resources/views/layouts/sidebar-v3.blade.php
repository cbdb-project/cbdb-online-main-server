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
                <li class="nav-item">
                    <a href="{{ route('dashboard') }}" class="nav-link {{ $activePage == '系統總覽' ? 'active' : '' }}">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>{{ __('nav.dashboard') }}</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('basicinformation.index') }}" class="nav-link {{ $activePage == 'Basicinformation' ? 'active' : '' }}">
                        <i class="nav-icon fas fa-landmark"></i>
                        <p>{{ __('nav.person_editing') }}</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('operations.index') }}" class="nav-link {{ $activePage == 'NewUpdate' ? 'active' : '' }}">
                        <i class="nav-icon fas fa-clipboard-list"></i>
                        <p>{{ __('nav.recent_operations') }}</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('operations.index', ['proposals_only' => 1]) }}" class="nav-link {{ $activePage == 'OperationsProposals' ? 'active' : '' }}">
                        <i class="nav-icon fas fa-clipboard-check"></i>
                        <p>
                            {{ __('nav.recent_proposals') }}
                            @if($hasPendingProposals)
                                <span class="badge badge-warning float-right">{{ __('nav.pending_review') }}</span>
                            @endif
                        </p>
                    </a>
                </li>

                @php
                    $codesPages = [
                        'Codes',
                        '全部表格',
                        'ADDRESSES',
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
                            {{ __('nav.all_tables') }}
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/codes" class="nav-link {{ in_array($activePage, ['Codes', '全部表格'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-th-list"></i>
                                <p>{{ __('nav.all_tables_home') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/ADDR_BELONGS_DATA" class="nav-link {{ in_array($activePage, ['ADDR_BELONGS_DATA'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-sitemap"></i>
                                <p>{{ __('codes.addr_belongs_data') }} <small>(ADDR_BELONGS_DATA)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/ADDR_CODES" class="nav-link {{ in_array($activePage, ['ADDR_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map-marker-alt"></i>
                                <p>{{ __('codes.addr_codes') }} <small>(ADDR_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/ADDRESSES" class="nav-link {{ in_array($activePage, ['ADDRESSES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map"></i>
                                <p>{{ __('codes.addresses') }} <small>(ADDRESSES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/ALTNAME_CODES" class="nav-link {{ in_array($activePage, ['ALTNAME_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-user-tag"></i>
                                <p>{{ __('codes.altname_codes') }} <small>(ALTNAME_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/APPOINTMENT_CODES" class="nav-link {{ in_array($activePage, ['APPOINTMENT_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-briefcase"></i>
                                <p>{{ __('codes.appointment_codes') }} <small>(APPOINTMENT_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/OFFICE_CODES" class="nav-link {{ in_array($activePage, ['OFFICE_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-id-badge"></i>
                                <p>{{ __('codes.office_codes') }} <small>(OFFICE_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/SOCIAL_INSTITUTION_CODES" class="nav-link {{ in_array($activePage, ['SOCIAL_INSTITUTION_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-university"></i>
                                <p>{{ __('codes.social_institution_codes') }} <small>(SOCIAL_INSTITUTION_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/TEXT_CODES" class="nav-link {{ in_array($activePage, ['TEXT_CODES'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-book"></i>
                                <p>{{ __('codes.text_codes') }} <small>(TEXT_CODES)</small></p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/codes/TEXT_INSTANCE_DATA" class="nav-link {{ in_array($activePage, ['TEXT_INSTANCE_DATA'], true) ? 'active' : '' }}">
                                <i class="nav-icon fas fa-book-open"></i>
                                <p>{{ __('codes.text_instance_data') }} <small>(TEXT_INSTANCE_DATA)</small></p>
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
                            {{ __('nav.views') }}
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="{{ route('app.view.index') }}" class="nav-link">
                                <i class="nav-icon fas fa-layer-group"></i>
                                <p>{{ __('nav.views_overview_new') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.index') }}" class="nav-link {{ $activePage == '檢視表總覽' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-list-ul"></i>
                                <p>{{ __('nav.views_overview') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'altname-data') }}" class="nav-link {{ $activePage == '別名資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-user-tag"></i>
                                <p>{{ __('views.view_altname_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'assoc-data') }}" class="nav-link {{ $activePage == '社會關係資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-project-diagram"></i>
                                <p>{{ __('views.view_assoc_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'biog-addr-data') }}" class="nav-link {{ $activePage == '人物地址資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map-marked-alt"></i>
                                <p>{{ __('views.view_biog_addr_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'biog-inst-addr-data') }}" class="nav-link {{ $activePage == '人物/社會機構/地址資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-network-wired"></i>
                                <p>{{ __('views.view_biog_inst_addr_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'biog-inst-data') }}" class="nav-link {{ $activePage == '人物社會機構資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-people-arrows"></i>
                                <p>{{ __('views.view_biog_inst_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'biog-source-data') }}" class="nav-link {{ $activePage == '人物來源資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-bookmark"></i>
                                <p>{{ __('views.view_biog_source_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'biog-text-data') }}" class="nav-link {{ $activePage == '人物著作資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-book-reader"></i>
                                <p>{{ __('views.view_biog_text_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'entry-data') }}" class="nav-link {{ $activePage == '人物入仕資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-user-graduate"></i>
                                <p>{{ __('views.view_entry_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'event-addr-data') }}" class="nav-link {{ $activePage == '人物事件地址檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map"></i>
                                <p>{{ __('views.view_event_addr_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'events-data') }}" class="nav-link {{ $activePage == '人物事件資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-history"></i>
                                <p>{{ __('views.view_events_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'kin-addr-data') }}" class="nav-link {{ $activePage == '人物親屬資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-users"></i>
                                <p>{{ __('views.view_kin_addr_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'people-data') }}" class="nav-link {{ $activePage == '人物基本資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-id-card"></i>
                                <p>{{ __('views.view_people_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'people-addr-data') }}" class="nav-link {{ $activePage == '人物索引地址檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map-pin"></i>
                                <p>{{ __('views.view_people_addr_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'posessions-addr-data') }}" class="nav-link {{ $activePage == '人物財產地址檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-coins"></i>
                                <p>{{ __('views.view_possessions_addr_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'posessions-data') }}" class="nav-link {{ $activePage == '人物財產資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-piggy-bank"></i>
                                <p>{{ __('views.view_possessions_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'posting-addr-data') }}" class="nav-link {{ $activePage == '任官地址資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-map-signs"></i>
                                <p>{{ __('views.view_posting_addr_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'posting-office-data') }}" class="nav-link {{ $activePage == '任官職務資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-briefcase"></i>
                                <p>{{ __('views.view_posting_office_data') }}</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('view.show', 'status-data') }}" class="nav-link {{ $activePage == '人物身份資料檢視' ? 'active' : '' }}">
                                <i class="nav-icon fas fa-id-card-alt"></i>
                                <p>{{ __('views.view_status_data') }}</p>
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
                                {{ __('nav.expert_tools') }}
                                <i class="right fas fa-angle-left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ route('app.query-playground.index') }}" class="nav-link {{ $activePage == 'Query Playground' || request()->routeIs('app.query-playground.*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-terminal"></i>
                                    <p>{{ __('nav.sql_query_playground') }}</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                @endif

                @if(Auth::check() and Auth::user()->isActive() and Auth::user()->isSuperAdmin())
                    @php
                        $notPublicPages = [
                            'Crowdsourcing',
                            '人物瀏覽',
                            '按入仕查詢',
                            '歷史地圖',
                        ];
                        $notPublicMenuOpen = in_array($activePage, $notPublicPages, true)
                            || request()->routeIs('crowdsourcing.*')
                            || request()->routeIs('app.person-browser.*')
                            || request()->routeIs('app.search-by.entry.*')
                            || request()->routeIs('app.maps.*');
                    @endphp
                    <li class="nav-item {{ $notPublicMenuOpen ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ $notPublicMenuOpen ? 'active' : '' }}">
                            <i class="nav-icon fas fa-lock"></i>
                            <p>
                                {{ __('nav.not_public_tools') }}
                                <i class="right fas fa-angle-left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ route('crowdsourcing.index') }}" class="nav-link {{ $activePage == 'Crowdsourcing' ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-users-cog"></i>
                                    <p>{{ __('nav.crowdsourcing_records') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('app.person-browser.index') }}" class="nav-link {{ request()->routeIs('app.person-browser.*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-user-friends"></i>
                                    <p>{{ __('nav.person_browser') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('app.search-by.entry.index') }}" class="nav-link {{ request()->routeIs('app.search-by.entry.*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-search"></i>
                                    <p>{{ __('nav.search_by_entry') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('app.maps.index') }}" class="nav-link {{ request()->routeIs('app.maps.*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-map"></i>
                                    <p>{{ __('nav.historical_maps') }}</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                @endif

                @if(Auth::check() and Auth::user()->isActive() and Auth::user()->isSuperAdmin())
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
                                {{ __('nav.admin_tools') }}
                                <i class="right fas fa-angle-left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ route('manage.index') }}" class="nav-link {{ in_array($activePage, ['用戶管理'], true) ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-user-cog"></i>
                                    <p>{{ __('nav.user_management') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('query-playground.nl-query-logs') }}" class="nav-link {{ $activePage == 'NL Query Logs' ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-comments"></i>
                                    <p>{{ __('admin.nl_query_logs') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.ai-fill-logs') }}" class="nav-link {{ $activePage == 'AI 填充日誌' ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-robot"></i>
                                    <p>{{ __('admin.ai_fill_logs') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.audit-logs') }}" class="nav-link {{ $activePage == '審計日誌' ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-clipboard-check"></i>
                                    <p>{{ __('admin.audit_logs') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.explainsql') }}" class="nav-link {{ $activePage == 'SQL 執行計畫' ? 'active' : '' }}">
                                    <i class="nav-icon fa fa-search"></i>
                                    <p>{{ __('admin.sql_explain') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.batch-load-book-titles') }}" class="nav-link {{ $activePage == '批次匯入書稿資料' ? 'active' : '' }}">
                                    <i class="nav-icon fa fa-upload"></i>
                                    <p>{{ __('admin.batch_load_books') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.batch-load-offices') }}" class="nav-link {{ $activePage == '批次匯入官職' ? 'active' : '' }}">
                                    <i class="nav-icon fa fa-briefcase"></i>
                                    <p>{{ __('admin.batch_load_offices') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.batch-load-social-institutes') }}" class="nav-link {{ $activePage == '批次匯入社會機構' ? 'active' : '' }}">
                                    <i class="nav-icon fa fa-university"></i>
                                    <p>{{ __('admin.batch_load_social_institutes') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.wiki-maintenance') }}" class="nav-link {{ $activePage == 'Wiki 對照資料維護' ? 'active' : '' }}">
                                    <i class="nav-icon fab fa-wikipedia-w"></i>
                                    <p>{{ __('admin.wiki_maintenance') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.cbdb-table-maintenance') }}" class="nav-link {{ $activePage == 'CBDB 內部表維護' ? 'active' : '' }}">
                                    <i class="nav-icon fa fa-database"></i>
                                    <p>{{ __('admin.table_maintenance') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('admin.unidirectional-relationship-repair') }}" class="nav-link {{ $activePage == '單向關係修復' ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-exchange-alt"></i>
                                    <p>{{ __('admin.unidirectional_repair') }}</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('merge-preview.index') }}" class="nav-link {{ $activePage == 'MergePreview' ? 'active' : '' }}">
                                    <i class="nav-icon ion ion-shuffle"></i>
                                    <p>{{ __('admin.merge_records') }}</p>
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
