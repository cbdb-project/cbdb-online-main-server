@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">最近眾包錄入記錄</div>
        <div class="panel-body">
            <div class="table-responsive">
                <table id="example1" class="table table-bordered table-striped">
                <p>* 修改類型 0表示crowdsourcing記錄，1表示新增，2表示整體覆寫（完整替換現有記錄，主要用於 code 表修改），3表示修改，4表示刪除，8表示記錄新增提案，9表示記錄修改提案<br />
                * 狀態 1表示crowdsourcing記錄並已插入數據庫，2表示記錄尚未處理，3表示記錄reject，4表示記錄處理失敗。
                </p>
                <thead>
                <tr>

                    <th>修改資源</th>
                    <th>修改值</th>
                    <th>資源 TTS</th>
                    <th>修改類型</th>
                    <th>修改人</th>
                    <th>次數</th>
                    <th>錄入時間</th>
                    <th>狀態</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                    @foreach($lists as $item)
                        <tr>

                            <td>{{ $item->resource }}</td>
                            @php
                                $diffSource = $item->resource_diff ?? $item->resource_original;
                                $hasDiffContent = false;
                                if (is_array($diffSource)) {
                                    if (($diffSource['type'] ?? null) === 'POSTED_TO_ADDR_DATA') {
                                        $hasDiffContent = !empty($diffSource['addresses'] ?? []);
                                    } else {
                                        $hasDiffContent = !empty($diffSource['rows'] ?? []);
                                    }
                                } elseif (is_string($diffSource) && trim($diffSource) !== '') {
                                    $hasDiffContent = true;
                                }
                                $resourceDataParsed = json_decode($item->resource_data, true);
                                if (!is_array($resourceDataParsed)) {
                                    $resourceDataParsed = is_string($item->resource_data) ? trim($item->resource_data) : $item->resource_data;
                                }
                            @endphp
                            <td>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal{{ $item->id }}">resource_data</button>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal-mapping{{ $item->id }}"
                                    {{ $hasDiffContent ? '' : 'disabled' }}>
                                    compare
                                </button>                                
                                <div id="myModal{{ $item->id }}" class="modal fade" role="dialog" tabindex="-1">
                                  <div class="modal-dialog">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                        <h4 class="modal-title">resource_data</h4>
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
                                        <h4 class="modal-title">compare</h4>
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
                            </td>
                            <td>{{ $item->resource_id }}</td>
                            <td>{{ $item->op_type }}</td>
                            <td>{{ $item->user->name }}</td>
                            <td>{{ $item->rate }}</td>
                            @php
                                $createdUtc = '';
                                $createdDisplay = '';
                                $createdAtRaw = $item->created_at;
                                $appTimezone = config('app.timezone', 'Asia/Shanghai');
                                if ($createdAtRaw instanceof \Carbon\Carbon) {
                                    $createdDisplay = $createdAtRaw;
                                    $createdUtc = $createdAtRaw->copy()->setTimezone('UTC')->toIso8601String();
                                } elseif (is_string($createdAtRaw) && trim($createdAtRaw) !== '') {
                                    $createdDisplay = trim($createdAtRaw);
                                    try {
                                        $parsed = \Carbon\Carbon::parse($createdAtRaw, $appTimezone);
                                        $createdUtc = $parsed->setTimezone('UTC')->toIso8601String();
                                    } catch (\Exception $e) {
                                        $createdUtc = $createdDisplay;
                                    }
                                }
                            @endphp
                            <td class="js-utc-datetime" data-utc="{{ $createdUtc }}">
                                {{ $createdDisplay }}
                            </td>
                            <td>{{ $item->crowdsourcing_status }}</td>
                            <td>
                                @if($item->crowdsourcing_status == 2 and Auth::check() and Auth::user()->is_admin != 2)
                                <a href="../../crowdsourcing/{{$item->id}}/confirm" type="button" class="btn btn-success">confirm</a>　
                                <a href="../../crowdsourcing/{{$item->id}}/reject" type="button" class="btn btn-danger">reject</a>
                                @endif
                            </td>
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
    <script>
        (function(window, document, undefined){

            let factory = function( $, DataTable ) {
                "use strict";


                /* Set the defaults for DataTables initialisation */
                $.extend( true, DataTable.defaults, {
                    dom:
                    "<'row'<'col-sm-6'l><'col-sm-6'f>>" +
                    "<'row'<'col-sm-12'tr>>" +
                    "<'row'<'col-sm-5'i><'col-sm-7'p>>",
                    renderer: 'bootstrap'
                } );


                /* Default class modification */
                $.extend( DataTable.ext.classes, {
                    sWrapper:      "dataTables_wrapper form-inline dt-bootstrap",
                    sFilterInput:  "form-control input-sm",
                    sLengthSelect: "form-control input-sm"
                } );

                /* Bootstrap paging button renderer */
                DataTable.ext.renderer.pageButton.bootstrap = function ( settings, host, idx, buttons, page, pages ) {
                    let api     = new DataTable.Api( settings );
                    let classes = settings.oClasses;
                    let lang    = settings.oLanguage.oPaginate;
                    let btnDisplay, btnClass, counter=0;

                    let attach = function( container, buttons ) {
                        let i, ien, node, button;
                        let clickHandler = function ( e ) {
                            e.preventDefault();
                            if ( !$(e.currentTarget).hasClass('disabled') ) {
                                api.page( e.data.action ).draw( false );
                            }
                        };

                        for ( i=0, ien=buttons.length ; i<ien ; i++ ) {
                            button = buttons[i];

                            if ( $.isArray( button ) ) {
                                attach( container, button );
                            }
                            else {
                                btnDisplay = '';
                                btnClass = '';

                                switch ( button ) {
                                    case 'ellipsis':
                                        btnDisplay = '&hellip;';
                                        btnClass = 'disabled';
                                        break;

                                    case 'first':
                                        btnDisplay = lang.sFirst;
                                        btnClass = button + (page > 0 ?
                                            '' : ' disabled');
                                        break;

                                    case 'previous':
                                        btnDisplay = lang.sPrevious;
                                        btnClass = button + (page > 0 ?
                                            '' : ' disabled');
                                        break;

                                    case 'next':
                                        btnDisplay = lang.sNext;
                                        btnClass = button + (page < pages-1 ?
                                            '' : ' disabled');
                                        break;

                                    case 'last':
                                        btnDisplay = lang.sLast;
                                        btnClass = button + (page < pages-1 ?
                                            '' : ' disabled');
                                        break;

                                    default:
                                        btnDisplay = button + 1;
                                        btnClass = page === button ?
                                            'active' : '';
                                        break;
                                }

                                if ( btnDisplay ) {
                                    node = $('<li>', {
                                        'class': classes.sPageButton+' '+btnClass,
                                        'id': idx === 0 && typeof button === 'string' ?
                                            settings.sTableId +'_'+ button :
                                            null
                                    } )
                                        .append( $('<a>', {
                                                'href': '#',
                                                'aria-controls': settings.sTableId,
                                                'data-dt-idx': counter,
                                                'tabindex': settings.iTabIndex
                                            } )
                                                .html( btnDisplay )
                                        )
                                        .appendTo( container );

                                    settings.oApi._fnBindAction(
                                        node, {action: button}, clickHandler
                                    );

                                    counter++;
                                }
                            }
                        }
                    };

                    // IE9 throws an 'unknown error' if document.activeElement is used
                    // inside an iframe or frame.
                    let activeEl;

                    try {
                        // Because this approach is destroying and recreating the paging
                        // elements, focus is lost on the select button which is bad for
                        // accessibility. So we want to restore focus once the draw has
                        // completed
                        activeEl = $(document.activeElement).data('dt-idx');
                    }
                    catch (e) {}

                    attach(
                        $(host).empty().html('<ul class="pagination"/>').children('ul'),
                        buttons
                    );

                    if ( activeEl ) {
                        $(host).find( '[data-dt-idx='+activeEl+']' ).focus();
                    }
                };


                /*
                 * TableTools Bootstrap compatibility
                 * Required TableTools 2.1+
                 */
                if ( DataTable.TableTools ) {
                    // Set the classes that TableTools uses to something suitable for Bootstrap
                    $.extend( true, DataTable.TableTools.classes, {
                        "container": "DTTT btn-group",
                        "buttons": {
                            "normal": "btn btn-default",
                            "disabled": "disabled"
                        },
                        "collection": {
                            "container": "DTTT_dropdown dropdown-menu",
                            "buttons": {
                                "normal": "",
                                "disabled": "disabled"
                            }
                        },
                        "print": {
                            "info": "DTTT_print_info"
                        },
                        "select": {
                            "row": "active"
                        }
                    } );

                    // Have the collection use a bootstrap compatible drop down
                    $.extend( true, DataTable.TableTools.DEFAULTS.oTags, {
                        "collection": {
                            "container": "ul",
                            "button": "li",
                            "liner": "a"
                        }
                    } );
                }

            }; // /factory


// Define as an AMD module if possible
            if ( typeof define === 'function' && define.amd ) {
                define( ['jquery', 'datatables'], factory );
            }
            else if ( typeof exports === 'object' ) {
                // Node/CommonJS
                factory( require('jquery'), require('datatables') );
            }
            else if ( jQuery ) {
                // Otherwise simply initialise as normal, stopping multiple evaluation
                factory( jQuery, jQuery.fn.dataTable );
            }


        })(window, document);
        $(function () {

            $("#example1").DataTable({
                "lengthMenu": [10, 25, 50, 75, 100, 150, 200],
                "pageLength": 100,
                "aaSorting" : [[6, "desc"]]
            });

            applyTimestampFormatting();

        });

        var userTimeZone = (Intl.DateTimeFormat().resolvedOptions().timeZone) || 'UTC';
        var userOffsetMinutes = new Date().getTimezoneOffset();

        function formatTimestamp(utcTimeString, targetTimeZone) {
            try {
                var utcDate = new Date(utcTimeString);
                if (isNaN(utcDate.getTime())) {
                    console.warn('Invalid time:', utcTimeString);
                    return utcTimeString;
                }

                var zone = targetTimeZone || userTimeZone;
                var parts = new Intl.DateTimeFormat(undefined, {
                    timeZone: zone,
                    timeZoneName: 'short'
                }).formatToParts(utcDate);
                var timeZoneName = '';
                for (var i = 0; i < parts.length; i++) {
                    if (parts[i].type === 'timeZoneName') {
                        timeZoneName = parts[i].value || '';
                        break;
                    }
                }

                var dateTimeWithoutTZ = utcDate.toLocaleString('sv-SE', {
                    timeZone: zone,
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false
                });

                return dateTimeWithoutTZ + ' ' + timeZoneName;
            } catch (error) {
                console.warn('Time conversion failed:', utcTimeString, error);
                return utcTimeString;
            }
        }

        function applyTimestampFormatting() {
            var nodes = document.querySelectorAll('.js-utc-datetime');
            Array.prototype.forEach.call(nodes, function (node) {
                var original = node.getAttribute('data-utc') || (node.textContent || '').trim();
                if (!original) {
                    return;
                }

                var displayText = formatTimestamp(original);
                node.textContent = displayText;
                if (userOffsetMinutes !== -480) {
                    var chinaText = formatTimestamp(original, 'Asia/Shanghai');
                    if (chinaText && chinaText !== original) {
                        node.setAttribute('title', chinaText);
                    } else {
                        node.removeAttribute('title');
                    }
                } else {
                    node.removeAttribute('title');
                }
            });
        }
    </script>
@endsection
