@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">MySQL EXPLAIN</h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                {{ __('admin.explain_sql_desc') }}
            </p>

            <form method="post" action="{{ route('admin.explainsql') }}" class="form">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="sql">{{ __('admin.explain_sql_label') }}</label>
                    <textarea name="sql" id="sql" rows="5" class="form-control @error('sql') is-invalid @enderror" placeholder="SELECT ...">{{ old('sql', $sql) }}</textarea>
                    @error('sql')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-primary">{{ __('admin.explain_sql_btn') }}</button>
            </form>

            @if($error)
                <div class="alert alert-danger" style="margin-top: 15px;">
                    {{ $error }}
                </div>
            @endif

            @if(is_array($results) && count($results) > 0)
                <div class="table-responsive" style="margin-top: 20px;">
                    <table class="table table-bordered table-striped table-sm">
                        <thead>
                        <tr>
                            @foreach($columns as $column)
                                <th>{{ $column }}</th>
                            @endforeach
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($results as $row)
                            <tr>
                                @foreach($columns as $column)
                                    <td>{{ data_get($row, $column) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif($results !== null && empty($results))
                <p style="margin-top: 20px;">{{ __('admin.explain_no_results') }}</p>
            @endif
        </div>
    </div>
@endsection
