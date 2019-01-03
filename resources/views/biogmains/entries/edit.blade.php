@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">入仕 Entry</div>
        <div class="panel-body">
            <div class="panel-body">
            <form action="{{ route('basicinformation.entries.update', ['id' =>$id, '_id' => $row->c_personid.'-'.$row->c_entry_code.'-'.$row->c_sequence.'-'.$row->c_kin_code.'-'.$row->c_assoc_code.'-'.$row->c_kin_id.'-'.$row->c_year.'-'.$row->c_assoc_id.'-'.$row->c_inst_code.'-'.$row->c_inst_name_code]) }}" class="form-horizontal" method="post">
                {{ method_field('PATCH') }}
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="person_id" class="col-sm-2 control-label">person id</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" value="{{ $id }}" disabled>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_sequence" class="col-sm-2 control-label">次序(entry_sequence)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_sequence" value="{{ $row->c_sequence }}" maxlength="4">
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_entry_code" class="col-sm-2 control-label">入仕法(entry_code)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_entry_code" name="c_entry_code">
                            @if($res['entry_str'])
                                <option value="{{ $row->c_entry_code }}" selected="selected">{{ $res['entry_str'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_year" class="col-sm-2 control-label">入仕年(year)</label>
                    <div class="col-md-1">
                        <input type="number" name="c_year" class="form-control"
                               value="{{ $row->c_year }}">
                    </div>
                    <div class="col-md-2 from-inline">
                        <label for="c_nianhao_id">年号</label>
                        <select-vue name="c_nianhao_id" model="nianhao" selected="{{ $row->c_nianhao_id }}"></select-vue>
                        <input type="text" name="c_entry_nh_year" class="form-control"
                               value="{{ $row->c_entry_nh_year }}">
                        <span for="c_entry_nh_year">年</span>
                    </div>
                    <div class="col-md-3">
                        <label for="c_entry_range">時限</label>
                        <select-vue name="c_entry_range" model="range" selected="{{ $row->c_entry_range }}"></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_exam_rank" class="col-sm-2 control-label">科第名次(exam_rank)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_exam_rank" value="{{ $row->c_exam_rank }}">
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_attempt_count" class="col-sm-2 control-label">第幾舉(c_attempt_count)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_attempt_count" value="{{ $row->c_attempt_count }}">
                        請填阿拉伯數字(半形/半角)
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_exam_field" class="col-sm-2 control-label">考試科目(c_exam_field)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_exam_field" value="{{ $row->c_exam_field }}">
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_parental_status" class="col-sm-2 control-label">父母狀態(c_parental_status)</label>
                    <div class="col-sm-10">
                        <select-vue name="c_parental_status" model="parentstatus" selected="{{ $row->c_parental_status }}"></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_entry_addr_id" class="col-sm-2 control-label">地點(c_addr_id)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_addr_id" name="c_entry_addr_id" required>
                            @if($res['addr_str'])
                                <option value="{{ $row->c_entry_addr_id }}" selected="selected">{{ $res['addr_str'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_age" class="col-sm-2 control-label">入仕年齡(age)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_age" value="{{ $row->c_age }}">
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_posting_notes" class="col-sm-2 control-label">授官(posting_id)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_posting_notes" value="{{ $row->c_posting_notes }}">
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_kin_code" class="col-sm-2 control-label">親屬關係類別(kin_code)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_kin_code" name="c_kin_code">
                            @if($res['kin_str'])
                                <option value="{{ $row->c_kin_code }}" selected="selected">{{ $res['kin_str'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">親戚(kin_id)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_kin_id" name="c_kin_id">
                            @if($res['biog_str'])
                                <option value="{{ $row->c_kin_id }}" selected="selected">{{ $res['biog_str'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_assoc_code" class="col-sm-2 control-label">社會關係類別(assoc_code)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_assoc_code" name="c_assoc_code">
                            @if($res['assoc_str'])
                                <option value="{{ $row->c_assoc_code }}" selected="selected">{{ $res['assoc_str'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_assoc_id" class="col-sm-2 control-label">社會關係人(assoc_id)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_assoc_id" name="c_assoc_id">
                            @if($res['biog_str2'])
                                <option value="{{ $row->c_assoc_id }}" selected="selected">{{ $res['biog_str2'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_inst_code" class="col-sm-2 control-label">社交機構代碼(c_inst_code)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_inst_code" name="c_inst_code">
                            @if($res['inst_str_new'])
                                <option value="{{ $row->c_inst_code }}" selected="selected">{{ $res['inst_str_new'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_inst_name_code" class="col-sm-2 control-label">社交機構名稱代碼(c_inst_name_code)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_inst_name_code" name="c_inst_name_code">
                            @if($res['inst_str'])
                                <option value="{{ $row->c_inst_name_code }}" selected="selected">{{ $res['inst_str'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">出處(source)</label>
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
                    <label for="c_notes" class="col-sm-2 control-label">注(notes)</label>
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
        $(".c_entry_code").select2(options('entry'));
        $(".c_addr_id").select2(options('addr'));
        $(".c_kin_code").select2(options('kincode'));
        $(".c_assoc_code").select2(options('assoccode'));
        $(".c_inst_code").select2(options('socialinstaddr'));
        $(".c_inst_name_code").select2(options('socialinst'));
        $(".c_source").select2(options('text'));
        $(".c_kin_id").select2(options('biog'));
        $(".c_assoc_id").select2(options('biog'));

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
