@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('admin.batch_social_page_title') }}</h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                {!! __('admin.batch_social_desc') !!}
            </p>

            @if(!empty($batchErrors))
                <div class="alert alert-danger">
                    <p class="text-bold">{{ __('admin.batch_import_failed') }}</p>
                    <ul class="list-unstyled">
                        @foreach($batchErrors as $message)
                            <li>・{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(!empty($results))
                <div class="alert alert-success">
                    {{ __('admin.batch_social_added', ['count' => count($results)]) }}
                </div>
            @endif

            <form method="post" action="{{ route('admin.batch-load-social-institutes.store') }}">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="entries">{{ __('admin.batch_data_tab_sep') }}</label>
                    <textarea name="entries" id="entries" class="form-control @error('entries') is-invalid @enderror" rows="10" placeholder="{{ __('admin.batch_social_placeholder') }}">{{ old('entries', $input) }}</textarea>
                    @error('entries')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-primary">{{ __('admin.batch_submit') }}</button>
                <a href="{{ route('admin.batch-load-social-institutes') }}" class="btn btn-default">{{ __('admin.batch_clear_reset') }}</a>
            </form>

            @if(!empty($results))
                <div class="table-responsive" style="margin-top: 20px;">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                        <tr>
                            <th>{{ __('admin.batch_col_line') }}</th>
                            <th>{{ __('admin.batch_col_inst_name') }}</th>
                            <th>{{ __('admin.batch_col_name_code') }}</th>
                            <th>{{ __('admin.batch_col_name_pinyin') }}</th>
                            <th>{{ __('admin.batch_col_name_new') }}</th>
                            <th>{{ __('admin.batch_col_inst_code') }}</th>
                            <th>{{ __('admin.batch_col_type_code') }}</th>
                            <th>{{ __('admin.batch_col_dynasty_code') }}</th>
                            <th>{{ __('admin.batch_col_addr_id') }}</th>
                            <th>{{ __('admin.batch_col_source_textid') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($results as $row)
                            <tr>
                                <td>{{ $row['line'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['name_code'] }}</td>
                                <td>{{ $row['name_pinyin'] }}</td>
                                <td>{{ $row['name_created'] ? __('admin.batch_yes') : __('admin.batch_no') }}</td>
                                <td>{{ $row['inst_code'] }}</td>
                                <td>{{ $row['type_label'] }} / {{ $row['type_code'] }}</td>
                                <td>{{ $row['dynasty_label'] }} / {{ $row['dynasty_code'] }}</td>
                                <td>{{ $row['addr_id'] }}</td>
                                <td>{{ $row['source_id'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
