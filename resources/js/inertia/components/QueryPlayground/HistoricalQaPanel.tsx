import React, { useState, useCallback, useRef } from 'react';
import ToolTracePanel, { type ToolCallTrace, type ToolResultSummary } from './ToolTracePanel';
import { useTranslation } from '../../hooks/useTranslation';

interface Evidence {
    type: 'database' | 'model_background';
    label: string;
    detail: string;
}

/**
 * 見 docs/QUERY_PLAYGROUND_QA_MULTITURN_PLAN.md：一輪問答（Q + A）。對話歷史完全
 * 存在前端 state（不落地、不做 localStorage），重新整理頁面即遺失。
 */
interface QaTurn {
    id: string;
    question: string;
    status: 'pending' | 'streaming' | 'done' | 'error';
    answerMarkdown?: string;
    summary?: string;
    sqlUsed?: string[];
    toolCalls?: ToolCallTrace[];
    evidence?: Evidence[];
    caveat?: string;
    error?: string;
}

interface Props {
    nlModel: string;
    answerFromNlEndpoint: string;
    answerFromNlStreamEndpoint: string;
    /** 見第 10 節：由後端 config('query_playground.qa_max_turns') 透過 Inertia props 傳入，前端不寫死數字。 */
    qaMaxTurns: number;
}

