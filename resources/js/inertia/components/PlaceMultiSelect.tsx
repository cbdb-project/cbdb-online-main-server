import React from 'react';

export interface PlaceOption {
    c_addr_id: number;
    c_name: string | null;
    c_name_chn: string | null;
}

interface Props {
    query: string;
    selectedPlaces: PlaceOption[];
    searchResults: PlaceOption[];
    loading: boolean;
    includeSubUnits: boolean;
    onQueryChange: (value: string) => void;
    onAddPlace: (place: PlaceOption) => void;
    onRemovePlace: (placeId: number) => void;
    onClearPlaces: () => void;
    onToggleIncludeSubUnits: (checked: boolean) => void;
}

export default function PlaceMultiSelect({
    query,
    selectedPlaces,
    searchResults,
    loading,
    includeSubUnits,
    onQueryChange,
    onAddPlace,
    onRemovePlace,
    onClearPlaces,
    onToggleIncludeSubUnits,
}: Props) {
    return (
        <div style={{ marginBottom: 14 }}>
            <label style={{ display: 'block', fontWeight: 500, fontSize: '0.85rem', marginBottom: 6 }}>入仕地點</label>
            <input
                type="text"
                value={query}
                onChange={(event) => onQueryChange(event.target.value)}
                placeholder="輸入地址名稱或 ID"
                style={inputStyle}
            />
            <div style={{ fontSize: '0.75rem', color: '#6c757d', marginTop: 4 }}>
                可多選，會比對 `ENTRY_DATA.c_entry_addr_id`
            </div>

            <div style={{ marginTop: 8, border: '1px solid #e3e7eb', borderRadius: 4, maxHeight: 260, overflowY: 'auto', backgroundColor: '#fff' }}>
                {loading && <div style={emptyStateStyle}>搜尋中...</div>}
                {!loading && query.trim() === '' && <div style={emptyStateStyle}>輸入關鍵字後顯示候選地點</div>}
                {!loading && query.trim() !== '' && searchResults.length === 0 && <div style={emptyStateStyle}>查無符合地點</div>}
                {!loading && searchResults.map((place) => (
                    <button
                        key={place.c_addr_id}
                        type="button"
                        onClick={() => onAddPlace(place)}
                        style={resultButtonStyle}
                    >
                        <span>{place.c_name_chn || place.c_name || `ADDR ${place.c_addr_id}`}</span>
                        <span style={{ color: '#6c757d', fontSize: '0.78rem' }}>#{place.c_addr_id}</span>
                    </button>
                ))}
            </div>

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.8rem', color: '#495057' }}>
                    <input
                        type="checkbox"
                        checked={includeSubUnits}
                        onChange={(event) => onToggleIncludeSubUnits(event.target.checked)}
                    />
                    包含下屬地點
                </label>
                <button
                    type="button"
                    onClick={onClearPlaces}
                    disabled={selectedPlaces.length === 0}
                    style={smallButtonStyle}
                >
                    清空地點
                </button>
            </div>

            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
                {selectedPlaces.length === 0 && <span style={{ color: '#6c757d', fontSize: '0.85rem' }}>不限地點</span>}
                {selectedPlaces.map((place) => (
                    <span key={place.c_addr_id} style={chipStyle}>
                        {(place.c_name_chn || place.c_name || `ADDR ${place.c_addr_id}`)} #{place.c_addr_id}
                        <button
                            type="button"
                            onClick={() => onRemovePlace(place.c_addr_id)}
                            style={chipRemoveStyle}
                            aria-label={`移除地點 ${place.c_addr_id}`}
                        >
                            ×
                        </button>
                    </span>
                ))}
            </div>
        </div>
    );
}

const inputStyle: React.CSSProperties = {
    width: '100%',
    maxWidth: '100%',
    boxSizing: 'border-box',
    padding: '6px 10px',
    border: '1px solid #ced4da',
    borderRadius: 4,
    fontSize: '0.875rem',
};

const emptyStateStyle: React.CSSProperties = {
    padding: '12px 14px',
    color: '#6c757d',
    fontSize: '0.85rem',
};

const resultButtonStyle: React.CSSProperties = {
    width: '100%',
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'center',
    padding: '8px 10px',
    backgroundColor: 'transparent',
    border: 'none',
    borderBottom: '1px solid #f0f0f0',
    cursor: 'pointer',
    textAlign: 'left',
};

const smallButtonStyle: React.CSSProperties = {
    padding: '4px 10px',
    border: '1px solid #6c757d',
    borderRadius: 4,
    backgroundColor: 'transparent',
    color: '#6c757d',
    cursor: 'pointer',
    fontSize: '0.8rem',
};

const chipStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    padding: '3px 8px',
    backgroundColor: '#198754',
    color: '#fff',
    borderRadius: 4,
    fontSize: '0.75rem',
};

const chipRemoveStyle: React.CSSProperties = {
    marginLeft: 4,
    background: 'none',
    border: 'none',
    color: '#fff',
    cursor: 'pointer',
    padding: '0 2px',
    fontSize: '0.85rem',
    lineHeight: 1,
};
