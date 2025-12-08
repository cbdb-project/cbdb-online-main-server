<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="/home" class="brand-link">
        <span class="brand-image elevation-3 text-lg" style="opacity: .8; margin-left: 0.5rem;"><b>CB</b>DB</span>
        <span class="brand-text font-weight-light">{{ config('app.name', 'CBDB') }}</span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">
        <!-- Sidebar Menu -->
        @php
            $hasPendingProposals = false;
            if (Auth::check() && Auth::user()->canManageUsers()) {
                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('operations')) {
                        $hasPendingProposals = \App\Operation::where('crowdsourcing_status', 0)
                            ->whereIn('op_type', [
                                \App\Operation::TYPE_PROPOSAL_CREATE,
                                \App\Operation::TYPE_PROPOSAL_UPDATE,
                            ])
                            ->where('resource_data', 'like', '%"__review_status":"pending"%')
                            ->exists();
                    }
                } catch (\Throwable $e) {
                    $hasPendingProposals = false;
                }
            }
        @endphp

        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                <!-- Add icons to the links using the .nav-icon class
                     with font-awesome or any other icon font library -->
                <li class="nav-header">MAIN NAVIGATION</li>

                <li class="nav-item">
                    <a href="{{ route('basicinformation.index') }}" class="nav-link {{ $page_title == 'Basicinformation' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>個人基本信息</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('operations.index') }}" class="nav-link {{ $page_title == 'NewUpdate' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>最近編輯列表</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('operations.index', ['proposals_only' => 1]) }}" class="nav-link {{ $page_title == 'OperationsProposals' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>
                            最近提案列表
                            @if($hasPendingProposals)
                                <span class="badge badge-warning float-right">待審核</span>
                            @endif
                        </p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('crowdsourcing.index') }}" class="nav-link {{ $page_title == 'Crowdsourcing' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>最近眾包錄入記錄</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('modified.index') }}" class="nav-link {{ $page_title == 'Modified' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>最近修改記錄</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/codes" class="nav-link {{ $page_title == 'Codes' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-database"></i>
                        <p>全部表格</p>
                    </a>
                </li>

                <li class="nav-header">編碼表 CODES</li>

                <li class="nav-item">
                    <a href="/codes/ALTNAME_CODES" class="nav-link {{ $page_title == 'Altname Codes' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>別名編碼表 <small>(ALTNAME_CODES)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/codes/APPOINTMENT_CODES" class="nav-link {{ $page_title == 'Appointment Type Codes' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>任命類型編碼表 <small>(APPOINTMENT_CODES)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/codes/TEXT_CODES" class="nav-link {{ $page_title == 'Text Codes' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>著作編碼表 <small>(TEXT_CODES)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/codes/ADDR_CODES" class="nav-link {{ $page_title == 'Addr Codes' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>地址編碼表 <small>(ADDR_CODES)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/codes/OFFICE_CODES" class="nav-link {{ $page_title == 'Office Codes' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>任官編碼表 <small>(OFFICE_CODES)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/codes/SOCIAL_INSTITUTION_CODES" class="nav-link {{ $page_title == 'Social Institution Codes' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>社會機構編碼表 <small>(SOCIAL_INSTITUTION_CODES)</small></p>
                    </a>
                </li>

                <li class="nav-header">資料表 DATA</li>

                <li class="nav-item">
                    <a href="/codes/ADDR_BELONGS_DATA" class="nav-link {{ $page_title == 'Addrbelongsdata Type Codes' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>地址從屬表 <small>(ADDR_BELONGS_DATA)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/codes/TEXT_INSTANCE_DATA" class="nav-link {{ $page_title == 'Text Instance Data' ? 'active' : '' }}">
                        <i class="nav-icon ion ion-ios-people-outline"></i>
                        <p>著作版本表 <small>(TEXT_INSTANCE_DATA)</small></p>
                    </a>
                </li>

                <li class="nav-header">檢視表 VIEW</li>

                <li class="nav-item">
                    <a href="{{ route('view.index') }}" class="nav-link {{ $page_title == '檢視表總覽' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-th-list"></i>
                        <p>檢視表總覽</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'addresses') }}" class="nav-link {{ $page_title == '地址層級檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>地址層級檢視</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'altname-data') }}" class="nav-link {{ $page_title == '別名資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>別名資料檢視 <small>(View_AltnameData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'assoc-data') }}" class="nav-link {{ $page_title == '社會關係資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>社會關係資料檢視 <small>(View_AssociationData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'biog-addr-data') }}" class="nav-link {{ $page_title == '人物地址資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物地址資料檢視 <small>(View_BiogAddrData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'biog-inst-addr-data') }}" class="nav-link {{ $page_title == '人物/社會機構/地址資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物/社會機構/地址 <small>(View_BiogInstAddrData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'biog-inst-data') }}" class="nav-link {{ $page_title == '人物社會機構資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物社會機構資料檢視 <small>(View_BiogInstData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'biog-source-data') }}" class="nav-link {{ $page_title == '人物來源資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物來源資料檢視 <small>(View_BiogSourceData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'biog-text-data') }}" class="nav-link {{ $page_title == '人物著作資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物著作資料檢視 <small>(View_BiogTextData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'entry-data') }}" class="nav-link {{ $page_title == '人物入仕資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物入仕資料檢視 <small>(View_EntryData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'event-addr-data') }}" class="nav-link {{ $page_title == '人物事件地址檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物事件地址檢視 <small>(View_EventAddrData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'events-data') }}" class="nav-link {{ $page_title == '人物事件資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物事件資料檢視 <small>(View_EventData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'kin-addr-data') }}" class="nav-link {{ $page_title == '人物親屬資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物親屬資料檢視 <small>(View_KinAddrData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'people-data') }}" class="nav-link {{ $page_title == '人物基本資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物基本資料檢視 <small>(View_PeopleData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'people-addr-data') }}" class="nav-link {{ $page_title == '人物索引地址檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物索引地址檢視 <small>(View_PeopleAddrData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'posessions-addr-data') }}" class="nav-link {{ $page_title == '人物財產地址檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物財產地址檢視 <small>(View_PossessionsAddrData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'posessions-data') }}" class="nav-link {{ $page_title == '人物財產資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物財產資料檢視 <small>(View_PossessionsData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'posting-addr-data') }}" class="nav-link {{ $page_title == '任官地址資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>任官地址資料檢視 <small>(View_PostingAddrData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'posting-office-data') }}" class="nav-link {{ $page_title == '任官職務資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>任官職務資料檢視 <small>(View_PostingOfficeData)</small></p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ route('view.show', 'status-data') }}" class="nav-link {{ $page_title == '人物身份資料檢視' ? 'active' : '' }}">
                        <i class="nav-icon fa fa-table"></i>
                        <p>人物身份資料檢視 <small>(View_StatusData)</small></p>
                    </a>
                </li>

                @if(Auth::check() and Auth::user()->isAdmin())
                    <li class="nav-header">Management</li>

                    <li class="nav-item">
                        <a href="{{ route('admin.explainsql') }}" class="nav-link {{ $page_title == 'SQL 執行計畫' ? 'active' : '' }}">
                            <i class="nav-icon fa fa-search"></i>
                            <p>SQL EXPLAIN</p>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="{{ route('admin.batch-load-book-titles') }}" class="nav-link {{ $page_title == '批次匯入書稿資料' ? 'active' : '' }}">
                            <i class="nav-icon fa fa-upload"></i>
                            <p>批次匯入書稿</p>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="{{ route('admin.batch-load-offices') }}" class="nav-link {{ $page_title == '批次匯入官職' ? 'active' : '' }}">
                            <i class="nav-icon fa fa-briefcase"></i>
                            <p>批次匯入官職</p>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="{{ route('admin.batch-load-social-institutes') }}" class="nav-link {{ $page_title == '批次匯入社會機構' ? 'active' : '' }}">
                            <i class="nav-icon fa fa-university"></i>
                            <p>批次匯入社會機構</p>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="{{ route('admin.wiki-maintenance') }}" class="nav-link {{ $page_title == 'Wiki 對照資料維護' ? 'active' : '' }}">
                            <i class="nav-icon fab fa-wikipedia-w"></i>
                            <p>Wiki 對照資料維護</p>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="{{ route('admin.cbdb-table-maintenance') }}" class="nav-link {{ $page_title == 'CBDB 內部表維護' ? 'active' : '' }}">
                            <i class="nav-icon fa fa-database"></i>
                            <p>CBDB 內部表維護</p>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="{{ route('admin.unidirectional-relationship-repair') }}" class="nav-link {{ $page_title == '單向關係修復' ? 'active' : '' }}">
                            <i class="nav-icon fa fa-exchange"></i>
                            <p>單向關係修復</p>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="{{ route('merge-preview.index') }}" class="nav-link {{ $page_title == 'MergePreview' ? 'active' : '' }}">
                            <i class="nav-icon ion ion-shuffle"></i>
                            <p>人物記錄合併</p>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a href="{{ route('manage.index') }}" class="nav-link {{ $page_title == 'Management' ? 'active' : '' }}">
                            <i class="nav-icon ion ion-ios-people-outline"></i>
                            <p>管理用戶</p>
                        </a>
                    </li>
                @endif
            </ul>
        </nav>
        <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
</aside>
