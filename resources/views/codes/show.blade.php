@extends('layouts.dashboard')

@section('content')

    <div class="box">
        <div class="box-header">
            <h3 class="box-title">代碼表</h3>

            <div class="box-tools pull-right">
                <a class="btn btn-default" href="/codes/{{ $q }}/create">新增</a>
            </div>
        </div>
        <!-- /.box-header -->
        <div class="box-body">
            <table id="example1" class="table table-bordered table-striped">
                <thead>
                <tr>
                    @foreach ($thead as $item)
                        <th>{{ $item }}</th>
                    @endforeach
                    <th style="width: 120px">操作</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($data as $item)
                    <tr>
                        @php($count = 0)
                        @php($id_ = '')
                        @foreach($item as $key=>$value)
                            @if($count > count($thead)-1)
                                @break
                            @endif
                            @if(str_contains($key, 'name') or str_contains($key, 'desc') or str_contains($key, 'code') or str_contains($key, 'id') or str_contains($key, 'sequence') or str_contains($key, 'chn') or str_contains($key, 'dy'))
                                @if($count == 0)
                                    @php($id_ = $value)
                                @endif
                                <td>{{ $value }}</td>
                                @php($count++)
                            @endif
                        @endforeach
                        <td>
                            <div class="btn-group">
                                <a type="button" class="btn btn-sm btn-info" href="/codes/{{ $q }}/{{ $id_ }}/edit">edit</a>
                                <a href="{{ route('codes.destroy', ['table_name'=>$q, 'id'=>$id_]) }}"
                                   onclick="alert('确认删除');
                                            event.preventDefault();
                                           document.getElementById('delete-form-{{ $id_ }}').submit();"
                                   class="btn btn-sm btn-danger">delete</a>
                            </div>
                            <form id="delete-form-{{ $id_ }}" action="{{ route('codes.destroy', ['table_name'=>$q, 'id'=>$id_]) }}" method="POST" style="display: none;">
                                {{ method_field('DELETE') }}
                                {{ csrf_field() }}
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    @foreach ($thead as $item)
                        <th>{{ $item }}</th>
                    @endforeach
                    <th style="width: 120px">操作</th>
                </tr>
                </tfoot>
            </table>
            {{--<div class="pull-right">{{ $data->links() }}</div>--}}
        </div>
        <!-- /.box-body -->
    </div>
    <!-- /.box -->
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
            $("#example1").DataTable();
        });
    </script>
@endsection
