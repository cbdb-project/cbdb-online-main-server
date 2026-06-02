/**
 * 全域 dirty 狀態 registry。
 * 各組件可透過 useDirtyGuard() 登記自己是否有未儲存的修改。
 * AppShell 在執行頁面層級的導航（例如語言切換）前查詢此 registry。
 */

type DirtyChecker = () => boolean;

const checkers = new Set<DirtyChecker>();

export function registerDirtyChecker(checker: DirtyChecker): () => void {
    checkers.add(checker);
    return () => checkers.delete(checker);
}

export function hasUnsavedChanges(): boolean {
    for (const check of checkers) {
        if (check()) return true;
    }
    return false;
}
