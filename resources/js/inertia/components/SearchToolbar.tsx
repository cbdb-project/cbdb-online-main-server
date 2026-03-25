import React from 'react';

interface Props {
    search: string;
    onSearchChange: (value: string) => void;
    onSubmit: () => void;
    onClear: () => void;
    placeholder?: string;
}

export default function SearchToolbar({ search, onSearchChange, onSubmit, onClear, placeholder = 'Search...' }: Props) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    onSubmit();
                }}
                style={{ display: 'flex', alignItems: 'center', gap: 8, flex: 1, minWidth: 200 }}
            >
                <input
                    type="text"
                    value={search}
                    onChange={(e) => onSearchChange(e.target.value)}
                    placeholder={placeholder}
                    style={{
                        padding: '6px 12px',
                        border: '1px solid #ced4da',
                        borderRadius: 4,
                        fontSize: '0.9rem',
                        width: 240,
                        maxWidth: '100%',
                    }}
                />
                <button
                    type="submit"
                    style={{
                        padding: '6px 16px',
                        backgroundColor: '#007bff',
                        color: '#fff',
                        border: 'none',
                        borderRadius: 4,
                        cursor: 'pointer',
                        fontSize: '0.9rem',
                    }}
                >
                    搜尋
                </button>
                {search && (
                    <button
                        type="button"
                        onClick={onClear}
                        style={{
                            padding: '6px 14px',
                            backgroundColor: '#6c757d',
                            color: '#fff',
                            border: 'none',
                            borderRadius: 4,
                            cursor: 'pointer',
                            fontSize: '0.9rem',
                        }}
                    >
                        清除
                    </button>
                )}
            </form>
        </div>
    );
}
