@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
$canEditSequence = Auth::check() && Auth::user()->canWriteDirectly();
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">財產清單</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.possession.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">新增</a>
                @endif
            @endauth
            @if($canEditSequence)
                @include('biogmains.partials.list-order-toolbar', [
                    'targetTableId' => 'possession-list-table',
                    'toolbarLabel' => '財產次序調整',
                ])
            @endif
            <div class="table-responsive">
                <table id="possession-list-table" class="table table-hover table-sm">
                <caption>共查詢到{{ $basicinformation->possession_count }}筆記錄</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    @if($canEditSequence)
                        <th data-sequence-demo-col style="min-width: 180px; display: none;">新次序</th>
                    @endif
                    <th>行為</th>
                    <th>財產</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">操作</th>
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
                                <button type="submit" class="btn btn-sm btn-outline-primary js-possession-sequence-submit">提交</button>
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
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.possession.edit.query', ['id' => $basicinformation->c_personid], $possessionPk) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的確定要刪除嗎？\n\n請確認！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('{{ $possessionFormId }}').submit();
                                                   }else{
                                                       return false;
                                                   }
                                                   "
                                           class="btn btn-sm btn-danger">delete</a>

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

@endsection

@push('scripts')
<script>
    (function() {
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
                    return { ok: false, message: '非 JSON 回應' };
                });

                if (!response.ok || !data.ok) {
                    setStatus(form, (data && data.message) ? data.message : '提交失敗', 'error');
                    return;
                }

                if (row && row.cells && row.cells[0]) {
                    row.cells[0].textContent = seqInput.value;
                }
                setStatus(form, '已提交', 'success');
            } catch (error) {
                setStatus(form, '網路或伺服器錯誤', 'error');
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
