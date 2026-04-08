import React from 'react';

interface Props {
    children: React.ReactNode;
}

/**
 * 卡片右上角的操作按鈕容器（修改、刪除等）。
 */
export default function CardActions({ children }: Props) {
    return <div style={containerStyle}>{children}</div>;
}

const containerStyle: React.CSSProperties = {
    position: 'absolute',
    top: 10,
    right: 14,
    display: 'flex',
    gap: 6,
    zIndex: 1,
};
