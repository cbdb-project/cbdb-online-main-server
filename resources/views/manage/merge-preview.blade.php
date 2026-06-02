@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('admin.manage_merge_title') }}</h3>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('merge-preview.index') }}" class="form-horizontal">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="primary_id" class="col-sm-2 control-label">{{ __('admin.manage_merge_primary_id') }}</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" id="primary_id" name="primary_id" value="{{ old('primary_id', $form_primary ?? (isset($preview['primary_id']) ? $preview['primary_id'] : '')) }}" placeholder="{{ __('admin.manage_merge_primary_placeholder') }}">
                    </div>
                </div>
                <div class="form-group">
                    <label for="secondary_id" class="col-sm-2 control-label">{{ __('admin.manage_merge_secondary_id') }}</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" id="secondary_id" name="secondary_id" value="{{ old('secondary_id', $form_secondary ?? (isset($preview['secondary_id']) ? $preview['secondary_id'] : '')) }}" placeholder="{{ __('admin.manage_merge_secondary_placeholder') }}">
                    </div>
                </div>
                <div class="form-group">
                    <label for="merge_reason" class="col-sm-2 control-label">{{ __('admin.manage_merge_reason') }}</label>
                    <div class="col-sm-10">
                        <textarea class="form-control" id="merge_reason" name="merge_reason" rows="3" placeholder="{{ __('admin.manage_merge_reason_placeholder') }}">{{ old('merge_reason', isset($preview['merge_reason']) ? $preview['merge_reason'] : ($merge_reason ?? '')) }}</textarea>
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
                                {{ __('admin.manage_merge_auto_arrange') }}
                            </label>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-2 col-sm-10">
                        <button type="submit" class="btn btn-primary" id="preview-button">{{ __('admin.manage_merge_preview_btn') }}</button>
                        <button type="button" class="btn btn-secondary" id="copy_merge_link" data-base="{{ route('merge-preview.index') }}">{{ __('admin.manage_merge_copy_link') }}</button>
                    </div>
                </div>
            </form>

            @if(!empty($preview))
                <hr>
                <h4>{{ __('admin.manage_merge_result_title') }}</h4>
                @if(!empty($merge_blocked))
                    <div class="alert alert-danger">
                        <strong>{{ __('admin.manage_merge_blocked') }}</strong>
                    </div>
                @endif
                <div class="row">
                    <div class="col-md-6">
                        <h5>{{ __('admin.manage_merge_primary_person') }}</h5>
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
                                <th>{{ __('admin.manage_merge_name_col') }}</th>
                                <td>
                                    @if($preview['primary_person']['exists'])
                                        {{ $preview['primary_person']['name_chn'] }} ({{ $preview['primary_person']['name'] }})
                                    @else
                                        <span class="text-danger">{{ __('admin.manage_merge_no_data') }}</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>{{ __('admin.manage_merge_gender_col') }}</th>
                                <td class="{{ $preview['gender_match'] === 'different' ? 'text-danger' : '' }}">
                                    @if($preview['primary_person']['exists'])
                                        {{ $preview['primary_person']['gender_label'] ?? __('admin.manage_merge_unknown') }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>{{ __('admin.manage_merge_dynasty_col') }}</th>
                                <td>
                                    @if($preview['primary_person']['exists'])
                                        {{ $preview['primary_person']['dynasty_name'] ?? __('admin.manage_merge_unknown') }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h5>{{ __('admin.manage_merge_secondary_person') }}</h5>
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
                                <th>{{ __('admin.manage_merge_name_col') }}</th>
                                <td>
                    @if($preview['secondary_person']['exists'])
                        {{ $preview['secondary_person']['name_chn'] }} ({{ $preview['secondary_person']['name'] }})
                    @else
                        <span class="text-danger">{{ __('admin.manage_merge_no_data') }}</span>
                    @endif
                </td>
            </tr>
            <tr>
                <th>{{ __('admin.manage_merge_dynasty_col') }}</th>
                <td>
                    @if($preview['secondary_person']['exists'])
                        {{ $preview['secondary_person']['dynasty_name'] ?? __('admin.manage_merge_unknown') }}
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <th>{{ __('admin.manage_merge_gender_col') }}</th>
                <td class="{{ $preview['gender_match'] === 'different' ? 'text-danger' : '' }}">
                    @if($preview['secondary_person']['exists'])
                        {{ $preview['secondary_person']['gender_label'] ?? __('admin.manage_merge_unknown') }}
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
                        'same'    => __('admin.manage_merge_status_same'),
                        'different' => __('admin.manage_merge_status_different'),
                        'unknown' => __('admin.manage_merge_status_unknown'),
                    ];
                    $statusClasses = [
                        'same'      => 'text-success',
                        'different' => 'text-warning',
                        'unknown'   => 'text-muted',
                    ];
                    $basicComparisons = [
                        [
                            'label'  => __('admin.manage_merge_name_col'),
                            'status' => $preview['name_match'] ?? 'unknown',
                            'messages' => [
                                'different' => __('admin.manage_merge_name_diff'),
                                'unknown'   => __('admin.manage_merge_name_unknown'),
                            ],
                        ],
                        [
                            'label'  => __('admin.manage_merge_gender_col'),
                            'status' => $preview['gender_match'] ?? 'unknown',
                            'messages' => [
                                'different' => __('admin.manage_merge_gender_diff'),
                                'unknown'   => __('admin.manage_merge_gender_unknown'),
                            ],
                        ],
                        [
                            'label'  => __('admin.manage_merge_dynasty_col'),
                            'status' => $preview['dynasty_match'] ?? 'unknown',
                            'messages' => [
                                'different' => __('admin.manage_merge_dynasty_diff'),
                                'unknown'   => __('admin.manage_merge_dynasty_unknown'),
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
                    <strong>{{ __('admin.manage_merge_comparison') }}</strong>
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
                        <strong>{{ __('admin.manage_merge_reason_label') }}</strong> {{ $preview['merge_reason'] }}
                    </div>
                @endif

                <h5>{{ __('admin.manage_merge_strategy') }}</h5>
                <p>{{ $preview['auto_arrange'] ? __('admin.manage_merge_auto_strategy') : __('admin.manage_merge_manual_strategy') }}</p>

                <h5>{{ __('admin.manage_merge_field_comparison') }}</h5>
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
                                    <th>{{ __('admin.manage_merge_field_col') }}</th>
                                    <th>{{ __('admin.manage_merge_primary_val') }}</th>
                                    <th>{{ __('admin.manage_merge_secondary_val') }}</th>
                                    <th>{{ __('admin.manage_merge_result_val') }}</th>
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
                    <p class="text-muted">{{ __('admin.manage_merge_no_biog_cols') }}</p>
                @endif

                <h5>{{ __('admin.manage_merge_other_data') }}</h5>
                <div class="row">
                    <div class="col-md-12">
                        @php
                            $altPrimaryCount = count($preview['altname_details_primary']);
                            $altSecondaryCount = count($preview['altname_details_secondary']);
                        @endphp
                        <h6>{{ __('admin.manage_merge_altname_section') }}</h6>
                        @if($altPrimaryCount || $altSecondaryCount)
                            <table class="table table-bordered table-condensed table-striped">
                                <colgroup>
                                    <col style="width:10%;">
                                    <col style="width:45%;">
                                    <col style="width:45%;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>{{ __('admin.manage_merge_field_col') }}</th>
                                        <th>{{ __('admin.manage_merge_primary_person') }} ({{ $preview['primary_id'] ?? '-' }}) — {{ $altPrimaryCount }} {{ __('admin.manage_merge_records_unit') }}</th>
                                        <th>{{ __('admin.manage_merge_secondary_person') }} ({{ $preview['secondary_id'] ?? '-' }}) — {{ $altSecondaryCount }} {{ __('admin.manage_merge_records_unit') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th>{{ __('admin.manage_merge_content_summary') }}</th>
                                        <td>
                                            @forelse($preview['altname_details_primary'] as $item)
                                                <div>
                                                    @php
                                                        $altName = $item->c_alt_name_chn ?: $item->c_alt_name ?: __('admin.manage_merge_none');
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
                                                <div class="text-muted">{{ __('common.no_data') }}</div>
                                            @endforelse
                                        </td>
                                        <td>
                                            @forelse($preview['altname_details_secondary'] as $item)
                                                <div>
                                                    @php
                                                        $altName = $item->c_alt_name_chn ?: $item->c_alt_name ?: __('admin.manage_merge_none');
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
                                                <div class="text-muted">{{ __('common.no_data') }}</div>
                                            @endforelse
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        @else
                            <p class="text-muted">{{ __('admin.manage_merge_no_altname') }}</p>
                        @endif
                    </div>
                </div>
                <div class="row" style="margin-top: 15px;">
                    <div class="col-md-12">
                        <h6>{{ __('admin.manage_merge_kin_section') }}</h6>
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
                                        <th>{{ __('admin.manage_merge_field_col') }}</th>
                                        <th>{{ __('admin.manage_merge_primary_person') }} ({{ $preview['primary_id'] ?? '-' }}) — {{ $kinPrimaryCount }} {{ __('admin.manage_merge_records_unit') }}</th>
                                        <th>{{ __('admin.manage_merge_secondary_person') }} ({{ $preview['secondary_id'] ?? '-' }}) — {{ $kinSecondaryCount }} {{ __('admin.manage_merge_records_unit') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th>{{ __('admin.manage_merge_content_summary') }}</th>
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
                                                <div class="text-muted">{{ __('common.no_data') }}</div>
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
                                                <div class="text-muted">{{ __('common.no_data') }}</div>
                                            @endforelse
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        @else
                            <p class="text-muted">{{ __('admin.manage_merge_no_kin') }}</p>
                        @endif
                    </div>
                </div>
                <div class="row" style="margin-top: 15px;">
                    <div class="col-md-12">
                        <h6>ASSOC_DATA {{ __('admin.manage_merge_comparison') }}</h6>
                        @php
                            $assocPrimary = $preview['table_counts_primary']['assoc'] ?? [];
                            $assocSecondary = $preview['table_counts_secondary']['assoc'] ?? [];
                            $assocDetailsPrimary = $preview['assoc_details_primary'] ?? [];
                            $assocDetailsSecondary = $preview['assoc_details_secondary'] ?? [];
                            $assocColumns = [
                                'c_personid'     => __('admin.manage_merge_assoc_personid'),
                                'c_kin_id'       => __('admin.manage_merge_assoc_kin_id'),
                                'c_assoc_id'     => __('admin.manage_merge_assoc_assoc_id'),
                                'c_assoc_kin_id' => __('admin.manage_merge_assoc_kin_assoc_id'),
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
                                            <th>{{ __('admin.manage_merge_field_col') }}</th>
                                            <th>{{ __('admin.manage_merge_primary_person') }} ({{ $preview['primary_id'] ?? '-' }}) — {{ $primaryCount }} {{ __('admin.manage_merge_records_unit') }}</th>
                                            <th>{{ __('admin.manage_merge_secondary_person') }} ({{ $preview['secondary_id'] ?? '-' }}) — {{ $secondaryCount }} {{ __('admin.manage_merge_records_unit') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <th>{{ __('admin.manage_merge_content_summary') }}</th>
                                            <td>
                                                @if($primaryRows->count())
                                                    <ul class="list-unstyled small" style="word-break: break-all;">
                                                        @foreach($primaryRows as $row)
                                                            <li><code style="white-space: pre-wrap; word-break: break-all;">{{ json_encode((array)$row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</code></li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <div class="text-muted">{{ __('common.no_data') }}</div>
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
                                                    <div class="text-muted">{{ __('common.no_data') }}</div>
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
                        'biog_addr'        => 'BIOG_ADDR_DATA',
                        'biog_inst'        => 'BIOG_INST_DATA',
                        'biog_source'      => 'BIOG_SOURCE_DATA',
                        'biog_text'        => 'BIOG_TEXT_DATA',
                        'entry'            => 'ENTRY_DATA',
                        'events'           => 'EVENTS_DATA',
                        'possession'       => 'POSSESSION_DATA',
                        'status'           => 'STATUS_DATA',
                        'posted_to_addr'   => 'POSTED_TO_ADDR_DATA',
                        'posting'          => 'POSTING_DATA',
                        'posted_to_office' => 'POSTED_TO_OFFICE_DATA',
                        'merged_person'    => 'MERGED_PERSON_DATA',
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
                                            <th>{{ __('admin.manage_merge_field_col') }}</th>
                                            <th>{{ __('admin.manage_merge_primary_person') }} ({{ $preview['primary_id'] ?? '-' }}) — {{ $primaryCount }} {{ __('admin.manage_merge_records_unit') }}</th>
                                            <th>{{ __('admin.manage_merge_secondary_person') }} ({{ $preview['secondary_id'] ?? '-' }}) — {{ $secondaryCount }} {{ __('admin.manage_merge_records_unit') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <th>{{ __('admin.manage_merge_content_summary') }}</th>
                                            <td>
                                                @if($primaryRows->count())
                                                    <ul class="list-unstyled small" style="word-break: break-all;">
                                                        @foreach($primaryRows as $row)
                                                            @php
                                                                $summary = $row['summary'] ?? __('admin.manage_merge_no_summary');
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
                                                    <div class="text-muted">{{ __('common.no_data') }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                @if($secondaryRows->count())
                                                    <ul class="list-unstyled small" style="word-break: break-all;">
                                                        @foreach($secondaryRows as $row)
                                                            @php
                                                                $summary = $row['summary'] ?? __('admin.manage_merge_no_summary');
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
                                                    <div class="text-muted">{{ __('common.no_data') }}</div>
                                                @endif
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            @else
                                <p class="text-muted">{{ __('admin.manage_merge_no_data_row') }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach

                <h5>{{ __('admin.manage_merge_sql_preview') }}</h5>
                <p class="text-muted small">{{ __('admin.manage_merge_sql_hint') }}</p>
                @if(!empty($preview['auto_min_target']) && $preview['auto_min_target'] != $preview['primary_id'])
                    <p class="text-muted small">{{ __('admin.manage_merge_id_adjust_hint', ['id' => $preview['auto_min_target']]) }}</p>
                @endif
                <pre class="bg-light" style="padding: 12px;">{{ implode("\n", $preview['sql_preview']) }}</pre>

                <p class="text-muted">{{ $preview['notes'] }}</p>
            @else
                <p class="text-muted">{{ __('admin.manage_merge_enter_hint') }}</p>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function(){
            var _linkCopied = {!! Js::from(__('admin.manage_merge_link_copied')) !!};
            var _copyPrompt = {!! Js::from(__('admin.manage_merge_copy_prompt')) !!};

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
                                alert(_linkCopied);
                            }).catch(function () {
                                window.prompt(_copyPrompt, txt);
                            });
                        } else {
                            window.prompt(_copyPrompt, txt);
                        }
                    };

                    copy(link);
                });
            }
        })();
    </script>
@endpush
