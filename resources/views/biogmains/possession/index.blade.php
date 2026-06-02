@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
$canEditSequence = Auth::check() && Auth::user()->canWriteDirectly();
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('biogmains.possession_list') }}</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.possession.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">{{ __('common.add') }}</a>
                @endif
            @endauth
            @if($canEditSequence)
                @include('biogmains.partials.list-order-toolbar', [
                    'targetTableId' => 'possession-list-table',
                    'toolbarLabel' => __('biogmains.possession_seq_title'),
                ])
            @endif
            <div class="table-responsive">
                <table id="possession-list-table" class="table table-hover table-sm">
                <caption>{{ __('biogmains.record_count', ['count' => $basicinformation->possession_count]) }}</caption>
                <thead>
                <tr>
                    <th>{{ __('person.seq_no') }}</th>
                    @if($canEditSequence)
                        <th data-sequence-demo-col style="min-width: 180px; display: none;">{{ __('biogmains.new_sequence') }}</th>
                    @endif
                    <th>{{ __('biogmains.action_col') }}</th>
                    <th>{{ __('biogmains.possession_col') }}</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">{{ __('biogmains.actions') }}</th>
                        @endif
                    @endauth
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->possession as $value)
                    <tr>
                        <td>{{ $value->pivot->c_sequence }}</td>
                        @if($canEditSequence)
                        <td data-sequence-demo-col style="display: none;">
                            @php
                            $possessionPk = [
                                'c_possession_record_id' => $value->pivot->c_possession_record_id,
                            ];
                            @endphp
                            <form
                                action="{{ CompositePrimaryKey::buildUrl('basicinformation.possession.update.query', ['id' => $basicinformation->c_personid], $possessionPk) }}"
                                method="POST"
                                class="form-inline m-0 js-possession-sequence-form"
                                data-person-id="{{ $basicinformation->c_personid }}"
                                data-pk-c-possession-record-id="{{ $possessionPk['c_possession_record_id'] }}"
                            >
                                {{ csrf_field() }}
                                {{ method_field('PATCH') }}
                                <input
                                    type="number"
                                    name="c_sequence"
                                    class="form-control form-control-sm mr-1"
                                    style="width: 78px;"
                                    value="{{ $value->pivot->c_sequence ?? '' }}"
                                >
                                <button type="submit" class="btn btn-sm btn-outline-primary js-possession-sequence-submit">{{ __('biogmains.submit_btn') }}</button>
                                <small class="text-muted ml-2 d-none js-possession-sequence-status"></small>
                            </form>
                        </td>
                        @endif
                        <td>{{ $value->c_possession_act_desc_chn }}</td>
                        <td>{{ $value->pivot->c_possession_desc_chn }}</td>
                        @auth
                            @if(Auth::user()->isActive())
                                <td>
                                    @php
                                    $possessionFormId = 'delete-form-' . $value->pivot->c_possession_record_id;
                                    @endphp
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.possession.edit.query', ['id' => $basicinformation->c_personid], $possessionPk) }}">{{ __('common.edit') }}</a>
                                        <a href=""
                                           onclick="
                                                   if (confirm({!! Js::from(__('biogmains.delete_confirm_js')) !!})===true){
                                                       event.preventDefault();
                                                       document.getElementById('{{ $possessionFormId }}').submit();
                                                   }else{
                                                       return false;
                                                   }
                                                   "
                                           class="btn btn-sm btn-danger">{{ __('common.delete') }}</a>

                                    </div>
                                    <form id="{{ $possessionFormId }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.possession.destroy.query', ['id' => $basicinformation->c_personid], $possessionPk) }}" method="POST" style="display: none;">
                                        {{ method_field('DELETE') }}
                                        {{ csrf_field() }}
                                    </form>
                                </td>
                            @endif
                        @endauth
                    </tr>
                @endforeach
                </tbody>
                </table>
            </div>
        </div>
    </div>

    @include('biogmains.history-button')
@endsection

@push('scripts')
<script>
    (function() {
        var __i18n = {
            nonJsonResponse: {!! Js::from(__('biogmains.non_json_response')) !!},
            submitFailed:    {!! Js::from(__('biogmains.submit_failed')) !!},
            networkError:    {!! Js::from(__('biogmains.network_error')) !!},
            submitted:       {!! Js::from(__('biogmains.submitted_ok')) !!},
        };

        function getCsrfToken() {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            return tokenEl ? tokenEl.getAttribute('content') : '';
        }

        function setStatus(form, text, type) {
            var statusEl = form.querySelector('.js-possession-sequence-status');
            if (!statusEl) {
                return;
            }

            statusEl.classList.remove('d-none', 'text-success', 'text-danger', 'text-muted');
            statusEl.classList.add(type === 'error' ? 'text-danger' : 'text-success');
            statusEl.textContent = text;
        }

        function clearStatus(form) {
            var statusEl = form.querySelector('.js-possession-sequence-status');
            if (!statusEl) {
                return;
            }

            statusEl.classList.add('d-none');
            statusEl.textContent = '';
            statusEl.classList.remove('text-success', 'text-danger', 'text-muted');
        }

        async function submitSequenceViaApi(form) {
            var submitBtn = form.querySelector('.js-possession-sequence-submit');
            var seqInput = form.querySelector('input[name="c_sequence"]');
            var row = form.closest('tr');
            if (!seqInput) {
                return;
            }

            clearStatus(form);

            var payload = {
                resource: 'possessions',
                person_id: Number(form.dataset.personId || 0),
                mode: 'direct',
                operation: 'update',
                target: {
                    pk: {
                        c_possession_record_id: form.dataset.pkCPossessionRecordId,
                    }
                },
                changes: {
                    c_sequence: seqInput.value
                }
            };

            if (submitBtn) {
                submitBtn.disabled = true;
            }

            try {
                var response = await fetch('/api/v2/mutate', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken()
                    },
                    body: JSON.stringify(payload)
                });

                var data = await response.json().catch(function() {
                    return { ok: false, message: __i18n.nonJsonResponse };
                });

                if (!response.ok || !data.ok) {
                    setStatus(form, (data && data.message) ? data.message : __i18n.submitFailed, 'error');
                    return;
                }

                if (row && row.cells && row.cells[0]) {
                    row.cells[0].textContent = seqInput.value;
                }
                setStatus(form, __i18n.submitted, 'success');
            } catch (error) {
                setStatus(form, __i18n.networkError, 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
            }
        }

        function initPossessionSequenceForms() {
            var forms = document.querySelectorAll('.js-possession-sequence-form');
            Array.prototype.forEach.call(forms, function(form) {
                if (form.dataset.xhrBound === '1') {
                    return;
                }
                form.dataset.xhrBound = '1';

                form.addEventListener('submit', function(event) {
                    if (!window.fetch) {
                        return;
                    }

                    event.preventDefault();
                    submitSequenceViaApi(form);
                });
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPossessionSequenceForms);
        } else {
            initPossessionSequenceForms();
        }

        document.addEventListener('submit', function(event) {
            var form = event.target && event.target.closest ? event.target.closest('.js-possession-sequence-form') : null;
            if (!form || !window.fetch) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            submitSequenceViaApi(form);
        }, true);
    })();
</script>
@endpush
