import React, { useState, useCallback } from 'react';
import { APP_THEME } from '../../theme';
import { useTranslation } from '../../hooks/useTranslation';

interface DynastyOption {
    c_dy: number;
    label: string;
    count: number;
}

interface Props {
    keyword: string;
    dynasty: string;
    dynastyOptions: DynastyOption[];
    onSearch: (keyword: string, dynasty: string) => void;
    onClear: () => void;
}

export default function PeopleSearchPanel({ keyword, dynasty, dynastyOptions, onSearch, onClear }: Props) {
    const t = useTranslation('person');
    const [input, setInput] = useState(keyword);
    const [selectedDynasty, setSelectedDynasty] = useState(dynasty);

    const handleSubmit = useCallback(
        (e: React.FormEvent) => {
            e.preventDefault();
            onSearch(input.trim(), selectedDynasty);
        },
        [input, selectedDynasty, onSearch],
    );

    const handleDynastyChange = useCallback(
        (e: React.ChangeEvent<HTMLSelectElement>) => {
            const value = e.target.value;
            setSelectedDynasty(value);
            onSearch(input.trim(), value);
        },
        [input, onSearch],
    );

    const handleClear = useCallback(() => {
        setInput('');
        setSelectedDynasty('');
        onClear();
    }, [onClear]);

    // Keep input in sync when keyword changes externally
    React.useEffect(() => {
        setInput(keyword);
    }, [keyword]);

    React.useEffect(() => {
        setSelectedDynasty(dynasty);
    }, [dynasty]);

    const total = dynastyOptions.reduce((sum, d) => sum + d.count, 0);

    return (
        <form onSubmit={handleSubmit} style={formStyle}>
            <input
                type="text"
                value={input}
                onChange={(e) => setInput(e.target.value)}
                placeholder={t('search_placeholder')}
                style={inputStyle}
            />
            <select
                value={selectedDynasty}
                onChange={handleDynastyChange}
                style={selectStyle}
            >
                <option value="">{t('all_dynasties')}{total > 0 ? ` (${total})` : ''}</option>
                {dynastyOptions.map((d) => (
                    <option key={d.c_dy} value={String(d.c_dy)}>
                        {d.label} ({d.count})
                    </option>
                ))}
            </select>
            <div style={btnGroupStyle}>
                <button type="submit" style={searchBtnStyle}>
                    {t('search_btn')}
                </button>
                {(keyword || dynasty) && (
                    <button type="button" onClick={handleClear} style={clearBtnStyle}>
                        {t('clear_btn')}
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

const selectStyle: React.CSSProperties = {
    padding: '6px 10px',
    border: '1px solid #ced4da',
    borderRadius: 4,
    fontSize: '0.8125rem',
    outline: 'none',
    width: '100%',
    boxSizing: 'border-box',
    backgroundColor: '#fff',
    color: '#495057',
    cursor: 'pointer',
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
    backgroundColor: APP_THEME.brand,
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
