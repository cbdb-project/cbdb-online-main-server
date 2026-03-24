import React, { useEffect } from 'react';

interface Props {
    isOpen: boolean;
    title: string;
    description?: string;
    width?: number;
    footer?: React.ReactNode;
    onClose: () => void;
    children: React.ReactNode;
}

export default function SelectionDialog({
    isOpen,
    title,
    description,
    width = 860,
    footer,
    onClose,
    children,
}: Props) {
    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const previousOverflow = document.body.style.overflow;
        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', handleKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [isOpen, onClose]);

    if (!isOpen) {
        return null;
    }

    return (
        <div style={backdropStyle} onClick={onClose}>
            <div
                style={{ ...dialogStyle, width: `min(${width}px, calc(100vw - 32px))` }}
                onClick={(event) => event.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-label={title}
            >
                <div style={headerStyle}>
                    <div>
                        <div style={{ fontSize: '1.05rem', fontWeight: 700 }}>{title}</div>
                        {description && (
                            <div style={{ marginTop: 4, color: '#6c757d', fontSize: '0.86rem' }}>
                                {description}
                            </div>
                        )}
                    </div>
                    <button type="button" onClick={onClose} style={closeButtonStyle} aria-label="關閉對話框">
                        ×
                    </button>
                </div>

                <div style={bodyStyle}>
                    {children}
                </div>

                {footer && (
                    <div style={footerStyle}>
                        {footer}
                    </div>
                )}
            </div>
        </div>
    );
}

const backdropStyle: React.CSSProperties = {
    position: 'fixed',
    inset: 0,
    zIndex: 1200,
    backgroundColor: 'rgba(15, 23, 42, 0.42)',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 16,
};

const dialogStyle: React.CSSProperties = {
    maxHeight: 'calc(100vh - 32px)',
    display: 'flex',
    flexDirection: 'column',
    backgroundColor: '#fff',
    borderRadius: 4,
    boxShadow: '0 28px 72px rgba(15, 23, 42, 0.28)',
    overflow: 'hidden',
};

const headerStyle: React.CSSProperties = {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    gap: 12,
    padding: '18px 20px',
    borderBottom: '1px solid #e5e7eb',
};

const closeButtonStyle: React.CSSProperties = {
    width: 36,
    height: 36,
    borderRadius: 4,
    border: '1px solid #d0d8e2',
    backgroundColor: '#fff',
    color: '#334155',
    cursor: 'pointer',
    fontSize: '1.2rem',
    lineHeight: 1,
};

const bodyStyle: React.CSSProperties = {
    flex: 1,
    overflowY: 'auto',
    padding: 20,
    backgroundColor: '#f8fafc',
};

const footerStyle: React.CSSProperties = {
    display: 'flex',
    justifyContent: 'flex-end',
    gap: 8,
    padding: '14px 20px',
    borderTop: '1px solid #e5e7eb',
    backgroundColor: '#fff',
};
