import React, { useState } from 'react';

// ─── Shared types ─────────────────────────────────────────────────────────────

export interface ToolResultSummary {
    status?: string;
    label?: string;
    error?: string;
    // SQL tool
    sql?: string;
    row_count?: number;
    columns?: string[];
    preview?: Record<string, unknown>[];
    // get_person_ids
    count?: number;
    person_ids?: unknown[];
    names?: unknown[];
    // get_table_row_by_id
    found?: boolean;
    table_name?: string;
    row_preview?: Record<string, unknown>;
    // schema tools
    columns_count?: number;
    column_names?: string[];
    total_matching?: number;
    // get_code_values
    code_type?: string;
    // list_allowed_tables
    tables?: string[];
    [key: string]: unknown;
}

export interface ToolCallTrace {
    tool_call_id?: string;
    name: string;
    status: 'running' | 'completed' | 'error';
    arguments?: Record<string, unknown>;
    result_summary?: ToolResultSummary;
    error?: string;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<ToolCallTrace['status'], string> = {
    running: 'var(--warning)',
    completed: 'var(--success)',
    error: 'var(--destructive)',
};

const STATUS_FOREGROUNDS: Record<ToolCallTrace['status'], string> = {
    running: 'var(--warning-foreground)',
    completed: 'var(--success-foreground)',
    error: 'var(--destructive-foreground)',
};

const STATUS_LABELS: Record<ToolCallTrace['status'], string> = {
    running: '執行中…',
    completed: '完成',
    error: '失敗',
};

function statusBadge(status: ToolCallTrace['status']) {
    return (
        <span style={{
            display: 'inline-block',
            padding: '1px 7px',
            borderRadius: 10,
            fontSize: '0.7rem',
            fontWeight: 600,
            backgroundColor: STATUS_COLORS[status],
            color: STATUS_FOREGROUNDS[status],
            marginLeft: 6,
            verticalAlign: 'middle',
        }}>
            {STATUS_LABELS[status]}
        </span>
    );
}

function getOneLiner(tc: ToolCallTrace): string {
    if (tc.status === 'running') return '執行中…';
    if (tc.status === 'error') {
        return tc.result_summary?.error ?? tc.error ?? '工具執行失敗';
    }
    return tc.result_summary?.label ?? '完成';
}

// ─── ToolTraceItem ────────────────────────────────────────────────────────────

function ToolTraceItem({ tc }: { tc: ToolCallTrace }) {
    const [expanded, setExpanded] = useState(false);
    const hasDetails = tc.arguments !== undefined || tc.result_summary !== undefined;

    return (
        <div style={{
            marginBottom: 6,
            border: '1px solid var(--border)',
            borderRadius: 4,
            backgroundColor: 'var(--card)',
            fontSize: '0.8rem',
        }}>
            {/* Header row */}
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    padding: '7px 10px',
                    cursor: hasDetails ? 'pointer' : 'default',
                    userSelect: 'none',
                }}
                onClick={() => hasDetails && setExpanded(!expanded)}
            >
                {/* Status dot */}
                <span style={{
                    display: 'inline-block',
                    width: 8,
                    height: 8,
                    borderRadius: '50%',
                    backgroundColor: STATUS_COLORS[tc.status],
                    marginRight: 8,
                    flexShrink: 0,
                }} />

                {/* Tool name */}
                <strong style={{ marginRight: 4, fontFamily: 'monospace', fontSize: '0.8rem' }}>
                    {tc.name}
                </strong>

                {/* Status badge */}
                {statusBadge(tc.status)}

