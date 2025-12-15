@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">人物記錄合併</h3>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('merge-preview.index') }}" class="form-horizontal">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="primary_id" class="col-sm-2 control-label">主要人物 ID</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" id="primary_id" name="primary_id" value="{{ old('primary_id', $form_primary ?? (isset($preview['primary_id']) ? $preview['primary_id'] : '')) }}" placeholder="輸入保留的 c_personid">
                    </div>
                </div>
                <div class="form-group">
                    <label for="secondary_id" class="col-sm-2 control-label">次要人物 ID</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" id="secondary_id" name="secondary_id" value="{{ old('secondary_id', $form_secondary ?? (isset($preview['secondary_id']) ? $preview['secondary_id'] : '')) }}" placeholder="輸入要合併掉的 c_personid">
                    </div>
                </div>
                <div class="form-group">
                    <label for="merge_reason" class="col-sm-2 control-label">合併理由</label>
                    <div class="col-sm-10">
                        <textarea class="form-control" id="merge_reason" name="merge_reason" rows="3" placeholder="簡要解釋合併理由，如史料證據與分析方法">{{ old('merge_reason', isset($preview['merge_reason']) ? $preview['merge_reason'] : ($merge_reason ?? '')) }}</textarea>
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-2 col-sm-10">
                        @php
                            $autoCheckedOld = old('auto_arrange');
                            if (is_null($autoCheckedOld)) {
                                if (isset($auto_arrange)) {
                                    $autoChecked = $auto_arrange;
                                } else {
                                    $autoChecked = true;
                                }
                            } else {
                                $autoChecked = (bool)$autoCheckedOld;
                            }
                        @endphp
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="auto_arrange" value="1" {{ $autoChecked ? 'checked' : '' }}>
                                將較大 ID 自動合併至較小 ID（合併後會進行一次 ID 移動）
                            </label>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-2 col-sm-10">
                        <button type="submit" class="btn btn-primary" id="preview-button">預覽合併結果</button>
                        <button type="button" class="btn btn-secondary" id="copy_merge_link" data-base="{{ route('merge-preview.index') }}">複製連結</button>
                    </div>
                </div>
            </form>

            @if(!empty($preview))
                <hr>
                <h4>合併結果預覽</h4>
                @if(!empty($merge_blocked))
                    <div class="alert alert-danger">
                        <strong>無法進行合併：</strong> 保留與來源人物的姓名資訊不同，請先人工確認後再動作。
                    </div>
                @endif
                <div class="row">
                    <div class="col-md-6">
                        <h5>保留人物</h5>
                        <table class="table table-bordered table-sm">
                            <tr>
                                <th>ID</th>
                                <td>
                                    @if($preview['primary_person']['exists'])
                                        <a href="{{ url('basicinformation/'.$preview['primary_id'].'/edit') }}" target="_blank">{{ $preview['primary_id'] }}</a>
                                    @else
                                        {{ $preview['primary_id'] }}
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>姓名</th>
                                <td>
                                    @if($preview['primary_person']['exists'])
                                        {{ $preview['primary_person']['name_chn'] }} ({{ $preview['primary_person']['name'] }})
                                    @else
                                        <span class="text-danger">查無資料</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>性別</th>
                                <td class="{{ $preview['gender_match'] === 'different' ? 'text-danger' : '' }}">
                                    @if($preview['primary_person']['exists'])
                                        {{ $preview['primary_person']['gender_label'] ?? '未詳' }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>朝代</th>
                                <td>
                                    @if($preview['primary_person']['exists'])
                                        {{ $preview['primary_person']['dynasty_name'] ?? '未詳' }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h5>合併來源</h5>
                        <table class="table table-bordered table-sm">
                            <tr>
                                <th>ID</th>
                                <td>
                                    @if($preview['secondary_person']['exists'])
                                        <a href="{{ url('basicinformation/'.$preview['secondary_id'].'/edit') }}" target="_blank">{{ $preview['secondary_id'] }}</a>
                                    @else
                                        {{ $preview['secondary_id'] }}
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>姓名</th>
                                <td>
                    @if($preview['secondary_person']['exists'])
                        {{ $preview['secondary_person']['name_chn'] }} ({{ $preview['secondary_person']['name'] }})
                    @else
                        <span class="text-danger">查無資料</span>
                    @endif
                </td>
            </tr>
            <tr>
                <th>朝代</th>
                <td>
                    @if($preview['secondary_person']['exists'])
                        {{ $preview['secondary_person']['dynasty_name'] ?? '未詳' }}
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <th>性別</th>
                <td class="{{ $preview['gender_match'] === 'different' ? 'text-danger' : '' }}">
                    @if($preview['secondary_person']['exists'])
                        {{ $preview['secondary_person']['gender_label'] ?? '未詳' }}
                    @else
                        —
                    @endif
                </td>
            </tr>
        </table>
    </div>
</div>

                @php
                    $statusLabels = [
                        'same' => '一致',
                        'different' => '不同',
                        'unknown' => '無法判斷',
                    ];
                    $statusClasses = [
                        'same' => 'text-success',
                        'different' => 'text-warning',
                        'unknown' => 'text-muted',
                    ];
                    $basicComparisons = [
                        [
                            'label' => '姓名',
                            'status' => $preview['name_match'] ?? 'unknown',
                            'messages' => [
                                'different' => '兩筆人物的姓名資訊不同，請再次確認合併關係。',
                                'unknown' => '至少一筆人物缺少姓名資料，無法比較姓名是否一致。',
                            ],
                        ],
                        [
                            'label' => '性別',
                            'status' => $preview['gender_match'] ?? 'unknown',
                            'messages' => [
                                'different' => '兩筆人物的性別不同，請再次確認合併關係。',
                                'unknown' => '無法判斷性別（其中至少一筆找不到人物資料或未填性別）。',
                            ],
                        ],
                        [
                            'label' => '朝代',
                            'status' => $preview['dynasty_match'] ?? 'unknown',
                            'messages' => [
                                'different' => '兩筆人物的朝代不同，請再次確認合併關係。',
                                'unknown' => '無法判斷朝代（其中至少一筆找不到人物資料）。',
                            ],
                        ],
                    ];
                    $basicParts = [];
                    $basicWarnings = [];
                    foreach ($basicComparisons as $item) {
                        $status = $item['status'];
                        $label = $item['label'];
                        $class = $statusClasses[$status] ?? '';
                        $statusText = $statusLabels[$status] ?? $status;
                        $basicParts[] = sprintf('%s：<span class="%s">%s</span>', $label, $class, $statusText);
                        if (!empty($item['messages'][$status] ?? '')) {
                            $basicWarnings[] = $item['messages'][$status];
                        }
                    }
                @endphp
                <div class="alert alert-info">
                    <strong>人物比對：</strong>
                    {!! implode('，', $basicParts) !!}
                    @if(!empty($basicWarnings))
                        <div class="small" style="margin-top: 8px;">
                            @foreach($basicWarnings as $warning)
                                <div class="text-warning">{{ $warning }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if(!empty($preview['merge_reason']))
                    <div class="alert alert-warning">
                        <strong>合併理由：</strong> {{ $preview['merge_reason'] }}
                    </div>
                @endif

                <h5>合併策略</h5>
                <p>{{ $preview['auto_arrange'] ? '已自動調整順序（大 ID 合併至小 ID）' : '依照輸入順序合併' }}</p>

                <h5>人物欄位比對</h5>
                @php
                    $columns = $preview['biog_columns'] ?? [];
                    $primaryAttrs = $preview['primary_person']['attributes'] ?? [];
                    $secondaryAttrs = $preview['secondary_person']['attributes'] ?? [];
                    $mergedAttrs = $preview['merged_person'] ?? [];
                    $mergedUpdates = $preview['merged_updates'] ?? [];
                @endphp
                @if(!empty($columns))
                    <div class="table-responsive">
                        <table class="table table-bordered table-condensed table-striped">
                            <colgroup>
                                <col style="width:10%;">
                                <col style="width:30%;">
                                <col style="width:30%;">
                                <col style="width:30%;">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>欄位</th>
                                    <th>保留人物值</th>
                                    <th>合併來源值</th>
                                    <th>合併後值（預覽）</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($columns as $column)
                                    @php
                                        $primaryVal = $primaryAttrs[$column] ?? null;
                                        $secondaryVal = $secondaryAttrs[$column] ?? null;
                                        $mergedVal = $mergedAttrs[$column] ?? ($secondaryVal ?? $primaryVal);
                                        $diff = ($primaryVal != $secondaryVal) || ($mergedVal != $secondaryVal) || array_key_exists($column, $mergedUpdates);
                                    @endphp
                                    <tr class="{{ $diff ? 'warning' : '' }}">
                                        <td>{{ $column }}</td>
                                        <td class="{{ ($column === 'c_name' || $column === 'c_name_chn') && $primaryVal != $secondaryVal ? 'text-danger' : '' }}">{{ is_null($primaryVal) ? 'NULL' : $primaryVal }}</td>
                                        <td class="{{ ($column === 'c_name' || $column === 'c_name_chn') && $primaryVal != $secondaryVal ? 'text-danger' : '' }}">{{ is_null($secondaryVal) ? 'NULL' : $secondaryVal }}</td>
                                        <td>{{ is_null($mergedVal) ? 'NULL' : $mergedVal }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted">無法取得 BIOG_MAIN 欄位列表。</p>
                @endif

                <h5>其他相關資料預覽</h5>
                <div class="row">
                    <div class="col-md-12">
                        @php
                            $altPrimaryCount = count($preview['altname_details_primary']);
                            $altSecondaryCount = count($preview['altname_details_secondary']);
                        @endphp
                        <h6>別名（ALTNAME_DATA）</h6>
                        @if($altPrimaryCount || $altSecondaryCount)
                            <table class="table table-bordered table-condensed table-striped">
                                <colgroup>
                                    <col style="width:10%;">
                                    <col style="width:45%;">
                                    <col style="width:45%;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>欄位</th>
                                        <th>保留人物 ({{ $preview['primary_id'] ?? '-' }}) — {{ $altPrimaryCount }} 筆</th>
                                        <th>合併來源 ({{ $preview['secondary_id'] ?? '-' }}) — {{ $altSecondaryCount }} 筆</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th>內容摘要</th>
                                        <td>
                                            @forelse($preview['altname_details_primary'] as $item)
                                                <div>
                                                    @php
                                                        $altName = $item->c_alt_name_chn ?: $item->c_alt_name ?: '(無)';
                                                        $altTypeLabel = $item->alt_type_label_chn ?: $item->alt_type_label;
                                                        $sequence = $item->c_sequence !== null ? $item->c_sequence : '(null)';
                                                    @endphp
                                                    Seq {{ $sequence }} — {{ $altName }}
                                                    (code {{ $item->c_alt_name_type_code }}
                                                    @if(!empty($altTypeLabel))
                                                        — {{ $altTypeLabel }}
                                                    @endif
                                                    )
                                                    <code style="display:block; white-space: pre-wrap; word-break: break-all;" class="text-muted">{{ json_encode((array)$item, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</code>
                                                </div>
                                            @empty
                                                <div class="text-muted">無資料</div>
                                            @endforelse
                                        </td>
                                        <td>
                                            @forelse($preview['altname_details_secondary'] as $item)
                                                <div>
                                                    @php
                                                        $altName = $item->c_alt_name_chn ?: $item->c_alt_name ?: '(無)';
                                                        $altTypeLabel = $item->alt_type_label_chn ?: $item->alt_type_label;
                                                        $sequence = $item->c_sequence !== null ? $item->c_sequence : '(null)';
                                                    @endphp
                                                    Seq {{ $sequence }} — {{ $altName }}
                                                    (code {{ $item->c_alt_name_type_code }}
                                                    @if(!empty($altTypeLabel))
                                                        — {{ $altTypeLabel }}
                                                    @endif
                                                    )
                                                    <code style="display:block; white-space: pre-wrap; word-break: break-all;" class="text-muted">{{ json_encode((array)$item, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</code>
                                                </div>
                                            @empty
                                                <div class="text-muted">無資料</div>
                                            @endforelse
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        @else
                            <p class="text-muted">無別名資料。</p>
                        @endif
                    </div>
                </div>
                <div class="row" style="margin-top: 15px;">
                    <div class="col-md-12">
                        <h6>親屬（KIN_DATA）</h6>
                        @php
                            $kinPrimaryCount = count($preview['kin_details_primary']);
                            $kinSecondaryCount = count($preview['kin_details_secondary']);
                        @endphp
                        @if($kinPrimaryCount || $kinSecondaryCount)
                            <table class="table table-bordered table-condensed table-striped">
                                <colgroup>
                                    <col style="width:10%;">
                                    <col style="width:45%;">
                                    <col style="width:45%;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>欄位</th>
                                        <th>保留人物 ({{ $preview['primary_id'] ?? '-' }}) — {{ $kinPrimaryCount }} 筆</th>
                                        <th>合併來源 ({{ $preview['secondary_id'] ?? '-' }}) — {{ $kinSecondaryCount }} 筆</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th>內容摘要</th>
                                        <td>
                                            @forelse($preview['kin_details_primary'] as $item)
                                                <div>
                                                    @php
                                                        $kinName = $item->kin_name_chn ?: $item->kin_name;
                                                        $kinCodeLabel = $item->kinship_label_chn ?: $item->kinship_label;
                                                    @endphp
                                                    KinID <a href="{{ url('basicinformation/' . $item->c_kin_id . '/edit') }}" target="_blank">{{ $item->c_kin_id }}</a>
                                                    @if(!empty($kinName))
                                                        — {{ $kinName }}
                                                    @endif
                                                    (code {{ $item->c_kin_code }}
                                                    @if(!empty($kinCodeLabel))
                                                        — {{ $kinCodeLabel }}
                                                    @endif
                                                    )
                                                    <code style="display:block; white-space: pre-wrap; word-break: break-all;" class="text-muted">{{ json_encode((array)$item, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</code>
                                                </div>
                                            @empty
                                                <div class="text-muted">無資料</div>
                                            @endforelse
                                        </td>
                                        <td>
                                            @forelse($preview['kin_details_secondary'] as $item)
                                                <div>
                                                    @php
                                                        $kinName = $item->kin_name_chn ?: $item->kin_name;
                                                        $kinCodeLabel = $item->kinship_label_chn ?: $item->kinship_label;
                                                    @endphp
                                                    KinID <a href="{{ url('basicinformation/' . $item->c_kin_id . '/edit') }}" target="_blank">{{ $item->c_kin_id }}</a>
                                                    @if(!empty($kinName))
                                                        — {{ $kinName }}
                                                    @endif
                                                    (code {{ $item->c_kin_code }}
                                                    @if(!empty($kinCodeLabel))
                                                        — {{ $kinCodeLabel }}
                                                    @endif
                                                    )
                                                    <code style="display:block; white-space: pre-wrap; word-break: break-all;" class="text-muted">{{ json_encode((array)$item, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</code>
                                                </div>
                                            @empty
                                                <div class="text-muted">無資料</div>
                                            @endforelse
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        @else
                            <p class="text-muted">無親屬資料。</p>
                        @endif
                    </div>
                </div>
                <div class="row" style="margin-top: 15px;">
                    <div class="col-md-12">
                        <h6>ASSOC_DATA 關聯概況</h6>
                        @php
                            $assocPrimary = $preview['table_counts_primary']['assoc'] ?? [];
                            $assocSecondary = $preview['table_counts_secondary']['assoc'] ?? [];
                            $assocDetailsPrimary = $preview['assoc_details_primary'] ?? [];
                            $assocDetailsSecondary = $preview['assoc_details_secondary'] ?? [];
                            $assocColumns = [
                                'c_personid' => 'c_personid = 人物 ID',
                                'c_kin_id' => 'c_kin_id = 親屬人物 ID',
                                'c_assoc_id' => 'c_assoc_id = 關聯人物 ID',
                                'c_assoc_kin_id' => 'c_assoc_kin_id = 關聯親屬 ID',
                            ];
                        @endphp
                        @foreach($assocColumns as $key => $label)
                            @php
                                $primaryCount = $assocPrimary[$key] ?? 0;
                                $secondaryCount = $assocSecondary[$key] ?? 0;
                                $primaryRows = collect($assocDetailsPrimary[$key] ?? []);
                                $secondaryRows = collect($assocDetailsSecondary[$key] ?? []);
                            @endphp
                            <div class="table-responsive" style="margin-top: 10px;">
                                <h6>{{ $label }}</h6>
                                <table class="table table-bordered table-condensed table-striped">
                                    <colgroup>
                                        <col style="width:10%;">
                                        <col style="width:45%;">
                                        <col style="width:45%;">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th>欄位</th>
                                            <th>保留人物 ({{ $preview['primary_id'] ?? '-' }}) — {{ $primaryCount }} 筆</th>
                                            <th>合併來源 ({{ $preview['secondary_id'] ?? '-' }}) — {{ $secondaryCount }} 筆</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <th>內容摘要</th>
                                            <td>
                                                @if($primaryRows->count())
                                                    <ul class="list-unstyled small" style="word-break: break-all;">
                                                        @foreach($primaryRows as $row)
                                                            <li><code style="white-space: pre-wrap; word-break: break-all;">{{ json_encode((array)$row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</code></li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <div class="text-muted">無資料</div>
                                                @endif
                                            </td>
                                            <td>
                                                @if($secondaryRows->count())
                                                    <ul class="list-unstyled small" style="word-break: break-all;">
                                                        @foreach($secondaryRows as $row)
                                                            <li><code style="white-space: pre-wrap; word-break: break-all;">{{ json_encode((array)$row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</code></li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <div class="text-muted">無資料</div>
                                                @endif
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        @endforeach
                    </div>
                </div>
                @php
                    $tableMap = [
                        'biog_addr' => 'BIOG_ADDR_DATA',
                        'biog_inst' => 'BIOG_INST_DATA',
                        'biog_source' => 'BIOG_SOURCE_DATA',
                        'biog_text' => 'BIOG_TEXT_DATA',
                        'entry' => 'ENTRY_DATA',
                        'events' => 'EVENTS_DATA',
                        'possession' => 'POSSESSION_DATA',
                        'status' => 'STATUS_DATA',
                        'posted_to_addr' => 'POSTED_TO_ADDR_DATA',
                        'posting' => 'POSTING_DATA',
                        'posted_to_office' => 'POSTED_TO_OFFICE_DATA',
                        'merged_person' => 'MERGED_PERSON_DATA',
                    ];
                    $countsPrimary = $preview['table_counts_primary'] ?? [];
                    $countsSecondary = $preview['table_counts_secondary'] ?? [];
                @endphp
                @foreach($tableMap as $key => $label)
                    @php
                        $primaryCount = $countsPrimary[$key] ?? 0;
                        $secondaryCount = $countsSecondary[$key] ?? 0;
                        $primaryRows = collect($preview['other_details_primary'][$label] ?? []);
                        $secondaryRows = collect($preview['other_details_secondary'][$label] ?? []);
                    @endphp
                    <div class="row" style="margin-top: 15px;">
                        <div class="col-md-12">
                            <h6>{{ $label }}</h6>
                            @if($primaryCount || $secondaryCount || $primaryRows->count() || $secondaryRows->count())
                                <table class="table table-bordered table-condensed table-striped">
                                    <colgroup>
                                        <col style="width:10%;">
                                        <col style="width:45%;">
                                        <col style="width:45%;">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th>欄位</th>
                                            <th>保留人物 ({{ $preview['primary_id'] ?? '-' }}) — {{ $primaryCount }} 筆</th>
                                            <th>合併來源 ({{ $preview['secondary_id'] ?? '-' }}) — {{ $secondaryCount }} 筆</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <th>內容摘要</th>
                                            <td>
                                                @if($primaryRows->count())
                                                    <ul class="list-unstyled small" style="word-break: break-all;">
                                                        @foreach($primaryRows as $row)
                                                            @php
                                                                $summary = $row['summary'] ?? '(無摘要)';
                                                                $rawData = $row['raw'] ?? $row;
                                                                $rawJson = json_encode($rawData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                                                            @endphp
                                                            <li>
                                                                <span>{{ $summary }}</span>
                                                                <code style="display:block; white-space: pre-wrap; word-break: break-all;" class="text-muted">{{ $rawJson }}</code>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <div class="text-muted">無資料</div>
                                                @endif
                                            </td>
                                            <td>
                                                @if($secondaryRows->count())
                                                    <ul class="list-unstyled small" style="word-break: break-all;">
                                                        @foreach($secondaryRows as $row)
                                                            @php
                                                                $summary = $row['summary'] ?? '(無摘要)';
                                                                $rawData = $row['raw'] ?? $row;
                                                                $rawJson = json_encode($rawData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                                                            @endphp
                                                            <li>
                                                                <span>{{ $summary }}</span>
                                                                <code style="display:block; white-space: pre-wrap; word-break: break-all;" class="text-muted">{{ $rawJson }}</code>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <div class="text-muted">無資料</div>
                                                @endif
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            @else
                                <p class="text-muted">無資料。</p>
                            @endif
                        </div>
                    </div>
                @endforeach

                <h5>SQL 操作預覽</h5>
                <p class="text-muted small">以下為示意語句，實際合併時請依工作流程審核後執行。</p>
                @if(!empty($preview['auto_min_target']) && $preview['auto_min_target'] != $preview['primary_id'])
                    <p class="text-muted small">已附上將資料最終調整為 ID {{ $preview['auto_min_target'] }} 的附加語句。</p>
                @endif
                <pre class="bg-light" style="padding: 12px;">{{ implode("\n", $preview['sql_preview']) }}</pre>

                <p class="text-muted">{{ $preview['notes'] }}</p>
            @else
                <p class="text-muted">輸入兩個人物編號後，系統會在此呈現合併前後的概要。</p>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function(){
            const copyBtn = document.getElementById('copy_merge_link');
            if (copyBtn) {
                copyBtn.addEventListener('click', function () {
                    const base = this.getAttribute('data-base') || window.location.pathname;
                    const params = new URLSearchParams();
                    const primaryVal = (document.getElementById('primary_id').value || '').trim();
                    const secondaryVal = (document.getElementById('secondary_id').value || '').trim();
                    const reasonVal = (document.getElementById('merge_reason').value || '').trim();
                    const autoBox = document.querySelector('input[name=\"auto_arrange\"]');
                    const mergeToMin = autoBox ? (autoBox.checked ? 'true' : 'false') : 'true';

                    if (primaryVal !== '') params.set('primary_id', primaryVal);
                    if (secondaryVal !== '') params.set('secondary_id', secondaryVal);
                    params.set('merge_to_min', mergeToMin);
                    if (reasonVal !== '') params.set('reason', reasonVal);

                    const query = params.toString();
                    const link = query ? base + '?' + query : base;

                    const copy = (txt) => {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(txt).then(function () {
                                alert('連結已複製到剪貼簿。');
                            }).catch(function () {
                                window.prompt('請複製以下連結：', txt);
                            });
                        } else {
                            window.prompt('請複製以下連結：', txt);
                        }
                    };

                    copy(link);
                });
            }
        })();
    </script>
@endpush
