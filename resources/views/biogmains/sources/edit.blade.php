@extends('layouts.dashboard-v3')

@section('content')
@include('biogmains.defense')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">出處 Source</h3>
        </div>
        <div class="card-body">
            <div class="card-body">
@php
$row->c_notes = unionPKDef($row->c_notes);
$row->c_pages = unionPKDef($row->c_pages);
$wikiSourceIds = [60795, 68942, 68943];
$isWikiSource = in_array($row->c_textid, $wikiSourceIds);
@endphp

            {{-- Wiki数据源警告 --}}
            @if($isWikiSource)
                <div class="alert alert-warning alert-dismissible" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <strong><i class="fa fa-exclamation-triangle"></i> 警告：</strong>
                    本記錄為批量導入的 Wiki 對照資料，如果修改此記錄，下次導入時會丟失您的修改。
                    請確認是否需要進行手動修改。
                </div>
            @endif

            <form action="{{ route('basicinformation.sources.update', ['basicinformation' => $id, 'source' => $row->c_personid.'-'.$row->c_textid.'-'.$row->c_pages]) }}"  method="post">
                {{ method_field('PATCH') }}
                {{ csrf_field() }}
                <div class="form-group row">
                    <label for="person_id" class="col-sm-2 col-form-label">person id</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" value="{{ $id }}" disabled>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="" class="col-sm-2 col-form-label">出處(c_source)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_source" name="c_textid" required>
                            @if($res['text_str'])
                                <option value="{{ $row->c_textid }}" selected="selected">{{ $res['text_str'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_pages" class="col-sm-2 col-form-label">頁數/條目</label>
                    <div class="col-sm-4">
@php
$row->c_pages = unionPKDef_decode_for_convert($row->c_pages);
@endphp
                        <input type="text" class="form-control" name="c_pages" value="{{ $row->c_pages }}">
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_notes" class="col-sm-2 col-form-label">注(c_notes)</label>
                    <div class="col-sm-10">
@php
$row->c_notes = unionPKDef_decode_for_convert($row->c_notes);
@endphp
                        <textarea class="form-control" name="c_notes" id="" cols="30"
                                  rows="5">{{ $row->c_notes }}</textarea>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_female" class="col-sm-2 col-form-label">是主要出處</label>
                    <div class="col-sm-4">
                        <select class="form-control select2" name="c_main_source">
                            <option value=0 {{ $row->c_main_source == 0? 'selected': '' }}>0-否
                            </option>
                            <option value=1 {{ $row->c_main_source == 1? 'selected': '' }}>1-是
                            </option>
                        </select>
                    </div>
                    <label for="c_ethnicity_code" class="col-sm-2 col-form-label">是本人傳記</label>
                    <div class="col-sm-4">
                        <select class="form-control select2" name="c_self_bio">
                            <option value=0 {{ $row->c_self_bio == 0? 'selected': '' }}>0-否
                            </option>
                            <option value=1 {{ $row->c_self_bio == 1? 'selected': '' }}>1-是
                            </option>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="" class="col-sm-2 col-form-label">建檔</label>
                    <div class="col-sm-10">
                        <input type="text" name="" class="form-control"
                               value="{{ $row->c_created_by.'/'.$row->c_created_date }}"
                               disabled>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="" class="col-sm-2 col-form-label">更新</label>
                    <div class="col-sm-10">
                        <input type="text" name="" class="form-control"
                               value="{{ $row->c_modified_by.'/'.$row->c_modified_date }}"
                               disabled>
                    </div>
                </div>
                <div class="form-group row">
                    <div class="offset-sm-2 col-sm-10">
                        <button type="submit" class="btn btn-secondary">Submit</button>
                    </div>
                </div>
            </form>
            </div>
        </div>
    </div>

@endsection
@section('js')
    <script>
    onViteReady(function() {
        $(".select2").select2();
        $(".c_source").select2(options('text'));

        function formatRepo (repo) {
            if (repo.loading) {
                return repo.text;
            }

            return "<div class='select2-result-repository clearfix'>" +
                "<div class='select2-result-repository__meta'>" +
                "<div class='select2-result-repository__title'>" +
                repo.text +
                "</div></div></div>";
        }

        function formatRepoSelection (repo) {
            return repo.text || repo.text;
        }

        function options(model) {
            return {
                ajax: {
                    url: "/api/select/search/"+model,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            q: params.term, // search term
                            page: params.page,
                        };
                    },
                    processResults: function (data, params) {
                        // parse the results into the format expected by Select2
                        // since we are using custom formatting functions we do not need to
                        // alter the remote JSON data, except to indicate that infinite
                        // scrolling can be used
                        params.page = params.page || 1;

                        return {
                            results: data.data,
                            pagination: {
                                more: (params.page * 30) < data.total
                            }
                        };
                    },
                    cache: true
                },
                placeholder: '请搜索',
                escapeMarkup: function (markup) { return markup; }, // let our custom formatter work
                minimumInputLength: 1,
                templateResult: formatRepo,
                templateSelection: formatRepoSelection
            }
        }
    });
    </script>
@endsection
