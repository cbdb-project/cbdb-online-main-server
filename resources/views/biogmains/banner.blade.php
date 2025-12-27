@php
    $currentRoute = request()->route()->getName();
@endphp

<div class="mb-4 biogmain-navigation">
    <h3 class="text-center mb-3">{{ $basicinformation->c_name_chn.'（'.$basicinformation->c_name.'）- '.$basicinformation->c_personid }}</h3>

    <ul class="nav nav-tabs" style="flex-wrap: wrap;">
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.edit' ? 'active' : '' }}" href="/basicinformation/{{ $basicinformation->c_personid }}/edit">
                <i class="fas fa-user" aria-hidden="true"></i> 基本資料
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.addresses.index' ? 'active' : '' }}" href="{{ route('basicinformation.addresses.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-map-marker-alt" aria-hidden="true"></i> 地址<span class="badge badge-light ml-1">{{ $basicinformation->biog_addresses_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.altnames.index' ? 'active' : '' }}" href="{{ route('basicinformation.altnames.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-id-card"></i> 别名<span class="badge badge-light ml-1">{{ $basicinformation->altnames_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.texts.index' ? 'active' : '' }}" href="{{ route('basicinformation.texts.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-book"></i> 著述<span class="badge badge-light ml-1">{{ $basicinformation->texts_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.offices.index' ? 'active' : '' }}" href="{{ route('basicinformation.offices.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-briefcase"></i> 官名<span class="badge badge-light ml-1">{{ $basicinformation->offices_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.entries.index' ? 'active' : '' }}" href="{{ route('basicinformation.entries.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-door-open"></i> 入仕<span class="badge badge-light ml-1">{{ $basicinformation->entries_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.events.index' ? 'active' : '' }}" href="{{ route('basicinformation.events.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-calendar-alt"></i> 事件<span class="badge badge-light ml-1">{{ $basicinformation->events_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.statuses.index' ? 'active' : '' }}" href="{{ route('basicinformation.statuses.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-users"></i> 社會區分<span class="badge badge-light ml-1">{{ $basicinformation->statuses_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.kinship.index' ? 'active' : '' }}" href="{{ route('basicinformation.kinship.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-user-friends"></i> 親屬<span class="badge badge-light ml-1">{{ $basicinformation->kinship_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.assoc.index' ? 'active' : '' }}" href="{{ route('basicinformation.assoc.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-network-wired"></i> 社會關係<span class="badge badge-light ml-1">{{ $basicinformation->assoc_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.possession.index' ? 'active' : '' }}" href="{{ route('basicinformation.possession.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-coins"></i> 財產<span class="badge badge-light ml-1">{{ $basicinformation->possession_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.socialinst.index' ? 'active' : '' }}" href="{{ route('basicinformation.socialinst.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-building"></i> 社交機構<span class="badge badge-light ml-1">{{ $basicinformation->inst_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.sources.index' ? 'active' : '' }}" href="{{ route('basicinformation.sources.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-file-alt"></i> 出處<span class="badge badge-light ml-1">{{ $basicinformation->sources_count }}</span>
            </a>
        </li>
    </ul>
</div>