                {/* One-liner summary */}
                {tc.status !== 'running' && (
                    <span style={{ marginLeft: 10, color: 'var(--muted-foreground)', flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {getOneLiner(tc)}
                    </span>
                )}

                {/* Expand chevron */}
                {hasDetails && tc.status !== 'running' && (
                    <span style={{ marginLeft: 8, color: 'var(--muted-foreground)', flexShrink: 0 }}>
                        {expanded ? '▼' : '▶'}
                    </span>
                )}
            </div>

            {/* Expanded details */}
            {expanded && (
                <div style={{
                    borderTop: '1px solid var(--border)',
                    padding: '10px 12px',
                    backgroundColor: 'var(--surface-sunken)',
                }}>
                    {/* Arguments */}
                    {tc.arguments && Object.keys(tc.arguments).length > 0 && (
                        <div style={{ marginBottom: 10 }}>
                            <div style={{ fontWeight: 600, color: 'var(--muted-foreground)', marginBottom: 4 }}>
                                📥 呼叫參數
                            </div>
                            {tc.name === 'query_read_only_sql' && typeof tc.arguments.sql === 'string' ? (
                                <div>
                                    <pre style={codeBlockStyle}>
                                        {tc.arguments.sql}
                                    </pre>
                                    {Object.keys(tc.arguments).filter(k => k !== 'sql').map(k => (
                                        <div key={k} style={{ fontSize: '0.75rem', color: 'var(--muted-foreground)' }}>
                                            <strong>{k}:</strong> {JSON.stringify(tc.arguments![k])}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <pre style={codeBlockStyle}>
                                    {JSON.stringify(tc.arguments, null, 2)}
                                </pre>
                            )}
                        </div>
                    )}

                    {/* Result summary */}
                    {tc.result_summary && tc.status !== 'running' && (
                        <ResultSummaryView summary={tc.result_summary} toolName={tc.name} />
                    )}
                </div>
            )}
        </div>
    );
}

// ─── ResultSummaryView ────────────────────────────────────────────────────────

function ResultSummaryView({ summary, toolName }: { summary: ToolResultSummary; toolName: string }) {
    if (summary.status === 'error') {
        return (
            <div style={{ color: 'var(--danger-subtle-foreground)' }}>
                <div style={{ fontWeight: 600, marginBottom: 4 }}>❌ 錯誤</div>
                <pre style={{ ...codeBlockStyle, borderColor: 'var(--danger-border)', backgroundColor: 'var(--danger-subtle)', color: 'var(--danger-subtle-foreground)' }}>
                    {summary.error}
                </pre>
            </div>
        );
    }

    return (
        <div>
            <div style={{ fontWeight: 600, color: 'var(--muted-foreground)', marginBottom: 6 }}>
                📤 執行結果
            </div>

            {/* For SQL tool: show SQL + table results */}
            {toolName === 'query_read_only_sql' && summary.sql && (
                <div style={{ marginBottom: 8 }}>
                    <div style={{ fontSize: '0.75rem', color: 'var(--muted-foreground)', marginBottom: 2 }}>SQL：</div>
                    <pre style={codeBlockStyle}>{summary.sql}</pre>
                </div>
            )}

            {/* Row count */}
            {summary.row_count !== undefined && (
                <div style={{ marginBottom: 4 }}>
                    <span style={labelStyle}>筆數：</span>
                    {summary.row_count}
                    {summary.total_matching !== undefined && summary.total_matching !== summary.row_count && (
                        <span style={{ color: 'var(--muted-foreground)' }}> （共 {summary.total_matching} 筆匹配）</span>
                    )}
                </div>
            )}

            {/* Columns */}
            {summary.columns && summary.columns.length > 0 && (
                <div style={{ marginBottom: 4 }}>
                    <span style={labelStyle}>欄位：</span>
                    <span style={{ fontFamily: 'monospace', fontSize: '0.75rem' }}>
                        {summary.columns.join(', ')}
                    </span>
                </div>
            )}

            {/* Preview rows */}
            {summary.preview && summary.preview.length > 0 && (
                <div style={{ marginBottom: 4 }}>
                    <div style={{ ...labelStyle, display: 'block', marginBottom: 3 }}>預覽（前 {summary.preview.length} 筆）：</div>
                    <div style={{ overflowX: 'auto' }}>
                        <table style={{ borderCollapse: 'collapse', fontSize: '0.73rem', width: '100%' }}>
                            <thead>
                                <tr>
                                    {Object.keys(summary.preview[0]).map(col => (
                                        <th key={col} style={thStyle}>{col}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {summary.preview.map((row, i) => (
                                    <tr key={i}>
                                        {Object.values(row).map((val, j) => (
                                            <td key={j} style={tdStyle}>{String(val ?? '')}</td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Person IDs */}
            {summary.person_ids !== undefined && (
                <div style={{ marginBottom: 4 }}>
                    <span style={labelStyle}>找到人物：</span>
                    {summary.count !== undefined && <span>{summary.count} 筆，</span>}
                    {summary.names && summary.names.length > 0 && (
                        <span>{(summary.names as string[]).join('、')}</span>
                    )}
                    {summary.person_ids.length > 0 && (
                        <span style={{ color: 'var(--muted-foreground)', marginLeft: 4 }}>
                            (ID: {(summary.person_ids as unknown[]).join(', ')})
                        </span>
                    )}
                </div>
            )}

            {/* Row preview for get_table_row_by_id */}
            {summary.row_preview !== undefined && (
                <div style={{ marginBottom: 4 }}>
                    <span style={labelStyle}>記錄預覽：</span>
                    {!summary.found ? (
                        <span style={{ color: 'var(--muted-foreground)' }}>未找到</span>
                    ) : (
                        <pre style={codeBlockStyle}>
                            {JSON.stringify(summary.row_preview, null, 2)}
                        </pre>
                    )}
                </div>
            )}

            {/* Schema: column names */}
            {summary.column_names !== undefined && summary.column_names.length > 0 && (
                <div style={{ marginBottom: 4 }}>
                    <span style={labelStyle}>欄位名稱：</span>
                    <span style={{ fontFamily: 'monospace', fontSize: '0.75rem' }}>
                        {summary.column_names.join(', ')}
                        {summary.columns_count !== undefined && summary.columns_count > summary.column_names.length && (
                            <span style={{ color: 'var(--muted-foreground)' }}> …共 {summary.columns_count} 個</span>
                        )}
                    </span>
                </div>
            )}

            {/* Schema: columns_count only (get_table_schema) */}
            {summary.columns_count !== undefined && summary.column_names === undefined && (
                <div style={{ marginBottom: 4 }}>
                    <span style={labelStyle}>欄位數：</span>{summary.columns_count}
                </div>
            )}

            {/* get_code_values / list_allowed_tables */}
            {summary.code_type && (
                <div style={{ marginBottom: 4 }}>
                    <span style={labelStyle}>代碼類型：</span>{summary.code_type}
                    {summary.count !== undefined && <span>，共 {summary.count} 筆</span>}
                </div>
            )}
            {summary.tables && summary.tables.length > 0 && (
                <div style={{ marginBottom: 4 }}>
                    <span style={labelStyle}>允許表格：</span>
                    <span style={{ fontFamily: 'monospace', fontSize: '0.75rem' }}>
                        {summary.tables.join(', ')}
                        {summary.count !== undefined && summary.count > summary.tables.length && (
                            <span style={{ color: 'var(--muted-foreground)' }}> …共 {summary.count} 個</span>
                        )}
                    </span>
                </div>
            )}
        </div>
    );
}

// ─── Shared styles ────────────────────────────────────────────────────────────

const codeBlockStyle: React.CSSProperties = {
    whiteSpace: 'pre-wrap',
    wordBreak: 'break-all',
    backgroundColor: 'var(--surface-sunken)',
    border: '1px solid var(--border)',
    borderRadius: 3,
    padding: '6px 8px',
    fontSize: '0.75rem',
    fontFamily: 'SFMono-Regular, Menlo, Monaco, Consolas, monospace',
    margin: '2px 0',
    maxHeight: 200,
    overflowY: 'auto',
};

const labelStyle: React.CSSProperties = {
    fontWeight: 600,
    color: 'var(--muted-foreground)',
    marginRight: 4,
};

const thStyle: React.CSSProperties = {
    border: '1px solid var(--border)',
    padding: '2px 6px',
    backgroundColor: 'var(--surface-sunken)',
    fontWeight: 600,
    whiteSpace: 'nowrap',
};

const tdStyle: React.CSSProperties = {
    border: '1px solid var(--border)',
    padding: '2px 6px',
    maxWidth: 200,
    overflow: 'hidden',
    textOverflow: 'ellipsis',
    whiteSpace: 'nowrap',
};

// ─── ToolTracePanel ───────────────────────────────────────────────────────────

interface ToolTracePanelProps {
    toolCalls: ToolCallTrace[];
}

export default function ToolTracePanel({ toolCalls }: ToolTracePanelProps) {
    if (toolCalls.length === 0) return null;

    return (
        <div style={{ marginBottom: 16 }}>
            <div style={{ fontWeight: 600, fontSize: '0.85rem', color: 'var(--muted-foreground)', marginBottom: 6 }}>
                🔧 工具調用過程
                <span style={{ fontWeight: 400, color: 'var(--muted-foreground)', marginLeft: 6 }}>
                    （{toolCalls.length} 次，點擊可展開詳情）
                </span>
            </div>
            {toolCalls.map((tc, i) => (
                <ToolTraceItem key={tc.tool_call_id ?? i} tc={tc} />
            ))}
        </div>
    );
}
