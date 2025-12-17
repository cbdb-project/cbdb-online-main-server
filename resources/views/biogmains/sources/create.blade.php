@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">出處 Source</h3>
        </div>
        <div class="card-body">
            <div class="card-body">
            <form action="{{ route('basicinformation.sources.store', ['basicinformation' => $id]) }}" method="post">
                {{ csrf_field() }}
                <div class="form-group row">
                    <label for="person_id" class="col-sm-2 col-form-label">person id</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" value="{{ $id }}" disabled>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_textid" class="col-sm-2 col-form-label">出處(c_source)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_source" name="c_textid" required>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_pages" class="col-sm-2 col-form-label">頁數/條目</label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" name="c_pages" value="0">
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_notes" class="col-sm-2 col-form-label">注(c_notes)</label>
                    <div class="col-sm-10">
                        <textarea class="form-control" name="c_notes" id="" cols="30"
                                  rows="5"></textarea>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_main_source" class="col-sm-2 col-form-label">是主要出處</label>
                    <div class="col-sm-4">
                        <select class="form-control select2" name="c_main_source">
                            <option value=0>0-否
                            </option>
                            <option value=1>1-是
                            </option>
                        </select>
                    </div>
                    <label for="c_self_bio" class="col-sm-2 col-form-label">是本人傳記</label>
                    <div class="col-sm-4">
                        <select class="form-control select2" name="c_self_bio">
                            <option value="0">0-否
                            </option>
                            <option value="1">1-是
                            </option>
                        </select>
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
