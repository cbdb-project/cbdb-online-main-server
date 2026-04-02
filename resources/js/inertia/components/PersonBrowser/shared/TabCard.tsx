import React from 'react';

interface Props {
    children: React.ReactNode;
}

/**
 * 單筆資料卡片容器，每個 tab 的 list item 都用這個包裹。
 */
export default function TabCard({ children }: Props) {
    return <div style={cardStyle}>{children}</div>;
}

const cardStyle: React.CSSProperties = {
    position: 'relative',
    border: '1px solid #dee2e6',
    borderRadius: 6,
    padding: '10px 88px 10px 14px',
    backgroundColor: '#fff',
};
