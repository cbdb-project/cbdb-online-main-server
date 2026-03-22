@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
$canEditSequence = Auth::check() && Auth::user()->canWriteDirectly();
@endphp

@section('content')
    @include('biogmains.banner')
    @include('biogmains.defense')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">別名</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.altnames.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">新增</a>
                @endif
            @endauth
            @if($canEditSequence)
                @include('biogmains.partials.list-order-toolbar', [
                    'targetTableId' => 'altname-list-table',
                    'toolbarLabel' => '別名次序調整',
                ])
            @endif
            <div class="table-responsive">
                <table id="altname-list-table" class="table table-hover table-sm">
                <caption>共查詢到{{ $basicinformation->altnames_count }}筆記錄</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    @if($canEditSequence)
                        <th data-sequence-demo-col style="min-width: 180px; display: none;">新次序</th>
                    @endif
                    <th>別名拼音</th>
                    <th>別名漢字</th>
                    <th>別名類型</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">操作</th>
                        @endif
                    @endauth
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->altnames as $key=>$value)
@php
$value->pivot->c_alt_name = unionPKDef($value->pivot->c_alt_name);
$value->pivot->c_alt_name_chn = unionPKDef($value->pivot->c_alt_name_chn);
$c_alt_name_view = unionPKDef_decode_for_convert($value->pivot->c_alt_name);
$c_alt_name_chn_view = unionPKDef_decode_for_convert($value->pivot->c_alt_name_chn);

//20210715新增錯別字過濾
$errWord = array('?', '', '�');
$value->pivot->c_alt_name_chn = str_replace($errWord, '', $value->pivot->c_alt_name_chn);

//別名類型顯示
$altTypeLabel = trim((string) ($value->c_name_type_desc_chn ?? ''));
if ($altTypeLabel === '') {
    $altTypeLabel = trim((string) ($value->c_name_type_desc ?? ''));
}
if ($altTypeLabel === '') {
    $altTypeLabel = $value->pivot->c_alt_name_type_code;
}

@endphp
                    <tr>
                        <td>{{ $value->pivot->c_sequence }}</td>
                        @if($canEditSequence)
                        <td data-sequence-demo-col style="display: none;">
                            @php
                                $altPk = [
                                    'c_personid' => $value->pivot->c_personid,
                                    'c_alt_name_chn' => unionPKDef_decode($value->pivot->c_alt_name_chn),
                                    'c_alt_name_type_code' => $value->pivot->c_alt_name_type_code,
                                ];
                            @endphp
                            <form
                                action="{{ CompositePrimaryKey::buildUrl('basicinformation.altnames.update.query', ['id' => $basicinformation->c_personid], $altPk) }}"
                                method="POST"
                                class="form-inline m-0 js-altname-sequence-form"
                                data-person-id="{{ $basicinformation->c_personid }}"
                                data-pk-c-personid="{{ $altPk['c_personid'] }}"
                                data-pk-c-alt-name-chn="{{ $altPk['c_alt_name_chn'] }}"
                                data-pk-c-alt-name-type-code="{{ $altPk['c_alt_name_type_code'] }}"
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
                                <button type="submit" class="btn btn-sm btn-outline-primary js-altname-sequence-submit">提交</button>
                                <small class="text-muted ml-2 d-none js-altname-sequence-status"></small>
                            </form>
                        </td>
                        @endif
                        <td>{{ $c_alt_name_view }}</td>
                        <td>{{ $c_alt_name_chn_view }}</td>
                        <td>{{ $altTypeLabel }}</td>
                        @auth
                            @if(Auth::user()->isActive())
                                <td>
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.altnames.edit.query', ['id' => $basicinformation->c_personid], ['c_personid' => $value->pivot->c_personid, 'c_alt_name_chn' => unionPKDef_decode($value->pivot->c_alt_name_chn), 'c_alt_name_type_code' => $value->pivot->c_alt_name_type_code]) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的確定要刪除嗎？\n\n請確認！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('delete-form-{{ $value->pivot->c_personid."-".$value->pivot->c_alt_name_chn."-".$value->pivot->c_alt_name_type_code }}').submit();
                                                   }else{
                                                       return false;
                                                   }
                                        "
                                           class="btn btn-sm btn-danger">delete</a>

                                    </div>
                                    <form id="delete-form-{{ $value->pivot->c_personid.'-'.$value->pivot->c_alt_name_chn.'-'.$value->pivot->c_alt_name_type_code }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.altnames.destroy.query', ['id' => $basicinformation->c_personid], ['c_personid' => $value->pivot->c_personid, 'c_alt_name_chn' => unionPKDef_decode($value->pivot->c_alt_name_chn), 'c_alt_name_type_code' => $value->pivot->c_alt_name_type_code]) }}" method="POST" style="display: none;">
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
        function getCsrfToken() {
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            return tokenEl ? tokenEl.getAttribute('content') : '';
        }

        function setStatus(form, text, type) {
            var statusEl = form.querySelector('.js-altname-sequence-status');
            if (!statusEl) {
                return;
            }

            statusEl.classList.remove('d-none', 'text-success', 'text-danger', 'text-muted');
            statusEl.classList.add(type === 'error' ? 'text-danger' : 'text-success');
            statusEl.textContent = text;
        }

        function clearStatus(form) {
            var statusEl = form.querySelector('.js-altname-sequence-status');
            if (!statusEl) {
                return;
            }

            statusEl.classList.add('d-none');
            statusEl.textContent = '';
            statusEl.classList.remove('text-success', 'text-danger', 'text-muted');
        }

        async function submitSequenceViaApi(form) {
            var submitBtn = form.querySelector('.js-altname-sequence-submit');
            var seqInput = form.querySelector('input[name="c_sequence"]');
            var row = form.closest('tr');
            if (!seqInput) {
                return;
            }

            clearStatus(form);

            var payload = {
                resource: 'altnames',
                person_id: Number(form.dataset.personId || 0),
                mode: 'direct',
                operation: 'update',
                target: {
                    pk: {
                        c_personid: form.dataset.pkCPersonid,
                        c_alt_name_chn: form.dataset.pkCAltNameChn,
                        c_alt_name_type_code: form.dataset.pkCAltNameTypeCode,
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

        function initAltnameSequenceForms() {
            var forms = document.querySelectorAll('.js-altname-sequence-form');
            Array.prototype.forEach.call(forms, function(form) {
                if (form.dataset.xhrBound === '1') {
                    return;
                }
                form.dataset.xhrBound = '1';

                form.addEventListener('submit', function(event) {
                    if (!window.fetch) {
                        return; // fallback to normal form submit
                    }

                    event.preventDefault();
                    submitSequenceViaApi(form);
                });
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAltnameSequenceForms);
        } else {
            initAltnameSequenceForms();
        }

        // 捕獲階段兜底：若頁面其他腳本攔截 submit，仍優先走 XHR
        document.addEventListener('submit', function(event) {
            var form = event.target && event.target.closest ? event.target.closest('.js-altname-sequence-form') : null;
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
