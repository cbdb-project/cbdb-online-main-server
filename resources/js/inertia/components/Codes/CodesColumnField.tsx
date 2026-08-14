import React from 'react';
import * as LabelPrimitive from '@radix-ui/react-label';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import CodeAutocomplete from '../PersonBrowser/shared/CodeAutocomplete';
import PersonJumpLink from '../PersonEditorShared/PersonJumpLink';
import { useTranslation } from '../../hooks/useTranslation';

/**
 * 泛用 codes 表單的單一欄位。
 *
 * React 版原本把新增／編輯頁寫成「columns → 純 Input」，舊版 codes/edit.blade.php 的逐欄特殊處理
 * （稽核欄不可編輯＋替換預覽、欄位提示、TEXT_INSTANCE_DATA 的 Load Data 鈕）因此全部漏移植。
 * 這個元件是那些行為的單一落點；行為資料由後端 CodesController::codeColumnBehaviour() 供給，
 * 前端不再各自硬編碼（Create.tsx 先前的 COLUMN_HINTS 就是硬編碼中文、英文語境會漏字）。
 *
 * 刻意**不**使用 ui/FormField：它會把 id 與 aria 注入「單一子節點」，而這裡的子節點是一個包住
 * 輸入框＋動作鈕＋提示的 <div>——那會讓 <div> 和 <input> 拿到相同 id（重複 id），且 <label for>
 * 指到不可標記的 <div> 而失效（點欄位標籤不再聚焦輸入框）。故在此自行組出 label／aria 關聯。
 */

export interface ColumnBehaviour {
    /**
     * 系統蓋章的欄位（稽核欄）→ 灰底唯讀。
     * 用 readOnly 而非 disabled：這四欄的用途就是「被讀」，readOnly 仍可聚焦、可選取複製，
     * 且與舊版 codes/edit.blade.php 的 readonly 屬性一致；disabled 會讓文字無法選取。
     * 兩者都達成「使用者不能改」。送出內容不受影響：Inertia useForm 送的是 form.data（JS 物件），
     * 不是 DOM 表單；後端一律以 enforceAuditFieldsForCreate/Update 覆蓋。
     */
    readonly?: boolean;
    /**
     * 欄位提示。text 已由後端翻譯；link 另以資料傳出，故不需要 dangerouslySetInnerHTML。
     * text 內若含 `:link` 佔位，連結會就地嵌在句中（保留舊版讀法）；沒有佔位則附在句末。
     * tone='warn' 用於「提交後會被替換為 X」——舊版是 text-info + <strong>，不該與一般提示同重量。
     */
    hint?: { text: string; link?: { href: string; label: string }; tone?: 'info' | 'warn' };
    /** 額外互動。目前僅 'load_text_title'（依 c_textid 帶入書名）。 */
    action?: string;
    /**
     * 該欄改用可搜尋的選擇器而非純輸入框。
     * 目前只有 kind='person'（外鍵指向 BIOG_MAIN 的欄位，見 CodesController::personFkColumns）。
     * label 是目前值的顯示名稱，供選擇器初始顯示——否則使用者只看到一個數字。
     */
    picker?: {
        kind: 'person';
        endpoint: string;
        label?: string | null;
        /**
         * 目前值是否對應一位存在的人物。查不到時 label 是退回的原始 ID（見後端 codeColumnBehaviour），
         * 故 false 時既不可把 label 當姓名、也不該給跳轉連結（會開到 404）。
         * null＝目前沒有數值可判定。
         */
        exists?: boolean | null;
        /** 人物編輯頁 URL 模板（後端 flag-aware 產生），把 `__ID__` 換成人物 ID。 */
        edit_url_template?: string;
    };
}

interface Props {
    column: string;
    value: string;
    onChange: (value: string) => void;
    error?: string | string[] | null;
    required?: boolean;
    isKey?: boolean;
    behaviour?: ColumnBehaviour;
    /** 動作按鈕文案；有 behaviour.action 時必須提供。 */
    actionLabel?: string;
    onAction?: () => void;
    actionPending?: boolean;
    /** 動作結果訊息，就近顯示在觸發它的欄位下方（而非表單底部）。 */
    actionMessage?: string | null;
    actionFailed?: boolean;
    /** 剛被動作填入 → 標黃底（對齊舊版 Load Data 後的 #FFFFBB 提示）。 */
    highlighted?: boolean;
}

/**
 * 把提示文字裡的 `:link` 佔位換成真正的 <a>，讓連結留在句中（舊版是 HTML 字串裡的 inline <a>）。
 * 沒有佔位（或沒有 link）時退回「句末附連結」。React 會轉義文字，故無 XSS 風險。
 */
function renderHintText(hint: NonNullable<ColumnBehaviour['hint']>): React.ReactNode {
    const { link } = hint;
    if (!link) {
        return hint.text;
    }

    const anchor = (key: string) => (
        <a
            key={key}
            href={link.href}
            target="_blank"
            rel="noopener noreferrer"
            className="text-primary hover:underline"
        >
            {link.label}
        </a>
    );

    if (!hint.text.includes(':link')) {
        return <>{hint.text}{' '}{anchor('link')}</>;
    }

    // 替換**所有** :link 佔位（不只第一個），否則多佔位的譯文會把後面的 :link 當純文字印出來。
    const segments = hint.text.split(':link');

    return (
        <>
            {segments.map((segment, index) => (
                <React.Fragment key={index}>
                    {segment}
                    {index < segments.length - 1 && anchor(`link-${index}`)}
                </React.Fragment>
            ))}
        </>
    );
}

