import React from 'react';

/**
 * 子資源列表的 legacy 風格表格（對齊 legacy biogmains/<sub>/index.blade.php 的 table-bordered），
 * 取代 person-browser 的 TabCard 卡片呈現。每個分頁提供自己的欄位定義（對齊該子資源 legacy index 的欄）
 * 與每列動作（編輯/刪除），共用表格外觀。
 */
export interface TableColumn<T> {
    header: string;
    render: (item: T, index: number) => React.ReactNode;
    /** 可選欄寬（如動作欄固定寬）。 */
    width?: number | string;
}

interface Props<T> {
    columns: Array<TableColumn<T>>;
    items: T[];
    rowKey: (item: T, index: number) => string;
    /** 每列動作欄（編輯/刪除）；未提供則不顯示動作欄。 */
    actions?: (item: T) => React.ReactNode;
    actionsHeader?: string;
    emptyText?: string;
}

export default function SubresourceTable<T>({ columns, items, rowKey, actions, actionsHeader = '操作', emptyText }: Props<T>) {
    const colCount = columns.length + (actions ? 1 : 0);
    return (
        <div style={wrapStyle}>
            <table style={tableStyle}>
                <thead>
                    <tr>
                        {columns.map((c, ci) => (
                            <th key={ci} style={{ ...thStyle, ...(c.width ? { width: c.width } : {}) }}>{c.header}</th>
                        ))}
                        {actions ? <th style={{ ...thStyle, width: 140 }}>{actionsHeader}</th> : null}
                    </tr>
                </thead>
                <tbody>
                    {items.length === 0 ? (
                        <tr>
                            <td style={emptyTdStyle} colSpan={colCount}>{emptyText ?? '—'}</td>
                        </tr>
                    ) : (
                        items.map((item, i) => (
                            <tr key={rowKey(item, i)}>
                                {columns.map((c, ci) => (
                                    <td key={ci} style={tdStyle}>{c.render(item, i)}</td>
                                ))}
                                {actions ? <td style={tdStyle}>{actions(item)}</td> : null}
                            </tr>
                        ))
                    )}
                </tbody>
            </table>
        </div>
    );
}

const wrapStyle: React.CSSProperties = { overflowX: 'auto', marginBottom: 8 };
const tableStyle: React.CSSProperties = { width: '100%', borderCollapse: 'collapse', fontSize: '0.875rem', background: '#fff' };
const thStyle: React.CSSProperties = {
    textAlign: 'left', padding: '8px 10px', borderBottom: '2px solid #dee2e6', borderTop: '1px solid #dee2e6',
    background: '#f8f9fa', color: '#495057', fontWeight: 700, whiteSpace: 'nowrap',
};
const tdStyle: React.CSSProperties = { padding: '8px 10px', borderBottom: '1px solid #dee2e6', color: '#212529', verticalAlign: 'top' };
const emptyTdStyle: React.CSSProperties = { padding: '16px 10px', textAlign: 'center', color: '#94a3b8', borderBottom: '1px solid #dee2e6' };
