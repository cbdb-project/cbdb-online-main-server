{{--
    遞迴渲染單一導覽節點（導覽單一來源 App\Support\Navigation）。
    參數：$node（節點陣列）、$activePage（目前頁面 $page_title 字串）。
    保留與舊 sidebar-v3 完全一致的 AdminLTE 標記/class/active 行為。
--}}
@php
    $hasChildren = !empty($node['children']);
    $isActive = \App\Support\Navigation::nodeActive($node, $activePage);
    $isOpen = $hasChildren ? \App\Support\Navigation::treeOpen($node, $activePage) : false;
    $badge = $node['badge'] ?? null;
@endphp
<li class="nav-item {{ $isOpen ? 'menu-open' : '' }}">
    <a href="{{ $node['href'] ?? '#' }}" class="nav-link {{ ($isActive || $isOpen) ? 'active' : '' }}">
        <i class="nav-icon {{ $node['icon'] }}"></i>
        <p>
            {{ __($node['label']) }}
            @if(!empty($node['suffix']))
                <small>{{ $node['suffix'] }}</small>
            @endif
            @if($hasChildren)
                <i class="right fas fa-angle-left"></i>
            @endif
            @if($badge && ($badge['show'] ?? false))
                <span class="badge badge-{{ $badge['variant'] }} float-right">{{ __($badge['label']) }}</span>
            @endif
        </p>
    </a>
    @if($hasChildren)
        <ul class="nav nav-treeview">
            @foreach($node['children'] as $child)
                @include('layouts.partials.sidebar-node', ['node' => $child, 'activePage' => $activePage])
            @endforeach
        </ul>
    @endif
</li>
