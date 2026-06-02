@php
    $columns = [
        'before'  => __('operations.diff_before'),
        'after'   => __('operations.diff_after'),
        'current' => __('operations.diff_current'),
    ];

    $keyLabels = [
        'c_personid'   => __('person.person_id'),
        'c_office_id'  => __('person.office_id'),
        'c_posting_id' => __('person.posting_id'),
    ];

    $keys = $diff['keys'] ?? [];
    $addresses = $diff['addresses'] ?? [];

    $normalizeValue = function ($value) {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return trim((string) $value);
    };

    $computeHighlights = function (array $values) use ($normalizeValue) {
        $groups = [];
        $columnGroups = [];

        foreach ($values as $column => $value) {
            $normalized = $normalizeValue($value);
            if ($normalized === null || $normalized === '') {
                continue;
            }
            $groups[$normalized] = ($groups[$normalized] ?? 0) + 1;
            $columnGroups[$normalized][] = $column;
        }

        $highlights = [];
        foreach ($columnGroups as $normalized => $columnsWithValue) {
            if (($groups[$normalized] ?? 0) >= 2) {
                foreach ($columnsWithValue as $column) {
                    $highlights[$column] = true;
                }
            }
        }

        return $highlights;
    };

    $highlightStyle = 'background-color:#dff0d8;';
@endphp

<div class="posted-to-addr-diff">
    <div class="table-responsive">
        <table class="table table-bordered table-striped table-condensed">
            <thead>
                <tr>
                    <th>{{ __('operations.diff_field') }}</th>
                    @foreach ($columns as $columnLabel)
                        <th>{{ $columnLabel }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($keyLabels as $field => $label)
                    @php
                        $rowValues = [];
                        foreach ($columns as $columnKey => $columnLabel) {
                            $rowValues[$columnKey] = $keys[$columnKey][$field] ?? null;
                        }
                        $highlights = $computeHighlights($rowValues);
                    @endphp
                    <tr>
                        <td>{{ $label }}</td>
                        @foreach ($columns as $columnKey => $columnLabel)
                            @php
                                $value = $rowValues[$columnKey] ?? null;
                                $cellStyle = ($highlights[$columnKey] ?? false) ? $highlightStyle : '';
                            @endphp
                            <td @if ($cellStyle) style="{{ $cellStyle }}" @endif>
                                @if ($value === null)
                                    <span class="text-muted">—</span>
                                @else
                                    {{ $value }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped table-condensed">
            <thead>
                <tr>
                    <th>{{ __('operations.addr_id') }}</th>
                    @foreach ($columns as $columnLabel)
                        <th>{{ $columnLabel }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @if (empty($addresses))
                    <tr>
                        <td colspan="{{ count($columns) + 1 }}"><span class="text-muted small">{{ __('operations.no_addr_data') }}</span></td>
                    </tr>
                @else
                    @foreach ($addresses as $row)
                        @php
                            $rowValues = [];
                            foreach ($columns as $columnKey => $columnLabel) {
                                $rowValues[$columnKey] = $row[$columnKey] ?? null;
                            }
                            $highlights = $computeHighlights($rowValues);
                        @endphp
                        <tr>
                            <td>{{ $row['id'] ?? '—' }}</td>
                            @foreach ($columns as $columnKey => $columnLabel)
                                @php
                                    $entry = $rowValues[$columnKey] ?? null;
                                    $cellStyle = ($highlights[$columnKey] ?? false) ? $highlightStyle : '';
                                @endphp
                                <td @if ($cellStyle) style="{{ $cellStyle }}" @endif>
                                    @if ($entry === null)
                                        <span class="text-muted">—</span>
                                    @else
                                        {{ $entry }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
</div>
