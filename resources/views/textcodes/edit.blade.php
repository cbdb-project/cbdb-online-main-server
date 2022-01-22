@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">著作編碼表</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="/textcodes/{{ $id }}" class="form-horizontal" method="post">
                    {{ method_field('PATCH') }}
                    {{ csrf_field() }}
                    <div class="form-group">
                        <label for="author" class="col-sm-2 control-label">author</label>
                        <div class="col-sm-8">
                            <select class="form-control author" name="" readonly="readonly">
                            </select>
                        </div>
                        <div class="col-sm-2">
                            <button type="button" id="button_ajax_load" class="btn btn-info">Jump to author</button>
                        </div>
                    </div>
                    @foreach($row->toArray() as $key => $value)
                        <div class="form-group"
                        @if($key == 'c_created_by' || $key == 'c_created_date' || $key == 'c_modified_by' || $key == 'c_modified_date')
                            style="display:none"
                        @endif
                        >
                            <label for="{{ $key }}" class="col-sm-2 control-label">{{ $key }}</label>
                            <div class="col-sm-10">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ $value }}">
                            </div>
                        </div>
                    @endforeach
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
    author_first_load();
    function author_first_load(){
        let c_textid = $("input[name='c_textid']").val();
        //console.log(c_textid);
        let data = [{
            id: 0,
            text: 'author'
        }];
        $.get('/api/select/search/textauthor', {q: c_textid}, function (data, textStatus){
            //console.log(data);
            for (let i=data.data.length-1; i>-1; i--){
                item = data.data[i];
                //console.log(item);
                $(".author").append(new Option(item['text'], item['value']));
            }
        });
    }

    /* Simulate succeed ajax */
    $("#button_ajax_load").click(function(){
        var author = $(".author").val();
        var url = "/basicinformation/" + author + "/texts";
        //console.log(url);
        var new_window = window.open('_blank');
        new_window.location = url ;

    });

    </script>
@endsection
