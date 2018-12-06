@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">社會關係</div>
        <div class="panel-body">
            <div class="panel-body">
            <form action="{{ route('basicinformation.assoc.update', [$id, $row->tts_sysno]) }}" class="form-horizontal" method="post">
                {{ method_field('PATCH') }}
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="person_id" class="col-sm-2 control-label">person id</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" value="{{ $id }}" disabled>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_sequence" class="col-sm-2 control-label">次序(sequence)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_sequence" value="{{ $row->c_sequence }}" maxlength="4">
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">親屬關係人</label>
                    <div class="col-sm-1">關係</div>
                    <div class="col-sm-3">
                        <select class="form-control c_kin_code" name="c_kin_code">
                            @if($res['kin_code'])
                                <option value="{{ $row->c_kin_code }}" selected="selected">{{ $res['kin_code'] }}</option>
                            @endif
                        </select>
                    </div>
                    <div class="col-sm-1">姓名</div>
                    <div class="col-sm-3">
                        <select class="form-control biog" name="c_kin_id">
                            @if($res['kin_id'])
                                <option value="{{ $row->c_kin_id }}" selected="selected">{{ $res['kin_id'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">社會關係人Y</label>
                    <div class="col-sm-1">關係</div>
                    <div class="col-sm-3">
                        <select class="form-control c_assoc_code" name="c_assoc_code" onchange="assocship_pair()">
                            @if($res['assoc_code'])
                                <option value="{{ $row->c_assoc_code }}" selected="selected">{{ $res['assoc_code'] }}</option>
                            @endif
                        </select>
                    </div>
                    <div class="col-sm-1">姓名</div>
                    <div class="col-sm-3">
                        <select class="form-control biog" name="c_assoc_id">
                            @if($res['assoc_id'])
                                <option value="{{ $row->c_assoc_id }}" selected="selected">{{ $res['assoc_id'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">社會關係人親屬</label>
                    <div class="col-sm-1">關係</div>
                    <div class="col-sm-3">
                        <select class="form-control c_assoc_kin_code" name="c_assoc_kin_code">
                            @if($res['assoc_kin_code'])
                                <option value="{{ $row->c_assoc_kin_code }}" selected="selected">{{ $res['assoc_kin_code'] }}</option>
                            @endif
                        </select>
                    </div>
                    <div class="col-sm-1">姓名</div>
                    <div class="col-sm-3">
                        <select class="form-control biog" name="c_assoc_kin_id">
                            @if($res['assoc_kin_id'])
                                <option value="{{ $row->c_assoc_kin_id }}" selected="selected">{{ $res['assoc_kin_id'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_assoc_year" class="col-sm-2 control-label">社會關係年份</label>
                    <div class="col-md-1">
                        <input type="number" name="c_assoc_year" class="form-control"
                               value="{{ $row->c_assoc_year }}">
                    </div>

                    <div class="col-md-2 from-inline">
                        <label for="c_assoc_nh_code">年号</label>
                        <select-vue name="c_assoc_nh_code" model="nianhao" selected="{{ $row->c_assoc_nh_code }}"></select-vue>
                        <input type="number" name="c_assoc_nh_year" class="form-control"
                               value="{{ $row->c_assoc_nh_year }}">
                        <span for="c_assoc_nh_year">年</span>
                    </div>
                    <div class="col-md-3">
                        <label for="">時限</label>
                        <select-vue name="c_assoc_range" model="range" selected="{{ $row->c_assoc_range }}"></select-vue>
                    </div>
                    <div class="col-md-2">
                        <label for="">閏</label>
                        <select name="c_assoc_intercalary" class="form-control select2">
                            <option disabled value="">请选择</option>
                            <option value="0" {{ $row->c_assoc_intercalary == 0? 'selected': '' }}>0-否
                            </option>
                            <option value="1" {{ $row->c_assoc_intercalary == 1? 'selected': '' }}>1-是
                            </option>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <input type="number" name="c_assoc_month" class="form-control"
                               value="{{ $row->c_assoc_month }}">
                        <span for="">月</span>
                        <input type="number" name="c_assoc_day" class="form-control"
                               value="{{ $row->c_assoc_day }}">
                        <span for="">日</span>
                        <label for="">日(干支) </label>
                        <select-vue name="c_assoc_day_gz" model="ganzhi" selected="{{ $row->c_assoc_day_gz }}"></select-vue>
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
                    <label for="c_topic_code" class="col-sm-2 control-label">學術主題</label>
                    <div class="col-sm-10">
                        <select-vue name="c_topic_code" model="topic" selected="{{ $row->c_topic_code }}"></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_occasion_code" class="col-sm-2 control-label">場合</label>
                    <div class="col-sm-10">
                        <select-vue name="c_occasion_code" model="occasion" selected="{{ $row->c_occasion_code }}"></select-vue>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_text_title" class="col-sm-2 control-label">作品標題</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_text_title" value="{{ $row->c_text_title }}">
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_assoc_count" class="col-sm-2 control-label">關係次數(c_assoc_count)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_assoc_count" value="{{ $row->c_assoc_count }}">
                        此欄位僅適用於書信 : 當無法以標題及日期區分多次信件時 , 則僅建「一筆」社會關係 , 並將信件總數填於此欄 . 請填阿拉伯數字
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">社會關係指證人</label>
                    <div class="col-sm-10">
                        <select class="form-control biog" name="c_tertiary_personid">
                            @if($res['tertiary_personid'])
                                <option value="{{ $row->c_tertiary_personid }}" selected="selected">{{ $res['tertiary_personid'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">社會關係發生地</label>
                    <div class="col-sm-10">
                        <select class="form-control c_addr_id" name="c_addr_id">
                            @if($res['addr_id'])
                                <option value="{{ $row->c_addr_id }}" selected="selected">{{ $res['addr_id'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label">社交機構代碼(c_inst_code)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_inst_code" name="c_inst_code">
                            @if($res['inst_code'])
                                <option value="{{ $row->c_inst_code }}" selected="selected">{{ $res['inst_code'] }}</option>
                            @endif
                        </select>
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
                    <label for="" class="col-sm-2 control-label">成对社会关系</label>
                    <div class="col-sm-10">
                        <select class="form-control c_assocship_pair" name="c_assocship_pair">
                            <option value="" selected="selected"></option>
                        </select>
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
        $(".biog").select2(options('biog'));
        $(".c_kin_code").select2(options('kincode'));
        $(".c_assoc_kin_code").select2(options('kincode'));
        $(".c_assoc_code").select2(options('assoccode'));
        $(".c_addr_id").select2(options('addr'));
        $(".c_inst_code").select2(options('socialinst'));
        $(".c_source").select2(options('text'));
        $(".c_assocship_pair").select2();
        assocship_pair();

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

        function assocship_pair(){
            let c_assoc_code = $('.c_assoc_code').val();
            let c_assoc_id = $('.c_assoc_id').val();
            // console.log(c_assoc_id, c_assoc_code);
            // if (c_kin_id == 0 || c_kin_id == -999) {return}
            let data = [{
                id: 0,
                text: '请选择对应社会关系'
            }];
            // $(".c_kinship_pair").val(null).trigger("change");
            // console.log($(".c_kinship_pair").val());
            $.get('/api/select/search/assocpair', {assoc_code: c_assoc_code, person_id: c_assoc_id}, function (data, textStatus){
                //返回的 data 可以是 xmlDoc, jsonObj, html, text, 等等.
                // console.log(data);
                for (let i=data.length-1; i>-1; i--){

                    item = data[i];
                    // console.log(item);
                    $(".c_assocship_pair").append(new Option(item['c_assoc_code'] + ' ' + item['c_assoc_desc_chn'] + ' ' + item['c_assoc_desc'], item['c_assoc_code'], false, true));
                }
            });

        }
    </script>
@endsection
