import React from 'react';
import { Modal } from './ui/Modal';
import { Button } from './ui/Button';
import type { Tier2UmlautHit } from '../utils/pinyinUmlaut';

interface Props {
    hits: Tier2UmlautHit[] | null;
    onConvert: () => void;
    onKeep: () => void;
    onCancel: () => void;
    /** useTranslation('biogmains') 的 t（缺鍵時回傳鍵名）；缺鍵則用內建繁中後備。 */
    t?: (key: string) => string;
}

/**
 * §D-6 Tier 2 保存確認彈窗（通用 /codes 編輯器）。
 *
 * 當「可能含西文」的 Tier 2 欄位偵測到符合漢語拼音規則的 v→ü（如 Lvchuan→Lüchuan）時跳出，
 * 由使用者決定「轉換／保留原樣」。西文（如 Denver/Vietnam）不會命中規則、不會彈窗。
 * 復用 AltnameEditor 的機制，但支援每張表動態多個 Tier 2 欄位。
 */
export function PinyinUmlautConfirmDialog({ hits, onConvert, onKeep, onCancel, t }: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    const open = !!hits && hits.length > 0;

    return (
        <Modal
            open={open}
            onOpenChange={(o) => { if (!o) onCancel(); }}
            title={tr('pinyin_umlaut_title', '偵測到拼音 v → ü')}
            description={tr('pinyin_umlaut_hint_codes', '下列欄位可能含以 v 代寫的 ü。是否依漢語拼音規則轉換？若為西文（如 Denver）請選「保留原樣」。')}
            footer={
                <>
                    <Button variant="outline" onClick={onCancel}>{tr('cancel', '取消')}</Button>
                    <Button variant="secondary" onClick={onKeep}>{tr('pinyin_umlaut_keep', '保留原樣並儲存')}</Button>
                    <Button variant="default" onClick={onConvert}>{tr('pinyin_umlaut_convert', '轉換並儲存')}</Button>
                </>
            }
        >
            <div className="space-y-3 text-sm">
                {(hits ?? []).map((hit) => (
                    <div key={hit.field}>
                        <div className="font-medium">{hit.field}</div>
                        <ul className="ml-4 list-disc">
                            {hit.conversions.map((c, i) => (
                                <li key={`${hit.field}-${c.index}-${i}`}>
                                    <code>{c.from}</code> → <code>{c.to}</code>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-1 text-muted-foreground" style={{ wordBreak: 'break-all' }}>
                            {tr('pinyin_umlaut_preview', '轉換後：')}<code>{hit.converted}</code>
                        </div>
                    </div>
                ))}
            </div>
        </Modal>
    );
}
