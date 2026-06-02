@php
    $currentRoute = request()->route()->getName();
@endphp

<div class="mb-4 biogmain-navigation">
    <h3 class="text-center mb-3">{{ $basicinformation->c_name_chn.'（'.$basicinformation->c_name.'）- '.$basicinformation->c_personid }}</h3>

    <ul class="nav nav-tabs" style="flex-wrap: wrap;">
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.edit' ? 'active' : '' }}" href="/basicinformation/{{ $basicinformation->c_personid }}/edit">
                <i class="fas fa-user" aria-hidden="true"></i> {{ __('person.tab_basic_info') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.addresses.index' ? 'active' : '' }}" href="{{ route('basicinformation.addresses.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-map-marker-alt" aria-hidden="true"></i> {{ __('person.tab_addresses') }}<span class="badge badge-light ml-1">{{ $basicinformation->biog_addresses_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.altnames.index' ? 'active' : '' }}" href="{{ route('basicinformation.altnames.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-id-card"></i> {{ __('person.tab_alt_names') }}<span class="badge badge-light ml-1">{{ $basicinformation->altnames_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.texts.index' ? 'active' : '' }}" href="{{ route('basicinformation.texts.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-book"></i> {{ __('person.tab_texts') }}<span class="badge badge-light ml-1">{{ $basicinformation->texts_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.offices.index' ? 'active' : '' }}" href="{{ route('basicinformation.offices.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-briefcase"></i> {{ __('person.tab_postings') }}<span class="badge badge-light ml-1">{{ $basicinformation->offices_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.entries.index' ? 'active' : '' }}" href="{{ route('basicinformation.entries.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-door-open"></i> {{ __('person.tab_entries') }}<span class="badge badge-light ml-1">{{ $basicinformation->entries_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.events.index' ? 'active' : '' }}" href="{{ route('basicinformation.events.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-calendar-alt"></i> {{ __('person.tab_events') }}<span class="badge badge-light ml-1">{{ $basicinformation->events_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.statuses.index' ? 'active' : '' }}" href="{{ route('basicinformation.statuses.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-users"></i> {{ __('person.tab_statuses') }}<span class="badge badge-light ml-1">{{ $basicinformation->statuses_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.kinship.index' ? 'active' : '' }}" href="{{ route('basicinformation.kinship.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-user-friends"></i> {{ __('person.tab_kinship') }}<span class="badge badge-light ml-1">{{ $basicinformation->kinship_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.assoc.index' ? 'active' : '' }}" href="{{ route('basicinformation.assoc.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-network-wired"></i> {{ __('person.tab_associations') }}<span class="badge badge-light ml-1">{{ $basicinformation->assoc_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.possession.index' ? 'active' : '' }}" href="{{ route('basicinformation.possession.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-coins"></i> {{ __('person.tab_possessions') }}<span class="badge badge-light ml-1">{{ $basicinformation->possession_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.socialinst.index' ? 'active' : '' }}" href="{{ route('basicinformation.socialinst.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-building"></i> {{ __('person.tab_social_institutions') }}<span class="badge badge-light ml-1">{{ $basicinformation->inst_count }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $currentRoute === 'basicinformation.sources.index' ? 'active' : '' }}" href="{{ route('basicinformation.sources.index', ['basicinformation' => $basicinformation->c_personid]) }}">
                <i class="fas fa-file-alt"></i> {{ __('person.tab_sources') }}<span class="badge badge-light ml-1">{{ $basicinformation->sources_count }}</span>
            </a>
        </li>
    </ul>
</div>
