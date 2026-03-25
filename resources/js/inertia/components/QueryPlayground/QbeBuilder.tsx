import React, { useState, useCallback, useEffect, useRef } from 'react';

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

interface Props {
    tables: QbeTable[];
    schemaEndpoint: string;
    onGenerateSql: (sql: string) => void;
}

const OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'IS NULL', 'IS NOT NULL'];

export default function QbeBuilder({ tables, schemaEndpoint, onGenerateSql }: Props) {
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
    const [joins, setJoins] = useState<{ table: string; type: string; on: string }[]>([]);

    const availableColumns: ColumnInfo[] = (() => {
        const cols: ColumnInfo[] = [];
        if (schemas[baseTable]) {
            schemas[baseTable].columns.forEach((c) => cols.push({ name: `${baseTable}.${c.name}`, type: c.type }));
        }
        joins.forEach((j) => {
            if (schemas[j.table]) {
                schemas[j.table].columns.forEach((c) => cols.push({ name: `${j.table}.${c.name}`, type: c.type }));
            }
        });
        return cols;
    })();

    const schemasRef = useRef(schemas);
    schemasRef.current = schemas;

    const fetchSchema = useCallback(async (tableNames: string[]) => {
        if (tableNames.length === 0) return;
        // Only fetch tables not already loaded
        const toFetch = tableNames.filter((t) => !schemasRef.current[t]);
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
            setSchemaError(err instanceof Error ? err.message : 'Schema 載入失敗');
        } finally {
            setLoadingSchema(false);
        }
    }, [schemaEndpoint]);

    useEffect(() => {
        if (baseTable) {
            const allTables = [baseTable, ...joins.map((j) => j.table).filter(Boolean)];
            fetchSchema(allTables);
        }
    }, [baseTable, joins, fetchSchema]);

    const handleBaseTableChange = (tableName: string) => {
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
        setJoins([...joins, { table: '', type: 'INNER JOIN', on: '' }]);
    };

    const updateJoin = (index: number, field: string, value: string) => {
        const updated = [...joins];
        updated[index] = { ...updated[index], [field]: value };
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

        const selectPart = selectedColumns.length > 0
            ? (distinct ? 'SELECT DISTINCT ' : 'SELECT ') + selectedColumns.join(', ')
            : (distinct ? 'SELECT DISTINCT *' : 'SELECT *');

        let fromPart = `FROM ${baseTable}`;
        joins.forEach((j) => {
            if (j.table && j.on) {
                fromPart += `\n  ${j.type} ${j.table} ON ${j.on}`;
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

    const nonInternalTables = tables.filter((t) => !t.internal);
    const internalTables = tables.filter((t) => t.internal);

    return (
        <div>
            {/* Base table selection */}
            <div style={{ marginBottom: 16 }}>
                <label style={labelStyle}>主表 (Base Table)</label>
                <select
                    value={baseTable}
                    onChange={(e) => handleBaseTableChange(e.target.value)}
                    style={selectStyle}
                >
                    <option value="">-- 選擇主表 --</option>
                    <optgroup label="資料表">
                        {nonInternalTables.map((t) => (
                            <option key={t.name} value={t.name}>
                                {t.name}{t.description ? ` — ${t.description}` : ''}
                            </option>
                        ))}
                    </optgroup>
                    {internalTables.length > 0 && (
                        <optgroup label="內部表 (CBDB__)">
                            {internalTables.map((t) => (
                                <option key={t.name} value={t.name}>
                                    {t.name}{t.description ? ` — ${t.description}` : ''}
                                </option>
                            ))}
                        </optgroup>
                    )}
                </select>
            </div>

            {loadingSchema && <div style={{ color: '#6c757d', fontSize: '0.85rem', marginBottom: 8 }}>載入 Schema 中…</div>}
            {schemaError && <div style={{ color: '#dc3545', fontSize: '0.85rem', marginBottom: 8 }}>⚠ {schemaError}</div>}

            {baseTable && schemas[baseTable] && (
                <>
                    {/* Joins */}
                    <div style={{ marginBottom: 16 }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                            <label style={labelStyle}>JOIN（可選）</label>
                            <button onClick={addJoin} style={smallBtnStyle}>+ 新增 JOIN</button>
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
                                    <option value="">-- 選擇表 --</option>
                                    {tables.map((t) => (
                                        <option key={t.name} value={t.name}>{t.name}</option>
                                    ))}
                                </select>
                                <input
                                    type="text"
                                    value={join.on}
                                    onChange={(e) => updateJoin(i, 'on', e.target.value)}
                                    placeholder="ON 條件，例如 A.id = B.id"
                                    style={{ ...inputStyle, flex: 2, minWidth: 200 }}
                                />
                                <button onClick={() => removeJoin(i)} style={removeBtnStyle}>✕</button>
                            </div>
                        ))}
                    </div>

                    {/* SELECT columns */}
                    <div style={{ marginBottom: 16 }}>
                        <label style={labelStyle}>SELECT 欄位（不選則為 *）</label>
                        <div style={{
                            maxHeight: 180,
                            overflowY: 'auto',
                            border: '1px solid #dee2e6',
                            borderRadius: 4,
                            padding: 8,
                            backgroundColor: '#fff',
                        }}>
                            {availableColumns.length === 0 ? (
                                <span style={{ color: '#6c757d', fontSize: '0.85rem' }}>無可用欄位</span>
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
                            <label style={labelStyle}>WHERE 條件</label>
                            <button onClick={addWhereCondition} disabled={availableColumns.length === 0} style={smallBtnStyle}>+ 新增條件</button>
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
                                        placeholder="值"
                                        style={{ ...inputStyle, flex: 1, minWidth: 120 }}
                                    />
                                )}
                                <button onClick={() => removeWhereCondition(i)} style={removeBtnStyle}>✕</button>
                            </div>
                        ))}
                    </div>

                    {/* GROUP BY */}
                    <div style={{ marginBottom: 16 }}>
                        <label style={labelStyle}>GROUP BY（可選）</label>
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
                            <label style={labelStyle}>ORDER BY（可選）</label>
                            <button onClick={addOrderBy} disabled={availableColumns.length === 0} style={smallBtnStyle}>+ 新增排序</button>
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
                                placeholder="不限"
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
                        產生 SQL 並切換至 SQL 模式
                    </button>
                </>
            )}
        </div>
    );
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
