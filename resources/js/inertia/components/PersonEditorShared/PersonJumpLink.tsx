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
 */
export default function PersonJumpLink({
    personId,
    name,
    tr,
}: {
    personId?: string | number | null;
    name?: string;
    tr: (k: string, fb: string) => string;
}) {
    const id = Number(personId);
    if (!Number.isInteger(id) || id <= 0) {
        return null;
    }
    const nm = (name ?? '').trim();
    const title = tr('goto_person_page_title', '在新分頁開啟此人物的詳情頁') + (nm ? `：${nm}` : '');
    return (
        <a
            href={`/app/basicinformation/${id}`}
            target="_blank"
            rel="noopener noreferrer"
            title={title}
            style={linkStyle}
        >
            <i className="fas fa-external-link-alt" aria-hidden style={{ fontSize: '0.78em' }} />
            {tr('goto_person_page', '前往人物頁面')}
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
