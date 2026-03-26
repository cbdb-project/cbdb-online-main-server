import React, { useState, useCallback } from 'react';

interface Props {
    keyword: string;
    onSearch: (keyword: string) => void;
    onClear: () => void;
}

export default function PeopleSearchPanel({ keyword, onSearch, onClear }: Props) {
    const [input, setInput] = useState(keyword);

    const handleSubmit = useCallback(
        (e: React.FormEvent) => {
            e.preventDefault();
            onSearch(input.trim());
        },
        [input, onSearch],
    );

    const handleClear = useCallback(() => {
        setInput('');
        onClear();
    }, [onClear]);

    // Keep input in sync when keyword changes externally
    React.useEffect(() => {
        setInput(keyword);
    }, [keyword]);

    return (
        <form onSubmit={handleSubmit} style={formStyle}>
            <input
                type="text"
                value={input}
                onChange={(e) => setInput(e.target.value)}
                placeholder="搜尋人物（ID / 姓名 / 拼音）"
                style={inputStyle}
            />
            <div style={btnGroupStyle}>
                <button type="submit" style={searchBtnStyle}>
                    搜尋
                </button>
                {keyword && (
                    <button type="button" onClick={handleClear} style={clearBtnStyle}>
                        清除
                    </button>
                )}
            </div>
        </form>
    );
}

const formStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 6,
    padding: '10px 12px',
    borderBottom: '1px solid #dee2e6',
};

const inputStyle: React.CSSProperties = {
    padding: '6px 10px',
    border: '1px solid #ced4da',
    borderRadius: 4,
    fontSize: '0.875rem',
    outline: 'none',
    width: '100%',
    boxSizing: 'border-box',
};

const btnGroupStyle: React.CSSProperties = {
    display: 'flex',
    gap: 6,
};

const searchBtnStyle: React.CSSProperties = {
    flex: 1,
    padding: '5px 12px',
    fontSize: '0.8125rem',
    border: 'none',
    borderRadius: 4,
    backgroundColor: '#007bff',
    color: '#fff',
    cursor: 'pointer',
};

const clearBtnStyle: React.CSSProperties = {
    padding: '5px 12px',
    fontSize: '0.8125rem',
    border: '1px solid #ced4da',
    borderRadius: 4,
    backgroundColor: '#fff',
    color: '#495057',
    cursor: 'pointer',
};
