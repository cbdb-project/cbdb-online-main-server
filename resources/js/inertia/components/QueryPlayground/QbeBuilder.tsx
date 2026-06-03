import React, { useState, useCallback, useEffect, useRef } from 'react';
import { useTranslation } from '../../hooks/useTranslation';

interface QbeTable {
    name: string;
    description: string;
    internal: boolean;
}

interface ColumnInfo {
    name: string;
    type: string;
}

interface TableSchema {
    description: string;
    columns: ColumnInfo[];
    error: string | null;
}

interface WhereCondition {
    column: string;
    operator: string;
    value: string;
}

interface JoinConfig {
    table: string;
    alias: string;
    type: string;
    leftColumn: string;
    operator: string;
    rightColumn: string;
}

interface Props {
    tables: QbeTable[];
    schemaEndpoint: string;
    onGenerateSql: (sql: string) => void;
}

interface QbeDraftState {
    baseTable: string;
    selectedColumns: string[];
    whereConditions: WhereCondition[];
    groupByColumns: string[];
    orderByColumns: { column: string; direction: 'ASC' | 'DESC' }[];
    distinct: boolean;
    limit: string;
    joins: JoinConfig[];
}

interface PersistedQbeDraft {
    version: number;
    savedAt: string;
    state: QbeDraftState;
}

interface QbeHistoryEntry {
    id: string;
    savedAt: string;
    state: QbeDraftState;
}

const OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'IS NULL', 'IS NOT NULL'];
const QBE_DRAFT_STORAGE_KEY = 'query-playground:qbe:draft:v1';
const QBE_HISTORY_STORAGE_KEY = 'query-playground:qbe:history:v1';
const QBE_MAX_HISTORY_ENTRIES = 8;

