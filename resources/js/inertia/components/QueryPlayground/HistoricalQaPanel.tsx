import React, { useState, useCallback, useRef } from 'react';

interface ToolCall {
    name: string;
    status: 'running' | 'complete' | 'error';
    args?: Record<string, unknown>;
    result?: string;
}

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
    const [question, setQuestion] = useState('');
    const [consent, setConsent] = useState(false);
    const [useTools, setUseTools] = useState(true);
    const [useStreaming, setUseStreaming] = useState(true);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [answerMarkdown, setAnswerMarkdown] = useState('');
    const [summary, setSummary] = useState('');
    const [sqlUsed, setSqlUsed] = useState<string[]>([]);
    const [toolCalls, setToolCalls] = useState<ToolCall[]>([]);
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
                if (!reader) throw new Error('無法讀取回應串流');

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
                setError(err instanceof Error ? err.message : '問答生成失敗');
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
                    throw new Error(data.error || '問答生成失敗');
                }

                setAnswerMarkdown(data.answer_markdown || '');
                setSummary(data.summary || '');
                setSqlUsed(data.sql_used || []);
                setEvidence(data.evidence || []);
                setCaveat(data.caveat || '');
            } catch (err) {
                setError(err instanceof Error ? err.message : '問答生成失敗');
            } finally {
                setLoading(false);
            }
        }
    }, [question, consent, useTools, useStreaming, answerFromNlEndpoint, answerFromNlStreamEndpoint]);

    const handleStreamEvent = (event: string, data: Record<string, unknown>) => {
        switch (event) {
            case 'status':
                // General status message — no-op for now
                break;
            case 'tool_execution_start':
                setToolCalls((prev) => [...prev, {
                    name: String(data.tool_name || data.name || 'tool'),
                    status: 'running',
                    args: data.arguments as Record<string, unknown>,
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
            case 'complete':
                setAnswerMarkdown(String(data.answer_markdown || ''));
                setSummary(String(data.summary || ''));
                setSqlUsed((data.sql_used as string[]) || []);
                setEvidence((data.evidence as Evidence[]) || []);
                setCaveat(String(data.caveat || ''));
                break;
            case 'error':
                setError(String(data.error || '問答生成發生錯誤'));
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
                <strong>⚠ 隱私提示：</strong>此功能使用 AI 模型（{nlModel}）回答歷史人物問題。您的問題內容將傳送至 Google Gemini API 進行處理，
                並會記錄查詢日誌以改善服務品質。請勿輸入敏感個人資訊。
            </div>

            {/* Question input */}
            <div style={{ marginBottom: 12 }}>
                <label style={{ fontWeight: 600, fontSize: '0.9rem', color: '#343a40', display: 'block', marginBottom: 4 }}>
                    請輸入您的歷史人物問題
                </label>
                <textarea
                    value={question}
                    onChange={(e) => setQuestion(e.target.value)}
                    placeholder="例如：李白是什麼時代的人？王安石有哪些常見別名？韓愈與哪些人物有社會關係？"
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
                    使用工具查詢資料庫（建議開啟）
                </label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem', cursor: 'pointer' }}>
                    <input type="checkbox" checked={useStreaming} onChange={(e) => setUseStreaming(e.target.checked)} />
                    串流模式
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
                        backgroundColor: loading || !question.trim() || !consent ? '#adb5bd' : '#28a745',
                        color: '#fff',
                        cursor: loading || !question.trim() || !consent ? 'default' : 'pointer',
                    }}
                >
                    {loading ? '回答生成中…' : '📖 回答問題'}
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

            {/* Error */}
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

            {/* Loading indicator */}
            {loading && !answerMarkdown && toolCalls.length === 0 && (
                <div style={{ padding: 24, textAlign: 'center', color: '#6c757d' }}>
                    <div style={{ fontSize: '1.5rem', marginBottom: 8 }}>⏳</div>
                    正在查詢資料庫並生成回答…
                </div>
            )}

            {/* Answer display */}
            {answerMarkdown && (
                <div style={{
                    backgroundColor: '#f8f9fa',
                    border: '1px solid #dee2e6',
                    borderRadius: 6,
                    padding: 20,
                    marginBottom: 16,
                }}>
                    <h4 style={{ margin: '0 0 12px', fontSize: '1rem', fontWeight: 600, color: '#212529' }}>
                        📖 回答
                    </h4>
                    <div
                        style={{
                            fontSize: '0.9rem',
                            lineHeight: 1.7,
                            color: '#212529',
                        }}
                        dangerouslySetInnerHTML={{ __html: renderMarkdown(answerMarkdown) }}
                    />
                </div>
            )}

            {/* Caveat */}
            {caveat && answerMarkdown && (
                <div style={{
                    backgroundColor: '#e2e3e5',
                    border: '1px solid #d6d8db',
                    borderRadius: 6,
                    padding: 10,
                    marginBottom: 12,
                    fontSize: '0.8rem',
                    color: '#383d41',
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
                            border: '1px solid #6c757d',
                            borderRadius: 4,
                            backgroundColor: 'transparent',
                            color: '#6c757d',
                            cursor: 'pointer',
                        }}
                    >
                        {showDetails ? '▼ 隱藏詳細資訊' : '▶ 顯示詳細資訊（SQL、證據來源）'}
                    </button>

                    {showDetails && (
                        <div style={{
                            marginTop: 8,
                            border: '1px solid #dee2e6',
                            borderRadius: 6,
                            padding: 16,
                            backgroundColor: '#fff',
                        }}>
                            {/* SQL used */}
                            {sqlUsed.length > 0 && (
                                <div style={{ marginBottom: 12 }}>
                                    <label style={{ fontWeight: 600, fontSize: '0.85rem', color: '#343a40', display: 'block', marginBottom: 6 }}>
                                        使用的 SQL 查詢
                                    </label>
                                    {sqlUsed.map((sql, i) => (
                                        <pre key={i} style={{
                                            whiteSpace: 'pre-wrap',
                                            wordBreak: 'break-all',
                                            backgroundColor: '#f8f9fa',
                                            border: '1px solid #dee2e6',
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
                                    <label style={{ fontWeight: 600, fontSize: '0.85rem', color: '#343a40', display: 'block', marginBottom: 6 }}>
                                        資料來源
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
                                                backgroundColor: ev.type === 'database' ? '#d4edda' : '#cce5ff',
                                                color: ev.type === 'database' ? '#155724' : '#004085',
                                            }}>
                                                {ev.type === 'database' ? '📋 資料庫' : '📚 模型補充'}
                                            </span>
                                            <strong>{ev.label}</strong>
                                            <span style={{ color: '#6c757d' }}>— {ev.detail}</span>
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
 * Handles headings, bold, italic, lists, blockquotes, code blocks, and paragraphs.
 */
function renderMarkdown(md: string): string {
    // Escape HTML first
    let html = md
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    // Code blocks (``` ... ```)
    html = html.replace(/```(\w*)\n([\s\S]*?)```/g, (_match, _lang, code) => {
        return `<pre style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;padding:10px;font-size:0.85rem;overflow-x:auto;"><code>${code.trim()}</code></pre>`;
    });

    // Inline code
    html = html.replace(/`([^`]+)`/g, '<code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;font-size:0.85em;">$1</code>');

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
    html = html.replace(/^&gt; (.+)$/gm, '<blockquote style="border-left:3px solid #dee2e6;padding-left:12px;margin:8px 0;color:#6c757d;">$1</blockquote>');

    // Unordered lists
    html = html.replace(/^[-*] (.+)$/gm, '<li style="margin-left:16px;">$1</li>');

    // Wrap consecutive <li> in <ul>
    html = html.replace(/((?:<li[^>]*>.*?<\/li>\n?)+)/g, '<ul style="padding-left:8px;margin:8px 0;">$1</ul>');

    // Horizontal rule
    html = html.replace(/^---$/gm, '<hr style="border:none;border-top:1px solid #dee2e6;margin:12px 0;" />');

    // Paragraphs: convert double newlines to paragraph breaks
    html = html.replace(/\n\n/g, '</p><p style="margin:8px 0;">');

    // Single line breaks
    html = html.replace(/\n/g, '<br/>');

    return `<p style="margin:8px 0;">${html}</p>`;
}
