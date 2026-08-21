import React, { useCallback, useLayoutEffect, useRef } from 'react';
import { cn } from '../../lib/utils';

export interface LineNumberedTextareaProps {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    rows?: number;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
}

/**
 * 帶行號側欄的 textarea：批次匯入頁的錯誤訊息以「第 N 行」定位，
 * 需要側欄讓使用者直接對照（後端行號＝原始輸入以 \r\n|\n|\r 切分後的 index + 1，
 * 與此處 value.split('\n') 的計數一致；空行不重新編號，兩邊逐行對齊）。
 *
 * 對齊靠三件事，改動時請一併確認：
 * 1. textarea 必須 `wrap="off"`（改為水平捲動）。預設的軟換行會把一筆過長的
 *    tab 分隔資料折成多個視覺列，而側欄每個編號固定佔一列，之後所有編號都會錯位。
 * 2. 側欄以 absolute 疊放、不參與 flex 交叉軸高度計算。否則側欄的自然高度（＝行數）
 *    會把容器撐高，textarea 跟著被拉伸，失去 rows 的固定高度與內部捲動
 *    （貼上數百行時輸入框會長到數百列高，把送出按鈕推走）。
 * 3. 垂直同步用 `transform: translateY(-scrollTop)`，不用側欄自己的 scrollTop。
 *    水平捲軸會吃掉 textarea 的可視高度，使兩者的 scrollTop 上限不同；
 *    寫 scrollTop 會在捲到最底時被 clamp，造成約半行的偏移。
 *
 * FormField 會把 id / aria-invalid / aria-describedby clone 到單一子元素上；
 * 這裡把這些屬性轉發到真正的 <textarea>，讓行號側欄僅作為視覺裝飾疊加。
 */
export function LineNumberedTextarea({
    id,
    value,
    onChange,
    placeholder,
    rows = 10,
    'aria-invalid': ariaInvalid,
    'aria-describedby': ariaDescribedBy,
}: LineNumberedTextareaProps) {
    const gutterRef = useRef<HTMLPreElement>(null);
    const gutterColRef = useRef<HTMLDivElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    const lineCount = Math.max(1, value.split('\n').length);
    // 側欄寬度隨最大行號位數成長；1.25rem 覆蓋 pre 的左右內距（各 0.5rem）、
    // 外層 border-r 的 1px，並留一點餘裕，避免 font-mono fallback 到非等寬字型時
    // 最左邊的數字被 overflow-hidden 靜默裁掉。最少 3 位數以降低寬度跳動頻率。
    const gutterWidth = `calc(${Math.max(3, String(lineCount).length)}ch + 1.25rem)`;

    const syncGutterOffset = useCallback(() => {
        const textarea = textareaRef.current;
        if (textarea && gutterRef.current) {
            gutterRef.current.style.transform = `translateY(${-textarea.scrollTop}px)`;
        }
    }, []);

    // textarea 出現水平捲軸時，其可視高度比側欄少一個捲軸的高度；把差額寫進側欄外層的
    // padding-bottom，讓 overflow 的裁切邊（padding box）與 textarea 內容區同高，
    // 避免最底部多畫一個沒有對應文字的行號。textarea 是 border-0，故
    // offsetHeight - clientHeight 即捲軸高度（若日後給它加 border 需連同扣除）。
    // 順帶重算 transform：value 變短時瀏覽器會 clamp scrollTop，靠這裡在 paint 前收斂，
    // 不必依賴 clamp 是否派發 scroll 事件。
    const syncGutterMetrics = useCallback(() => {
        const textarea = textareaRef.current;
        const column = gutterColRef.current;
        if (!textarea || !column) {
            return;
        }

        column.style.paddingBottom = `${Math.max(0, textarea.offsetHeight - textarea.clientHeight)}px`;
        syncGutterOffset();
    }, [syncGutterOffset]);

    useLayoutEffect(() => {
        syncGutterMetrics();
    }, [value, syncGutterMetrics]);

    useLayoutEffect(() => {
        const textarea = textareaRef.current;
        if (!textarea) {
            return;
        }

        // 觀察 content-box：捲軸出現／消失、容器寬度變化、使用者手動 resize 都會觸發。
        const observer = new ResizeObserver(syncGutterMetrics);
        observer.observe(textarea);

        return () => observer.disconnect();
    }, [syncGutterMetrics]);

    return (
        <div
            className={cn(
                'flex items-stretch overflow-hidden rounded-md border border-input bg-background shadow-sm focus-within:ring-2 focus-within:ring-ring',
                ariaInvalid && 'border-destructive'
            )}
        >
            <div
                ref={gutterColRef}
                aria-hidden="true"
                // font-mono text-sm 是必要的：gutterWidth 的 ch 單位在此解析。
                className="relative shrink-0 overflow-hidden border-r border-border bg-muted/50 font-mono text-sm"
                style={{ width: gutterWidth }}
            >
                <pre
                    ref={gutterRef}
                    className="absolute inset-x-0 top-0 m-0 select-none whitespace-pre px-2 py-2 text-right font-mono text-sm leading-normal text-muted-foreground"
                >
                    {Array.from({ length: lineCount }, (_, i) => i + 1).join('\n')}
                </pre>
            </div>
            <textarea
                ref={textareaRef}
                id={id}
                rows={rows}
                wrap="off"
                spellCheck={false}
                aria-invalid={ariaInvalid}
                aria-describedby={ariaDescribedBy}
                className="min-w-0 flex-1 resize-y overflow-auto border-0 bg-transparent px-3 py-2 font-mono text-sm leading-normal shadow-none focus-visible:outline-none"
                placeholder={placeholder}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onScroll={syncGutterOffset}
            />
        </div>
    );
}