export default function QbeBuilder({ tables, schemaEndpoint, onGenerateSql }: Props) {
    const t = useTranslation('query');
    const [baseTable, setBaseTable] = useState('');
    const [schemas, setSchemas] = useState<Record<string, TableSchema>>({});
    const [loadingSchema, setLoadingSchema] = useState(false);
    const [schemaError, setSchemaError] = useState('');

    // QBE state
    const [selectedColumns, setSelectedColumns] = useState<string[]>([]);
    const [whereConditions, setWhereConditions] = useState<WhereCondition[]>([]);
    const [groupByColumns, setGroupByColumns] = useState<string[]>([]);
    const [orderByColumns, setOrderByColumns] = useState<{ column: string; direction: 'ASC' | 'DESC' }[]>([]);
    const [distinct, setDistinct] = useState(false);
    const [limit, setLimit] = useState('');

    // Join state
    const [joins, setJoins] = useState<JoinConfig[]>([]);
    const [historyEntries, setHistoryEntries] = useState<QbeHistoryEntry[]>([]);
    const [selectedHistoryId, setSelectedHistoryId] = useState('');
    const [persistenceNotice, setPersistenceNotice] = useState('');
    const [lastSavedAt, setLastSavedAt] = useState('');
    const hasRestoredDraftRef = useRef(false);

    const availableColumns: ColumnInfo[] = (() => {
        const cols: ColumnInfo[] = [];
        if (schemas[baseTable]) {
            schemas[baseTable].columns.forEach((c) => cols.push({ name: `${baseTable}.${c.name}`, type: c.type }));
        }
        joins.forEach((j) => {
            if (schemas[j.table]) {
                const referenceName = getJoinReference(j);
                schemas[j.table].columns.forEach((c) => cols.push({ name: `${referenceName}.${c.name}`, type: c.type }));
            }
        });
        return cols;
    })();

    const schemasRef = useRef(schemas);
    schemasRef.current = schemas;

    const fetchSchema = useCallback(async (tableNames: string[]) => {
        if (tableNames.length === 0) return;
        // Only fetch tables not already loaded
        const toFetch = tableNames.filter((tableName) => !schemasRef.current[tableName]);
        if (toFetch.length === 0) return;

        setLoadingSchema(true);
        setSchemaError('');
        try {
            const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
            const response = await fetch(schemaEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify({ tables: toFetch }),
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            setSchemas((prev) => ({ ...prev, ...(data.tables || {}) }));
        } catch (err) {
            setSchemaError(err instanceof Error ? err.message : t('qbe_schema_failed'));
        } finally {
            setLoadingSchema(false);
        }
    }, [schemaEndpoint, t]);

    useEffect(() => {
        if (baseTable) {
            const allTables = [baseTable, ...joins.map((j) => j.table).filter(Boolean)];
            fetchSchema(allTables);
        }
    }, [baseTable, joins, fetchSchema]);

    const buildDraftState = useCallback((): QbeDraftState => ({
        baseTable,
        selectedColumns,
        whereConditions,
        groupByColumns,
        orderByColumns,
        distinct,
        limit,
        joins,
    }), [baseTable, selectedColumns, whereConditions, groupByColumns, orderByColumns, distinct, limit, joins]);

    const applyDraftState = useCallback((draft: QbeDraftState) => {
        setBaseTable(draft.baseTable || '');
        setSelectedColumns(draft.selectedColumns || []);
        setWhereConditions(draft.whereConditions || []);
        setGroupByColumns(draft.groupByColumns || []);
        setOrderByColumns(draft.orderByColumns || []);
        setDistinct(Boolean(draft.distinct));
        setLimit(draft.limit || '');
        setJoins(draft.joins || []);
    }, []);

    useEffect(() => {
        if (hasRestoredDraftRef.current) {
            return;
        }

        const storedDraft = readPersistedDraft();
        const storedHistory = readPersistedHistory();
        setHistoryEntries(storedHistory);
        setSelectedHistoryId(storedHistory[0]?.id || '');

        if (storedDraft && !isEmptyDraftState(storedDraft.state)) {
            applyDraftState(storedDraft.state);
            setLastSavedAt(storedDraft.savedAt);
            setPersistenceNotice(t('qbe_notice_restored_draft', { time: formatSavedAt(storedDraft.savedAt) }));
        }

        hasRestoredDraftRef.current = true;
    }, [applyDraftState]);

    useEffect(() => {
        if (!hasRestoredDraftRef.current) {
            return;
        }

        const draftState = buildDraftState();
        if (isEmptyDraftState(draftState)) {
            clearPersistedDraft();
            setLastSavedAt('');
            return;
        }

        const savedAt = new Date().toISOString();
        const timeoutId = window.setTimeout(() => {
            persistDraftState({
                version: 1,
                savedAt,
                state: draftState,
            });
            setLastSavedAt(savedAt);
        }, 250);

        return () => window.clearTimeout(timeoutId);
    }, [buildDraftState]);

    const saveHistorySnapshot = useCallback((reason: 'manual' | 'reset' | 'generate') => {
        const draftState = buildDraftState();
        if (isEmptyDraftState(draftState)) {
            return;
        }

        const fingerprint = buildDraftFingerprint(draftState);
        const savedAt = new Date().toISOString();
        const entry: QbeHistoryEntry = {
            id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
            savedAt,
            state: draftState,
        };

        const nextHistory = [
            entry,
            ...historyEntries.filter((historyEntry) => buildDraftFingerprint(historyEntry.state) !== fingerprint),
        ].slice(0, QBE_MAX_HISTORY_ENTRIES);

        setHistoryEntries(nextHistory);
        setSelectedHistoryId(entry.id);
        persistHistoryEntries(nextHistory);
        setPersistenceNotice(
            reason === 'manual'
                ? t('qbe_notice_saved', { time: formatSavedAt(savedAt) })
                : reason === 'generate'
                    ? t('qbe_notice_saved_before_sql', { time: formatSavedAt(savedAt) })
                    : t('qbe_notice_saved_before_reset', { time: formatSavedAt(savedAt) }),
        );
    }, [buildDraftState, historyEntries, t]);

    const handleBaseTableChange = (tableName: string) => {
        if (baseTable && baseTable !== tableName && !isEmptyDraftState(buildDraftState())) {
            saveHistorySnapshot('reset');
        }
        setBaseTable(tableName);
        setSelectedColumns([]);
        setWhereConditions([]);
        setGroupByColumns([]);
        setOrderByColumns([]);
        setJoins([]);
    };

    const addWhereCondition = () => {
        setWhereConditions([...whereConditions, { column: availableColumns[0]?.name || '', operator: '=', value: '' }]);
    };

    const updateWhereCondition = (index: number, field: keyof WhereCondition, value: string) => {
        const updated = [...whereConditions];
        updated[index] = { ...updated[index], [field]: value };
        setWhereConditions(updated);
    };

    const removeWhereCondition = (index: number) => {
        setWhereConditions(whereConditions.filter((_, i) => i !== index));
    };

    const addJoin = () => {
        setJoins([...joins, { table: '', alias: '', type: 'INNER JOIN', leftColumn: '', operator: '=', rightColumn: '' }]);
    };

    const updateJoin = (index: number, field: keyof JoinConfig, value: string) => {
        const updated = [...joins];
        const currentJoin = updated[index];
        const previousReference = getJoinReference(currentJoin);
        let nextJoin: JoinConfig = { ...currentJoin, [field]: value };

        if (field === 'table') {
            if (!value) {
                nextJoin = { ...nextJoin, alias: '', leftColumn: '', rightColumn: '' };
            } else if (!nextJoin.alias.trim() && requiresJoinAlias(value, baseTable, updated, index)) {
                nextJoin = { ...nextJoin, alias: buildJoinAlias(value, baseTable, updated, index) };
            }
        } else if (field === 'alias' && !value.trim() && requiresJoinAlias(nextJoin.table, baseTable, updated, index)) {
            nextJoin = { ...nextJoin, alias: buildJoinAlias(nextJoin.table, baseTable, updated, index) };
        }

        const nextReference = getJoinReference(nextJoin);
        updated[index] = nextJoin;

        if (field === 'table' || field === 'alias') {
            if (previousReference && nextReference && previousReference !== nextReference) {
                setSelectedColumns((prev) => prev.map((column) => replaceQualifiedReference(column, previousReference, nextReference)));
                setWhereConditions((prev) => prev.map((condition) => ({
                    ...condition,
                    column: replaceQualifiedReference(condition.column, previousReference, nextReference),
                })));
                setGroupByColumns((prev) => prev.map((column) => replaceQualifiedReference(column, previousReference, nextReference)));
                setOrderByColumns((prev) => prev.map((orderBy) => ({
                    ...orderBy,
                    column: replaceQualifiedReference(orderBy.column, previousReference, nextReference),
                })));
                updated.forEach((join, joinIndex) => {
                    updated[joinIndex] = {
                        ...join,
                        leftColumn: replaceQualifiedReference(join.leftColumn, previousReference, nextReference),
                        rightColumn: replaceQualifiedReference(join.rightColumn, previousReference, nextReference),
                    };
                });
            }
        }

        setJoins(updated);
        if (field === 'table' && value) {
            fetchSchema([value]);
        }
    };

    const removeJoin = (index: number) => {
        setJoins(joins.filter((_, i) => i !== index));
    };

    const addOrderBy = () => {
        setOrderByColumns([...orderByColumns, { column: availableColumns[0]?.name || '', direction: 'ASC' }]);
    };

    const generateSql = () => {
        if (!baseTable) return;
        saveHistorySnapshot('generate');

        const selectPart = selectedColumns.length > 0
            ? (distinct ? 'SELECT DISTINCT ' : 'SELECT ') + selectedColumns.join(', ')
            : (distinct ? 'SELECT DISTINCT *' : 'SELECT *');

        let fromPart = `FROM ${baseTable}`;
        joins.forEach((j) => {
            if (j.table && j.leftColumn && j.rightColumn) {
                const aliasClause = j.alias.trim() ? ` AS ${j.alias.trim()}` : '';
                fromPart += `\n  ${j.type} ${j.table}${aliasClause} ON ${j.leftColumn} ${j.operator} ${j.rightColumn}`;
            }
        });

        let wherePart = '';
        const validConditions = whereConditions.filter((c) => c.column);
        if (validConditions.length > 0) {
            const clauses = validConditions.map((c) => {
                if (c.operator === 'IS NULL' || c.operator === 'IS NOT NULL') {
                    return `${c.column} ${c.operator}`;
                }
                return `${c.column} ${c.operator} '${c.value}'`;
            });
            wherePart = `\nWHERE ${clauses.join('\n  AND ')}`;
        }

        let groupByPart = '';
        if (groupByColumns.length > 0) {
            groupByPart = `\nGROUP BY ${groupByColumns.join(', ')}`;
        }

        let orderByPart = '';
        if (orderByColumns.length > 0) {
            const orderClauses = orderByColumns.filter((o) => o.column).map((o) => `${o.column} ${o.direction}`);
            if (orderClauses.length > 0) {
                orderByPart = `\nORDER BY ${orderClauses.join(', ')}`;
            }
        }

        let limitPart = '';
        if (limit && parseInt(limit, 10) > 0) {
            limitPart = `\nLIMIT ${parseInt(limit, 10)}`;
        }

        const sql = `${selectPart}\n${fromPart}${wherePart}${groupByPart}${orderByPart}${limitPart}`;
        onGenerateSql(sql);
    };

    const restoreSelectedHistory = () => {
        const entry = historyEntries.find((historyEntry) => historyEntry.id === selectedHistoryId);
        if (!entry) {
            return;
        }

        applyDraftState(entry.state);
        persistDraftState({
            version: 1,
            savedAt: entry.savedAt,
            state: entry.state,
        });
        setLastSavedAt(entry.savedAt);
        setPersistenceNotice(t('qbe_notice_restored_version', { time: formatSavedAt(entry.savedAt) }));
    };

    const clearSavedQbeHistory = () => {
        clearPersistedDraft();
        clearPersistedHistory();
        setHistoryEntries([]);
        setSelectedHistoryId('');
        setLastSavedAt('');
        setPersistenceNotice(t('qbe_notice_cleared'));
    };

    const nonInternalTables = tables.filter((tbl) => !tbl.internal);
    const internalTables = tables.filter((tbl) => tbl.internal);

    return (
        <div>
            <div style={{
                marginBottom: 16,
                padding: 12,
                borderRadius: 6,
                border: '1px solid #d6d8db',
                backgroundColor: '#f8f9fa',
            }}>
                <div style={{ fontSize: '0.85rem', color: '#495057', marginBottom: 8 }}>
                    {t('qbe_autosave_hint')}
                    {lastSavedAt ? ` ${t('qbe_last_saved', { time: formatSavedAt(lastSavedAt) })}` : ''}
                </div>
                {persistenceNotice && (
                    <div style={{ fontSize: '0.8rem', color: '#0c5460', marginBottom: 8 }}>
                        {persistenceNotice}
                    </div>
                )}
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                    <button onClick={() => saveHistorySnapshot('manual')} style={smallBtnStyle}>
                        {t('qbe_save_current')}
                    </button>
                    <select
                        value={selectedHistoryId}
                        onChange={(e) => setSelectedHistoryId(e.target.value)}
                        style={{ ...selectStyle, minWidth: 260 }}
                        disabled={historyEntries.length === 0}
                    >
                        <option value="">{historyEntries.length === 0 ? t('qbe_no_history') : t('qbe_select_history')}</option>
                        {historyEntries.map((entry) => (
                            <option key={entry.id} value={entry.id}>
                                {formatSavedAt(entry.savedAt)}{entry.state.baseTable ? ` — ${entry.state.baseTable}` : ''}
                            </option>
                        ))}
                    </select>
                    <button
                        onClick={restoreSelectedHistory}
                        disabled={!selectedHistoryId}
                        style={{ ...smallBtnStyle, opacity: selectedHistoryId ? 1 : 0.6 }}
                    >
                        {t('qbe_restore_version')}
                    </button>
                    <button onClick={clearSavedQbeHistory} style={removeBtnStyle}>
                        {t('qbe_clear_history')}
                    </button>
                </div>
            </div>

            {/* Base table selection */}
            <div style={{ marginBottom: 16 }}>
                <label style={labelStyle}>{t('qbe_base_table')}</label>
                <select
                    value={baseTable}
                    onChange={(e) => handleBaseTableChange(e.target.value)}
                    style={selectStyle}
                >
                    <option value="">{t('qbe_select_base_table')}</option>
                    <optgroup label={t('qbe_tables_group')}>
                        {nonInternalTables.map((tbl) => (
                            <option key={tbl.name} value={tbl.name}>
                                {tbl.name}{tbl.description ? ` — ${tbl.description}` : ''}
                            </option>
                        ))}
                    </optgroup>
                    {internalTables.length > 0 && (
                        <optgroup label={t('qbe_internal_tables_group')}>
                            {internalTables.map((tbl) => (
                                <option key={tbl.name} value={tbl.name}>
                                    {tbl.name}{tbl.description ? ` — ${tbl.description}` : ''}
                                </option>
                            ))}
                        </optgroup>
                    )}
                </select>
            </div>

            {loadingSchema && <div style={{ color: '#6c757d', fontSize: '0.85rem', marginBottom: 8 }}>{t('qbe_loading_schema')}</div>}
            {schemaError && <div style={{ color: '#dc3545', fontSize: '0.85rem', marginBottom: 8 }}>⚠ {schemaError}</div>}

            {baseTable && schemas[baseTable] && (
                <>
                    {/* Joins */}
                    <div style={{ marginBottom: 16 }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                            <label style={labelStyle}>{t('qbe_join_optional')}</label>
                            <button onClick={addJoin} style={smallBtnStyle}>{t('qbe_add_join')}</button>
                        </div>
                        {joins.map((join, i) => (
                            <div key={i} style={{ display: 'flex', gap: 8, marginBottom: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                                <select
                                    value={join.type}
                                    onChange={(e) => updateJoin(i, 'type', e.target.value)}
                                    style={{ ...selectStyle, flex: '0 0 140px' }}
                                >
                                    <option value="INNER JOIN">INNER JOIN</option>
                                    <option value="LEFT JOIN">LEFT JOIN</option>
                                    <option value="RIGHT JOIN">RIGHT JOIN</option>
                                </select>
                                <select
                                    value={join.table}
                                    onChange={(e) => updateJoin(i, 'table', e.target.value)}
                                    style={{ ...selectStyle, flex: 1, minWidth: 150 }}
                                >
                                    <option value="">{t('qbe_select_join_table')}</option>
                                    {tables.map((tbl) => (
                                        <option key={tbl.name} value={tbl.name}>{tbl.name}</option>
                                    ))}
                                </select>
                                <input
                                    type="text"
                                    value={join.alias}
                                    onChange={(e) => updateJoin(i, 'alias', e.target.value)}
                                    placeholder={t('qbe_join_alias_hint')}
                                    style={{ ...inputStyle, flex: '0 0 180px' }}
                                />
                                <select
                                    value={join.leftColumn}
                                    onChange={(e) => updateJoin(i, 'leftColumn', e.target.value)}
                                    style={{ ...selectStyle, flex: 1, minWidth: 180 }}
                                >
                                    <option value="">{t('qbe_left_col')}</option>
                                    {availableColumns.map((col) => (
                                        <option key={`left-${i}-${col.name}`} value={col.name}>{col.name}</option>
                                    ))}
                                </select>
                                <select
                                    value={join.operator}
                                    onChange={(e) => updateJoin(i, 'operator', e.target.value)}
                                    style={{ ...selectStyle, flex: '0 0 110px' }}
                                >
                                    <option value="=">=</option>
                                    <option value="!=">!=</option>
                                    <option value="<">&lt;</option>
                                    <option value=">">&gt;</option>
                                    <option value="<=">&lt;=</option>
                                    <option value=">=">&gt;=</option>
                                </select>
                                <select
                                    value={join.rightColumn}
                                    onChange={(e) => updateJoin(i, 'rightColumn', e.target.value)}
                                    style={{ ...selectStyle, flex: 1, minWidth: 180 }}
                                >
                                    <option value="">{t('qbe_right_col')}</option>
                                    {availableColumns.map((col) => (
                                        <option key={`right-${i}-${col.name}`} value={col.name}>{col.name}</option>
                                    ))}
                                </select>
                                <button onClick={() => removeJoin(i)} style={removeBtnStyle}>✕</button>
                            </div>
                        ))}
                    </div>

                    {/* SELECT columns */}
                    <div style={{ marginBottom: 16 }}>
                        <label style={labelStyle}>{t('qbe_select_cols')}</label>
                        <div style={{
                            maxHeight: 180,
                            overflowY: 'auto',
                            border: '1px solid #dee2e6',
                            borderRadius: 4,
                            padding: 8,
                            backgroundColor: '#fff',
                        }}>
                            {availableColumns.length === 0 ? (
                                <span style={{ color: '#6c757d', fontSize: '0.85rem' }}>{t('qbe_no_cols')}</span>
                            ) : (
                                availableColumns.map((col) => (
                                    <label key={col.name} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '2px 0', fontSize: '0.85rem', cursor: 'pointer' }}>
                                        <input
                                            type="checkbox"
                                            checked={selectedColumns.includes(col.name)}
                                            onChange={(e) => {
                                                if (e.target.checked) {
                                                    setSelectedColumns([...selectedColumns, col.name]);
                                                } else {
                                                    setSelectedColumns(selectedColumns.filter((c) => c !== col.name));
                                                }
                                            }}
                                        />
                                        <span style={{ fontFamily: 'monospace' }}>{col.name}</span>
                                        <span style={{ color: '#6c757d', fontSize: '0.75rem' }}>({col.type || '?'})</span>
                                    </label>
                                ))
                            )}
                        </div>
                    </div>

                    {/* WHERE conditions */}
                    <div style={{ marginBottom: 16 }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                            <label style={labelStyle}>{t('qbe_where_conditions')}</label>
                            <button onClick={addWhereCondition} disabled={availableColumns.length === 0} style={smallBtnStyle}>{t('qbe_add_condition')}</button>
                        </div>
                        {whereConditions.map((cond, i) => (
                            <div key={i} style={{ display: 'flex', gap: 8, marginBottom: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                                <select
                                    value={cond.column}
                                    onChange={(e) => updateWhereCondition(i, 'column', e.target.value)}
                                    style={{ ...selectStyle, flex: 1, minWidth: 150 }}
                                >
                                    {availableColumns.map((col) => (
                                        <option key={col.name} value={col.name}>{col.name}</option>
                                    ))}
                                </select>
                                <select
                                    value={cond.operator}
                                    onChange={(e) => updateWhereCondition(i, 'operator', e.target.value)}
                                    style={{ ...selectStyle, flex: '0 0 120px' }}
                                >
                                    {OPERATORS.map((op) => (
                                        <option key={op} value={op}>{op}</option>
                                    ))}
                                </select>
                                {cond.operator !== 'IS NULL' && cond.operator !== 'IS NOT NULL' && (
                                    <input
                                        type="text"
                                        value={cond.value}
                                        onChange={(e) => updateWhereCondition(i, 'value', e.target.value)}
                                        placeholder={t('qbe_value_placeholder')}
                                        style={{ ...inputStyle, flex: 1, minWidth: 120 }}
                                    />
                                )}
                                <button onClick={() => removeWhereCondition(i)} style={removeBtnStyle}>✕</button>
                            </div>
                        ))}
                    </div>

                    {/* GROUP BY */}
                    <div style={{ marginBottom: 16 }}>
                        <label style={labelStyle}>{t('qbe_group_by_optional')}</label>
                        <div style={{
                            maxHeight: 120,
                            overflowY: 'auto',
                            border: '1px solid #dee2e6',
                            borderRadius: 4,
                            padding: 8,
                            backgroundColor: '#fff',
                        }}>
                            {availableColumns.map((col) => (
                                <label key={col.name} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '2px 0', fontSize: '0.85rem', cursor: 'pointer' }}>
                                    <input
                                        type="checkbox"
                                        checked={groupByColumns.includes(col.name)}
                                        onChange={(e) => {
                                            if (e.target.checked) {
                                                setGroupByColumns([...groupByColumns, col.name]);
                                            } else {
                                                setGroupByColumns(groupByColumns.filter((c) => c !== col.name));
                                            }
                                        }}
                                    />
                                    <span style={{ fontFamily: 'monospace', fontSize: '0.85rem' }}>{col.name}</span>
                                </label>
                            ))}
                        </div>
                    </div>

                    {/* ORDER BY */}
                    <div style={{ marginBottom: 16 }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                            <label style={labelStyle}>{t('qbe_order_by_optional')}</label>
                            <button onClick={addOrderBy} disabled={availableColumns.length === 0} style={smallBtnStyle}>{t('qbe_add_order_by')}</button>
                        </div>
                        {orderByColumns.map((ob, i) => (
                            <div key={i} style={{ display: 'flex', gap: 8, marginBottom: 8, alignItems: 'center' }}>
                                <select
                                    value={ob.column}
                                    onChange={(e) => {
                                        const updated = [...orderByColumns];
                                        updated[i] = { ...updated[i], column: e.target.value };
                                        setOrderByColumns(updated);
                                    }}
                                    style={{ ...selectStyle, flex: 1 }}
                                >
                                    {availableColumns.map((col) => (
                                        <option key={col.name} value={col.name}>{col.name}</option>
                                    ))}
                                </select>
                                <select
                                    value={ob.direction}
                                    onChange={(e) => {
                                        const updated = [...orderByColumns];
                                        updated[i] = { ...updated[i], direction: e.target.value as 'ASC' | 'DESC' };
                                        setOrderByColumns(updated);
                                    }}
                                    style={{ ...selectStyle, flex: '0 0 100px' }}
                                >
                                    <option value="ASC">ASC ↑</option>
                                    <option value="DESC">DESC ↓</option>
                                </select>
                                <button onClick={() => setOrderByColumns(orderByColumns.filter((_, idx) => idx !== i))} style={removeBtnStyle}>✕</button>
                            </div>
                        ))}
                    </div>

                    {/* Options row */}
                    <div style={{ display: 'flex', gap: 16, alignItems: 'center', marginBottom: 16, flexWrap: 'wrap' }}>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem', cursor: 'pointer' }}>
                            <input type="checkbox" checked={distinct} onChange={(e) => setDistinct(e.target.checked)} />
                            DISTINCT
                        </label>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem' }}>
                            LIMIT:
                            <input
                                type="number"
                                value={limit}
                                onChange={(e) => setLimit(e.target.value)}
                                placeholder={t('qbe_limit_placeholder')}
                                min={0}
                                style={{ ...inputStyle, width: 80 }}
                            />
                        </label>
                    </div>

                    {/* Generate SQL button */}
                    <button
                        onClick={generateSql}
                        style={{
                            padding: '10px 24px',
                            fontSize: '0.9rem',
                            fontWeight: 600,
                            border: 'none',
                            borderRadius: 5,
                            backgroundColor: '#28a745',
                            color: '#fff',
                            cursor: 'pointer',
                        }}
                    >
                        {t('qbe_generate_sql_btn')}
                    </button>
                </>
            )}
        </div>
    );
}

function getJoinReference(join: JoinConfig): string {
    return join.alias.trim() || join.table;
}

function requiresJoinAlias(table: string, baseTable: string, joins: JoinConfig[], currentIndex: number): boolean {
    if (!table) {
        return false;
    }

    const usedReferences = [
        baseTable,
        ...joins
            .filter((_, index) => index !== currentIndex)
            .map((join) => getJoinReference(join))
            .filter(Boolean),
    ];

    return usedReferences.includes(table);
}

function buildJoinAlias(table: string, baseTable: string, joins: JoinConfig[], currentIndex: number): string {
    const usedReferences = new Set([
        baseTable,
        ...joins
            .filter((_, index) => index !== currentIndex)
            .map((join) => getJoinReference(join))
            .filter(Boolean),
    ]);

    let suffix = 2;
    let alias = `${table}_${suffix}`;
    while (usedReferences.has(alias)) {
        suffix += 1;
        alias = `${table}_${suffix}`;
    }

    return alias;
}

function replaceQualifiedReference(column: string, previousReference: string, nextReference: string): string {
    if (!column.startsWith(`${previousReference}.`)) {
        return column;
    }

    return `${nextReference}.${column.slice(previousReference.length + 1)}`;
}

function isEmptyDraftState(state: QbeDraftState): boolean {
    return !state.baseTable
        && state.selectedColumns.length === 0
        && state.whereConditions.length === 0
        && state.groupByColumns.length === 0
        && state.orderByColumns.length === 0
        && state.joins.length === 0
        && !state.distinct
        && !state.limit;
}

function buildDraftFingerprint(state: QbeDraftState): string {
    return JSON.stringify(state);
}

function persistDraftState(payload: PersistedQbeDraft): void {
    const storage = getQbeStorage();
    if (!storage) {
        return;
    }

    storage.setItem(QBE_DRAFT_STORAGE_KEY, JSON.stringify(payload));
}

function readPersistedDraft(): PersistedQbeDraft | null {
    const storage = getQbeStorage();
    if (!storage) {
        return null;
    }

    const raw = storage.getItem(QBE_DRAFT_STORAGE_KEY);
    if (!raw) {
        return null;
    }

    try {
        const parsed = JSON.parse(raw) as PersistedQbeDraft;
        if (parsed?.version !== 1 || !parsed?.state) {
            return null;
        }

        return parsed;
    } catch {
        return null;
    }
}

function clearPersistedDraft(): void {
    const storage = getQbeStorage();
    storage?.removeItem(QBE_DRAFT_STORAGE_KEY);
}

function persistHistoryEntries(entries: QbeHistoryEntry[]): void {
    const storage = getQbeStorage();
    if (!storage) {
        return;
    }

    storage.setItem(QBE_HISTORY_STORAGE_KEY, JSON.stringify(entries));
}

function readPersistedHistory(): QbeHistoryEntry[] {
    const storage = getQbeStorage();
    if (!storage) {
        return [];
    }

    const raw = storage.getItem(QBE_HISTORY_STORAGE_KEY);
    if (!raw) {
        return [];
    }

    try {
        const parsed = JSON.parse(raw) as QbeHistoryEntry[];
        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed.filter((entry) => entry?.id && entry?.savedAt && entry?.state);
    } catch {
        return [];
    }
}

function clearPersistedHistory(): void {
    const storage = getQbeStorage();
    storage?.removeItem(QBE_HISTORY_STORAGE_KEY);
}

function getQbeStorage(): Storage | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.sessionStorage;
}

function formatSavedAt(savedAt: string): string {
    const date = new Date(savedAt);
    if (Number.isNaN(date.getTime())) {
        return savedAt;
    }

    return date.toLocaleString('zh-TW', {
        hour12: false,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

const labelStyle: React.CSSProperties = {
    fontWeight: 600,
    fontSize: '0.9rem',
    color: '#343a40',
    display: 'block',
    marginBottom: 4,
};

const selectStyle: React.CSSProperties = {
    padding: '6px 10px',
    border: '1px solid #ced4da',
    borderRadius: 4,
    fontSize: '0.85rem',
    backgroundColor: '#fff',
    color: '#212529',
};

const inputStyle: React.CSSProperties = {
    padding: '6px 10px',
    border: '1px solid #ced4da',
    borderRadius: 4,
    fontSize: '0.85rem',
    backgroundColor: '#fff',
    color: '#212529',
};

const smallBtnStyle: React.CSSProperties = {
    padding: '4px 12px',
    fontSize: '0.8rem',
    border: '1px solid #ced4da',
    borderRadius: 4,
    backgroundColor: '#fff',
    color: '#495057',
    cursor: 'pointer',
};

const removeBtnStyle: React.CSSProperties = {
    padding: '4px 8px',
    fontSize: '0.85rem',
    border: '1px solid #dc3545',
    borderRadius: 4,
    backgroundColor: 'transparent',
    color: '#dc3545',
    cursor: 'pointer',
    lineHeight: 1,
};
