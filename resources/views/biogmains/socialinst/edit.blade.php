@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">社交機構 SocialInst</div>
        <div class="panel-body">
            <div class="panel-body">
            <form action="{{ route('basicinformation.socialinst.update', [$id, $row->tts_sysno]) }}" class="form-horizontal" method="post">
                {{ method_field('PATCH') }}
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="person_id" class="col-sm-2 control-label">person id</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" value="{{ $id }}" disabled>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_inst_name_code" class="col-sm-2 control-label">社交機構代碼(c_inst_code)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_inst_name_code" name="c_inst_name_code">
                            @if($res['inst_str'])
                                <option value="{{ $row->c_inst_name_code }}" selected="selected">{{ $res['inst_str'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_bi_role_code" class="col-sm-2 control-label">社交機構角色(c_bi_role_code)</label>
                    <div class="col-sm-10">
                        <select-vue name="c_bi_role_code" model="birole" selected="{{ $row->c_bi_role_code }}"></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_bi_begin_year" class="col-sm-2 control-label">始年(firstyear)</label>
                    <div class="col-md-1">
                        <input type="number" name="c_bi_begin_year" class="form-control"
                               value="{{ $row->c_bi_begin_year }}">
                    </div>
                    <div class="col-md-2 from-inline">
                        <label for="c_bi_by_nh_code">年号</label>
                        <select-vue name="c_bi_by_nh_code" model="nianhao" selected="{{ $row->c_bi_by_nh_code }}"></select-vue>
                        <input type="number" name="c_bi_by_nh_year" class="form-control"
                               value="{{ $row->c_bi_by_nh_year }}">
                        <span for="c_bi_by_nh_year">年</span>
                    </div>
                    <div class="col-md-3">
                        <label for="c_bi_by_range">時限</label>
                        <select-vue name="c_bi_by_range" model="range" selected="{{ $row->c_bi_by_range }}"></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_bi_end_year" class="col-sm-2 control-label">終年(lastyear)</label>
                    <div class="col-md-1">
                        <input type="number" name="c_bi_end_year" class="form-control"
                               value="{{ $row->c_bi_end_year }}">
                    </div>
                    <div class="col-md-2 from-inline">
                        <label for="c_bi_ey_nh_code">年号</label>
                        <select-vue name="c_bi_ey_nh_code" model="nianhao" selected="{{ $row->c_bi_ey_nh_code }}"></select-vue>
                        <input type="number" name="c_bi_ey_nh_year" class="form-control"
                               value="{{ $row->c_bi_ey_nh_year }}">
                        <span for="c_bi_ey_nh_year">年</span>
                    </div>
                    <div class="col-md-3">
                        <label for="c_bi_ey_range">時限</label>
                        <select-vue name="c_bi_ey_range" model="range" selected="{{ $row->c_bi_ey_range }}"></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">出處(c_source)</label>
                    <div class="col-sm-5">
                        <select class="form-control c_source" name="c_source">
                            @if($res['text_str'])
                                <option value="{{ $row->c_source }}" selected="selected">{{ $res['text_str'] }}</option>
                            @endif
                        </select>
                    </div>
                    <label for="c_pages" class="col-sm-2 control-label">頁數/條目</label>
                    <div class="col-sm-3">
                        <input type="text" class="form-control" name="c_pages" value="{{ $row->c_pages }}">
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_notes" class="col-sm-2 control-label">注(c_notes)</label>
                    <div class="col-sm-10">
                        <textarea class="form-control" name="c_notes" id="" cols="30"
                                  rows="5">{{ $row->c_notes }}</textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">建檔</label>
                    <div class="col-sm-10">
                        <input type="text" name="" class="form-control"
                               value="{{ $row->c_created_by.'/'.$row->c_created_date }}"
                               disabled>
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">更新</label>
                    <div class="col-sm-10">
                        <input type="text" name="" class="form-control"
                               value="{{ $row->c_modified_by.'/'.$row->c_modified_date }}"
                               disabled>
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-2 col-sm-10">
                        <button type="submit" class="btn btn-default">Submit</button>
                    </div>
                </div>
            </form>
            </div>
        </div>
    </div>

@endsection
@section('js')
    <script>
        $(".select2").select2();
        $(".c_inst_name_code").select2(options('socialinst'));
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
                    headers: {
                        "Authorization": "Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImp0aSI6ImY3NGZlOTk0ZDkxNWE4ZjdjYjljZDA1MzhjM2Q0NTEyN2MxNDJmNDk4NjQyNjlhMzhkZTQ5NjhjNzdmMDIwMTkxMDI1Mjc1ZjE0Y2JkOTc2In0.eyJhdWQiOiIxIiwianRpIjoiZjc0ZmU5OTRkOTE1YThmN2NiOWNkMDUzOGMzZDQ1MTI3YzE0MmY0OTg2NDI2OWEzOGRlNDk2OGM3N2YwMjAxOTEwMjUyNzVmMTRjYmQ5NzYiLCJpYXQiOjE1MDU3NzI0OTUsIm5iZiI6MTUwNTc3MjQ5NSwiZXhwIjoxNTM3MzA4NDk1LCJzdWIiOiIyIiwic2NvcGVzIjpbXX0.cOcfPc3ZOeq4Hh6GU52BOjkICOncLeE9PJQQtIu-Xpsm2DAAbSYTGHS7twmcDSjcpVe7vy7xUMXpfkGAmtM1IzOagV7dVWq2TCreEm3ev0qrMKonB_82p8oAeYPImDyB2pxgiDWXA867SLhZ_14wtPc3wFYNYlesE2KGDmFX7i9oDnTfF9QolpcOBB77kkgwxWJu5V3Jjgcs0CUJGdZyTvATXwCyUC0alakC6UD23Qd9M83KDP00tCL5BeirMFUNEdzaMPS-107l6-q_y1psyPrczksfrVFc1kRfaxoHGwmjInkgTy0-ZLegwPtfXk01BDI-My8WQEUn8JcbhD3k3G4A7SmN0dGN04-q1Oh2DZOzAD0n6Ptf8rTCTWal6YOPotINAyqeGl9gvzuMoWWGSP3m7TtoGbhLOu-m-7smHwwUvzcUqWuHjHLP7zV3sKu0G0yseK5A8pWThwRS1HDI402EqIa1n3Q3iH8c5PC58MdDC1_zzZ-6D2VEOS5FFV6PcQaAh1xESjfM6GlAGxF45CJG1GE-RlfZ14QeH-tNLmG3VZKZvGtCOfrsyVgKjvdvL8D3CbjqNrFTxTzK9fAWTmTZWmKZQQZrMINsTtQ4-WMU7uKuEvIv8pZHkLC5g2G33POJ2LYhIyaQREWjSD6D-z8cpYgBcPCkpHvO3_agxr8"
                    },
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
    </script>
@endsection