export function CodesColumnField({
    column, value, onChange, error, required, isKey,
    behaviour, actionLabel, onAction, actionPending, actionMessage, actionFailed, highlighted,
}: Props) {
    const readOnly = behaviour?.readonly === true;
    const hint = behaviour?.hint;
    const picker = behaviour?.picker;
    const messages = Array.isArray(error) ? error : error ? [error] : [];
    const hasError = messages.length > 0;
    const describedById = `${column}-desc`;

    // 人物欄的「前往人物基本資料」連結文案。此元件的三個使用頁（Create／Edit／ProposalEdit）
    // 都以 codes group 作為 page_translations，故直接在元件內取譯，不必逐頁再傳一個 tr。
    const t = useTranslation('codes');
    const tr = (k: string, fb: string) => { const v = t(k); return v && v !== k ? v : fb; };

    /**
     * 使用者在本頁自行選過的人物（值＋顯示名稱）。
     *
     * picker.label／picker.exists 只描述「隨頁面送來的那個值」；使用者改選他人後就過期了，
     * 照樣沿用會把別人的姓名標到新的人身上。選擇器本身在 onChange 就給了新選項的 label，
     * 這裡把它留住即可——既拿回姓名，也不需要去猜「現在的值還是不是原本那個」。
     * 從選擇器選出來的一定是搜尋端點回傳的真實人物，故 exists 視為 true。
     */
    const [picked, setPicked] = React.useState<{ value: string; label: string } | null>(null);
    const pickedHere = picked !== null && picked.value === value;
    const personName = pickedHere ? picked.label : (picker?.exists ? (picker.label ?? undefined) : undefined);
    // 連結只在「確定存在的人物」上出現：查不到的殘留 ID（例如提案送審後被合併掉的人物）
    // 給了連結只會開到 404。
    const personLinkable = picker?.kind === 'person' && (pickedHere || picker.exists === true);

    return (
        <div className="space-y-1">
            <LabelPrimitive.Root htmlFor={column} className="text-sm font-medium">
                {column}
                {/* 稽核欄由系統蓋章，不是使用者的責任，故不標必填星號。 */}
                {required && !readOnly && <span className="ml-0.5 text-destructive">*</span>}
            </LabelPrimitive.Root>

            <div className="flex items-start gap-2">
                {picker ? (
                    // 人物欄：可用姓名或 ID 搜尋。CodeAutocomplete 自行維護選定後的顯示文字，
                    // 故此處只需回寫代碼；初始顯示名稱由後端 picker.label 提供。
                    <div className="flex-1">
                        <CodeAutocomplete
                            mode="search"
                            id={column}
                            endpoint={picker.endpoint}
                            value={value}
                            initialLabel={picker.label ?? ''}
                            disabled={readOnly}
                            aria-invalid={hasError ? true : undefined}
                            aria-describedby={hasError || hint || actionMessage ? describedById : undefined}
                            onChange={(v, label) => { setPicked({ value: v, label }); onChange(v); }}
                        />
                    </div>
                ) : (
                    <Input
                        id={column}
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                        readOnly={readOnly}
                        aria-readonly={readOnly || undefined}
                        aria-invalid={hasError ? true : undefined}
                        aria-describedby={hasError || hint || actionMessage ? describedById : undefined}
                        // 唯讀欄位灰底表示「系統維護」；剛被帶入的欄位黃底表示「這是剛填上的」。
                        className={
                            (readOnly ? 'bg-muted text-muted-foreground ' : '')
                            + (highlighted ? 'bg-yellow-100 dark:bg-yellow-900/40' : '')
                        }
                    />
                )}
                {behaviour?.action && onAction && (
                    <Button
                        type="button"
                        variant="secondary"
                        className="shrink-0"
                        disabled={actionPending}
                        onClick={onAction}
                    >
                        {actionLabel}
                    </Button>
                )}
            </div>

            {isKey && <span className="text-xs text-blue-700 dark:text-blue-400">PK</span>}

            <div id={describedById}>
                {hint && (
                    <p className={hint.tone === 'warn' ? 'text-xs font-semibold text-primary' : 'text-xs text-muted-foreground'}>
                        {renderHintText(hint)}
                    </p>
                )}
                {actionMessage && (
                    <p
                        className={actionFailed ? 'text-xs text-destructive' : 'text-xs text-muted-foreground'}
                        role="status"
                        aria-live="polite"
                    >
                        {actionMessage}
                    </p>
                )}
                {messages.map((m, i) => (
                    <p key={i} className="text-xs text-destructive" role="alert">
                        {m}
                    </p>
                ))}
            </div>

            {/* 人物欄已選定一位真實人物 → 就近給一個可直達其基本資料的連結（與 KinEditor／
                AssocEditor 的人物參照欄同一個元件、同一種樣式）。放在提示／錯誤之後，
                驗證訊息才緊貼輸入框。 */}
            {personLinkable && (
                <PersonJumpLink
                    personId={value}
                    name={personName}
                    tr={tr}
                    to="edit"
                    hrefTemplate={picker?.edit_url_template}
                    context={column}
                />
            )}
        </div>
    );
}
