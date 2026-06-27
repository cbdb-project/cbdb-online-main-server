import React, { useState, useCallback, useRef, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import ModeTabs, { type PlaygroundMode } from '../../components/QueryPlayground/ModeTabs';
import SqlEditorPanel from '../../components/QueryPlayground/SqlEditorPanel';
import QueryResultTable from '../../components/QueryPlayground/QueryResultTable';
import QbeBuilder from '../../components/QueryPlayground/QbeBuilder';
import NlQueryPanel from '../../components/QueryPlayground/NlQueryPanel';
import HistoricalQaPanel from '../../components/QueryPlayground/HistoricalQaPanel';
import { useTranslation } from '../../hooks/useTranslation';

interface QbeTable {
    name: string;
    description: string;
    internal: boolean;
}

interface PageProps {
    initialSql: string;
    nlModel: string;
    qbeTables: QbeTable[];
    pageUrl: string;
    runEndpoint: string;
    schemaEndpoint: string;
    generateFromNlEndpoint: string;
    generateFromNlStreamEndpoint: string;
    answerFromNlEndpoint: string;
    answerFromNlStreamEndpoint: string;
}

interface QueryResult {
    columns: string[];
    rows: Record<string, unknown>[];
    page: number;
    hasMore: boolean;
}

export default function Index() {
    const props = usePage<{ props: PageProps }>().props as unknown as PageProps;
    const {
        initialSql,
        nlModel,
        qbeTables,
        pageUrl,
        runEndpoint,
        schemaEndpoint,
        generateFromNlEndpoint,
        generateFromNlStreamEndpoint,
        answerFromNlEndpoint,
        answerFromNlStreamEndpoint,
    } = props;

    const t = useTranslation('query');

    // Determine initial mode from URL
    const getInitialMode = (): PlaygroundMode => {
        const params = new URLSearchParams(window.location.search);
        const mode = params.get('mode');
        if (mode === 'nl' || mode === 'qbe' || mode === 'qa') return mode;
        return 'sql';
    };

    const [activeMode, setActiveMode] = useState<PlaygroundMode>(getInitialMode);
    const [sql, setSql] = useState(initialSql);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [result, setResult] = useState<QueryResult | null>(null);
    const [copiedLink, setCopiedLink] = useState(false);

    const resultRef = useRef<HTMLDivElement>(null);
    const abortControllerRef = useRef<AbortController | null>(null);

    // Update URL when mode or sql changes
    const updateUrl = useCallback((newMode: PlaygroundMode, newSql: string) => {
        const params = new URLSearchParams();
        if (newMode !== 'sql') params.set('mode', newMode);
        if (newSql && newSql !== 'SELECT * FROM DYNASTIES') params.set('sql', newSql);
        const queryString = params.toString();
        const url = pageUrl + (queryString ? `?${queryString}` : '');
        window.history.replaceState({}, '', url);
    }, [pageUrl]);

    const handleModeChange = useCallback((mode: PlaygroundMode) => {
        setActiveMode(mode);
        updateUrl(mode, sql);
    }, [sql, updateUrl]);

    const handleSqlChange = useCallback((newSql: string) => {
        setSql(newSql);
        // Debounce URL update for typing
    }, []);

    // Execute SQL query
    const executeQuery = useCallback(async (querySql?: string, page: number = 1) => {
        const targetSql = querySql ?? sql;
        if (!targetSql.trim()) return;

        // Cancel previous request
        abortControllerRef.current?.abort();
        abortControllerRef.current = new AbortController();

        setLoading(true);
        setError('');

        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

        try {
            const response = await fetch(runEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ sql: targetSql, page }),
                signal: abortControllerRef.current.signal,
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }

            setResult({
                columns: data.columns || [],
                rows: data.data || [],
                page: data.page || page,
                hasMore: data.has_more || false,
            });

            // Update URL with current SQL
            updateUrl(activeMode, targetSql);

            // Scroll to results
            setTimeout(() => {
                resultRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 100);
        } catch (err) {
            if (err instanceof Error && err.name === 'AbortError') return;
            setError(err instanceof Error ? err.message : t('query_failed'));
            setResult(null);
        } finally {
            setLoading(false);
        }
    }, [sql, runEndpoint, activeMode, updateUrl]);

    const handlePageChange = useCallback((page: number) => {
        executeQuery(sql, page);
    }, [sql, executeQuery]);

    const handleShare = useCallback(() => {
        const params = new URLSearchParams();
        if (activeMode !== 'sql') params.set('mode', activeMode);
        if (sql.trim()) params.set('sql', sql);
        const queryString = params.toString();
        const shareUrl = window.location.origin + pageUrl + (queryString ? `?${queryString}` : '');

        navigator.clipboard.writeText(shareUrl).then(() => {
            setCopiedLink(true);
            setTimeout(() => setCopiedLink(false), 2000);
        }).catch(() => {
            // Fallback: select a temporary input
            const input = document.createElement('input');
            input.value = shareUrl;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            setCopiedLink(true);
            setTimeout(() => setCopiedLink(false), 2000);
        });
    }, [activeMode, sql, pageUrl]);

    // Handle SQL generated from QBE or NL
    const handleSqlFromOtherMode = useCallback((generatedSql: string) => {
        setSql(generatedSql);
        setActiveMode('sql');
        updateUrl('sql', generatedSql);
    }, [updateUrl]);

    // Handle browser back/forward
    useEffect(() => {
        const handlePopState = () => {
            const params = new URLSearchParams(window.location.search);
            const mode = params.get('mode');
            if (mode === 'nl' || mode === 'qbe' || mode === 'qa') {
                setActiveMode(mode);
            } else {
                setActiveMode('sql');
            }
            const urlSql = params.get('sql');
            if (urlSql) setSql(urlSql);
        };
        window.addEventListener('popstate', handlePopState);
        return () => window.removeEventListener('popstate', handlePopState);
    }, []);

    return (
        <DashboardLayout disableContentPadding>
            <div style={{ maxWidth: 1200, margin: '0 auto', padding: '24px 16px' }}>
                {/* Header */}
                <div style={{ marginBottom: 20 }}>
                    <h2 style={{ margin: '0 0 4px', fontSize: '1.4rem', fontWeight: 700, color: 'var(--foreground)' }}>
                        {t('page_title')}
                    </h2>
                    <p style={{ margin: 0, color: 'var(--muted-foreground)', fontSize: '0.85rem' }}>
                        {t('beta_notice')}
                    </p>
                </div>

                {/* Mode Tabs */}
                <ModeTabs activeMode={activeMode} onModeChange={handleModeChange} />

                {/* Mode content */}
                <div style={{
                    backgroundColor: 'var(--card)',
                    border: '1px solid var(--border)',
                    borderTop: 'none',
                    borderRadius: '0 0 6px 6px',
                    padding: 20,
                }}>
                    {activeMode === 'sql' && (
                        <SqlEditorPanel
                            sql={sql}
                            onSqlChange={handleSqlChange}
                            onExecute={() => executeQuery()}
                            onShare={handleShare}
                            loading={loading}
                        />
                    )}

                    {activeMode === 'nl' && (
                        <NlQueryPanel
                            nlModel={nlModel}
                            generateFromNlEndpoint={generateFromNlEndpoint}
                            generateFromNlStreamEndpoint={generateFromNlStreamEndpoint}
                            onSqlGenerated={handleSqlFromOtherMode}
                        />
                    )}

                    {activeMode === 'qbe' && (
                        <QbeBuilder
                            tables={qbeTables}
                            schemaEndpoint={schemaEndpoint}
                            onGenerateSql={handleSqlFromOtherMode}
                        />
                    )}

                    {activeMode === 'qa' && (
                        <HistoricalQaPanel
                            nlModel={nlModel}
                            answerFromNlEndpoint={answerFromNlEndpoint}
                            answerFromNlStreamEndpoint={answerFromNlStreamEndpoint}
                        />
                    )}
                </div>

                {/* Copied link toast */}
                {copiedLink && (
                    <div style={{
                        position: 'fixed',
                        bottom: 24,
                        right: 24,
                        backgroundColor: 'var(--success)',
                        color: 'var(--success-foreground)',
                        padding: '10px 20px',
                        borderRadius: 6,
                        fontSize: '0.85rem',
                        fontWeight: 600,
                        boxShadow: '0 2px 8px rgba(0,0,0,0.15)',
                        zIndex: 1000,
                    }}>
                        {t('link_copied')}
                    </div>
                )}

                {/* Error display */}
                {error && (
                    <div style={{
                        marginTop: 16,
                        backgroundColor: 'var(--danger-subtle)',
                        border: '1px solid var(--danger-border)',
                        borderRadius: 6,
                        padding: 12,
                        fontSize: '0.85rem',
                        color: 'var(--danger-subtle-foreground)',
                    }}>
                        <strong>{t('error_prefix')}</strong>{error}
                    </div>
                )}

                {/* Results area - shared across modes */}
                <div ref={resultRef} style={{ marginTop: 20 }}>
                    {(result || loading) && (
                        <div style={{
                            backgroundColor: 'var(--card)',
                            border: '1px solid var(--border)',
                            borderRadius: 6,
                            padding: 16,
                        }}>
                            <div style={{
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'center',
                                marginBottom: 12,
                            }}>
                                <h3 style={{ margin: 0, fontSize: '1rem', fontWeight: 600, color: 'var(--foreground)' }}>
                                    {t('query_results_title')}
                                </h3>
                                {result && (
                                    <span style={{ fontSize: '0.8rem', color: 'var(--muted-foreground)' }}>
                                        {result.rows.length} {t('results_count_suffix')}
                                    </span>
                                )}
                            </div>

                            {loading ? (
                                <div style={{ padding: 24, textAlign: 'center', color: 'var(--muted-foreground)' }}>
                                    <div style={{ fontSize: '1.5rem', marginBottom: 8 }}>⏳</div>
                                    {t('query_executing')}
                                </div>
                            ) : result ? (
                                <QueryResultTable
                                    columns={result.columns}
                                    rows={result.rows}
                                    page={result.page}
                                    hasMore={result.hasMore}
                                    loading={loading}
                                    onPageChange={handlePageChange}
                                />
                            ) : null}
                        </div>
                    )}
                </div>
            </div>
        </DashboardLayout>
    );
}
