@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">人名列表</div>
        <div class="panel-body">
            <table class="table table-bordered table-striped">
                <p>* 修改类型 1表示新增， 3表示修改，4表示删除</p>
                <thead>
                <tr>
                    <th>人物</th>
                    <th>修改资源</th>
                    <th>修改值</th>
                    <th>资源tts</th>
                    <th>修改类型</th>
                    <th>修改人</th>
                    <th>修改时间</th>
                </tr>
                </thead>
                <tbody>
                    @foreach($lists as $item)
                        <tr>
                            <td>
                            <a href="/basicinformation/
@php
  $a = $item->resource;
  $id = $item->c_personid;
  $res_id = $item->resource_id;
if($item->op_type == 4) { echo $id; }
else {
  switch ($a) {
    case "BIOG_MAIN":
      echo $id;
      break;
    case "BIOG_ADDR_DATA":
      echo $id."/addresses/".$res_id;
      break;
    case "ALTNAME_DATA":
      echo $id."/altnames/".$res_id;
      break;
    case "TEXT_DATA":
      echo $id."/texts/".$res_id;
      break;
    case "POSTED_TO_OFFICE_DATA":
      echo $id."/offices/".$res_id;
      break;
    case "ENTRY_DATA":
      echo $id."/entries/".$res_id;
      break;
    case "EVENTS_DATA":
      echo $id."/events/".$res_id;
      break;
    case "STATUS_DATA":
      echo $id."/statuses/".$res_id;
      break;
    case "KIN_DATA":
      echo $id."/kinship/".$res_id;
      break;
    case "ASSOC_DATA":
      echo $id."/assoc/".$res_id;
      break;
    case "POSSESSION_DATA":
      echo $id."/possession/".$res_id;
      break;
    case "BIOG_INST_DATA":
      echo $id."/socialinst/".$res_id;
      break;
    case "BIOG_SOURCE_DATA":
      echo $id."/sources/".$res_id;
      break;
    default: 
      echo $id;
  }
}
@endphp
/edit">{{ $item->biogmain->c_name_chn.' '.$item->biogmain->c_name }}</a>
                            </td>
                            <td>{{ $item->resource }}</td>
                            <td>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal{{ $item->id }}">resource_data</button>
                            </td>
                            <td>{{ $item->resource_id }}</td>
                            <td>{{ $item->op_type }}</td>
                            <td>{{ $item->user->name }}</td>
                            <td>{{ $item->updated_at }}</td>
                        </tr>
                        <!--Start-->
                        <div id="myModal{{ $item->id }}" class="modal fade" role="dialog">
                          <div class="modal-dialog">
                            <!-- Modal content-->
                            <div class="modal-content">
                              <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                <h4 class="modal-title">resource_data </h4>
                              </div>
                              <div class="modal-body" style="word-break: break-all;">
                                <textarea rows="16" cols="90">{{ $item->resource_data }}</textarea>
                              </div>
                              <div class="modal-footer">
                                <!--temporarily
                                <a href="" type="button" class="btn btn-success">Confirm</a>
                                <a href="" type="button" class="btn btn-danger">Reject</a>
                                -->
                                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                              </div>
                            </div>
                          </div>
                        </div>
                        <!--End-->
                    @endforeach
                </tbody>

            </table>
            <div class="pull-right">
                {{ $lists->links() }}
            </div>
        </div>
    </div>

@endsection

@section('js')

@endsection
