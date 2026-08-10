import React from 'react';
import {
    flexRender,
    rowPaginationFeature,
    rowSortingFeature,
    tableFeatures,
    useTable,
    type ColumnDef,
    type RowData,
    type SortingState,
    type OnChangeFn,
} from '@tanstack/react-table';
import { Pagination, type PaginationMeta } from '../ui/Pagination';
import { Button } from '../ui/Button';
import { downloadCsv, printTable, type ExportColumn } from './export';
import { cn } from '../../lib/utils';

// v9 要求靜態宣告啟用的功能；manual 模式只需排序/分頁的狀態管理，不需 row model 工廠。
const features = tableFeatures({ rowSortingFeature, rowPaginationFeature });

/** 供各頁面宣告欄位用的 ColumnDef 別名（v9 起 ColumnDef 需綁定 features 泛型）。 */
export type DataTableColumn<T extends RowData> = ColumnDef<typeof features, T, unknown>;

export interface DataTableLabels {
    empty: string;
    loading: string;
    exportCsv: string;
    print: string;
    previous: string;
    next: string;
    summaryTemplate: string;
}

interface DataTableProps<T extends RowData> {
    columns: DataTableColumn<T>[];
    data: T[];
    /** Laravel 分頁 meta（伺服器端分頁）。 */
    meta: PaginationMeta;
    onPageChange: (page: number) => void;
    /** 伺服器端排序：目前狀態 + 變更回呼（由頁面轉成 Inertia partial reload）。 */
    sorting?: SortingState;
    onSortingChange?: OnChangeFn<SortingState>;
    loading?: boolean;
    labels: DataTableLabels;
    /** 匯出欄位定義；省略則不顯示匯出列。rows 預設為目前載入資料。 */
    exportColumns?: ExportColumn<T>[];
    exportFilename?: string;
    exportTitle?: string;
    /** 工具列額外內容（如篩選器）放在匯出按鈕左側。 */
    toolbar?: React.ReactNode;
    /** 穩定列 id（如複合主鍵）；省略時 TanStack 以 index 為 id。 */
    getRowId?: (row: T, index: number) => string;
}

/**
 * 伺服器端 DataTable（TanStack headless）。manualPagination/Sorting：資料與分頁由
 * 伺服器提供，排序/換頁透過回呼觸發 Inertia partial reload。匯出/列印自建。
 */
export function DataTable<T extends RowData>({
    columns,
    data,
    meta,
    onPageChange,
    sorting,
    onSortingChange,
    loading,
    labels,
    exportColumns,
    exportFilename = 'export',
    exportTitle = 'Export',
    toolbar,
    getRowId,
}: DataTableProps<T>) {
    const table = useTable({
        features,
        data,
        columns,
        getRowId,
        manualPagination: true,
        manualSorting: true,
        // 預設欄位不可排序；需排序的欄位於 columnDef 設 enableSorting: true，
        // 避免對後端不支援的欄位產生會被忽略的 sort 參數。
        defaultColumn: { enableSorting: false },
        state: { sorting: sorting ?? [] },
        onSortingChange,
        pageCount: meta.last_page,
    });

    return (
        <div className="space-y-3">
            {(toolbar || exportColumns) && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">{toolbar}</div>
                    {exportColumns && (
                        <div className="flex items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => downloadCsv(exportFilename, exportColumns, data)}
                            >
                                <i className="fas fa-file-csv" aria-hidden /> {labels.exportCsv}
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => printTable(exportTitle, exportColumns, data)}
                            >
                                <i className="fas fa-print" aria-hidden /> {labels.print}
                            </Button>
                        </div>
                    )}
                </div>
            )}

            <div className="relative overflow-x-auto rounded-md border border-border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50">
                        {table.getHeaderGroups().map((hg) => (
                            <tr key={hg.id}>
                                {hg.headers.map((header) => {
                                    const canSort = header.column.getCanSort();
                                    const sortDir = header.column.getIsSorted();
                                    return (
                                        <th
                                            key={header.id}
                                            className={cn(
                                                'px-3 py-2 text-left font-medium',
                                                canSort && 'cursor-pointer select-none hover:bg-muted'
                                            )}
                                            onClick={
                                                canSort
                                                    ? header.column.getToggleSortingHandler()
                                                    : undefined
                                            }
                                            aria-sort={
                                                sortDir === 'asc'
                                                    ? 'ascending'
                                                    : sortDir === 'desc'
                                                      ? 'descending'
                                                      : undefined
                                            }
                                        >
                                            <span className="inline-flex items-center gap-1">
                                                {header.isPlaceholder
                                                    ? null
                                                    : flexRender(
                                                          header.column.columnDef.header,
                                                          header.getContext()
                                                      )}
                                                {sortDir === 'asc' && <i className="fas fa-sort-up" aria-hidden />}
                                                {sortDir === 'desc' && (
                                                    <i className="fas fa-sort-down" aria-hidden />
                                                )}
                                            </span>
                                        </th>
                                    );
                                })}
                            </tr>
                        ))}
                    </thead>
                    <tbody>
                        {table.getRowModel().rows.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={columns.length}
                                    className="px-3 py-8 text-center text-muted-foreground"
                                >
                                    {loading ? labels.loading : labels.empty}
                                </td>
                            </tr>
                        ) : (
                            table.getRowModel().rows.map((row) => (
                                <tr key={row.id} className="border-t border-border hover:bg-muted/30">
                                    {row.getAllCells().map((cell) => (
                                        <td key={cell.id} className="px-3 py-2 align-top">
                                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                        </td>
                                    ))}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
                {loading && (
                    <div className="absolute inset-0 flex items-center justify-center bg-background/60 text-sm text-muted-foreground">
                        {labels.loading}
                    </div>
                )}
            </div>

            <Pagination
                meta={meta}
                onPageChange={onPageChange}
                summaryTemplate={labels.summaryTemplate}
                labels={{ previous: labels.previous, next: labels.next }}
            />
        </div>
    );
}
