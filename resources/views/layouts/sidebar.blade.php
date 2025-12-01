<!-- Left side column. contains the logo and sidebar -->
<aside class="main-sidebar">

    <!-- sidebar: style can be found in sidebar.less -->
    <section class="sidebar">

        <!-- search form (Optional) -->
        {{--<form action="#" method="get" class="sidebar-form">--}}
            {{--<div class="input-group">--}}
                {{--<input type="text" name="q" class="form-control" placeholder="Search...">--}}
                {{--<span class="input-group-btn">--}}
                {{--<button type="submit" name="search" id="search-btn" class="btn btn-flat"><i class="fa fa-search"></i>--}}
                {{--</button>--}}
              {{--</span>--}}
            {{--</div>--}}
        {{--</form>--}}
        <!-- /.search form -->

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
        <ul class="sidebar-menu">
            <li class="header">MAIN NAVIGATION</li>
            <!-- Optionally, you can add icons to the links -->
            {{--<li class="{{ $page_title == 'Dashboard' ? 'active' : '' }}"><a href="/home"><i class="fa fa-dashboard"></i> <span>控制面板</span></a></li>--}}
            <li class="{{ $page_title == 'Basicinformation' ? 'active' : '' }}"><a href="{{ route('basicinformation.index') }}"><i class="ion ion-ios-people-outline"></i> <span>個人基本信息</span></a></li>
            <li class="{{ $page_title == 'NewUpdate' ? 'active' : '' }}"><a href="{{ route('operations.index') }}"><i class="ion ion-ios-people-outline"></i> <span>最近編輯列表</span></a></li>
            <li class="{{ $page_title == 'OperationsProposals' ? 'active' : '' }}"><a href="{{ route('operations.index', ['proposals_only' => 1]) }}"><i class="ion ion-ios-people-outline"></i> <span>最近提案列表{{ $hasPendingProposals ? '（待審核）' : '' }}</span></a></li>
            <li class="{{ $page_title == 'Crowdsourcing' ? 'active' : '' }}"><a href="{{ route('crowdsourcing.index') }}"><i class="ion ion-ios-people-outline"></i> <span>最近眾包錄入記錄</span></a></li>
            <li class="{{ $page_title == 'Modified' ? 'active' : '' }}"><a href="{{ route('modified.index') }}"><i class="ion ion-ios-people-outline"></i> <span>最近修改記錄</span></a></li>
            <li class="{{ $page_title == 'Codes' ? 'active' : '' }}"><a href="/codes"><i class="fa fa-database"></i> <span>全部表格</span></a></li>

            <li class="header">編碼表 CODES</li>
            {{--<li class="{{ $page_title == 'ADDR_CODES' ? 'active' : '' }}"><a href="/codes/ADDR_CODES"><i class="fa fa-database"></i> <span>ADDR_CODES</span></a></li>--}}
            {{--<li class="{{ $page_title == 'ALTNAME_CODES' ? 'active' : '' }}"><a href="/codes/ALTNAME_CODES"><i class="fa fa-database"></i> <span>ALTNAME_CODES</span></a></li>--}}
            {{--<li class="{{ $page_title == 'Address Codes' ? 'active' : '' }}"><a href="{{ route('addresscodes.index') }}"><i class="ion ion-ios-people-outline"></i> <span>地址編碼表 (ADDRESSES)</span></a></li>--}}
            <li class="{{ $page_title == 'Altname Codes' ? 'active' : '' }}"><a href="/codes/ALTNAME_CODES"><i class="ion ion-ios-people-outline"></i> <span>別名編碼表 (ALTNAME_CODES)</span></a></li>
            <li class="{{ $page_title == 'Appointment Type Codes' ? 'active' : '' }}"><a href="/codes/APPOINTMENT_CODES"><i class="ion ion-ios-people-outline"></i> <span>任命類型編碼表<br> (APPOINTMENT_CODES)</span></a></li>
            <li class="{{ $page_title == 'Text Codes' ? 'active' : '' }}"><a href="/codes/TEXT_CODES"><i class="ion ion-ios-people-outline"></i> <span>著作編碼表 (TEXT_CODES)</span></a></li>
            <li class="{{ $page_title == 'Addr Codes' ? 'active' : '' }}"><a href="/codes/ADDR_CODES"><i class="ion ion-ios-people-outline"></i> <span>地址編碼表 (ADDR_CODES)</span></a></li>
            <li class="{{ $page_title == 'Office Codes' ? 'active' : '' }}"><a href="/codes/OFFICE_CODES"><i class="ion ion-ios-people-outline"></i> <span>任官編碼表 (OFFICE_CODES)</span></a></li>
            <li class="{{ $page_title == 'Social Institution Codes' ? 'active' : '' }}"><a href="/codes/SOCIAL_INSTITUTION_CODES"><i class="ion ion-ios-people-outline"></i> <span>社會機構編碼表<br>(SOCIAL_INSTITUTION_CODES)</span></a></li>
            <li class="header">資料表 DATA</li>
            <li class="{{ $page_title == 'Addrbelongsdata Type Codes' ? 'active' : '' }}"><a href="/codes/ADDR_BELONGS_DATA"><i class="ion ion-ios-people-outline"></i> <span>地址從屬表<br>(ADDR_BELONGS_DATA)</span></a></li>
            <li class="{{ $page_title == 'Text Instance Data' ? 'active' : '' }}"><a href="/codes/TEXT_INSTANCE_DATA"><i class="ion ion-ios-people-outline"></i> <span>著作版本表<br>(TEXT_INSTANCE_DATA)</span></a></li>
            <li class="header">檢視表 VIEW</li>
            <li class="{{ $page_title == '檢視表總覽' ? 'active' : '' }}"><a href="{{ route('view.index') }}"><i class="fa fa-th-list"></i> <span>檢視表總覽</span></a></li>
            <li class="{{ $page_title == '地址層級檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'addresses') }}"><i class="fa fa-table"></i> <span>地址層級檢視</span></a></li>
            <li class="{{ $page_title == '別名資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'altname-data') }}"><i class="fa fa-table"></i> <span>別名資料檢視<br>(View_AltnameData)</span></a></li>
            <li class="{{ $page_title == '社會關係資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'assoc-data') }}"><i class="fa fa-table"></i> <span>社會關係資料檢視<br>(View_AssociationData)</span></a></li>
            <li class="{{ $page_title == '人物地址資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'biog-addr-data') }}"><i class="fa fa-table"></i> <span>人物地址資料檢視<br>(View_BiogAddrData)</span></a></li>
            <li class="{{ $page_title == '人物/社會機構/地址資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'biog-inst-addr-data') }}"><i class="fa fa-table"></i> <span>人物/社會機構/地址資料檢視<br>(View_BiogInstAddrData)</span></a></li>
            <li class="{{ $page_title == '人物社會機構資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'biog-inst-data') }}"><i class="fa fa-table"></i> <span>人物社會機構資料檢視<br>(View_BiogInstData)</span></a></li>
            <li class="{{ $page_title == '人物來源資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'biog-source-data') }}"><i class="fa fa-table"></i> <span>人物來源資料檢視<br>(View_BiogSourceData)</span></a></li>
            <li class="{{ $page_title == '人物著作資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'biog-text-data') }}"><i class="fa fa-table"></i> <span>人物著作資料檢視<br>(View_BiogTextData)</span></a></li>
            <li class="{{ $page_title == '人物入仕資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'entry-data') }}"><i class="fa fa-table"></i> <span>人物入仕資料檢視<br>(View_EntryData)</span></a></li>
            <li class="{{ $page_title == '人物事件地址檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'event-addr-data') }}"><i class="fa fa-table"></i> <span>人物事件地址檢視<br>(View_EventAddrData)</span></a></li>
            <li class="{{ $page_title == '人物事件資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'events-data') }}"><i class="fa fa-table"></i> <span>人物事件資料檢視<br>(View_EventData)</span></a></li>
            <li class="{{ $page_title == '人物親屬資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'kin-addr-data') }}"><i class="fa fa-table"></i> <span>人物親屬資料檢視<br>(View_KinAddrData)</span></a></li>
            <li class="{{ $page_title == '人物基本資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'people-data') }}"><i class="fa fa-table"></i> <span>人物基本資料檢視<br>(View_PeopleData)</span></a></li>
            <li class="{{ $page_title == '人物索引地址檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'people-addr-data') }}"><i class="fa fa-table"></i> <span>人物索引地址檢視<br>(View_PeopleAddrData)</span></a></li>
            <li class="{{ $page_title == '人物財產地址檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'posessions-addr-data') }}"><i class="fa fa-table"></i> <span>人物財產地址檢視<br>(View_PossessionsAddrData)</span></a></li>
            <li class="{{ $page_title == '人物財產資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'posessions-data') }}"><i class="fa fa-table"></i> <span>人物財產資料檢視<br>(View_PossessionsData)</span></a></li>
            <li class="{{ $page_title == '任官地址資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'posting-addr-data') }}"><i class="fa fa-table"></i> <span>任官地址資料檢視<br>(View_PostingAddrData)</span></a></li>
            <li class="{{ $page_title == '任官職務資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'posting-office-data') }}"><i class="fa fa-table"></i> <span>任官職務資料檢視<br>(View_PostingOfficeData)</span></a></li>
            <li class="{{ $page_title == '人物身份資料檢視' ? 'active' : '' }}"><a href="{{ route('view.show', 'status-data') }}"><i class="fa fa-table"></i> <span>人物身份資料檢視<br>(View_StatusData)</span></a></li>

            @if(Auth::check() and Auth::user()->isAdmin())
                <li class="header">Management</li>
                <li class="{{ $page_title == 'SQL 執行計畫' ? 'active' : '' }}"><a href="{{ route('admin.explainsql') }}"><i class="fa fa-search"></i> <span>SQL EXPLAIN</span></a></li>
                <li class="{{ $page_title == '批次匯入書稿資料' ? 'active' : '' }}"><a href="{{ route('admin.batch-load-book-titles') }}"><i class="fa fa-upload"></i> <span>批次匯入書稿</span></a></li>
                <li class="{{ $page_title == '批次匯入官職' ? 'active' : '' }}"><a href="{{ route('admin.batch-load-offices') }}"><i class="fa fa-briefcase"></i> <span>批次匯入官職</span></a></li>
                <li class="{{ $page_title == '批次匯入社會機構' ? 'active' : '' }}"><a href="{{ route('admin.batch-load-social-institutes') }}"><i class="fa fa-university"></i> <span>批次匯入社會機構</span></a></li>
                <li class="{{ $page_title == 'Wiki 對照資料維護' ? 'active' : '' }}"><a href="{{ route('admin.wiki-maintenance') }}"><i class="fa fa-wikipedia-w"></i> <span>Wiki 對照資料維護</span></a></li>
                <li class="{{ $page_title == 'CBDB 內部表維護' ? 'active' : '' }}"><a href="{{ route('admin.cbdb-table-maintenance') }}"><i class="fa fa-database"></i> <span>CBDB 內部表維護</span></a></li>
                <li class="{{ $page_title == 'MergePreview' ? 'active' : '' }}"><a href="{{ route('merge-preview.index') }}"><i class="ion ion-shuffle"></i> <span>人物記錄合併</span></a></li>
                <li class="{{ $page_title == 'Management' ? 'active' : '' }}"><a href="{{ route('manage.index') }}"><i class="ion ion-ios-people-outline"></i> <span>管理用戶</span></a></li>
            @endif
        </ul>
        <!-- /.sidebar-menu -->
    </section>
    <!-- /.sidebar -->
</aside>
