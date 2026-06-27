import React, { useState, useCallback, useRef } from 'react';
import ToolTracePanel, { type ToolCallTrace, type ToolResultSummary } from './ToolTracePanel';
import { useTranslation } from '../../hooks/useTranslation';

interface Evidence {
    type: 'database' | 'model_background';
    label: string;
    detail: string;
}

interface Props {
    nlModel: string;
    answerFromNlEndpoint: string;
    answerFromNlStreamEndpoint: string;
}

export default function HistoricalQaPanel({ nlModel, answerFromNlEndpoint, answerFromNlStreamEndpoint }: Props) {
    const t = useTranslation('query');
    const [question, setQuestion] = useState('');
    const [consent, setConsent] = useState(false);
    const [useTools, setUseTools] = useState(true);
    const [useStreaming, setUseStreaming] = useState(true);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [answerMarkdown, setAnswerMarkdown] = useState('');
    const [summary, setSummary] = useState('');
    const [sqlUsed, setSqlUsed] = useState<string[]>([]);
    const [toolCalls, setToolCalls] = useState<ToolCallTrace[]>([]);
    const [evidence, setEvidence] = useState<Evidence[]>([]);
    const [caveat, setCaveat] = useState('');
    const [showDetails, setShowDetails] = useState(false);
    const abortControllerRef = useRef<AbortController | null>(null);

    const resetResults = () => {
        setError('');
        setAnswerMarkdown('');
        setSummary('');
        setSqlUsed([]);
        setToolCalls([]);
        setEvidence([]);
        setCaveat('');
    };

    const handleSubmit = useCallback(async () => {
        if (!question.trim() || !consent) return;

        setLoading(true);
        resetResults();

        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

        if (useStreaming) {
            try {
                abortControllerRef.current = new AbortController();
                const response = await fetch(answerFromNlStreamEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'text/event-stream',
                    },
                    body: JSON.stringify({ question, use_tools: useTools }),
                    signal: abortControllerRef.current.signal,
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({ error: `HTTP ${response.status}` }));
                    throw new Error(errorData.error || `HTTP ${response.status}`);
                }

                const reader = response.body?.getReader();
                if (!reader) throw new Error(t('qa_stream_failed'));

                const decoder = new TextDecoder();
                let buffer = '';
                let currentEvent = '';
                let currentData = '';

                const flushEvent = () => {
                    if (!currentEvent || !currentData) return;
                    try {
                        const parsed = JSON.parse(currentData);
                        handleStreamEvent(currentEvent, parsed);
                    } catch (e) {
                        console.warn('Failed to parse SSE event:', e);
                    }
                    currentEvent = '';
                    currentData = '';
                };

                const processLine = (line: string) => {
                    const normalizedLine = line.replace(/\r$/, '');
                    if (normalizedLine.startsWith('event: ')) {
                        currentEvent = normalizedLine.slice(7).trim();
                    } else if (normalizedLine.startsWith('data: ')) {
                        const dataLine = normalizedLine.slice(6);
                        currentData = currentData ? `${currentData}\n${dataLine}` : dataLine;
                    } else if (normalizedLine === '') {
                        flushEvent();
                    }
                };

                while (true) {
                    const { done, value } = await reader.read();
                    if (value) {
                        buffer += decoder.decode(value, { stream: !done });
                    }
                    if (done) {
                        buffer += decoder.decode();
                    }

                    const lines = buffer.split('\n');
                    const remainingLine = lines.pop() || '';
                    buffer = done ? '' : remainingLine;

                    for (const line of lines) {
                        processLine(line);
                    }

                    if (done) {
                        if (remainingLine) processLine(remainingLine);
                        flushEvent();
                        break;
                    }
                }
            } catch (err) {
                if (err instanceof Error && err.name === 'AbortError') return;
                setError(err instanceof Error ? err.message : t('qa_failed'));
            } finally {
                setLoading(false);
                abortControllerRef.current = null;
            }
        } else {
            // Non-streaming
            try {
                const response = await fetch(answerFromNlEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ question, use_tools: useTools }),
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.error || t('qa_failed'));
                }

                setAnswerMarkdown(data.answer_markdown || '');
                setSummary(data.summary || '');
                setSqlUsed(data.sql_used || []);
                setEvidence(data.evidence || []);
                setCaveat(data.caveat || '');
                // Populate tool calls from non-streaming response
                if (Array.isArray(data.tool_calls)) {
                    const traces: ToolCallTrace[] = data.tool_calls.map((tc: Record<string, unknown>) => ({
                        tool_call_id: String(tc.tool_call_id ?? ''),
                        name: String(tc.tool_name ?? ''),
                        status: tc.status === 'error' ? 'error' : 'completed',
                        arguments: (tc.arguments as Record<string, unknown>) ?? undefined,
                        result_summary: (tc.result_summary as ToolResultSummary) ?? undefined,
                        error: tc.status === 'error' ? String((tc.result_summary as Record<string, unknown>)?.error ?? '') : undefined,
                    }));
                    setToolCalls(traces);
                }
            } catch (err) {
                setError(err instanceof Error ? err.message : t('qa_failed'));
            } finally {
                setLoading(false);
            }
        }
    }, [question, consent, useTools, useStreaming, answerFromNlEndpoint, answerFromNlStreamEndpoint, t]);

    const handleStreamEvent = (event: string, data: Record<string, unknown>) => {
        switch (event) {
            case 'status':
                // General status message — no-op for now
                break;
            case 'tool_execution_start':
                setToolCalls((prev) => [...prev, {
                    tool_call_id: String(data.tool_call_id ?? ''),
                    name: String(data.tool_name || data.name || 'tool'),
                    status: 'running',
                    arguments: (data.arguments as Record<string, unknown>) ?? undefined,
                }]);
                break;
            case 'tool_execution_complete': {
                const toolCallId = String(data.tool_call_id ?? '');
                const status: ToolCallTrace['status'] = data.success === false ? 'error' : 'completed';
                const resultSummary = (data.result_summary as ToolResultSummary) ?? undefined;
                setToolCalls((prev) => {
                    const updated = [...prev];
                    let idx = toolCallId ? updated.findLastIndex(tc => tc.tool_call_id === toolCallId) : -1;
                    if (idx < 0) idx = updated.findLastIndex(tc => tc.status === 'running');
                    if (idx >= 0) {
                        updated[idx] = {
                            ...updated[idx],
                            status,
                            result_summary: resultSummary,
                            error: status === 'error' ? (resultSummary?.error ?? String(data.error ?? '')) : undefined,
                        };
                    }
                    return updated;
                });
                break;
            }
            case 'complete':
                setAnswerMarkdown(String(data.answer_markdown || ''));
                setSummary(String(data.summary || ''));
                setSqlUsed((data.sql_used as string[]) || []);
                setEvidence((data.evidence as Evidence[]) || []);
                setCaveat(String(data.caveat || ''));
                break;
            case 'error':
                setError(String(data.error || t('qa_error')));
                break;
        }
    };

    const handleCancel = () => {
        abortControllerRef.current?.abort();
        setLoading(false);
    };

    return (
        <div>
            {/* Privacy notice */}
            <div style={{
                backgroundColor: 'var(--warning-subtle)',
                border: '1px solid var(--warning-border)',
                borderRadius: 6,
                padding: 12,
                marginBottom: 16,
                fontSize: '0.85rem',
                color: 'var(--warning-subtle-foreground)',
            }}>
                <strong>{t('qa_privacy_label')}</strong>{' '}{t('qa_privacy_body', { model: nlModel })}
            </div>

            {/* Question input */}
            <div style={{ marginBottom: 12 }}>
                <label style={{ fontWeight: 600, fontSize: '0.9rem', color: 'var(--muted-foreground)', display: 'block', marginBottom: 4 }}>
                    {t('qa_placeholder')}
                </label>
                <textarea
                    value={question}
                    onChange={(e) => setQuestion(e.target.value)}
                    placeholder={t('qa_example')}
                    rows={3}
                    maxLength={1000}
                    disabled={loading}
                    style={{
                        width: '100%',
                        padding: 12,
                        border: '1px solid var(--input)',
                        borderRadius: 6,
                        fontSize: '0.9rem',
                        resize: 'vertical',
                        boxSizing: 'border-box',
                    }}
                />
                <div style={{ textAlign: 'right', fontSize: '0.75rem', color: 'var(--muted-foreground)', marginTop: 2 }}>
                    {question.length}/1000
                </div>
            </div>

            {/* Options */}
            <div style={{ display: 'flex', gap: 16, marginBottom: 12, flexWrap: 'wrap', alignItems: 'center' }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem', cursor: 'pointer' }}>
                    <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
                    {t('qa_agree_privacy')}
                </label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem', cursor: 'pointer' }}>
                    <input type="checkbox" checked={useTools} onChange={(e) => setUseTools(e.target.checked)} />
                    {t('qa_use_tools')}
                </label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem', cursor: 'pointer' }}>
                    <input type="checkbox" checked={useStreaming} onChange={(e) => setUseStreaming(e.target.checked)} />
                    {t('qa_stream')}
                </label>
            </div>

            {/* Submit button */}
            <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
                <button
                    onClick={handleSubmit}
                    disabled={loading || !question.trim() || !consent}
                    style={{
                        padding: '8px 18px',
                        fontSize: '0.85rem',
                        fontWeight: 600,
                        border: 'none',
                        borderRadius: 5,
                        backgroundColor: loading || !question.trim() || !consent ? 'var(--muted-foreground)' : 'var(--success)',
                        color: 'var(--success-foreground)',
                        cursor: loading || !question.trim() || !consent ? 'default' : 'pointer',
                    }}
                >
                    {loading ? t('qa_answering') : t('qa_ask')}
                </button>
                {loading && (
                    <button
                        onClick={handleCancel}
                        style={{
                            padding: '8px 14px',
                            fontSize: '0.85rem',
                            border: '1px solid var(--destructive)',
                            borderRadius: 5,
                            backgroundColor: 'transparent',
                            color: 'var(--destructive)',
                            cursor: 'pointer',
                        }}
                    >
                        {t('qa_cancel')}
                    </button>
                )}
            </div>

            {/* Error */}
            {error && (
                <div style={{
                    backgroundColor: 'var(--danger-subtle)',
                    border: '1px solid var(--danger-border)',
                    borderRadius: 6,
                    padding: 12,
                    marginBottom: 12,
                    fontSize: '0.85rem',
                    color: 'var(--danger-subtle-foreground)',
                }}>
                    ⚠ {error}
                </div>
            )}

            {/* Tool trace */}
            <ToolTracePanel toolCalls={toolCalls} />

            {/* Loading indicator */}
            {loading && !answerMarkdown && toolCalls.length === 0 && (
                <div style={{ padding: 24, textAlign: 'center', color: 'var(--muted-foreground)' }}>
                    <div style={{ fontSize: '1.5rem', marginBottom: 8 }}>⏳</div>
                    {t('qa_querying')}
                </div>
            )}

            {/* Answer display */}
            {answerMarkdown && (
                <div style={{
                    backgroundColor: 'var(--surface-sunken)',
                    border: '1px solid var(--border)',
                    borderRadius: 6,
                    padding: 20,
                    marginBottom: 16,
                }}>
                    <h4 style={{ margin: '0 0 12px', fontSize: '1rem', fontWeight: 600, color: 'var(--foreground)' }}>
                        {t('qa_answer')}
                    </h4>
                    <div
                        style={{
                            fontSize: '0.9rem',
                            lineHeight: 1.7,
                            color: 'var(--foreground)',
                        }}
                        dangerouslySetInnerHTML={{ __html: renderMarkdown(answerMarkdown) }}
                    />
                </div>
            )}

            {/* Caveat */}
            {caveat && answerMarkdown && (
                <div style={{
                    backgroundColor: 'var(--muted)',
                    border: '1px solid var(--border)',
                    borderRadius: 6,
                    padding: 10,
                    marginBottom: 12,
                    fontSize: '0.8rem',
                    color: 'var(--muted-foreground)',
                }}>
                    ⚠ {caveat}
                </div>
            )}

            {/* Details panel (collapsible) */}
            {answerMarkdown && (sqlUsed.length > 0 || evidence.length > 0) && (
                <div style={{ marginBottom: 12 }}>
                    <button
                        onClick={() => setShowDetails(!showDetails)}
                        style={{
                            padding: '6px 12px',
                            fontSize: '0.8rem',
                            border: '1px solid var(--muted-foreground)',
                            borderRadius: 4,
                            backgroundColor: 'transparent',
                            color: 'var(--muted-foreground)',
                            cursor: 'pointer',
                        }}
                    >
                        {showDetails ? t('qa_hide_details') : t('qa_show_details')}
                    </button>

                    {showDetails && (
                        <div style={{
                            marginTop: 8,
                            border: '1px solid var(--border)',
                            borderRadius: 6,
                            padding: 16,
                            backgroundColor: 'var(--card)',
                        }}>
                            {/* SQL used */}
                            {sqlUsed.length > 0 && (
                                <div style={{ marginBottom: 12 }}>
                                    <label style={{ fontWeight: 600, fontSize: '0.85rem', color: 'var(--muted-foreground)', display: 'block', marginBottom: 6 }}>
                                        {t('qa_sql_used')}
                                    </label>
                                    {sqlUsed.map((sql, i) => (
                                        <pre key={i} style={{
                                            whiteSpace: 'pre-wrap',
                                            wordBreak: 'break-all',
                                            backgroundColor: 'var(--surface-sunken)',
                                            border: '1px solid var(--border)',
                                            borderRadius: 4,
                                            padding: 8,
                                            fontSize: '0.8rem',
                                            fontFamily: 'SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                                            margin: '0 0 6px',
                                        }}>
                                            {sql}
                                        </pre>
                                    ))}
                                </div>
                            )}

                            {/* Evidence */}
                            {evidence.length > 0 && (
                                <div>
                                    <label style={{ fontWeight: 600, fontSize: '0.85rem', color: 'var(--muted-foreground)', display: 'block', marginBottom: 6 }}>
                                        {t('qa_sources')}
                                    </label>
                                    {evidence.map((ev, i) => (
                                        <div key={i} style={{
                                            display: 'flex',
                                            alignItems: 'center',
                                            gap: 8,
                                            marginBottom: 4,
                                            fontSize: '0.8rem',
                                        }}>
                                            <span style={{
                                                display: 'inline-block',
                                                padding: '2px 6px',
                                                borderRadius: 3,
                                                fontSize: '0.7rem',
                                                fontWeight: 600,
                                                backgroundColor: ev.type === 'database' ? 'var(--success-subtle)' : 'var(--info-subtle)',
                                                color: ev.type === 'database' ? 'var(--success-subtle-foreground)' : 'var(--info-subtle-foreground)',
                                            }}>
                                                {ev.type === 'database' ? t('qa_db') : t('qa_model')}
                                            </span>
                                            <strong>{ev.label}</strong>
                                            <span style={{ color: 'var(--muted-foreground)' }}>— {ev.detail}</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Simple Markdown to HTML renderer.
 * Handles headings, bold, italic, lists, blockquotes, code blocks, tables, and paragraphs.
 */
function renderMarkdown(md: string): string {
    // Escape HTML first
    let html = md
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    // Code blocks (``` ... ```)
    html = html.replace(/```(\w*)\n([\s\S]*?)```/g, (_match, _lang, code) => {
        return `<pre style="background:var(--surface-sunken);border:1px solid var(--border);border-radius:4px;padding:10px;font-size:0.85rem;overflow-x:auto;"><code>${code.trim()}</code></pre>`;
    });

    // Inline code
    html = html.replace(/`([^`]+)`/g, '<code style="background:var(--muted);padding:1px 4px;border-radius:3px;font-size:0.85em;">$1</code>');

    // Headings
    html = html.replace(/^#### (.+)$/gm, '<h6 style="margin:12px 0 4px;font-size:0.85rem;font-weight:600;">$1</h6>');
    html = html.replace(/^### (.+)$/gm, '<h5 style="margin:14px 0 6px;font-size:0.9rem;font-weight:600;">$1</h5>');
    html = html.replace(/^## (.+)$/gm, '<h4 style="margin:16px 0 8px;font-size:1rem;font-weight:600;">$1</h4>');
    html = html.replace(/^# (.+)$/gm, '<h3 style="margin:18px 0 10px;font-size:1.1rem;font-weight:700;">$1</h3>');

    // Bold and italic
    html = html.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');

    // Blockquotes
    html = html.replace(/^&gt; (.+)$/gm, '<blockquote style="border-left:3px solid var(--border);padding-left:12px;margin:8px 0;color:var(--muted-foreground);">$1</blockquote>');

    // Unordered lists
    html = html.replace(/^[-*] (.+)$/gm, '<li style="margin-left:16px;">$1</li>');

    // Wrap consecutive <li> in <ul>
    html = html.replace(/((?:<li[^>]*>.*?<\/li>\n?)+)/g, '<ul style="padding-left:8px;margin:8px 0;">$1</ul>');

    // Markdown tables
    html = html.replace(
        /(^\|.+\|[ \t]*\n\|[ \t:|-]+\|[ \t]*\n(?:\|.+\|[ \t]*\n?)*)/gm,
        (_tableBlock: string) => {
            const lines = _tableBlock.trim().split('\n');
            if (lines.length < 2) return _tableBlock;

            const parseRow = (line: string) =>
                line.replace(/^\|/, '').replace(/\|$/, '').split('|').map(c => c.trim());

            const headers = parseRow(lines[0]);

            // Parse alignment from separator row
            const separators = parseRow(lines[1]);
            const aligns = separators.map(s => {
                if (s.startsWith(':') && s.endsWith(':')) return 'center';
                if (s.endsWith(':')) return 'right';
                return 'left';
            });

            let table = '<div style="overflow-x:auto;margin:10px 0;"><table style="border-collapse:collapse;width:100%;font-size:0.85rem;">';
            table += '<thead><tr>';
            headers.forEach((h, i) => {
                table += `<th style="border:1px solid var(--border);padding:6px 10px;background:var(--surface-sunken);text-align:${aligns[i] || 'left'};">${h}</th>`;
            });
            table += '</tr></thead><tbody>';

            for (let i = 2; i < lines.length; i++) {
                if (!lines[i].trim()) continue;
                const cells = parseRow(lines[i]);
                table += '<tr>';
                cells.forEach((c, j) => {
                    table += `<td style="border:1px solid var(--border);padding:6px 10px;text-align:${aligns[j] || 'left'};">${c}</td>`;
                });
                table += '</tr>';
            }

            table += '</tbody></table></div>';
            return table;
        },
    );

    // Horizontal rule
    html = html.replace(/^---$/gm, '<hr style="border:none;border-top:1px solid var(--border);margin:12px 0;" />');

    // Paragraphs: convert double newlines to paragraph breaks
    html = html.replace(/\n\n/g, '</p><p style="margin:8px 0;">');

    // Single line breaks
    html = html.replace(/\n/g, '<br/>');

    return `<p style="margin:8px 0;">${html}</p>`;
}
