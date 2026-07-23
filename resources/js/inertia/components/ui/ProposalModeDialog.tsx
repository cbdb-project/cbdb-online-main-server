import React from 'react';
import { Modal } from './Modal';
import { Button } from './Button';

interface ProposalModeDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: React.ReactNode;
    description?: React.ReactNode;
    saveDirectLabel: string;
    submitProposalLabel: string;
    cancelLabel: string;
    loading?: boolean;
    onSaveDirect: () => void;
    onSubmitProposal: () => void;
}

/**
 * 有直接保存權限的使用者點擊「提交建議」時的確認彈窗：
 * 讓使用者在「直接保存」與「仍要提交建議（等待審核）」之間二次確認選擇，
 * 避免有權限的使用者誤送出不必要的審核提案。取代 window.confirm，a11y 較佳。
 */
export function ProposalModeDialog({
    open,
    onOpenChange,
    title,
    description,
    saveDirectLabel,
    submitProposalLabel,
    cancelLabel,
    loading,
    onSaveDirect,
    onSubmitProposal,
}: ProposalModeDialogProps) {
    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            description={description}
            footer={
                <>
                    <Button variant="outline" disabled={loading} onClick={() => onOpenChange(false)}>
                        {cancelLabel}
                    </Button>
                    <Button variant="secondary" disabled={loading} onClick={onSubmitProposal}>
                        {submitProposalLabel}
                    </Button>
                    <Button variant="default" disabled={loading} onClick={onSaveDirect}>
                        {saveDirectLabel}
                    </Button>
                </>
            }
        />
    );
}
