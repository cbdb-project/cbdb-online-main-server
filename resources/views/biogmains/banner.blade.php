
<div class="text-center">
    <h3>{{ $basicinformation->c_name_chn.'（'.$basicinformation->c_name.'）' }}</h3>
    <div class="row text-left">
        <div class="col-sm-offset-1 col-sm-2">
            <a href="/basicinformation/{{ $basicinformation->c_personid }}/edit"><span class="glyphicon glyphicon-user" aria-hidden="true"></span>&nbsp;&nbsp;基本资料</a>
        </div>
        <div class="col-sm-2">
            <a href="/addresses/{{ $basicinformation->c_personid }}"><span class="glyphicon glyphicon-folder-open" aria-hidden="true"></span>&nbsp;&nbsp;地址({{ $basicinformation->addresses_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="/altnames/{{ $basicinformation->c_personid }}"><i class="glyphicon glyphicon-user"></i>&nbsp;&nbsp;别名({{ $basicinformation->altnames_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="/texts/{{ $basicinformation->c_personid }}"><i class="glyphicon glyphicon-user"></i>&nbsp;&nbsp;著述({{ $basicinformation->texts_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="/offices/{{ $basicinformation->c_personid }}"><i class="glyphicon glyphicon-user"></i>&nbsp;&nbsp;官名({{ $basicinformation->offices_count }})</a>
        </div>
        <div class="col-sm-offset-1 col-sm-2">
            <a href="/entries/{{ $basicinformation->c_personid }}"><i class="glyphicon glyphicon-user"></i>&nbsp;&nbsp;入仕({{ $basicinformation->entries_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="/events/{{ $basicinformation->c_personid }}"><i class="glyphicon glyphicon-user"></i>&nbsp;&nbsp;事件({{ $basicinformation->events_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="/statuses/{{ $basicinformation->c_personid }}"><i class="glyphicon glyphicon-user"></i>&nbsp;&nbsp;社會區分({{ $basicinformation->statuses_count }})</a>
        </div>
        <div class="col-sm-2">
            <a href="/kinship/{{ $basicinformation->c_personid }}"><i class="glyphicon glyphicon-user"></i>&nbsp;&nbsp;親屬({{ $basicinformation->kinship_count }})</a>
        </div>
        <div class="col-sm-3">
            <a href="/assoc/{{ $basicinformation->c_personid }}"><i class="glyphicon glyphicon-user"></i>&nbsp;&nbsp;社會關係({{ $basicinformation->assoc_count }})</a>
        </div>
        <div class="col-sm-offset-1 col-sm-2">
            <a href=""><i class="glyphicon glyphicon-user"></i> 財產(0)</a>
        </div>
        <div class="col-sm-2">
            <a href=""><i class="glyphicon glyphicon-user"></i> 社交機構(0)</a>
        </div>
        <div class="col-sm-2">
            <a href="/sources/{{ $basicinformation->c_personid }}"><span class="glyphicon glyphicon-folder-open" aria-hidden="true"></span>&nbsp;&nbsp;出处({{ $basicinformation->sources_count }})</a>
        </div>
    </div>
    <br>
</div>