@extends('layouts.dashboard')

@section('content')
@include('biogmains.defense')
    <div class="panel panel-default">
        <div class="panel-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                <p>* 修改类型 0表示crowdsourcing記錄，1表示新增，3表示修改，4表示删除<br />
                * 狀態 0代表是專業用戶修改的記錄，1代表crowdsourcing記錄並且已經被插入數據庫。
                </p>
                <thead>
                <tr>
                    <th>人物</th>
                    <th>修改资源</th>
                    <th>修改值</th>
                    <th>资源tts</th>
                    <th>修改类型</th>
                    <th>修改人</th>
                    <th>錄入时间</th>
                    <th>修改时间</th>
                    <th>狀態</th>
                </tr>
                </thead>
                <tbody>
                    @foreach($lists as $item)
@php
$item->resource_id = unionPKDef($item->resource_id);
$item->resource_data = unionPKDef($item->resource_data);
@endphp
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
    case "BIOG_TEXT_DATA":
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
      $res_id = str_replace("/","(slash)",$res_id);
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
//20200714不能直接轉回去, 版型會消失, 需要使用專屬的轉換函式.
$item->resource_id = unionPKDef_decode_for_convert($item->resource_id);
$item->resource_data = unionPKDef_decode_for_convert($item->resource_data);
@endphp
@endphp
/edit">{{ $item->biogmain->c_name_chn.' '.$item->biogmain->c_name }}</a>
                            </td>
                            <td>{{ $item->resource }}</td>
                            @php
                                $diffSource = $item->resource_diff ?? $item->resource_original;
                                $diffRows = is_array($diffSource) ? ($diffSource['rows'] ?? []) : [];
                                $hasDiffContent = !empty($diffRows) || (is_string($diffSource) && trim($diffSource) !== '');
                                $resourceDataParsed = json_decode($item->resource_data, true);
                                if (!is_array($resourceDataParsed)) {
                                    $resourceDataParsed = is_string($item->resource_data) ? trim($item->resource_data) : $item->resource_data;
                                }
                            @endphp
                                @php
                                    $canCompare = $hasDiffContent && (int)$item->op_type !== 4;
                                @endphp
                            <td>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal{{ $item->id }}">內容快照</button>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal-mapping{{ $item->id }}"
                                    {{ $canCompare ? '' : 'disabled' }}>
                                    比較
                                </button>

                                <div id="myModal{{ $item->id }}" class="modal fade" role="dialog" tabindex="-1">
                                  <div class="modal-dialog">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                        <h4 class="modal-title">內容快照</h4>
                                      </div>
                                      <div class="modal-body" style="word-break: break-all;">
                                        @include('components.key-value-table', ['data' => $resourceDataParsed])
                                      </div>
                                      <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                                      </div>
                                    </div>
                                  </div>
                                </div>

                                <div id="myModal-mapping{{ $item->id }}" class="modal fade" role="dialog" tabindex="-1">
                                  <div class="modal-dialog modal-lg" style="width:80vw;max-width:80vw;">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                        <h4 class="modal-title">比較</h4>
                                      </div>
                                      <div class="modal-body" style="word-break: break-all;">
                                        <div>
                                        @include('components.diff-table', ['diff' => $diffSource])
                                        </div>
                                      </div>
                                      <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                                @if(Auth::check() && Auth::user()->is_admin == 1 && in_array((int)$item->op_type, [3,4]) && $item->resource !== 'POSTED_TO_ADDR_DATA' && $canCompare)
                                    <form method="post" action="{{ route('operations.restore', $item->id) }}" style="display:inline;">
                                        {{ csrf_field() }}
                                        <button type="submit" class="btn btn-warning"
                                            onclick="return confirm('將以你的名義對該資源進行一次修改，恢復至本次改動之前，是否繼續？');">
                                            復原
                                        </button>
                                    </form>
                                @endif
                            </td>
                            <td>{{ $item->resource_id }}</td>
                            <td>
                                @php
                                    $opTypeLabels = [
                                        1 => '1-新增',
                                        2 => '2-整體覆寫',
                                        3 => '3-修改',
                                        4 => '4-刪除',
                                    ];
                                @endphp
                                {{ $opTypeLabels[$item->op_type] ?? $item->op_type }}
                            </td>
                            <td>{{ $item->user->name }}</td>
                            <td>{{ $item->created_at }}</td>
                            <td>{{ $item->updated_at }}</td>
                            <td>{{ $item->crowdsourcing_status }}</td>
                        </tr>
                    @endforeach
                </tbody>
                </table>
            </div>
            <div class="pull-right">
                {{ $lists->links() }}
            </div>
        </div>
    </div>

@endsection

@section('js')

@endsection
