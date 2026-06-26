<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand -->
    <div class="brand-link d-flex justify-content-center">
        <span class="brand-text font-weight-light text-center w-100">{{ config('app.name', 'CBDB') }}</span>
    </div>

    <!-- Sidebar -->
    <div class="sidebar">
        {{--
            側邊欄導覽改由單一真實來源 App\Support\Navigation 提供（角色閘門、
            feature flag 連結解析、待審提案 badge 皆在該類別內處理），與 React
            AppShell 共用同一份結構，避免兩套側邊欄漂移（見遷移計畫 §五 / §五之二）。
            active-state 仍沿用既有 $page_title（$activePage）字串以相容尚未遷移頁面。
        --}}
        @php
            $activePage = $page_title_key ?? ($page_title ?? '');
            $navTree = \App\Support\Navigation::tree(Auth::user());
        @endphp

        <!-- Sidebar Menu -->
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="true">
                @foreach($navTree as $node)
                    @include('layouts.partials.sidebar-node', ['node' => $node, 'activePage' => $activePage])
                @endforeach
            </ul>
        </nav>
        <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
</aside>
