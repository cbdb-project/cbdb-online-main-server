import React, { useState, useCallback, useRef } from 'react';

interface ToolCall {
    name: string;
    status: 'running' | 'complete' | 'error';
    args?: Record<string, unknown>;
    result?: string;
}

interface Props {
    nlModel: string;
    generateFromNlEndpoint: string;
    generateFromNlStreamEndpoint: string;
    onSqlGenerated: (sql: string) => void;
}

export default function NlQueryPanel({ nlModel, generateFromNlEndpoint, generateFromNlStreamEndpoint, onSqlGenerated }: Props) {
    const [question, setQuestion] = useState('');
    const [consent, setConsent] = useState(false);
    const [useTools, setUseTools] = useState(true);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [generatedSql, setGeneratedSql] = useState('');
    const [explanation, setExplanation] = useState('');
    const [toolCalls, setToolCalls] = useState<ToolCall[]>([]);
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
                if (!reader) throw new Error('無法讀取回應串流');

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
                setError(err instanceof Error ? err.message : '生成失敗');
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
                    throw new Error(data.error || '生成失敗');
                }

                setGeneratedSql(data.sql || '');
                setExplanation(data.explanation || '');
            } catch (err) {
                setError(err instanceof Error ? err.message : '生成失敗');
            } finally {
                setLoading(false);
            }
        }
    }, [question, consent, useTools, useStreaming, generateFromNlEndpoint, generateFromNlStreamEndpoint]);

    const handleStreamEvent = (event: string, data: Record<string, unknown>) => {
        switch (event) {
            case 'tool_execution_start':
                setToolCalls((prev) => [...prev, {
                    name: String(data.tool_name || data.name || 'tool'),
                    status: 'running',
                    args: data.args as Record<string, unknown>,
                }]);
                break;
            case 'tool_execution_complete':
                setToolCalls((prev) => {
                    const updated = [...prev];
                    const last = updated.findLastIndex((t) => t.status === 'running');
                    if (last >= 0) {
                        updated[last] = { ...updated[last], status: 'complete', result: String(data.result || '') };
                    }
                    return updated;
                });
                break;
            case 'llm_call_complete':
                // Progress indicator — no action needed
                break;
            case 'complete':
                setGeneratedSql(String(data.sql || ''));
                setExplanation(String(data.explanation || ''));
                break;
            case 'error':
                setError(String(data.error || '生成發生錯誤'));
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
                backgroundColor: '#fff3cd',
                border: '1px solid #ffc107',
                borderRadius: 6,
                padding: 12,
                marginBottom: 16,
                fontSize: '0.85rem',
                color: '#856404',
            }}>
                <strong>⚠ 隱私提示：</strong>此功能使用 AI 模型（{nlModel}）生成 SQL。您的問題內容將傳送至 Google Gemini API 進行處理，
                並會記錄查詢日誌以改善服務品質。請勿輸入敏感個人資訊。
            </div>

            <div style={{ marginBottom: 12 }}>
                <label style={{ fontWeight: 600, fontSize: '0.9rem', color: '#343a40', display: 'block', marginBottom: 4 }}>
                    用自然語言描述您想查詢的內容
                </label>
                <textarea
                    value={question}
                    onChange={(e) => setQuestion(e.target.value)}
                    placeholder="例如：找出所有宋朝進士的姓名和籍貫"
                    rows={3}
                    maxLength={1000}
                    disabled={loading}
                    style={{
                        width: '100%',
                        padding: 12,
                        border: '1px solid #ced4da',
                        borderRadius: 6,
                        fontSize: '0.9rem',
                        resize: 'vertical',
                        boxSizing: 'border-box',
                    }}
                />
                <div style={{ textAlign: 'right', fontSize: '0.75rem', color: '#6c757d', marginTop: 2 }}>
                    {question.length}/1000
                </div>
            </div>

            {/* Options */}
            <div style={{ display: 'flex', gap: 16, marginBottom: 12, flexWrap: 'wrap', alignItems: 'center' }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem', cursor: 'pointer' }}>
                    <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
                    我已閱讀並同意上述隱私提示
                </label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem', cursor: 'pointer' }}>
                    <input type="checkbox" checked={useTools} onChange={(e) => setUseTools(e.target.checked)} />
                    使用工具輔助（可查看資料表結構）
                </label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem', cursor: 'pointer' }}>
                    <input type="checkbox" checked={useStreaming} onChange={(e) => setUseStreaming(e.target.checked)} />
                    串流模式
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
                        backgroundColor: loading || !question.trim() || !consent ? '#adb5bd' : '#6f42c1',
                        color: '#fff',
                        cursor: loading || !question.trim() || !consent ? 'default' : 'pointer',
                    }}
                >
                    {loading ? '生成中…' : '🤖 生成 SQL'}
                </button>
                {loading && (
                    <button
                        onClick={handleCancel}
                        style={{
                            padding: '8px 14px',
                            fontSize: '0.85rem',
                            border: '1px solid #dc3545',
                            borderRadius: 5,
                            backgroundColor: 'transparent',
                            color: '#dc3545',
                            cursor: 'pointer',
                        }}
                    >
                        取消
                    </button>
                )}
            </div>

            {/* Error display */}
            {error && (
                <div style={{
                    backgroundColor: '#f8d7da',
                    border: '1px solid #f5c6cb',
                    borderRadius: 6,
                    padding: 12,
                    marginBottom: 12,
                    fontSize: '0.85rem',
                    color: '#721c24',
                }}>
                    ⚠ {error}
                </div>
            )}

            {/* Tool calls timeline */}
            {toolCalls.length > 0 && (
                <div style={{ marginBottom: 16 }}>
                    <label style={{ fontWeight: 600, fontSize: '0.85rem', color: '#343a40', display: 'block', marginBottom: 6 }}>
                        工具調用過程
                    </label>
                    <div style={{ borderLeft: '3px solid #dee2e6', paddingLeft: 12 }}>
                        {toolCalls.map((tc, i) => (
                            <div key={i} style={{ marginBottom: 8, fontSize: '0.8rem' }}>
                                <span style={{
                                    display: 'inline-block',
                                    width: 8,
                                    height: 8,
                                    borderRadius: '50%',
                                    backgroundColor: tc.status === 'running' ? '#ffc107' : tc.status === 'complete' ? '#28a745' : '#dc3545',
                                    marginRight: 8,
                                }} />
                                <strong>{tc.name}</strong>
                                {tc.status === 'running' && <span style={{ color: '#6c757d' }}> — 執行中…</span>}
                                {tc.status === 'complete' && <span style={{ color: '#28a745' }}> — 完成</span>}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Generated SQL */}
            {generatedSql && (
                <div style={{
                    backgroundColor: '#f8f9fa',
                    border: '1px solid #dee2e6',
                    borderRadius: 6,
                    padding: 16,
                    marginBottom: 12,
                }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                        <label style={{ fontWeight: 600, fontSize: '0.9rem', color: '#343a40' }}>
                            生成的 SQL
                        </label>
                        <button
                            onClick={() => onSqlGenerated(generatedSql)}
                            style={{
                                padding: '6px 14px',
                                fontSize: '0.85rem',
                                fontWeight: 600,
                                border: 'none',
                                borderRadius: 5,
                                backgroundColor: '#007bff',
                                color: '#fff',
                                cursor: 'pointer',
                            }}
                        >
                            ▶ 帶到 SQL 模式執行
                        </button>
                    </div>
                    <pre style={{
                        whiteSpace: 'pre-wrap',
                        wordBreak: 'break-all',
                        backgroundColor: '#fff',
                        border: '1px solid #dee2e6',
                        borderRadius: 4,
                        padding: 12,
                        fontSize: '0.85rem',
                        fontFamily: 'SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                        margin: 0,
                    }}>
                        {generatedSql}
                    </pre>
                    {explanation && (
                        <div style={{ marginTop: 12, fontSize: '0.85rem', color: '#495057' }}>
                            <strong>說明：</strong> {explanation}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
