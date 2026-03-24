import React, { useMemo } from 'react';

export interface EntryType {
    c_entry_type: string;
    c_entry_type_desc: string | null;
    c_entry_type_desc_chn: string | null;
    c_entry_type_parent_id: string | null;
    c_entry_type_level: number;
    c_entry_type_sortorder: number;
}

interface TreeNode extends EntryType {
    children: TreeNode[];
}

interface Props {
    types: EntryType[];
    selectedTypeId: string | null;
    loading: boolean;
    error: string | null;
    onSelect: (typeId: string) => void;
}

function buildTree(types: EntryType[]): TreeNode[] {
    const map: Record<string, TreeNode> = {};
    const roots: TreeNode[] = [];

    types.forEach((t) => {
        map[t.c_entry_type] = { ...t, children: [] };
    });

    types.forEach((t) => {
        const node = map[t.c_entry_type];
        if (t.c_entry_type_parent_id && map[t.c_entry_type_parent_id]) {
            map[t.c_entry_type_parent_id].children.push(node);
        } else {
            roots.push(node);
        }
    });

    return roots;
}

function TreeNodeItem({ node, level, selectedTypeId, onSelect }: {
    node: TreeNode;
    level: number;
    selectedTypeId: string | null;
    onSelect: (id: string) => void;
}) {
    const isSelected = node.c_entry_type === selectedTypeId;
    return (
        <>
            <div
                onClick={() => onSelect(node.c_entry_type)}
                style={{
                    paddingLeft: level * 20 + 12,
                    paddingTop: 6,
                    paddingBottom: 6,
                    paddingRight: 8,
                    cursor: 'pointer',
                    borderBottom: '1px solid #f0f0f0',
                    backgroundColor: isSelected ? '#007bff' : 'transparent',
                    color: isSelected ? '#fff' : '#333',
                    fontSize: '0.875rem',
                    transition: 'background-color 0.15s',
                }}
                onMouseEnter={(e) => {
                    if (!isSelected) (e.currentTarget as HTMLDivElement).style.backgroundColor = '#f8f9fa';
                }}
                onMouseLeave={(e) => {
                    if (!isSelected) (e.currentTarget as HTMLDivElement).style.backgroundColor = 'transparent';
                }}
            >
                {node.c_entry_type_desc_chn || node.c_entry_type_desc || node.c_entry_type}
            </div>
            {node.children.map((child) => (
                <TreeNodeItem
                    key={child.c_entry_type}
                    node={child}
                    level={level + 1}
                    selectedTypeId={selectedTypeId}
                    onSelect={onSelect}
                />
            ))}
        </>
    );
}

export default function EntryTypeTree({ types, selectedTypeId, loading, error, onSelect }: Props) {
    const tree = useMemo(() => buildTree(types), [types]);

    return (
        <div style={{ border: '1px solid #dee2e6', borderRadius: 4, backgroundColor: '#fff', overflow: 'hidden' }}>
            <div style={{ padding: '10px 14px', borderBottom: '1px solid #dee2e6', fontWeight: 600, fontSize: '0.95rem' }}>
                入仕類型
            </div>
            <div style={{ height: 400, overflowY: 'auto' }}>
                {loading && (
                    <div style={{ padding: 16, textAlign: 'center', color: '#6c757d' }}>載入中...</div>
                )}
                {error && (
                    <div style={{ padding: 16, color: '#dc3545' }}>{error}</div>
                )}
                {!loading && !error && tree.length === 0 && (
                    <div style={{ padding: 16, color: '#6c757d' }}>無資料</div>
                )}
                {!loading && !error && tree.map((node) => (
                    <TreeNodeItem
                        key={node.c_entry_type}
                        node={node}
                        level={0}
                        selectedTypeId={selectedTypeId}
                        onSelect={onSelect}
                    />
                ))}
            </div>
        </div>
    );
}
