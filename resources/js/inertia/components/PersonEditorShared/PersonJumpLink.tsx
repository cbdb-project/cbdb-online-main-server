import React from 'react';

/**
 * 「前往此人物頁」連結。
 *
 * 用於人物參照欄（如社會關係的「關聯人物」c_assoc_id、親屬的「親屬姓名」c_kin_id）：
 * 當欄位已選定一位真實人物時，於欄位下方顯示一個連到該人物詳情頁
 * （/app/basicinformation/{id}，=app.basicinformation.show）的小連結。
 *
 * 設計考量：
 *  - 僅在 value 為「正整數 personid」時顯示；哨兵 0 / 空 / 負值（未選）不顯示，避免連到無效頁。
 *  - target="_blank"：子資源編輯器常有未存變更（dirty 守衛），同分頁跳轉會遺失正在編輯的內容，
 *    故一律於「新分頁」開啟，使用者可邊編輯邊查看對方人物。
 *  - 樣式低調（小字、品牌色、外部連結圖示），置於輸入框下方，不與輸入框搶視覺；
 *    顏色用 var(--primary) 隨深色模式翻轉。人物姓名置於 title（tooltip），可見文字保持精簡
 *    （姓名已顯示在上方輸入框，不重複佔版面）。
 *  - to='edit' 改連基本資料編輯頁，供碼表編輯頁的人物欄使用：在那裡使用者正在編目一筆人物參照，
 *    要去的是「那個人的基本資料」而非唯讀詳情中樞。文案／tooltip 另用一組 key，避免顯示
 *    「詳情頁」卻連到編輯頁。四組 key 在 biogmains 與 codes 兩個 group 都有定義，因此無論
 *    呼叫端取譯自哪一個 group，兩種 to 都不會退回硬編碼中文（英文語境會漏字）。
 *  - hrefTemplate：呼叫端已有 flag-aware URL 模板（後端 person_page_url('__ID__', …)）時傳入，
 *    優先於元件內建路徑。刻意收模板而非成品 URL：`__ID__` 由此處以**正規化後的整數** id 代入，
 *    與顯示與否的判準用同一個值。若由呼叫端拿原始欄位值去代，'100.0' 這種 is_numeric 但非整數
 *    字面的值會通過兩邊的檢查卻組出撞不到路由（`where('id','[0-9]+')`）的 404 連結。
 *  - context：同一個表單有多個人物欄時（如 ASSOC_DATA 有 5 個），可見文字都一樣，
 *    以 aria-label 補上欄名，螢幕閱讀器才分得出這個連結屬於哪一欄。
 */
export default function PersonJumpLink({
    personId,
    name,
    tr,
    to = 'show',
    hrefTemplate,
    context,
}: {
    personId?: string | number | null;
    name?: string;
    tr: (k: string, fb: string) => string;
    to?: 'show' | 'edit';
    hrefTemplate?: string;
    context?: string;
}) {
    const id = Number(personId);
    if (!Number.isInteger(id) || id <= 0) {
        return null;
    }
    const nm = (name ?? '').trim();
    const isEdit = to === 'edit';
    const titleText = isEdit
        ? tr('goto_person_edit_page_title', '在新分頁開啟此人物的基本資料編輯頁')
        : tr('goto_person_page_title', '在新分頁開啟此人物的詳情頁');
    const title = titleText + (nm ? `：${nm}` : '');
    const text = isEdit ? tr('goto_person_edit_page', '前往人物基本資料') : tr('goto_person_page', '前往人物頁面');
    const href = hrefTemplate
        ? hrefTemplate.replace('__ID__', String(id))
        : (isEdit ? `/app/basicinformation/${id}/edit` : `/app/basicinformation/${id}`);
    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            title={title}
            aria-label={context ? `${text} (${context})` : undefined}
            style={linkStyle}
        >
            <i className="fas fa-external-link-alt" aria-hidden style={{ fontSize: '0.78em' }} />
            {text}
        </a>
    );
}

const linkStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 5,
    marginTop: 5,
    fontSize: '0.8rem',
    fontWeight: 600,
    color: 'var(--primary)',
    textDecoration: 'underline',
    textUnderlineOffset: 2,
    width: 'fit-content',
};
