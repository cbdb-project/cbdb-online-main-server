@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">最近眾包錄入記錄</div>
        <div class="panel-body">
            <table id="example1" class="table table-bordered table-striped">
                <p>* 修改类型 0表示crowdsourcing記錄，1表示新增，3表示修改，4表示删除<br />
                * 狀態 1表示crowdsourcing記錄並已插入數據庫，2表示記錄還沒有被處理，3表示記錄reject，4表示記錄處理失敗。
                </p>
                <thead>
                <tr>
                    <th>修改资源</th>
                    <th>修改值</th>
                    <th>资源tts</th>
                    <th>修改类型</th>
                    <th>修改人</th>
                    <th>次數</th>
                    <th>錄入时间</th>
                    <th>狀態</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                    @foreach($lists as $item)
                        <tr>
                            <td>{{ $item->resource }}</td>
                            <td>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal{{ $item->id }}">resource_data</button>
                            </td>
                            <td>{{ $item->resource_id }}</td>
                            <td>{{ $item->op_type }}</td>
                            <td>{{ $item->user->name }}</td>
                            <td>{{ $item->rate }}</td>
                            <td>{{ $item->created_at }}</td>
                            <td>{{ $item->crowdsourcing_status }}</td>
                            <td>
                                @if($item->crowdsourcing_status == 2)
                                <a href="../../crowdsourcing/{{$item->id}}/confirm" type="button" class="btn btn-success">confirm</a>　
                                <a href="../../crowdsourcing/{{$item->id}}/reject" type="button" class="btn btn-danger">reject</a>
                                @endif
                            </td>
                        </tr>
                        <!--Start-->
                        <div id="myModal{{ $item->id }}" class="modal fade" role="dialog">
                          <div class="modal-dialog">
                            <!-- Modal content-->
                            <div class="modal-content">
                              <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                <h4 class="modal-title">resource_data </h4>
                              </div>
                              <div class="modal-body" style="word-break: break-all;">
                                <textarea rows="16" cols="90">{{ $item->resource_data }}</textarea>
                              </div>
                              <div class="modal-footer">
                                <!--temporarily
                                <a href="" type="button" class="btn btn-success">Confirm</a>
                                <a href="" type="button" class="btn btn-danger">Reject</a>
                                -->
                                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                              </div>
                            </div>
                          </div>
                        </div>
                        <!--End-->
                    @endforeach
                </tbody>
            </table>
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
                "pageLength": 100
            });
        });
    </script>
@endsection
