import React, { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import { getCsrfToken } from './PersonBrowser/shared/csrf';

/**
 * 基本資料編輯器（對齊 legacy /basicinformation/{id}/edit，非 person-browser）。
 * 忠實復刻 legacy edit.blade：全 BIOG_MAIN 可編輯欄位 + 年號轉換（EraTimeField）+ 生成拼音 +
 * indexYear 聯動 + 三態授權提交。提交走既有 /api/v2/mutate（resource=basicinformation）。
 *
 * 註：本元件不依賴 app/person-browser 的編輯/分頁 UI；僅複用通用表單控件 EraTimeField / CodeAutocomplete。
 */

type Fields = Record<string, string>;

interface Props {
    personId: number;
    personLabel: string;
    initialFields: Fields;       // 所有 c_* 欄位的初始字串值
    initialLabels?: Fields;      // 代碼欄位的顯示標籤（年號等），key 同 c_* 名
    canEdit: boolean;            // 可直接寫入
    canPropose: boolean;         // 可提案
    mutateEndpoint: string;      // /api/v2/mutate
    deleteEndpoint?: string;     // /api/v2/delete
    pinyinEndpoint?: string;     // /api/select/search/pinyin
    indexUrl?: string;           // 刪除後導回的人物列表
    duplicateCollateralUrl?: string;
    saveasUrl?: string;
    t?: (k: string) => string;   // person/biogmains 翻譯
}

const READONLY_DERIVED = ['c_name_chn', 'c_name', 'c_name_proper', 'c_name_rm'];

// 一組日期欄位（生年/卒年/活動年）↔ EraTimeField 的子欄位映射。
interface DateGroup {
    year: string; nhCode: string; nhYear: string;
    range?: string; intercalary?: string; month?: string; day?: string; dayGz?: string; notes?: string;
}

export default function BasicInfoEditor({
    personId, personLabel, initialFields, initialLabels = {},
    canEdit, canPropose, mutateEndpoint, deleteEndpoint, pinyinEndpoint = '/api/select/search/pinyin',
    indexUrl = '/basicinformation', duplicateCollateralUrl, saveasUrl, t,
}: Props) {
    // useTranslation 在缺 key 時回傳 key 本身；故須在 t(k)===k（未翻譯）時退回中文 fallback，
    // 否則按鈕/標籤會顯示原始 key（如 save_directly）而非中文。
    const tr = (k: string, fallback: string) => {
        const v = t ? t(k) : k;
        return v && v !== k ? v : fallback;
    };
    const [fields, setFields] = useState<Fields>(initialFields);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const initialSnapshot = useRef<string>(JSON.stringify(initialFields));
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [pinyinDone, setPinyinDone] = useState(false);
    const [comment, setComment] = useState('');
    const [deleting, setDeleting] = useState(false);

    const dirty = useMemo(() => JSON.stringify(fields) !== initialSnapshot.current, [fields]);
    const dynastyCode = useMemo(() => {
        const v = parseInt(fields.c_dy ?? '', 10);
        return Number.isFinite(v) && v > 0 ? v : null;
    }, [fields.c_dy]);

    const set = (key: string, value: string) => setFields((p) => ({ ...p, [key]: value }));
    const setLabel = (key: string, value: string) => setLabels((p) => ({ ...p, [key]: value }));

    // 離頁守衛：有未存變更時警告（dirty guard）。TODO（基本資料收尾）：補 legacy 的
    // 「名中/拼音任一空」專屬提示（#check_info）。
    useEffect(() => {
        const handler = (e: BeforeUnloadEvent) => {
            if (!dirty) return;
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', handler);
        return () => window.removeEventListener('beforeunload', handler);
    }, [dirty]);

    // indexYear 聯動：享年 = 卒-生+1；index_year = 享年>60 ? 生+60 : 卒（對齊 legacy indexYear()）。
    const recomputeIndexYear = (next: Fields): Fields => {
        const by = parseInt(next.c_birthyear ?? '', 10);
        const dy = parseInt(next.c_deathyear ?? '', 10);
        const out = { ...next };
        if (Number.isFinite(by) && Number.isFinite(dy) && dy >= by) {
            const age = dy - by + 1;
            out.c_death_age = String(age);
            out.c_index_year = String(age > 60 ? by + 60 : dy);
        }
        return out;
    };

    const buildEra = (g: DateGroup): EraTimeFieldValues => ({
        year: fields[g.year] ?? '',
        nhCode: fields[g.nhCode] ?? '',
        nhCodeLabel: labels[g.nhCode] ?? '',
        nhYear: fields[g.nhYear] ?? '',
        range: g.range ? fields[g.range] ?? '' : '',
        rangeLabel: g.range ? labels[g.range] ?? '' : '',
        intercalary: g.intercalary ? fields[g.intercalary] ?? '0' : '0',
        month: g.month ? fields[g.month] ?? '' : '',
        day: g.day ? fields[g.day] ?? '' : '',
        dayGz: g.dayGz ? fields[g.dayGz] ?? '' : '',
        dayGzLabel: g.dayGz ? labels[g.dayGz] ?? '' : '',
        notes: g.notes ? fields[g.notes] ?? '' : '',
    });

    const applyEra = (g: DateGroup, patch: Partial<EraTimeFieldValues>) => {
        setFields((prev) => {
            let next = { ...prev };
            if (patch.year !== undefined) next[g.year] = patch.year;
            if (patch.nhCode !== undefined) next[g.nhCode] = patch.nhCode;
            if (patch.nhYear !== undefined) next[g.nhYear] = patch.nhYear;
            if (g.range && patch.range !== undefined) next[g.range] = patch.range;
            if (g.intercalary && patch.intercalary !== undefined) next[g.intercalary] = patch.intercalary;
            if (g.month && patch.month !== undefined) next[g.month] = patch.month;
            if (g.day && patch.day !== undefined) next[g.day] = patch.day;
            if (g.dayGz && patch.dayGz !== undefined) next[g.dayGz] = patch.dayGz;
            if (g.notes && patch.notes !== undefined) next[g.notes] = patch.notes;
            // 生/卒年變動時重算 indexYear。
            if ((g.year === 'c_birthyear' || g.year === 'c_deathyear') && patch.year !== undefined) {
                next = recomputeIndexYear(next);
            }
            return next;
        });
        if (patch.nhCodeLabel !== undefined) setLabel(g.nhCode, patch.nhCodeLabel);
        if (g.range && patch.rangeLabel !== undefined) setLabel(g.range, patch.rangeLabel);
        if (g.dayGz && patch.dayGzLabel !== undefined) setLabel(g.dayGz, patch.dayGzLabel);
    };

    // 生成拼音：用中文姓名查 /api/select/search/pinyin，回填拼音姓/名。
    const generatePinyin = async () => {
        try {
            const surnameRes = await fetch(`${pinyinEndpoint}?q=${encodeURIComponent(fields.c_surname_chn ?? '')}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
            }).then((r) => r.json()).catch(() => null);
            const mingziRes = await fetch(`${pinyinEndpoint}?q=${encodeURIComponent(fields.c_mingzi_chn ?? '')}&split=0`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
            }).then((r) => r.json()).catch(() => null);
            const pick = (res: unknown): string => {
                const rows = Array.isArray((res as { data?: unknown[] })?.data) ? (res as { data: Array<Record<string, unknown>> }).data : [];
                const first = rows[0];
                return first ? String(first.text ?? first.c_name ?? first.value ?? '') : '';
            };
            const sp = pick(surnameRes); const mp = pick(mingziRes);
            setFields((p) => ({ ...p, c_surname: sp || p.c_surname, c_mingzi: mp || p.c_mingzi }));
            setPinyinDone(true);
            window.setTimeout(() => setPinyinDone(false), 4000);
        } catch {
            setError(tr('save_failed', '生成拼音失敗'));
        }
    };

    const save = async (mode: 'direct' | 'proposal') => {
        setSaving(true); setError(null); setMessage(null);
        // 只送與初始不同、且非唯讀派生的欄位。
        const initial: Fields = JSON.parse(initialSnapshot.current);
        const changes: Record<string, string | null> = {};
        for (const [k, v] of Object.entries(fields)) {
            if (READONLY_DERIVED.includes(k)) continue;
            if ((initial[k] ?? '') !== (v ?? '')) changes[k] = v === '' ? null : v;
        }
        if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        try {
            const res = await fetch(mutateEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    resource: 'basicinformation', person_id: personId, mode, operation: 'update',
                    target: { pk: { c_personid: personId } }, changes,
                    ...(mode === 'proposal' ? { comment } : {}),
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setMessage(mode === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            initialSnapshot.current = JSON.stringify(fields);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    // 刪除（軟刪除）走 /api/v2/delete（對齊 legacy delete-form）。僅 canEdit 顯示。
    const doDelete = async () => {
        if (!deleteEndpoint) return;
        if (!window.confirm(tr('delete_confirm', '確定要刪除此人物嗎？此操作無法復原。'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    resource: 'basicinformation', person_id: personId, mode: 'direct',
                    operation: 'delete', target: { pk: { c_personid: personId } },
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            initialSnapshot.current = JSON.stringify(fields); // 避免離頁守衛攔截
            router.visit(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    // 對齊 legacy #check_info：名（中）或拼音名任一空 → 提示。
    const nameWarning = (fields.c_mingzi_chn ?? '') === '' || (fields.c_mingzi ?? '') === '';

    const textRow = (key: string, label: string, readonly = false, hint?: string) => (
        <div style={rowStyle}>
            <label style={labelStyle}>{label}</label>
            <div style={fieldStyle}>
                <input type="text" value={fields[key] ?? ''} readOnly={readonly} disabled={readonly}
                    onChange={(e) => set(key, e.target.value)}
                    style={{ ...inputStyle, ...(readonly ? readonlyStyle : {}) }} />
                {hint ? <small style={hintStyle} className="text-muted">{hint}</small> : null}
            </div>
        </div>
    );

    const codeRow = (key: string, label: string, model: string, idKey: string, labelKeys: string[]) => (
        <div style={rowStyle}>
            <label style={labelStyle}>{label}</label>
            <div style={fieldStyle}>
                <CodeAutocomplete mode="list" model={model} idKey={idKey} labelKeys={labelKeys}
                    value={fields[key] ?? ''} initialLabel={labels[key] ?? ''}
                    onChange={(v, lbl) => { set(key, v); setLabel(key, lbl); }} disabled={!canEdit && !canPropose} />
            </div>
        </div>
    );

    // 唯讀展示列（index 自動欄等）：顯示 label（display_value）若有，否則原始值。
    const displayRow = (key: string, label: string, hint?: string) => (
        <div style={rowStyle}>
            <label style={labelStyle}>{label}</label>
            <div style={fieldStyle}>
                <input type="text" value={labels[key] || fields[key] || ''} readOnly disabled
                    style={{ ...inputStyle, ...readonlyStyle }} />
                {hint ? <small style={hintStyle} className="text-muted">{hint}</small> : null}
            </div>
        </div>
    );

    // 緊湊欄位格（label 靠左、窄 label）：供雙欄並排列使用（對齊 legacy col-sm-6 內的 col-sm-4/8）。
    const cell = (key: string, label: string, readonly = false) => (
        <div style={cellRowStyle}>
            <label style={cellLabelStyle}>{label}</label>
            <input type="text" value={fields[key] ?? ''} readOnly={readonly} disabled={readonly}
                onChange={(e) => set(key, e.target.value)}
                style={{ ...inputStyle, ...(readonly ? readonlyStyle : {}) }} />
        </div>
    );

    // 唯讀派生格（label 置頂）：供 4-up grid 使用（對齊 legacy col-xl-3）。
    const derivedCell = (key: string, label: string, hint?: string) => (
        <div style={derivedCellStyle}>
            <label style={derivedLabelStyle}>{label}</label>
            <input type="text" value={labels[key] || fields[key] || ''} readOnly disabled
                style={{ ...inputStyle, ...readonlyStyle }} />
            {hint ? <small style={hintStyle} className="text-muted">{hint}</small> : null}
        </div>
    );

    // 區塊分組（使用者要求：以 block 視覺分隔各區，藍系標題；不對齊 legacy，為更合適設計）。
    // 注意：用「回傳 JSX 的函式」而非巢狀元件，避免每次 render 重新掛載 → input 失焦。
    const block = (title: string, children: React.ReactNode) => (
        <div style={blockStyle}><div style={blockHeaderStyle}>{title}</div>{children}</div>
    );

    const birth: DateGroup = { year: 'c_birthyear', nhCode: 'c_by_nh_code', nhYear: 'c_by_nh_year', range: 'c_by_range', intercalary: 'c_by_intercalary', month: 'c_by_month', day: 'c_by_day', dayGz: 'c_by_day_gz' };
    const death: DateGroup = { year: 'c_deathyear', nhCode: 'c_dy_nh_code', nhYear: 'c_dy_nh_year', range: 'c_dy_range', intercalary: 'c_dy_intercalary', month: 'c_dy_month', day: 'c_dy_day', dayGz: 'c_dy_day_gz' };
    const flEarly: DateGroup = { year: 'c_fl_earliest_year', nhCode: 'c_fl_ey_nh_code', nhYear: 'c_fl_ey_nh_year', notes: 'c_fl_ey_notes' };
    const flLate: DateGroup = { year: 'c_fl_latest_year', nhCode: 'c_fl_ly_nh_code', nhYear: 'c_fl_ly_nh_year', notes: 'c_fl_ly_notes' };

    return (
        <div style={cardStyle}>
            <h3 style={titleStyle}>{tr('basic_info_title', '人物基本資料')} — {personLabel}</h3>
            {message ? <div style={okStyle}>{message}</div> : null}
            {error ? <div style={errStyle}>{error}</div> : null}
            {pinyinDone ? <div style={okStyle}>{tr('basicinfo_pinyin_alert', '「生成拼音」已完成')}</div> : null}
            {nameWarning ? <div style={warnStyle}>{tr('name_required_warning', '請確認「名（中）」與「拼音名」是否填寫。')}</div> : null}

            {/* 區塊一：姓名（中文/拼音/外文/羅馬字 + 生成拼音 + 4 唯讀派生全名） */}
            {block(tr('block_names', '姓名'), <>
                <div style={twoColStyle}>
                    <div style={colStyle}>
                        {cell('c_surname_chn', tr('surname_chn', '姓（中）'))}
                        {cell('c_mingzi_chn', tr('mingzi_chn', '名（中）'))}
                    </div>
                    <div style={colStyle}>
                        {cell('c_surname', 'Xing')}
                        {cell('c_mingzi', 'Ming')}
                    </div>
                </div>
                {canEdit ? <div style={pinyinBtnRowStyle}><button type="button" style={infoBtn} onClick={() => void generatePinyin()}>{tr('generate_pinyin_btn', '生成拼音')}</button></div> : null}
                <div style={twoColStyle}>
                    <div style={colStyle}>{cell('c_surname_proper', tr('foreign_surname', '外文姓'))}</div>
                    <div style={colStyle}>{cell('c_mingzi_proper', tr('foreign_mingzi', '外文名'))}</div>
                </div>
                <div style={twoColStyle}>
                    <div style={colStyle}>{cell('c_surname_rm', tr('foreign_rm_surname', '羅馬字姓'))}</div>
                    <div style={colStyle}>{cell('c_mingzi_rm', tr('foreign_rm_mingzi', '羅馬字名'))}</div>
                </div>
                <div style={derivedGridStyle}>
                    {derivedCell('c_name_chn', '姓名（中） (c_name_chn)', tr('name_auto_hint', '由姓和名自動合併，無需手動填寫'))}
                    {derivedCell('c_name', '拼音 (c_name)', tr('pinyin_auto_hint', '由 Xing/Ming 自動合併，無需手動填寫'))}
                    {derivedCell('c_name_proper', '外文全名 (c_name_proper)', tr('foreign_full_auto_hint', '自動合併，無需手動填寫'))}
                    {derivedCell('c_name_rm', '羅馬字全名 (c_name_rm)', tr('rm_auto_hint', '自動合併，無需手動填寫'))}
                </div>
            </>)}

            {/* 區塊二：基本屬性（性別/族群/朝代） */}
            {block(tr('block_attributes', '基本屬性'), <>
                <div style={rowStyle}>
                    <label style={labelStyle}>{tr('gender', '性別')} (c_female)</label>
                    <div style={fieldStyle}>
                        <select value={fields.c_female ?? ''} disabled={!canEdit && !canPropose}
                            onChange={(e) => set('c_female', e.target.value)} style={inputStyle}>
                            <option value="">{tr('please_select', 'NULL')}</option>
                            <option value="0">0-{tr('male', '男')}</option>
                            <option value="1">1-{tr('female', '女')}</option>
                        </select>
                    </div>
                </div>
                {codeRow('c_ethnicity_code', tr('tribe', '族群/部族') + ' (c_ethnicity_code)', 'ethnicity', 'c_ethnicity_code', ['c_ethnicity_code', 'c_name_chn', 'c_name'])}
                {codeRow('c_dy', tr('dynasty', '朝代') + ' (c_dy)', 'dynasty', 'c_dy', ['c_dy', 'c_dynasty_chn', 'c_dynasty'])}
            </>)}

            {/* 區塊三：生卒年與指數年（生/卒年號轉換 + 自動計算的指數年/享年） */}
            {block(tr('block_life_dates', '生卒年與指數年'), <>
                <div style={sectionLabel}>{tr('birth_year', '生年')} (c_birthyear)</div>
                <EraTimeField values={buildEra(birth)} onChange={(p) => applyEra(birth, p)} dynastyCode={dynastyCode} showRange showLunar />
                <div style={sectionLabel}>{tr('death_year', '卒年')} (c_deathyear)</div>
                <EraTimeField values={buildEra(death)} onChange={(p) => applyEra(death, p)} dynastyCode={dynastyCode} showRange showLunar />
                {textRow('c_index_year', tr('index_year', '指數年') + ' (c_index_year)', true, tr('auto_calc_hint', '由算法自動計算'))}
                {displayRow('c_index_year_type_code', tr('index_year_method', '指數年方法') + ' (c_index_year_type_code)', tr('auto_calc_hint', '由算法自動計算'))}
                {displayRow('c_index_year_source_id', tr('index_year_source', '指數年來源') + ' (c_index_year_source_id)', tr('auto_calc_hint', '由算法自動計算'))}
                {displayRow('c_index_addr_id', tr('index_addr', '指數地址') + ' (c_index_addr_id)', tr('auto_calc_hint', '由算法自動計算'))}
                {displayRow('c_index_addr_type_code', tr('index_addr_type', '指數地址類型') + ' (c_index_addr_type_code)', tr('auto_calc_hint', '由算法自動計算'))}
                {textRow('c_death_age', tr('age_at_death', '享年') + ' (c_death_age)')}
                {codeRow('c_death_age_range', tr('range_label', '範圍') + ' (c_death_age_range)', 'range', 'c_range_code', ['c_range_code', 'c_approx', 'c_approx_chn'])}
            </>)}

            {/* 區塊四：在世年（始/終） */}
            {block(tr('block_floruit', '在世年（活動年）'), <>
                <div style={sectionLabel}>{tr('active_from', '在世始年')} (c_fl_earliest_year)</div>
                <EraTimeField values={buildEra(flEarly)} onChange={(p) => applyEra(flEarly, p)} dynastyCode={dynastyCode} showNotes />
                <div style={sectionLabel}>{tr('active_until', '在世終年')} (c_fl_latest_year)</div>
                <EraTimeField values={buildEra(flLate)} onChange={(p) => applyEra(flLate, p)} dynastyCode={dynastyCode} showNotes />
            </>)}

            {/* 區塊五：籍貫與戶籍（郡望/戶籍） */}
            {block(tr('block_origin', '籍貫與戶籍'), <>
                {codeRow('c_choronym_code', tr('choronym', '郡望') + ' (c_choronym_code)', 'choronym', 'c_choronym_code', ['c_choronym_code', 'c_choronym_chn', 'c_choronym'])}
                {codeRow('c_household_status_code', tr('household_field', '戶籍') + ' (c_household_status_code)', 'household', 'c_household_status_code', ['c_household_status_code', 'c_household_status_desc_chn', 'c_household_status_desc'])}
            </>)}

            {/* 區塊六：備註 */}
            {block(tr('block_notes', '備註'), (
                <div style={rowStyle}>
                    <label style={labelStyle}>{tr('notes_field', '備註')} (c_notes)</label>
                    <div style={fieldStyle}>
                        <textarea value={fields.c_notes ?? ''} disabled={!canEdit && !canPropose} rows={5}
                            onChange={(e) => set('c_notes', e.target.value)} style={{ ...inputStyle, height: 'auto' }} />
                    </div>
                </div>
            ))}

            {/* 修改說明：提案（提交建議）時附帶；legacy 對任何 active 使用者皆顯示，故凡可提案者（含可直接寫入者按「提交建議」）都顯示。 */}
            {(canEdit || canPropose) ? (
                <div style={rowStyle}>
                    <label style={labelStyle}>{tr('modification_note_label', '修改說明')}</label>
                    <div style={fieldStyle}>
                        <textarea value={comment} rows={3} onChange={(e) => setComment(e.target.value)}
                            placeholder={tr('modification_note_placeholder', '請說明修改原因')} style={{ ...inputStyle, height: 'auto' }} />
                        <span style={hintStyle}>{tr('modification_note_hint', '此說明將記錄於操作歷史中（提交建議時附帶）')}</span>
                    </div>
                </div>
            ) : null}

            {/* audit-fields 唯讀區（建檔/更新者，僅有值時顯示，對齊 legacy x-forms.audit-fields） */}
            {(fields.c_created_by || fields.c_created_date || fields.c_modified_by || fields.c_modified_date) ? (
                <div style={auditWrapStyle}>
                    <div style={sectionLabel}>{tr('create_or_modify', '建檔 / 更新資訊')}</div>
                    {displayRow('c_created_by', tr('audit_created_by', '建檔者') + ' (c_created_by)')}
                    {displayRow('c_created_date', tr('audit_created_date', '建檔日期') + ' (c_created_date)')}
                    {displayRow('c_modified_by', tr('audit_modified_by', '更新者') + ' (c_modified_by)')}
                    {displayRow('c_modified_date', tr('audit_modified_date', '更新日期') + ' (c_modified_date)')}
                </div>
            ) : null}

            <div style={submitRow}>
                {/* 主要動作靠左 */}
                {canEdit ? <button type="button" disabled={saving || !dirty} style={primaryBtn} onClick={() => void save('direct')}>{tr('save_directly', '直接保存')}</button> : null}
                {(canEdit || canPropose) ? <button type="button" disabled={saving || !dirty} style={infoBtn} onClick={() => void save('proposal')}>{tr('submit_proposal', '提交建議')}</button> : null}
                {/* 危險/另存動作靠右（對齊 legacy 的 float-right 分組） */}
                {(canEdit && deleteEndpoint) || duplicateCollateralUrl || saveasUrl ? (
                    <div style={btnGroupRight}>
                        {canEdit && deleteEndpoint ? <button type="button" disabled={deleting} style={dangerBtn} onClick={() => void doDelete()}>{tr('delete', '刪除')}</button> : null}
                        {duplicateCollateralUrl ? <a href={duplicateCollateralUrl} style={successLink}>{tr('duplicate_collateral', 'Duplicate Collateral Info')}</a> : null}
                        {saveasUrl ? <a href={saveasUrl} style={successLink}>{tr('duplicate_basic', 'Duplicate Basic Info')}</a> : null}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

const cardStyle: React.CSSProperties = { background: '#fff', border: '1px solid #dee2e6', borderRadius: 8, padding: 20 };
const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
// 區塊分組：淡邊框容器 + 藍系標題列（對齊系統藍 #255f93），視覺分隔各區（使用者要求）。
const blockStyle: React.CSSProperties = { border: '1px solid #e5e7eb', borderRadius: 8, padding: '14px 16px', marginBottom: 16, background: '#fcfdff' };
const blockHeaderStyle: React.CSSProperties = { fontSize: '0.9rem', fontWeight: 700, color: '#255f93', margin: '0 0 12px', paddingBottom: 8, borderBottom: '1px solid #e6eef6' };
const rowStyle: React.CSSProperties = { display: 'flex', gap: 12, alignItems: 'flex-start', marginBottom: 10 };
// 雙欄並排（對齊 legacy col-sm-6 × col-sm-6）：窄屏自動換行。
const twoColStyle: React.CSSProperties = { display: 'flex', gap: 24, flexWrap: 'wrap', marginBottom: 0 };
const colStyle: React.CSSProperties = { flex: '1 1 320px', minWidth: 0 };
const cellRowStyle: React.CSSProperties = { display: 'flex', gap: 8, alignItems: 'center', marginBottom: 10 };
// LABEL_W：雙欄格與單欄列共用同一 label 寬度，使所有 input 左緣對齊；
// 取 160 與其餘 12 個編輯器一致（跨頁統一）。
const LABEL_W = 160;
const cellLabelStyle: React.CSSProperties = { width: LABEL_W, flexShrink: 0, fontSize: '1rem', color: '#374151' };
// 唯讀派生 4-up grid（對齊 legacy col-xl-3）：label 置頂。
const derivedGridStyle: React.CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, marginBottom: 10 };
const derivedCellStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0 };
const derivedLabelStyle: React.CSSProperties = { fontSize: '0.8rem', color: '#374151' };
const pinyinBtnRowStyle: React.CSSProperties = { marginBottom: 10, paddingLeft: LABEL_W + 8 };
const labelStyle: React.CSSProperties = { width: LABEL_W, flexShrink: 0, fontSize: '1rem', paddingTop: 8, color: '#374151' };
const fieldStyle: React.CSSProperties = { flex: 1 };
const inputStyle: React.CSSProperties = { width: '100%', height: 36, padding: '0 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: '1rem', boxSizing: 'border-box' };
const readonlyStyle: React.CSSProperties = { background: '#f5f5f5', cursor: 'not-allowed' };
const hintStyle: React.CSSProperties = { display: 'block', marginTop: 2, fontSize: '0.78rem', color: '#6b7280' };
const sectionLabel: React.CSSProperties = { fontSize: '1rem', fontWeight: 600, color: '#374151', margin: '8px 0 4px' };
// 動作列：主要動作（保存/提交）靠左、危險/另存（刪除/Duplicate）靠右，對齊 legacy（非全堆左）。
const submitRow: React.CSSProperties = { display: 'flex', gap: 8, marginTop: 16, flexWrap: 'wrap', alignItems: 'center' };
const btnGroupRight: React.CSSProperties = { display: 'flex', gap: 8, flexWrap: 'wrap', marginLeft: 'auto' };
// 所有動作按鈕/連結統一尺寸（8px 14px + inline-flex 置中），避免 button 與 a 高度不一。
const actionBtnBase: React.CSSProperties = { padding: '8px 14px', borderRadius: 6, fontWeight: 700, cursor: 'pointer', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' };
const primaryBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid #255f93', background: '#255f93', color: '#fff' };
const infoBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid #0891b2', background: '#0891b2', color: '#fff' };
const okStyle: React.CSSProperties = { background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#065f46', padding: '8px 12px', borderRadius: 6, marginBottom: 10, fontSize: '1rem' };
const errStyle: React.CSSProperties = { background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', padding: '8px 12px', borderRadius: 6, marginBottom: 10, fontSize: '1rem' };
const warnStyle: React.CSSProperties = { background: '#fffbeb', border: '1px solid #fde68a', color: '#92400e', padding: '8px 12px', borderRadius: 6, marginBottom: 10, fontSize: '1rem' };
const auditWrapStyle: React.CSSProperties = { marginTop: 16, paddingTop: 12, borderTop: '1px solid #e5e7eb' };
const dangerBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid #dc3545', background: '#dc3545', color: '#fff' };
const successLink: React.CSSProperties = { ...actionBtnBase, border: '1px solid #28a745', background: '#28a745', color: '#fff' };
