/**
 * 全域守衛：避免 <input type="number"> 在獲得焦點時被滑鼠滾輪改值。
 *
 * 瀏覽器原生行為下，當 number input 處於 focus，捲動滾輪每一格會 ±1 改變數值，
 * 容易讓使用者在編輯後捲頁面時意外把值改掉（例如「享年」常見的 -1～-3 偏差）。
 *
 * 解法：在 document 層攔截 wheel 事件，若當前 active element 是
 * type="number" 的 input，就 blur 它，讓默認行為改回頁面捲動。
 *
 * 一次安裝即可生效於整個頁面（Blade + React/Inertia 共用）。
 */
export function installNumberInputWheelGuard() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }
    if (window.__numberInputWheelGuardInstalled) {
        return;
    }
    window.__numberInputWheelGuardInstalled = true;

    document.addEventListener(
        'wheel',
        () => {
            const active = document.activeElement;
            if (
                active instanceof HTMLInputElement &&
                active.type === 'number'
            ) {
                active.blur();
            }
        },
        { passive: true, capture: true },
    );
}