function makeTurnId(): string {
    return `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

export default function HistoricalQaPanel({ nlModel, answerFromNlEndpoint, answerFromNlStreamEndpoint, qaMaxTurns }: Props) {
    const t = useTranslation('query');
    const [question, setQuestion] = useState('');
    const [consent, setConsent] = useState(false);
    const [useTools, setUseTools] = useState(true);
    const [useStreaming, setUseStreaming] = useState(true);
    const [turns, setTurns] = useState<QaTurn[]>([]);
    const abortControllerRef = useRef<AbortController | null>(null);
    const activeTurnIdRef = useRef<string | null>(null);

    const isFirstTurn = turns.length === 0;
    const limitReached = turns.length >= qaMaxTurns;
    const lastTurn = turns.length > 0 ? turns[turns.length - 1] : null;
    const isBusy = lastTurn !== null && (lastTurn.status === 'pending' || lastTurn.status === 'streaming');

    const updateTurn = (turnId: string, patch: Partial<QaTurn>) => {
        setTurns((prev) => prev.map((turn) => (turn.id === turnId ? { ...turn, ...patch } : turn)));
    };

    const appendToolCall = (turnId: string, trace: ToolCallTrace) => {
        setTurns((prev) => prev.map((turn) =>
            turn.id === turnId ? { ...turn, toolCalls: [...(turn.toolCalls || []), trace] } : turn,
        ));
    };

    const updateLastToolCall = (turnId: string, matchId: string, patch: Partial<ToolCallTrace>) => {
        setTurns((prev) => prev.map((turn) => {
            if (turn.id !== turnId) return turn;
            const toolCalls = turn.toolCalls || [];
            const updated = [...toolCalls];
            let idx = matchId ? updated.findLastIndex((tc) => tc.tool_call_id === matchId) : -1;
            if (idx < 0) idx = updated.findLastIndex((tc) => tc.status === 'running');
            if (idx >= 0) updated[idx] = { ...updated[idx], ...patch };
            return { ...turn, toolCalls: updated };
        }));
    };

    /** 見第 4.1 節：只取已完成輪次的 {question, summary}，不送完整 answer_markdown。 */
    const buildConversationHistory = (): { question: string; summary: string }[] =>
        turns
            .filter((turn) => turn.status === 'done')
            .map((turn) => ({ question: turn.question, summary: turn.summary || '' }));

    // 按鈕本身也用 isBusy 停用（見下方 render）：若在請求進行中仍能清空 turns，
    // 該請求的 activeTurnIdRef/abortControllerRef 之後在 finally 被清成 null，
    // 會誤傷使用者緊接著送出的下一輪請求（取消鍵失靈、無法正確 abort）。
    const handleNewConversation = () => {
        setTurns([]);
        setQuestion('');
    };

    const handleSubmit = useCallback(async () => {
        const trimmedQuestion = question.trim();
        if (!trimmedQuestion || isBusy || limitReached) return;
        if (isFirstTurn && !consent) return;

        const turnId = makeTurnId();
        activeTurnIdRef.current = turnId;
        const conversationHistory = buildConversationHistory();

        setTurns((prev) => [...prev, { id: turnId, question: trimmedQuestion, status: 'pending' }]);
        setQuestion('');

        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
        const requestBody: Record<string, unknown> = { question: trimmedQuestion, use_tools: useTools };
        if (conversationHistory.length > 0) {
            requestBody.conversation_history = conversationHistory;
        }

        if (useStreaming) {
            try {
                abortControllerRef.current = new AbortController();
                updateTurn(turnId, { status: 'streaming' });

                const response = await fetch(answerFromNlStreamEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'text/event-stream',
                    },
                    body: JSON.stringify(requestBody),
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
                        handleStreamEvent(turnId, currentEvent, parsed);
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
                updateTurn(turnId, { status: 'error', error: err instanceof Error ? err.message : t('qa_failed') });
            } finally {
                abortControllerRef.current = null;
                activeTurnIdRef.current = null;
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
                    body: JSON.stringify(requestBody),
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.error || t('qa_failed'));
                }

                const patch: Partial<QaTurn> = {
                    status: 'done',
                    answerMarkdown: data.answer_markdown || '',
                    summary: data.summary || '',
                    sqlUsed: data.sql_used || [],
                    evidence: data.evidence || [],
                    caveat: data.caveat || '',
                };
                if (Array.isArray(data.tool_calls)) {
                    patch.toolCalls = data.tool_calls.map((tc: Record<string, unknown>) => ({
                        tool_call_id: String(tc.tool_call_id ?? ''),
                        name: String(tc.tool_name ?? ''),
                        status: tc.status === 'error' ? 'error' : 'completed',
                        arguments: (tc.arguments as Record<string, unknown>) ?? undefined,
                        result_summary: (tc.result_summary as ToolResultSummary) ?? undefined,
                        error: tc.status === 'error' ? String((tc.result_summary as Record<string, unknown>)?.error ?? '') : undefined,
                    }));
                }
                updateTurn(turnId, patch);
            } catch (err) {
                updateTurn(turnId, { status: 'error', error: err instanceof Error ? err.message : t('qa_failed') });
            } finally {
                activeTurnIdRef.current = null;
            }
        }
    }, [question, consent, useTools, useStreaming, isBusy, limitReached, isFirstTurn, turns, answerFromNlEndpoint, answerFromNlStreamEndpoint, t]);

    const handleStreamEvent = (turnId: string, event: string, data: Record<string, unknown>) => {
        switch (event) {
            case 'status':
                // General status message — no-op for now
                break;
            case 'tool_execution_start':
                appendToolCall(turnId, {
                    tool_call_id: String(data.tool_call_id ?? ''),
                    name: String(data.tool_name || data.name || 'tool'),
                    status: 'running',
                    arguments: (data.arguments as Record<string, unknown>) ?? undefined,
                });
                break;
            case 'tool_execution_complete': {
                const toolCallId = String(data.tool_call_id ?? '');
                const status: ToolCallTrace['status'] = data.success === false ? 'error' : 'completed';
                const resultSummary = (data.result_summary as ToolResultSummary) ?? undefined;
                updateLastToolCall(turnId, toolCallId, {
                    status,
                    result_summary: resultSummary,
                    error: status === 'error' ? (resultSummary?.error ?? String(data.error ?? '')) : undefined,
                });
                break;
            }
            case 'complete':
                updateTurn(turnId, {
                    status: 'done',
                    answerMarkdown: String(data.answer_markdown || ''),
                    summary: String(data.summary || ''),
                    sqlUsed: (data.sql_used as string[]) || [],
                    evidence: (data.evidence as Evidence[]) || [],
                    caveat: String(data.caveat || ''),
                });
                break;
            case 'error':
                updateTurn(turnId, { status: 'error', error: String(data.error || t('qa_error')) });
                break;
        }
    };

    const handleCancel = () => {
        abortControllerRef.current?.abort();
        // 取消時該輪從未完成，從 turns 移除而非留下卡住的 pending/streaming 卡片。
        const turnId = activeTurnIdRef.current;
        if (turnId) {
            setTurns((prev) => prev.filter((turn) => turn.id !== turnId));
        }
        activeTurnIdRef.current = null;
    };

    return (
        <div>
            {/* Privacy notice：只在第一輪（尚未開始對話）顯示，追問沿用同一份提交端行為，不重複記錄新的同意動作。 */}
            {isFirstTurn && (
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
            )}

            {/* 訊息串：依序顯示每一輪 Q/A */}
            {turns.map((turn) => (
                <div key={turn.id} style={{ marginBottom: 20 }}>
                    <div style={{
                        fontWeight: 600,
                        fontSize: '0.9rem',
                        color: 'var(--foreground)',
                        marginBottom: 8,
                    }}>
                        {turn.question}
                    </div>

                    {turn.error && (
                        <div style={{
                            backgroundColor: 'var(--danger-subtle)',
                            border: '1px solid var(--danger-border)',
                            borderRadius: 6,
                            padding: 12,
                            marginBottom: 12,
                            fontSize: '0.85rem',
                            color: 'var(--danger-subtle-foreground)',
                        }}>
                            ⚠ {turn.error}
                        </div>
                    )}

                    {(turn.toolCalls?.length ?? 0) > 0 && <ToolTracePanel toolCalls={turn.toolCalls || []} />}

                    {(turn.status === 'pending' || turn.status === 'streaming') && !turn.answerMarkdown && (turn.toolCalls?.length ?? 0) === 0 && (
                        <div style={{ padding: 24, textAlign: 'center', color: 'var(--muted-foreground)' }}>
                            <div style={{ fontSize: '1.5rem', marginBottom: 8 }}>⏳</div>
                            {t('qa_querying')}
                        </div>
                    )}

                    {turn.answerMarkdown && (
                        <TurnAnswer t={t} turn={turn} />
                    )}
                </div>
            ))}

            {/* 輪數上限提示 */}
            {limitReached && (
                <div style={{
                    backgroundColor: 'var(--muted)',
                    border: '1px solid var(--border)',
                    borderRadius: 6,
                    padding: 12,
                    marginBottom: 16,
                    fontSize: '0.85rem',
                    color: 'var(--muted-foreground)',
                }}>
                    {t('qa_turn_limit_reached', { count: String(qaMaxTurns) })}
                </div>
            )}

            {/* Question input：第一輪顯示完整表單（含同意勾選/選項），追問後精簡為「Ask anything」樣式。 */}
            {!limitReached && (
                <div style={{ marginBottom: 12 }}>
                    {isFirstTurn && (
                        <label style={{ fontWeight: 600, fontSize: '0.9rem', color: 'var(--muted-foreground)', display: 'block', marginBottom: 4 }}>
                            {t('qa_placeholder')}
                        </label>
                    )}
                    <textarea
                        value={question}
                        onChange={(e) => setQuestion(e.target.value)}
                        placeholder={isFirstTurn ? t('qa_example') : t('qa_follow_up_placeholder')}
                        rows={isFirstTurn ? 3 : 2}
                        maxLength={1000}
                        disabled={isBusy}
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
            )}

            {/* Options：只在第一輪顯示，追問沿用第一輪選擇的 use_tools/use_streaming。 */}
            {isFirstTurn && !limitReached && (
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
            )}

            {/* Submit / cancel / new conversation buttons */}
            <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
                {!limitReached && (
                    <button
                        onClick={handleSubmit}
                        disabled={isBusy || !question.trim() || (isFirstTurn && !consent)}
                        style={{
                            padding: '8px 18px',
                            fontSize: '0.85rem',
                            fontWeight: 600,
                            border: 'none',
                            borderRadius: 5,
                            backgroundColor: isBusy || !question.trim() || (isFirstTurn && !consent) ? 'var(--muted-foreground)' : 'var(--success)',
                            color: 'var(--success-foreground)',
                            cursor: isBusy || !question.trim() || (isFirstTurn && !consent) ? 'default' : 'pointer',
                        }}
                    >
                        {isBusy ? t('qa_answering') : t('qa_ask')}
                    </button>
                )}
                {isBusy && (
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
                {turns.length > 0 && (
                    <button
                        onClick={handleNewConversation}
                        disabled={isBusy}
                        style={{
                            padding: '8px 14px',
                            fontSize: '0.85rem',
                            border: '1px solid var(--muted-foreground)',
                            borderRadius: 5,
                            backgroundColor: 'transparent',
                            color: 'var(--muted-foreground)',
                            cursor: isBusy ? 'default' : 'pointer',
                            opacity: isBusy ? 0.5 : 1,
                        }}
                    >
                        {t('qa_new_conversation')}
                    </button>
                )}
            </div>
        </div>
    );
}

/** 單輪答案卡片：答案本文、caveat、可折疊的 SQL/證據來源詳細資訊。 */
function TurnAnswer({ t, turn }: { t: (key: string, replace?: Record<string, string>) => string; turn: QaTurn }) {
    const [showDetails, setShowDetails] = useState(false);
    const sqlUsed = turn.sqlUsed || [];
    const evidence = turn.evidence || [];

    return (
        <div>
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
                    dangerouslySetInnerHTML={{ __html: renderMarkdown(turn.answerMarkdown || '') }}
                />
            </div>

            {turn.caveat && (
                <div style={{
                    backgroundColor: 'var(--muted)',
                    border: '1px solid var(--border)',
                    borderRadius: 6,
                    padding: 10,
                    marginBottom: 12,
                    fontSize: '0.8rem',
                    color: 'var(--muted-foreground)',
                }}>
                    ⚠ {turn.caveat}
                </div>
            )}

            {(sqlUsed.length > 0 || evidence.length > 0) && (
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
