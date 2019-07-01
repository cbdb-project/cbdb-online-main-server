<!-- Left side column. contains the logo and sidebar -->
<aside class="main-sidebar">

    <!-- sidebar: style can be found in sidebar.less -->
    <section class="sidebar">

        <!-- Sidebar user panel (optional) -->
        <div class="user-panel">
            @if (Auth::guest())

            @else
                <div class="pull-left image">
                    <img src="/images/avatar/{{ Auth::user()->avatar }}" class="img-circle" alt="User Image">
                </div>
                <div class="pull-left info">
                    <p>{{ Auth::user()->name }}</p>
                    <!-- Status -->
                    <a href="#"><i class="fa fa-circle text-success"></i> Online</a>
                </div>
            @endif

        </div>

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
        <ul class="sidebar-menu">
            <li class="header">MAIN NAVIGATION</li>
            <!-- Optionally, you can add icons to the links -->
            {{--<li class="{{ $page_title == 'Dashboard' ? 'active' : '' }}"><a href="/home"><i class="fa fa-dashboard"></i> <span>控制面板</span></a></li>--}}
            <li class="{{ $page_title == 'Basicinformation' ? 'active' : '' }}"><a href="{{ route('basicinformation.index') }}"><i class="ion ion-ios-people-outline"></i> <span>個人基本信息</span></a></li>
            <li class="{{ $page_title == 'NewUpdate' ? 'active' : '' }}"><a href="{{ route('operations.index') }}"><i class="ion ion-ios-people-outline"></i> <span>最近編輯列表</span></a></li>
            <li class="{{ $page_title == 'Crowdsourcing' ? 'active' : '' }}"><a href="{{ route('crowdsourcing.index') }}"><i class="ion ion-ios-people-outline"></i> <span>最近眾包錄入記錄</span></a></li>
            <li class="{{ $page_title == 'Modified' ? 'active' : '' }}"><a href="{{ route('modified.index') }}"><i class="ion ion-ios-people-outline"></i> <span>最近修改記錄</span></a></li>

            <li class="header">CODES</li>
            <li class="{{ $page_title == 'Codes' ? 'active' : '' }}"><a href="/codes"><i class="fa fa-database"></i> <span>代碼表(CODES TABLES)</span></a></li>
            {{--<li class="{{ $page_title == 'ADDR_CODES' ? 'active' : '' }}"><a href="/codes/ADDR_CODES"><i class="fa fa-database"></i> <span>ADDR_CODES</span></a></li>--}}
            {{--<li class="{{ $page_title == 'ALTNAME_CODES' ? 'active' : '' }}"><a href="/codes/ALTNAME_CODES"><i class="fa fa-database"></i> <span>ALTNAME_CODES</span></a></li>--}}
            <li class="{{ $page_title == 'Address Codes' ? 'active' : '' }}"><a href="{{ route('addresscodes.index') }}"><i class="ion ion-ios-people-outline"></i> <span>地址編碼表(ADDRESSES)</span></a></li>
            <li class="{{ $page_title == 'Altname Codes' ? 'active' : '' }}"><a href="{{ route('altnamecodes.index') }}"><i class="ion ion-ios-people-outline"></i> <span>別名編碼表</span></a></li>
            <li class="{{ $page_title == 'Appointment Type Codes' ? 'active' : '' }}"><a href="{{ route('appointcodes.index') }}"><i class="ion ion-ios-people-outline"></i> <span>任命類型編碼表</span></a></li>
            <li class="{{ $page_title == 'Text Codes' ? 'active' : '' }}"><a href="{{ route('textcodes.index') }}"><i class="ion ion-ios-people-outline"></i> <span>著作編碼表</span></a></li>
            <li class="{{ $page_title == 'Addrbelongsdata Type Codes' ? 'active' : '' }}"><a href="{{ route('addrbelongsdata.index') }}"><i class="ion ion-ios-people-outline"></i> <span>著作從屬表</span></a></li>
            <li class="{{ $page_title == 'Addr Codes' ? 'active' : '' }}"><a href="{{ route('addrcodes.index') }}"><i class="ion ion-ios-people-outline"></i> <span>地址編碼表(ADDR_CODES)</span></a></li>
            <li class="{{ $page_title == 'Office Codes' ? 'active' : '' }}"><a href="{{ route('officecodes.index') }}"><i class="ion ion-ios-people-outline"></i> <span>任官編碼表</span></a></li>
            <li class="{{ $page_title == 'Social Institution Codes' ? 'active' : '' }}"><a href="{{ route('socialinstitutioncodes.index') }}"><i class="ion ion-ios-people-outline"></i> <span>社會機構編碼表</span></a></li>

            @if(Auth::check() and Auth::user()->is_admin == 1)
                <li class="header">Management</li>
                <li class="{{ $page_title == 'Management' ? 'active' : '' }}"><a href="{{ route('manage.index') }}"><i class="ion ion-ios-people-outline"></i> <span>管理用戶</span></a></li>
            @endif
        </ul>
        <!-- /.sidebar-menu -->
    </section>
    <!-- /.sidebar -->
</aside>
