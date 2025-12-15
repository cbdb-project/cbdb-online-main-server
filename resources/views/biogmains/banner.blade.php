
<div class="text-center">
    <h3>{{ $basicinformation->c_name_chn.'（'.$basicinformation->c_name.'）- '.$basicinformation->c_personid }}</h3>
    <div class="row text-left">
        <div class="offset-sm-1 col-sm-2">
            <a href="/basicinformation/{{ $basicinformation->c_personid }}/edit"><i class="fas fa-user" aria-hidden="true"></i>&nbsp;&nbsp;基本資料</a>
        </div>
        <div class="col-sm-2">
            <a href="{{ route('basicinformation.addresses.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-map-marker-alt" aria-hidden="true"></i>&nbsp;&nbsp;地址({{ $basicinformation->biog_addresses_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="{{ route('basicinformation.altnames.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-id-card"></i>&nbsp;&nbsp;别名({{ $basicinformation->altnames_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="{{ route('basicinformation.texts.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-book"></i>&nbsp;&nbsp;著述({{ $basicinformation->texts_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="{{ route('basicinformation.offices.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-briefcase"></i>&nbsp;&nbsp;官名({{ $basicinformation->offices_count }})</a>
        </div>
        <div class="offset-sm-1 col-sm-2">
            <a href="{{ route('basicinformation.entries.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-door-open"></i>&nbsp;&nbsp;入仕({{ $basicinformation->entries_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="{{ route('basicinformation.events.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-calendar-alt"></i>&nbsp;&nbsp;事件({{ $basicinformation->events_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="{{ route('basicinformation.statuses.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-users"></i>&nbsp;&nbsp;社會區分({{ $basicinformation->statuses_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="{{ route('basicinformation.kinship.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-user-friends"></i>&nbsp;&nbsp;親屬({{ $basicinformation->kinship_count }})</a>
        </div>
        <div class="col-sm-3">
            <a href="{{ route('basicinformation.assoc.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-network-wired"></i>&nbsp;&nbsp;社會關係({{ $basicinformation->assoc_count }})</a>
        </div>
        <div class="offset-sm-1 col-sm-2">
            <a href="{{ route('basicinformation.possession.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-coins"></i>&nbsp;&nbsp;財產({{ $basicinformation->possession_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="{{ route('basicinformation.socialinst.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-building"></i>&nbsp;&nbsp;社交機構({{ $basicinformation->inst_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="{{ route('basicinformation.sources.index', ['basicinformation' => $basicinformation->c_personid]) }}"><i class="fas fa-file-alt"></i>&nbsp;&nbsp;出處({{ $basicinformation->sources_count }})</a>
        </div>
    </div>
    <br>
</div>
