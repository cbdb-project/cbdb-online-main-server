@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">官名 Postings</div>
        <div class="panel-body">
            <div class="panel-body">
            <form action="{{ route('basicinformation.offices.store', $id) }}" class="form-horizontal" method="post">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="person_id" class="col-sm-2 control-label">person id</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" value="{{ $id }}" disabled>
                    </div>
                </div>
                <div class="form-group">
                    <label for="person_id" class="col-sm-2 control-label">次序(sequence)</label>
                    <div class="col-sm-10">
                        <input name="c_sequence" type="text" class="form-control" value="" maxlength="4">
                        <p>註:若有同時任命的官職, 請手動填上相同的sequence</p>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_office_id" class="col-sm-2 control-label">官名(office_id)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_office_id" name="c_office_id">
                            <option value="0">0 unknown 未详</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_office_id" class="col-sm-2 control-label">社交機構代碼(c_inst_code)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_inst_code" name="c_inst_code">

                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_addr" class="col-sm-2 control-label">地名</label>
                    <div class="col-sm-10">
                        <select class="form-control c_addr" name="c_addr[]" multiple="multiple">

                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_source" class="col-sm-2 control-label">出處(c_source)</label>
                    <div class="col-sm-5">
                        <select class="form-control c_source" name="c_source">

                        </select>
                    </div>
                    <label for="c_pages" class="col-sm-2 control-label">頁數/條目</label>
                    <div class="col-sm-3">
                        <input type="text" class="form-control" name="c_pages" value="">
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_firstyear" class="col-sm-2 control-label">始年(firstyear)</label>
                    <div class="col-md-1">
                        <input type="text" name="c_firstyear" class="form-control"
                               value="">
                    </div>

                    <div class="col-md-2 from-inline">
                        <label for="c_fy_nh_code">年号</label>
                        <select-vue name="c_fy_nh_code" model="nianhao" selected=""></select-vue>
                        <input type="text" name="c_fy_nh_year" class="form-control"
                               value="">
                        <span for="">年</span>
                    </div>
                    <div class="col-md-3">
                        <label for="">時限</label>
                        <select-vue name="c_fy_range" model="range" selected=""></select-vue>
                    </div>
                    <div class="col-md-2">
                        <label for="">閏</label>
                        <select name="c_fy_intercalary" class="form-control select2" name="c_natal">
                            <option disabled value="">请选择</option>
                            <option value="0" selected>0-否
                            </option>
                            <option value="1">1-是
                            </option>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <input type="text" name="c_fy_month" class="form-control"
                               value="">
                        <span for="">月</span>
                        <input type="text" name="c_fy_day" class="form-control"
                               value="">
                        <span for="">日</span>
                        <label for="">日(干支) </label>
                        <select-vue name="c_fy_day_gz" model="ganzhi" selected=""></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_lastyear" class="col-sm-2 control-label">終年(lastyear)</label>
                    <div class="col-md-1">
                        <input type="text" name="c_lastyear" class="form-control"
                               value="">
                    </div>

                    <div class="col-md-2 from-inline">
                        <label for="c_ly_nh_code">年号</label>
                        <select-vue name="c_ly_nh_code" model="nianhao" selected=""></select-vue>
                        <input type="text" name="c_ly_nh_year" class="form-control"
                               value="">
                        <span for="">年</span>
                    </div>
                    <div class="col-md-3">
                        <label for="">時限</label>
                        <select-vue name="c_ly_range" model="range" selected=""></select-vue>
                    </div>
                    <div class="col-md-2">
                        <label for="">閏</label>
                        <select name="c_ly_intercalary" class="form-control select2" name="c_natal">
                            <option disabled value="">请选择</option>
                            <option value="0" selected>0-否
                            </option>
                            <option value="1">1-是
                            </option>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <input type="text" name="c_ly_month" class="form-control"
                               value="">
                        <span for="">月</span>
                        <input type="text" name="c_ly_day" class="form-control"
                               value="">
                        <span for="">日</span>
                        <label for="">日(干支) </label>
                        <select-vue name="c_ly_day_gz" model="ganzhi" selected=""></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_appt_type_code" class="col-sm-2 control-label">除授類別(c_appt_type_code)</label>
                    <div class="col-sm-10">
                        <select-vue name="c_appt_type_code" model="appttype" selected=""></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_assume_office_code" class="col-sm-2 control-label">是否赴任(c_assume_office_code)</label>
                    <div class="col-sm-10">
                        <select-vue name="c_assume_office_code" model="assumeoffice" selected=""></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_office_category_id" class="col-sm-2 control-label">職官類別(c_office_category_id)</label>
                    <div class="col-sm-10">
                        <select-vue name="c_office_category_id" model="officecate" selected=""></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_notes" class="col-sm-2 control-label">注(c_notes)</label>
                    <div class="col-sm-10">
                        <textarea class="form-control" name="c_notes" id="" cols="30"
                                  rows="5"></textarea>

                    </div>
                </div>
                <div class="form-group">
                    <label for="c_dy" class="col-sm-2 control-label">朝代(dy)</label>
                    <div class="col-sm-10">
                        <select-vue name="c_dy" model="dynasty" selected=""></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">建檔</label>
                    <div class="col-sm-10">
                        <input type="text" name="" class="form-control"
                               value=""
                               disabled>
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">更新</label>
                    <div class="col-sm-10">
                        <input type="text" name="" class="form-control"
                               value=""
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
        $(".c_office_id").select2(options('office'));
        $(".c_source").select2(options('text'));
        $(".c_inst_code").select2(options('socialinst'));
        $(".c_addr").select2(options('officeaddr'));

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