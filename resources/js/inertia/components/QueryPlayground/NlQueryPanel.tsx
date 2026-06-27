import React, { useState, useCallback, useRef } from 'react';
import ToolTracePanel, { type ToolCallTrace, type ToolResultSummary } from './ToolTracePanel';
import { useTranslation } from '../../hooks/useTranslation';

interface Props {
    nlModel: string;
    generateFromNlEndpoint: string;
    generateFromNlStreamEndpoint: string;
    onSqlGenerated: (sql: string) => void;
}

export default function NlQueryPanel({ nlModel, generateFromNlEndpoint, generateFromNlStreamEndpoint, onSqlGenerated }: Props) {
    const t = useTranslation('query');
    const [question, setQuestion] = useState('');
    const [consent, setConsent] = useState(false);
    const [useTools, setUseTools] = useState(true);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [generatedSql, setGeneratedSql] = useState('');
    const [explanation, setExplanation] = useState('');
    const [toolCalls, setToolCalls] = useState<ToolCallTrace[]>([]);
    const [useStreaming, setUseStreaming] = useState(true);
    const abortControllerRef = useRef<AbortController | null>(null);

    const handleGenerate = useCallback(async () => {
        if (!question.trim() || !consent) return;

        setLoading(true);
        setError('');
        setGeneratedSql('');
        setExplanation('');
        setToolCalls([]);

        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

        if (useStreaming) {
            // SSE streaming version
            try {
                abortControllerRef.current = new AbortController();
                const response = await fetch(generateFromNlStreamEndpoint, {
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
                if (!reader) throw new Error(t('nl_stream_failed'));

                const decoder = new TextDecoder();
                let buffer = '';
                let currentEvent = '';
                let currentData = '';

                const flushEvent = () => {
                    if (!currentEvent || !currentData) {
                        return;
                    }

                    try {
                        const parsed = JSON.parse(currentData);
                        handleStreamEvent(currentEvent, parsed);
                    } catch {
                        // Ignore malformed JSON
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
                        if (remainingLine) {
                            processLine(remainingLine);
                        }
                        flushEvent();
                        break;
                    }
                }
            } catch (err) {
                if (err instanceof Error && err.name === 'AbortError') return;
                setError(err instanceof Error ? err.message : t('nl_generate_failed'));
            } finally {
                setLoading(false);
                abortControllerRef.current = null;
            }
        } else {
            // Non-streaming version
            try {
                const response = await fetch(generateFromNlEndpoint, {
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
                    throw new Error(data.error || t('nl_generate_failed'));
                }

                setGeneratedSql(data.sql || '');
                setExplanation(data.explanation || '');
                // Populate tool calls if returned in non-streaming response
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
                setError(err instanceof Error ? err.message : t('nl_generate_failed'));
            } finally {
                setLoading(false);
            }
        }
    }, [question, consent, useTools, useStreaming, generateFromNlEndpoint, generateFromNlStreamEndpoint, t]);

    const handleStreamEvent = (event: string, data: Record<string, unknown>) => {
        switch (event) {
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
                    let idx = toolCallId ? updated.findLastIndex(t => t.tool_call_id === toolCallId) : -1;
                    if (idx < 0) idx = updated.findLastIndex(t => t.status === 'running');
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
            case 'llm_call_complete':
                // Progress indicator — no action needed
                break;
            case 'complete':
                setGeneratedSql(String(data.sql || ''));
                setExplanation(String(data.explanation || ''));
                break;
            case 'error':
                setError(String(data.error || t('nl_generate_error')));
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
                <strong>{t('nl_privacy_label')}</strong>{' '}{t('nl_privacy_body', { model: nlModel })}
            </div>

            <div style={{ marginBottom: 12 }}>
                <label style={{ fontWeight: 600, fontSize: '0.9rem', color: 'var(--muted-foreground)', display: 'block', marginBottom: 4 }}>
                    {t('nl_query_placeholder')}
                </label>
                <textarea
                    value={question}
                    onChange={(e) => setQuestion(e.target.value)}
                    placeholder={t('nl_example')}
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
                    {t('nl_agree_privacy')}
                </label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem', cursor: 'pointer' }}>
                    <input type="checkbox" checked={useTools} onChange={(e) => setUseTools(e.target.checked)} />
                    {t('nl_use_tools')}
                </label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem', cursor: 'pointer' }}>
                    <input type="checkbox" checked={useStreaming} onChange={(e) => setUseStreaming(e.target.checked)} />
                    {t('nl_stream')}
                </label>
            </div>

            {/* Generate button */}
            <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
                <button
                    onClick={handleGenerate}
                    disabled={loading || !question.trim() || !consent}
                    style={{
                        padding: '8px 18px',
                        fontSize: '0.85rem',
                        fontWeight: 600,
                        border: 'none',
                        borderRadius: 5,
                        backgroundColor: loading || !question.trim() || !consent ? 'var(--muted-foreground)' : 'var(--primary)',
                        color: 'var(--primary-foreground)',
                        cursor: loading || !question.trim() || !consent ? 'default' : 'pointer',
                    }}
                >
                    {loading ? t('nl_generating') : t('nl_generate')}
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
                        {t('nl_cancel')}
                    </button>
                )}
            </div>

            {/* Error display */}
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

            {/* Generated SQL */}
            {generatedSql && (
                <div style={{
                    backgroundColor: 'var(--surface-sunken)',
                    border: '1px solid var(--border)',
                    borderRadius: 6,
                    padding: 16,
                    marginBottom: 12,
                }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                        <label style={{ fontWeight: 600, fontSize: '0.9rem', color: 'var(--muted-foreground)' }}>
                            {t('nl_generated_sql')}
                        </label>
                        <button
                            onClick={() => onSqlGenerated(generatedSql)}
                            style={{
                                padding: '6px 14px',
                                fontSize: '0.85rem',
                                fontWeight: 600,
                                border: 'none',
                                borderRadius: 5,
                                backgroundColor: 'var(--primary)',
                                color: 'var(--primary-foreground)',
                                cursor: 'pointer',
                            }}
                        >
                            {t('nl_send_to_sql')}
                        </button>
                    </div>
                    <pre style={{
                        whiteSpace: 'pre-wrap',
                        wordBreak: 'break-all',
                        backgroundColor: 'var(--muted)',
                        border: '1px solid var(--border)',
                        borderRadius: 4,
                        padding: 12,
                        fontSize: '0.85rem',
                        fontFamily: 'SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                        margin: 0,
                    }}>
                        {generatedSql}
                    </pre>
                    {explanation && (
                        <div style={{ marginTop: 12, fontSize: '0.85rem', color: 'var(--muted-foreground)' }}>
                            <strong>{t('nl_explanation')}</strong> {explanation}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
