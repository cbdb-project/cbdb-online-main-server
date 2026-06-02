# CBDB Online 中英文切換介面 — 實施計劃

**狀態：** 🚧 Phase 6 進行中（分支：`feature/i18n-zh-en-toggle`）  
**計劃日期：** 2026-06-01  
**Phase 0–5 完成：** 2026-06-02  
**Phase 6 啟動：** 2026-06-02  
**作者：** AI 協作草稿（王宏甦審閱）

---

## 目錄

1. [背景與目標](#1-背景與目標)
2. [現狀分析](#2-現狀分析)
3. [方案比較與選型](#3-方案比較與選型)
4. [推薦架構設計](#4-推薦架構設計)
5. [翻譯術語對照表](#5-翻譯術語對照表)
6. [待討論術語](#6-待討論術語)
7. [實施計劃（分 Phase）](#7-實施計劃分-phase)
8. [風險與注意事項](#8-風險與注意事項)
9. [Phase 6：Blade 視圖全面翻譯](#9-phase-6blade-視圖全面翻譯)

---

## 1. 背景與目標

CBDB Online 目前介面以繁體中文為主，但 CBDB（中國歷代人物傳記資料庫）的使用者群體跨越亞洲、北美、歐洲等地，許多非中文使用者需要操作這套系統。

**目標：**
- 在現有介面頂部或側欄新增一個語言切換按鈕（繁體中文 ⇄ English）
- 切換後頁面更新翻譯，不需完整重新整理頁面
- 使用者語言偏好以 session 儲存，跨頁面保持
- 翻譯術語以 FormLabels.xlsx 及兩份使用者手冊（2025/2026 版）為依據，確保術語與學術慣例一致

**範圍：**
- 前台介面（Blade 版面 + React/Inertia 頁面）
- 後端回傳的訊息（Flash 訊息、驗證錯誤等）
- **不包含** 資料庫內容本身（人物姓名、朝代名等歷史資料不做翻譯）

---

## 2. 現狀分析

### 2.1 現有 i18n 基礎

| 項目 | 現況 |
|------|------|
| `config/app.php` locale | 設為 `'en'`，但 UI 全是繁體中文（不一致） |
| `resources/lang/` | 只有 `en/`（auth、validation、pagination、passwords），無繁體中文資料夾 |
| Blade 字串 | 全部硬編碼繁體中文，估計 300–500 個字串 |
| React/Inertia 字串 | 全部硬編碼繁體中文，估計 150–200 個字串 |
| HandleInertiaRequests | 只共享 app version 和 auth user，無 locale 資訊 |
| Composer 套件 | 無任何 i18n 套件 |
| npm 套件 | 無任何 i18n 套件 |

### 2.2 字串分佈

**Blade 重點文件：**
- `resources/views/layouts/sidebar-v3.blade.php`（490 行，~60 個導覽文字）
- `resources/views/layouts/header-v3.blade.php`（87 行，少量 UI 文字）
- `resources/views/biogmains/`（50+ 個人物編輯表單）
- `resources/views/codes/`（代碼表管理）
- `resources/views/operations/`（操作記錄）

**React/Inertia 重點文件：**
- `resources/js/inertia/Layouts/AppShell.tsx`（系統標題）
- `resources/js/inertia/components/PersonBrowser/`（14 個組件）
- `resources/js/inertia/components/QueryPlayground/`（8 個組件）
- `resources/js/inertia/Pages/`（5 個頁面文件）

---

## 3. 方案比較與選型

### 3.1 後端方案

| 方案 | 維護狀態 | 優點 | 缺點 | 適合度 |
|------|----------|------|------|--------|
| **Laravel 內建 i18n**（`lang/` 目錄） | 框架核心，長期維護 | 零外部依賴；Blade `__()` 原生支援；JSON 格式可選 | 不含 URL 路由前綴；需手動傳遞給 React | **★★★★★** |
| `mcamara/laravel-localization` | 活躍（v2.4.0, 2026-03） | URL 前綴（`/en/...`）；SEO 友好；自動路由生成 | 無法使用 `route:cache`；Inertia 整合複雜；對純 session 切換反而過重 | **★★★** |
| `spatie/laravel-translation-loader` | 較少更新 | 資料庫驅動，可熱更新 | 學術工具，翻譯內容穩定，無需熱更新；增加 DB 查詢 | **★★** |

**後端決策：使用 Laravel 內建 i18n**，不引入外部套件。

### 3.2 前端方案（React/Inertia）

| 方案 | 維護狀態 | 優點 | 缺點 | 適合度 |
|------|----------|------|------|--------|
| **Inertia shared data + 自訂 Hook** | Inertia 核心功能 | 零依賴；Laravel 為單一真實來源；bundle 影響極小 | 需手動管理翻譯 Key 清單；無內建複數規則 | **★★★★★** |
| `react-i18next` + `i18next` | 活躍（v14.x, 2025） | 業界標準；完整複數/插值支援 | 翻譯與 Laravel 重複管理；bundle 增加 50–60 KB；對本專案偏重 | **★★★** |
| `eramitgupta/laravel-lang-sync-inertia` | 較新（2025 年）| 自動同步 Laravel lang 至 Inertia | 社群小；穩定性待驗證 | **★★★** |

**前端決策：Inertia shared data + 自訂 `useTranslation()` Hook**，不引入新 npm 套件。

### 3.3 Locale 切換機制

| 機制 | SEO | 實現複雜度 | Inertia 友好度 |
|------|-----|------------|----------------|
| URL 前綴（`/en/...`） | 最佳 | 高（需重新生成路由） | 一般（需全頁刷新） |
| Query String（`?locale=en`） | 普通 | 低 | 佳 |
| **Session + Cookie（推薦）** | 普通 | 低 | **最佳**（Inertia partial reload） |

**切換機制決策：Session + Cookie 儲存語言偏好；切換時以 `router.post('/locale')` 觸發一次 Inertia visit，Inertia v2 在 `back()` redirect 後自動更新所有 shared props（不需要額外 `router.reload()`）。**

---

## 4. 推薦架構設計

### 4.1 目錄結構

```
resources/lang/
├── en/
│   ├── common.php        # 通用按鈕、標籤、訊息
│   ├── nav.php           # 側欄與頂部導覽
│   ├── person.php        # 人物相關欄位與動作
│   ├── codes.php         # 代碼表頁面
│   ├── views.php         # 檢視表頁面
│   ├── query.php         # Query Playground
│   ├── operations.php    # 操作記錄/提案
│   ├── admin.php         # 管理員工具（低優先，見 §5.10）
│   ├── auth.php          # （已有，保留）
│   ├── validation.php    # （已有，保留）
│   └── pagination.php    # （已有，保留）
└── zh-TW/
    ├── common.php
    ├── nav.php
    ├── person.php
    ├── codes.php
    ├── views.php
    ├── query.php
    ├── operations.php
    ├── admin.php         # 低優先
    ├── auth.php
    ├── validation.php
    └── pagination.php
```

> 繁體中文使用 `zh-TW` 作為 locale key（符合 IETF BCP 47 標準）。

### 4.2 後端 Locale 流程

```
請求進入
  └── SetLocaleMiddleware
        ├── 讀取 Session key 'locale'
        ├── 若無，讀取 Cookie 'locale'
        ├── 若無，讀取 Accept-Language header（優先 zh-TW/zh → zh-TW；其他 → en）
        └── App::setLocale($locale)

切換請求（POST /locale）
  └── LocaleController@switch
        ├── 驗證 locale ∈ ['zh-TW', 'en']
        ├── Session::put('locale', $locale)
        ├── Cookie::queue('locale', $locale, 525600)  // 一年
        └── return redirect()->back(fallback: url('/'))
              // ⚠️ 只可回傳 redirect()->back()，不可用 Inertia::location()（全頁跳轉）。
              // 必須加 fallback 參數：若 Referer header 遺失（隱私設定、proxy 清除），
              //   back() 會靜默重導至 /；指定 fallback: url('/') 讓行為明確。
              // back() 對 Blade 表單 POST → 302 → 瀏覽器跟隨 redirect（正常重整）。
              // back() 對 Inertia XHR POST → 302 → Inertia v2 視為一次 visit，
              //   自動更新所有 shared props（含 locale、translations），保持 SPA 模式。
```

### 4.3 Inertia 共享資料

```php
// app/Http/Middleware/HandleInertiaRequests.php（修改後）
public function share(Request $request): array {
    return array_merge(parent::share($request), [
        'app' => ['version' => get_app_version()],
        'auth' => [...],
        'locale' => app()->getLocale(),
        // 注意：trans('group') 僅在 lang/{locale}/group.php 存在時才回傳 array。
        // 若檔案不存在，Laravel 回傳字串 'group'（fallback_locale 對整檔取用不生效）。
        // 因此 Phase 1 建立 zh-TW lang 檔之前，不可將 locale 切換為 zh-TW（見 §7 Phase 0 注意）。
        // inertia-laravel 以 array_merge（淺合併）合并 shared props 與頁面 props。
        // 若頁面 props 也有 'translations' key，會完全覆蓋此處的 shared translations，
        // 導致 common/nav/person/query 在該頁消失。
        // ⚠️ 解決方案：shared props 固定用 'translations' key；
        //   頁面特定群組（views, codes, operations）由控制器以獨立 key 傳入，
        //   例如 'page_translations'，前端以 useTranslation() 的 group 參數區分。
        'translations' => [
            'common'     => (array) trans('common'),
            'nav'        => (array) trans('nav'),
            'person'     => (array) trans('person'),
            'query'      => (array) trans('query'),
        ],
    ]);
}
```

> **重要：** `(array) trans('group')` 是防禦性型別轉換。PHP 行為：`(array) 'string'` 產生 `[0 => 'string']`（索引陣列），**不是空 dict**。但前端的 `translations?.[group]?.[key]` 用字串 key 查詢時，此陣列中不存在對應 key，會回傳 `undefined` 並退化為 key 本身——行為安全，不會崩潰。根本解法是**務必在 Phase 1 完成後才在生產環境啟用 zh-TW locale**（見 §7 Phase 0 注意事項）。

### 4.4 React 自訂 Hook

```typescript
// resources/js/inertia/hooks/useTranslation.ts
import { useMemo } from 'react';
import { usePage } from '@inertiajs/react';

type TranslationGroup = Record<string, string>;
type Translations = Record<string, TranslationGroup>;

interface PageProps {
    translations?: Translations;       // shared props（HandleInertiaRequests）
    page_translations?: Translations;  // 頁面特定 props（各控制器傳入）
}

export function useTranslation(group: string) {
    const { translations, page_translations } =
        usePage<PageProps>().props;
    // 優先查 page_translations（頁面特定），再查 shared translations。
    // 兩者使用不同 key 是為了避免 inertia-laravel 淺合併時 shared props 被覆蓋。
    const groupDict = page_translations?.[group] ?? translations?.[group];
    // useMemo 確保 t 函式引用穩定，避免下游 useCallback/useEffect dep array 每次失效。
    return useMemo(() => {
        return (key: string, replace?: Record<string, string>): string => {
            let value = groupDict?.[key] ?? key;
            if (replace) {
                Object.entries(replace).forEach(([k, v]) => {
                    value = value.replace(`:${k}`, v);
                });
            }
            return value;
        };
    }, [groupDict, group]);
}
```

**使用方式：**
```tsx
// 在 React 組件中
const t = useTranslation('person');
<button>{t('edit_basic_info')}</button>  // 輸出：Edit Basic Info / 編輯基本信息
```

### 4.5 語言切換按鈕位置

放置於 `header-v3.blade.php` 頂部右側，緊鄰現有深色模式切換按鈕：

```html
<!-- 語言切換按鈕（放在深色模式按鈕旁） -->
<li class="nav-item">
    <form action="{{ route('locale.switch') }}" method="POST" style="display:inline">
        @csrf
        <input type="hidden" name="locale"
               value="{{ app()->getLocale() === 'zh-TW' ? 'en' : 'zh-TW' }}">
        <button type="submit" class="nav-link btn btn-link" title="Switch language">
            {{ app()->getLocale() === 'zh-TW' ? 'EN' : '中文' }}
        </button>
    </form>
</li>
```

**⚠️ 兩個 layout 各自負責各自的切換按鈕：**
- Blade 頁面（dashboard、biogmains、codes、operations 等）→ 按鈕在 `header-v3.blade.php`
- Inertia 頁面（PersonBrowser、QueryPlayground、ViewTables、SearchByEntry）→ 按鈕在 `AppShell.tsx`（這些頁面完全不渲染 `header-v3.blade.php`，它們使用獨立的 React layout）

對 Inertia 頁面，切換按鈕放在 `AppShell.tsx` 並使用 Inertia router：

```tsx
// 在 AppShell.tsx 的語言切換（Inertia router 版）
import { router, usePage } from '@inertiajs/react';

const { locale } = usePage<{ locale: string }>().props;
const switchLocale = () => {
    const next = locale === 'zh-TW' ? 'en' : 'zh-TW';
    // LocaleController 回傳 back()（Inertia redirect）時，Inertia v2 會自動完成
    // 一次完整的 Inertia visit 並更新所有 shared props（含 translations、locale）。
    // 不需要在 onSuccess 再呼叫 router.reload()——那會造成第二次多餘請求。
    router.post('/locale', { locale: next }, { preserveScroll: true });
    // 注意：LocaleController 不可回傳 Inertia::location()（全頁跳轉），
    // 應回傳 back()，讓 Inertia 保持 SPA 模式並更新 props。
};
```

---

## 5. 翻譯術語對照表

> **依據：** FormLabels.xlsx（三語對照表）、英文版用戶手冊（2026-04-13）、中文版用戶手冊（2025 年張若溪譯、王宏甦校）
>
> **標記說明：** `[?]` = 有疑問，見第 6 節討論

### 5.1 系統與導覽（nav.php）

| Key | 繁體中文 | English | 備註 |
|-----|---------|---------|------|
| `app_title` | 中國歷代人物傳記資料庫 | China Biographical Database (CBDB) | 官方英文名 |
| `app_title_short` | CBDB | CBDB | — |
| `dashboard` | 系統總覽 | Dashboard | — |
| `person_editing` | 人物編輯 | Edit Person | — |
| `recent_operations` | 最近操作記錄 | Recent Changes | — |
| `recent_proposals` | 最近提案列表 | Recent Proposals | — |
| `pending_review` | 待審核 | Pending Review | — |
| `crowdsourcing_records` | 最近眾包錄入記錄 | Recent Crowdsourcing Records | ✓ |
| `all_tables` | 全部表格 | All Tables | — |
| `all_tables_home` | 全部表格首頁 | All Tables Home | — |
| `views` | 檢視表 | Views | — |
| `views_overview` | 檢視表總覽 | Views Overview | — |
| `views_overview_new` | 檢視表總覽（新版） | Views Overview (New) | — |
| `expert_tools` | 專家工具 | Expert Tools | — |
| `sql_query_playground` | SQL 查詢練習場 | SQL Query Playground | — |
| `admin_only_tools` | 非公開工具 | Restricted Tools | ✓（側欄無獨立標籤，僅子項顯示） |
| `person_browser` | 人物瀏覽 | Person Browser | — |
| `search_by_entry` | 按入仕查詢 | Search by Entry Type | ✓ |
| `historical_maps` | 歷史地圖 | Historical Maps | — |
| `admin_tools` | 管理員工具 | Admin Tools | — |
| `user_management` | 用戶管理 | User Management | — |
| `language_switch_to_en` | EN | EN | 按鈕標籤 |
| `language_switch_to_zh` | 中文 | 中文 | 按鈕標籤 |

### 5.2 通用按鈕與標籤（common.php）

| Key | 繁體中文 | English | 備註 |
|-----|---------|---------|------|
| `save` | 保存 | Save | — |
| `confirm` | 確定 | Confirm | — |
| `cancel` | 取消 | Cancel | — |
| `search` | 搜尋 | Search | — |
| `delete` | 刪除 | Delete | — |
| `add` | 新增 | Add | — |
| `edit` | 編輯 | Edit | — |
| `create` | 建立 | Create | — |
| `update` | 更新 | Update | — |
| `reset` | 重置 | Reset | — |
| `close` | 關閉 | Close | — |
| `back` | 返回 | Back | — |
| `submit` | 提交 | Submit | — |
| `loading` | 載入中… | Loading… | — |
| `no_data` | 無資料 | No data | — |
| `page_of` | 第 :current 頁，共 :total 頁 | Page :current of :total | — |
| `profile_settings` | 個人設定 | Profile Settings | — |
| `sign_out` | 登出 | Sign out | — |
| `dark_mode_toggle` | 切換深色模式 | Toggle dark mode | — |
| `home` | 首頁 | Home | — |
| `yes` | 是 | Yes | — |
| `no` | 否 | No | — |

### 5.3 人物資料欄位（person.php）

> 均源自 FormLabels.xlsx 的 `c_english`、`c_fanti` 欄位。

| Key | 繁體中文 | English | FormLabels 依據 |
|-----|---------|---------|----------------|
| `person` | 人物 | Person | — |
| `person_id` | 人物 ID | Person ID | — |
| `full_name` | 全名 | Full Name | `Full Name` |
| `name` | 姓名 | Name | — |
| `alt_name` | 別名 | Alternative Name | — |
| `alt_name_type` | 別名類型 | Alt. Name Type | — |
| `pinyin` | 拼音 | Pinyin | — |
| `gender` | 性別 | Gender | — |
| `female` | 女 | Female | `Female` |
| `male` | 男 | Male | — |
| `birth_year` | 生年 | Birth Year | `Born` |
| `death_year` | 卒年 | Death Year | `Died` |
| `age_at_death` | 享年 | Age at Death | `Age at Death` |
| `active_from` | 在世始年 | Active From | `Active from` |
| `active_until` | 在世終年 | Active Until | `Active until` |
| `index_year` | 指數年 | Index Year | `Index Year` |
| `dynasty` | 朝代 | Dynasty | `Dynasty` |
| `choronym` | 郡望 | Choronym | `Choronym` ✓ |
| `ethnicity` | 種族 | Ethnicity | `Ethnicity` |
| `source` | 出處 | Source | `Source` |
| `pages` | 頁碼/條目 | Pages | `Pages`（避免與 `Entry`=入仕 混淆，不寫斜線雙義） |
| `reign_year` | 年號 | Reign Year | `Reign Year` ✓ |
| `tribe` | 部、族 | Tribe | `Tribe` |
| `basic_info` | 基本資料 | Basic Information | — |
| `edit_basic_info` | 編輯基本信息 | Edit Basic Information | — |
| `delete_person` | 刪除人物 | Delete Person | — |
| `create_or_modify` | 建立 / 修改資訊 | Create / Modify Information | — |
| `search_person_placeholder` | 搜尋人物（ID / 姓名 / 拼音） | Search Person (ID / Name / Pinyin) | — |

### 5.4 人物關係欄位（person.php 續）

| Key | 繁體中文 | English | FormLabels 依據 |
|-----|---------|---------|----------------|
| `kinship` | 親屬關係 | Kinship | `Kinship` |
| `associations` | 社會關係 | Associations | `Associations` |
| `assoc_type_friendship` | 朋友 | Friendship | `Friendship` |
| `assoc_type_family` | 家庭 | Family | `Family` |
| `assoc_type_religion` | 宗教 | Religion | `Religion` |
| `assoc_type_finance` | 財務 | Finance | `Finance` |
| `assoc_type_medicine` | 醫療 | Medicine | `Medicine` |
| `assoc_type_military` | 軍事 | Military | `Military` |
| `assoc_type_scholarship` | 學術 | Scholarship | `Scholarship` |
| `assoc_type_teacher_student` | 師生關係 | Teacher-Student | `Teacher-Student` |
| `assoc_type_scholarly_affiliation` | 學術交往 | Scholarly Affiliation | `Scholarly Affiliation` |
| `assoc_type_scholarly_topic` | 主題相近 | Scholarly Topic | `Scholarly Topic` |
| `assoc_type_literary_artistic` | 文學藝術交往 | Literary/Artistic Affiliations | `Literary / Artistic Affiliations` |
| `assoc_type_politics` | 政治 | Politics | `Politics` |
| `assoc_type_equal_relation` | 官場平等關係 | Equal Relations (Official Sphere) | `Equal Relations` |
| `assoc_type_subordinate` | 官場下屬關係 | Subordinate Relation | `Subordinate Relation` |
| `assoc_type_superior` | 官場上司關係 | Superior Relation | `Superior Relation` |
| `assoc_type_recommendation` | 薦舉保任 | Recommendation/Sponsorship | `Recommendation / Sponsorship` |
| `postings` | 職官 | Postings | `Postings` |
| `status` | 社會區分 | Status | `Status` ✓ |
| `entry` | 入仕 | Entry | `Entry` ✓ |
| `addresses` | 地址 | Addresses | — |
| `texts` | 著作 | Texts | ✓ |
| `sources` | 來源 | Sources | — |
| `events` | 事件 | Events | — |
| `possessions` | 財產 | Possessions | — |

### 5.5 著作類型（person.php 續）

| Key | 繁體中文 | English | FormLabels 依據 |
|-----|---------|---------|----------------|
| `text_type_commemorative` | 記詠 | Commemorative Texts | `Commemorative Texts` |
| `text_type_epitaph` | 墓誌類 | Epitaphs | `Epitaphs` |
| `text_type_preface_postface` | 序跋 | Prefaces/Postfaces | `Prefaces / Postfaces` |
| `text_type_biography` | 傳記 | Biographical Texts | `Biographical Texts` |
| `text_type_explanatory` | 論說 | Explanatory Texts | `Explanatory Texts` |

### 5.6 代碼表（codes.php）

| Key | 繁體中文 | English | 備註 |
|-----|---------|---------|------|
| `codes_home` | 全部表格首頁 | Tables Home | — |
| `addr_belongs_data` | 地址從屬表 | Address Hierarchy Table | — |
| `addr_codes` | 地址編碼表 | Address Codes | — |
| `altname_codes` | 別名編碼表 | Alternative Name Codes | — |
| `appointment_codes` | 任命類型編碼表 | Appointment Type Codes | — |
| `office_codes` | 任官編碼表 | Office Codes | — |
| `social_institution_codes` | 社會機構編碼表 | Social Institution Codes | — |
| `text_codes` | 著作編碼表 | Text Codes | — |
| `text_instance_data` | 著作版本表 | Text Instance Data | — |

### 5.7 檢視表（views.php）

| Key | 繁體中文 | English | 備註 |
|-----|---------|---------|------|
| `view_altname_data` | 別名資料檢視 | Alternative Names View | — |
| `view_assoc_data` | 社會關係資料檢視 | Associations View | — |
| `view_biog_addr_data` | 人物地址資料檢視 | Person Addresses View | — |
| `view_biog_inst_addr_data` | 人物/社會機構/地址資料檢視 | Person / Institution / Address View | — |
| `view_biog_inst_data` | 人物社會機構資料檢視 | Person Social Institutions View | — |
| `view_biog_source_data` | 人物來源資料檢視 | Person Sources View | — |
| `view_biog_text_data` | 人物著作資料檢視 | Person Texts View | — |
| `view_entry_data` | 人物入仕資料檢視 | Person Entries View | — |
| `view_event_addr_data` | 人物事件地址檢視 | Person Event Addresses View | — |
| `view_events_data` | 人物事件資料檢視 | Person Events View | — |
| `view_kin_addr_data` | 人物親屬資料檢視 | Person Kinship View | — |
| `view_people_data` | 人物基本資料檢視 | Person Basic Data View | — |
| `view_people_addr_data` | 人物索引地址檢視 | Person Index Addresses View | — |
| `view_possessions_addr_data` | 人物財產地址檢視 | Person Possessions Addresses View | — |
| `view_possessions_data` | 人物財產資料檢視 | Person Possessions View | — |
| `view_posting_addr_data` | 任官地址資料檢視 | Posting Addresses View | — |
| `view_posting_office_data` | 任官職務資料檢視 | Posting Offices View | — |
| `view_status_data` | 人物身份資料檢視 | Person Status View | — |

### 5.8 Query Playground（query.php）

| Key | 繁體中文 | English | 備註 |
|-----|---------|---------|------|
| `query_playground_title` | SQL 查詢練習場 | SQL Query Playground | — |
| `mode_sql` | SQL 查詢 | SQL Query | — |
| `mode_qbe` | 查詢設計 (QBE) | Query Builder (QBE) | — |
| `nl_query_placeholder` | 用自然語言描述您想查詢的內容 | Describe what you want to query in natural language | — |
| `sql_editor_placeholder` | 輸入 SQL 查詢語句 | Enter SQL query | — |
| `querying` | 查詢中… | Querying… | — |
| `run_query` | ▶ 執行查詢 | ▶ Run Query | — |
| `no_results_yet` | 尚無查詢結果 | No results yet | — |
| `empty_results` | 查詢結果為空 | Query returned no results | — |
| `qbe_autosave` | QBE 草稿會自動儲存 | QBE draft is auto-saved | — |
| `account_inactive` | 您的帳號尚未啟用，無法使用此功能。 | Your account is not yet activated. | 後端 Flash 訊息 |
| `historical_qa_log_note` | 並會記錄查詢日誌 | Query logs will be recorded | — |

### 5.9 操作記錄與提案（operations.php）

| Key | 繁體中文 | English | 備註 |
|-----|---------|---------|------|
| `operations_title` | 操作記錄 | Operations | — |
| `proposals_title` | 提案列表 | Proposals | — |
| `pending_review` | 待審核 | Pending Review | — |
| `proposal_create` | 建立提案 | Create Proposal | — |
| `proposal_update` | 更新提案 | Update Proposal | — |
| `approved` | 已批准 | Approved | — |
| `rejected` | 已拒絕 | Rejected | — |
| `crowdsourcing` | 眾包錄入 | Crowdsourcing | [?] |

### 5.10 管理員工具（admin.php，低優先）

| Key | 繁體中文 | English | 備註 |
|-----|---------|---------|------|
| `batch_load_books` | 批次載入書籍標題 | Batch Load Book Titles | — |
| `batch_load_offices` | 批次載入官職 | Batch Load Offices | — |
| `wiki_maintenance` | 維基維護 | Wiki Maintenance | — |
| `table_maintenance` | 表格維護 | Table Maintenance | — |
| `audit_logs` | 稽核日誌 | Audit Logs | — |
| `ai_fill_logs` | AI 填充日誌 | AI Fill Logs | — |

---

## 6. 術語確認記錄

以下術語已於 2026-06-01 與王宏甦確認，可直接進入實施。

| # | 繁體中文 | 最終英文譯名 | 說明 |
|---|---------|------------|------|
| 6.1 | 郡望 | **Choronym** | 與 Harvard CBDB 英文版及用戶手冊一致 |
| 6.2 | 入仕 | **Entry** | 統一用 `Entry`，沿用 FormLabels；不在不同語境分用 Government Entry |
| 6.3 | 社會區分 | **Status** | 介面統一用 `Status`（FormLabels 標準） |
| 6.4 | 著作 | **Texts** | 與 Harvard CBDB 英文版一致；`Writings` 語義偏窄 |
| 6.5 | 眾包錄入 | **Crowdsourcing** | 系統功能用語 |
| 6.6 | 按入仕查詢 | **Search by Entry Type** | 避免與「記錄條目」的 Entry 混淆 |
| 6.7 | 非公開工具（側欄群組） | 無獨立標籤 | 維持現行：無父選單標籤，子項直接顯示 |
| 6.8 | 年號 | **Reign Year** | 沿用 FormLabels；表示「某年號下的年份」而非年號本身 |

---

## 7. 實施計劃（分 Phase）

### Phase 0：基礎設施（約 2 天）

- [ ] 修改 `config/app.php`：locale 改為 `zh-TW`（與現有 UI 一致），加入 `'available_locales' => ['zh-TW', 'en']`（自訂 key，需在 SetLocaleMiddleware 中以 `config('app.available_locales')` 讀取）
  - **⚠️ 注意：** locale 改為 zh-TW 後，若 `lang/zh-TW/` 目錄尚未建立，`trans('group')` 整檔取用會回傳字串而非 array（`fallback_locale` 僅對點記法 key 生效）。**Phase 0 部署後請立即暫停，等 Phase 1 完成 lang/zh-TW/ 全部檔案後再啟用 zh-TW locale。**
- [ ] 建立 `app/Http/Middleware/SetLocaleMiddleware.php`
- [ ] 在 `app/Http/Kernel.php` 的 `$middlewareGroups['web']` 中注冊 `SetLocaleMiddleware`（本專案使用 Laravel 8.x 舊式 bootstrap，**不支援** L11+ 的 `bootstrap/app.php →withMiddleware()` API）
  - **⚠️ 順序：** 必須放在 `StartSession::class` **之後**（否則 `Session::get('locale')` 讀到的是未啟動的 session，永遠回傳 null）。建議插在 `ShareErrorsFromSession::class` 之前。
- [ ] 建立 `app/Http/Controllers/LocaleController.php`（POST `/locale`）
- [ ] 新增路由：`Route::post('/locale', [LocaleController::class, 'switch'])->name('locale.switch')`
  - **⚠️ 位置：** 必須放在 `routes/web.php` 的 `auth` middleware group **之外**（讓訪客在登入頁也能切換語言）。放在 `Route::middleware(['auth'])->group(...)` 內部會導致 guest 收到 302 重導至 `/login`，切換靜默失敗。
  - **⚠️ CSRF：** Inertia 的 `router.post()` 自動帶 X-XSRF-TOKEN；Blade 的 `<form>` 帶 `@csrf`。**不要將 `/locale` 加入 `VerifyCsrfToken::$except`**，否則移除 CSRF 保護，允許任意站點靜默切換使用者語言偏好。
- [ ] 修改 `HandleInertiaRequests::share()` 加入 `locale` 和 `translations`
- [ ] 建立 `resources/js/inertia/hooks/useTranslation.ts`

### Phase 1：建立翻譯檔案（約 3 天）

- [ ] 建立 `resources/lang/zh-TW/` 目錄及所有模組文件（nav, common, person, codes, views, query, operations）
- [ ] 建立 `resources/lang/en/` 目錄及對應英文文件
- [ ] 翻譯來源以本文件第 5 節術語表為依據
- [ ] 建立 `resources/lang/zh-TW/auth.php`、`validation.php`、`pagination.php`、`passwords.php`（後者用於密碼重設流程 flash 訊息，Laravel PasswordBroker 內部呼叫 `trans('passwords.sent')` 等 key）

### Phase 1.5：頁面級翻譯 prop 規劃（含於 Phase 1，勿跳過）

`views`、`codes`、`operations`、`admin` 四個群組**不放入** `HandleInertiaRequests::share()`，原因是 inertia-laravel 用 `array_merge`（淺合併）合并 shared props 與頁面 props——若頁面 props 使用同一個 `'translations'` key，shared translations 會被完全覆蓋，`common`/`nav` 等關鍵群組消失。

**解決方案：頁面特定群組用獨立的 `page_translations` key 傳入：**

```php
// ViewTableController::appIndex
return Inertia::render('ViewTables/List', [
    'tables'           => $tables,
    'page_translations' => ['views' => (array) trans('views')],
]);
```

對應 `useTranslation` hook 同時支援兩個 prop key：

```typescript
export function useTranslation(group: string) {
    const { translations, page_translations } = usePage<{
        translations?: Translations;
        page_translations?: Translations;
    }>().props;
    // 先查 page_translations（頁面特定），再查 shared translations
    const groupDict = page_translations?.[group] ?? translations?.[group];
    return useMemo(() => {
        return (key: string, replace?: Record<string, string>): string => {
            let value = groupDict?.[key] ?? key;
            if (replace) {
                Object.entries(replace).forEach(([k, v]) => {
                    value = value.replace(`:${k}`, v);
                });
            }
            return value;
        };
    }, [groupDict, group]);
}
```

### Phase 2：Blade 模板提取（約 5 天）

依優先度排序：

| 優先 | 文件 | 估計字串數 |
|------|------|-----------|
| 高 | `sidebar-v3.blade.php` | ~60 |
| 高 | `header-v3.blade.php` | ~10 |
| 高 | `dashboard-v3.blade.php` | ~20 |
| 中 | `biogmains/*.blade.php` | ~150 |
| 中 | `codes/*.blade.php` | ~40 |
| 中 | `operations/*.blade.php` | ~30 |
| 低 | `admin/*.blade.php` | ~50 |
| 低 | `crowdsourcing/*.blade.php` | ~30 |

**操作方式：** 用正則搜尋所有 `>中文文字<` 及 `"中文文字"` 模式，逐一提取為 `{{ __('nav.dashboard') }}` 等形式。

### Phase 3：React/Inertia 提取（約 4 天）

依優先度排序：

| 優先 | 文件/組件 | 估計字串數 |
|------|---------|-----------|
| 高 | `Layouts/AppShell.tsx` | ~10 |
| 高 | `components/QueryPlayground/*.tsx` | ~30 |
| 高 | `components/PersonBrowser/PeopleSearchPanel.tsx` | ~10 |
| 高 | `components/PersonBrowser/BasicInfoView.tsx` | ~15 |
| 高 | `components/PersonBrowser/BrowserTabs.tsx` | ~10 |
| 中 | `components/PersonBrowser/*Tab.tsx`（9 個 Tab） | ~60 |
| 中 | `Pages/QueryPlayground/Index.tsx` | ~20 |
| 中 | `Pages/PersonBrowser/Index.tsx` | ~10 |
| 低 | `Pages/ViewTables/*.tsx` | ~20 |
| 低 | `Pages/SearchByEntry/Index.tsx` | ~10 |

**操作方式：** 在每個組件加 `const t = useTranslation('person')` 等，替換字串字面值。

### Phase 4：語言切換 UI（約 1 天）

- [ ] 在 `header-v3.blade.php` 加入語言切換按鈕（Blade form submit 版，用於非 Inertia 頁面）
- [ ] 在 `AppShell.tsx` 加入 `router.post('/locale')` 版（用於 Inertia 頁面，無全頁刷新）
- [ ] 確認切換後 URL 不變（僅 session/cookie 改變）

### Phase 5：測試與 QA（約 2 天）

- [ ] 每個 Blade 頁面分別以 `zh-TW` 和 `en` 瀏覽，確認無缺漏（missing key 顯示為 key 本身）
- [ ] 每個 Inertia 頁面切換語言後確認 translations props 更新，且為單次請求（不觸發第二次 reload）
- [ ] 新增 `LocaleControllerTest`：驗證 POST /locale 設定 session、cookie，以及無效 locale 被拒絕
- [ ] **⚠️ 測試環境 locale 固定：** 在 `tests/TestCase.php` 的 `setUp()` 中加入 `App::setLocale('en')`，避免現有測試因 zh-TW 成為預設 locale 而斷言失敗。Phase 1 建立 zh-TW 翻譯檔後，任何斷言英文 Flash 訊息或驗證錯誤字串的測試都需要複查。
- [ ] 全跑 `./vendor/bin/phpunit`，確認無 locale 相關回歸

---

## 8. 風險與注意事項

### 8.1 `config/app.php` locale 目前為 `'en'`

目前 `locale` 設定值與實際 UI 語言矛盾（UI 是繁體中文）。Phase 0 需要改為 `'zh-TW'`。

**fallback_locale 的作用範圍：** `fallback_locale = 'en'` 對**點記法 key 查詢**（如 `trans('auth.failed')`）生效——若 `lang/zh-TW/auth.php` 不存在，Laravel 會改讀 `lang/en/auth.php` 中的同名 key，正常回傳英文字串。但 §4.3 採用的**整檔取用**（`trans('common')` 無點記法）不在 fallback 機制的保護範圍內：若 `lang/zh-TW/common.php` 不存在，回傳值是字串 `'common'` 而非 array，導致 Inertia translations prop 崩潰。

**結論：** locale 改為 zh-TW 後，**必須立即建立 `lang/zh-TW/` 目錄的所有檔案（Phase 1）**，不可在兩個 Phase 之間上線。Phase 1 的建立順序：先建 common.php 和 nav.php（最先被 Inertia shared data 引用），再建其餘檔案。

### 8.2 Blade 頁面的翻譯傳遞

Blade 頁面透過 `__()` 直接呼叫，不需額外傳遞；但如果 Blade 內有動態生成的 JS 字串（`@json`、`<script>` 區塊），需要另外處理。

### 8.3 混合繁/簡中文問題

`FormLabels.xlsx` 的 `c_jianti`（簡體）與 `c_fanti`（繁體）部分有差異，本計劃以繁體中文（`zh-TW`）為準。後續若需支援簡體，可直接新增 `lang/zh-CN/` 目錄。

**Accept-Language 映射規則：** `SetLocaleMiddleware` 讀取 header 時，建議使用 `str_starts_with($lang, 'zh')` 將**所有** `zh-*` 子標籤（包含 `zh-CN`、`zh-Hans`、`zh-SG`）統一映射至 `zh-TW`。理由：目前僅有繁體中文版，讓簡體使用者看繁體中文優於看英文。若日後新增 `zh-CN` 語言支援，再細化此規則。

### 8.4 Flash 訊息與後端錯誤

`laracasts/flash` 套件的 Flash 訊息由後端控制器產生，這些字串也需要提取進翻譯文件。已在 Phase 1 提醒，但數量較小（~20 條），可在 Phase 2 一併處理。

### 8.5 前端重新編譯

修改 `resources/js/` 後需執行 `npm run build`。建議在 Phase 3 結束時統一執行，避免多次編譯。

### 8.6 `mcamara/laravel-localization` 放棄理由存檔

- 若未來需要 SEO 友好的 URL 路由（`/en/person/123`），可重新評估此套件（v2.4.0, 2026-03，仍活躍）。
- 目前 CBDB Online 為需登入的工具，SEO 優先度低，不值得增加此複雜度。

---

---

## 9. Phase 6：Blade 視圖全面翻譯

**背景：** Phase 2 只翻譯了主要版面（`layouts/`）與少數特定頁面（codes、operations、crowdsourcing、biogmains/banner）。2026-06-02 全面掃描後發現 **96 個 Blade 檔案、約 3,006 行**中文字串尚未翻譯，其中最重要的是 `biogmains/` 下的人物編輯表單（用戶最常接觸的介面）。

**翻譯策略：** 針對現有翻譯群組，優先重用 `person.php`、`common.php`；新增 `biogmains.php` 群組存放表單特有字串（標籤、提示、動作按鈕等）；`admin.php` 已有但仍需擴充；`auth.php` 已有但需補充登入流程字串。

---

### Phase 6A：biogmains 人物編輯表單（最高優先）

**範圍：** 54 個檔案、~1,314 行未翻譯字串。這是目前最常被用戶使用的編輯介面。

| 子目錄 | 檔案數 | 估計行數 | 說明 |
|--------|--------|---------|------|
| `basicinformation/` | 4 | ~120 | 基本資料 create/edit/index（`show.blade.php` 為 React/Inertia，跳過） |
| `addresses/` | 4 | ~71 | 地址資料 CRUD |
| `altname/` | 4 | ~69 | 別名資料 CRUD |
| `assoc/` | 4 | ~256 | 社會關係（含智能填充 UI） |
| `entries/` | 4 | ~79 | 入仕資料 CRUD |
| `events/` | 4 | ~56 | 事件管理 CRUD |
| `kinship/` | 4 | ~85 | 親屬關係 CRUD |
| `offices/` | 4 | ~310 | 官職管理（含智能填充 UI，字串最多） |
| `possession/` | 4 | ~66 | 財產記錄 CRUD |
| `socialinst/` | 4 | ~57 | 社交機構 CRUD |
| `sources/` | 4 | ~36 | 出處資料 CRUD |
| `statuses/` | 4 | ~131 | 社會區分（含智能識別） |
| `texts/` | 4 | ~55 | 著述資料 CRUD |
| `partials/` | 1 | ~18 | `list-order-toolbar.blade.php` |
| `defense.blade.php` | 1 | ~53 | 複合主鍵說明（開發者頁） |
| `history-button.blade.php` | 1 | ~1 | 歷史記錄按鈕 |

**新增翻譯群組：** `biogmains.php`（zh-TW + en），存放表單通用標籤（如：來源序號、修改說明、新增記錄、智能填充按鈕文字等）。表單欄位標籤儘量重用現有 `person.php` 的 key。

**JS 字串處理方式（已確認）：** `addresses/`、`altname/`、`assoc/`、`entries/`、`events/`、`kinship/`、`offices/`、`statuses/` 等目錄的 `<script>` 區塊均有中文 alert/confirm 字串，統一用 `{!! Js::from(__('biogmains.xxx')) !!}` 注入為 JS 變數（`Js::from()` 比 `@json()` 更清晰且已有 XSS 保護），再於 alert/confirm 中引用該變數。

**注意：** `components/forms/audit-fields.blade.php`、`components/forms/person-id-display.blade.php` 被多個 biogmains 表單 `@include`，**須在其他 6A 步驟之前翻譯（6A-0）**，否則 6A-2 之後仍顯示中文。

**步驟：**
- [ ] 6A-0：翻譯共用元件 `components/forms/audit-fields.blade.php` 與 `components/forms/person-id-display.blade.php`（biogmains 表單共用，須先行）
- [ ] 6A-1：建立 `resources/lang/zh-TW/biogmains.php` 與 `resources/lang/en/biogmains.php`
- [ ] 6A-2：翻譯 `basicinformation/` 三個檔案（create/edit/index；show.blade.php 跳過）
- [ ] 6A-3：翻譯 `addresses/`、`altname/`、`sources/` 表單（字串較少，合批）
- [ ] 6A-4：翻譯 `entries/`、`events/`、`kinship/`、`texts/`（合批）
- [ ] 6A-5：翻譯 `possession/`、`socialinst/`（合批）
- [ ] 6A-6：翻譯 `statuses/`（含智能識別 UI，JS 字串用 Js::from() 注入）
- [ ] 6A-7：翻譯 `assoc/`（最複雜，含智能填充 JS alert 字串，用 Js::from() 注入）
- [ ] 6A-8：翻譯 `offices/`（最多字串，智能填充 + 官職搜尋 UI，用 Js::from() 注入）
- [ ] 6A-9：翻譯 `partials/`、`history-button.blade.php`（`defense.blade.php` 為開發者頁，跳過）

---

### Phase 6B：使用者流程頁面（高優先）

| 檔案 | 估計行數 | 說明 |
|------|---------|------|
| `auth/login.blade.php` 等 4 個檔案 | ~65 | 登入、註冊、密碼重設 |
| `profile/*.blade.php` | ~249 | 個人設定、API 令牌管理 |
| `home.blade.php` | ~17 | 登入後首頁歡迎訊息 |
| `welcome.blade.php` | ~17 | 未登入歡迎頁 |
| `dashboard/*.blade.php` | ~23 | 儀表板統計 |

**注意：** `auth/` 部分字串（`__('auth.failed')` 等）已透過 `lang/zh-TW/auth.php` 翻譯；需補齊 Blade 模板中直接硬編碼的中文（表單 label、提示文字）。

**步驟：**
- [ ] 6B-1：翻譯 `auth/` 四個檔案（login、register、passwords、email）
- [ ] 6B-2：翻譯 `profile/`（含令牌管理的 JS confirm 對話框字串）
- [ ] 6B-3：翻譯 `home.blade.php`、`welcome.blade.php`、`dashboard/`

---

### Phase 6C：標準功能頁面（中優先）

| 目錄/檔案 | 估計行數 | 說明 |
|----------|---------|------|
| `components/*.blade.php`（5 個）+ `components/forms/`（2 個，已在 6A-0 完成） | ~139 | 共用元件（`forms/` 已提前至 6A-0） |
| `view/*.blade.php`（2 個） | ~58 | 舊版檢視表頁面 |
| `crowdsourcing/index.blade.php` | ~24 | 已部分翻譯，補齊剩餘 |
| `maps/index.blade.php` | ~54 | 歷史地圖頁面 |
| `query_playground/` | ~127 | 查詢練習場日誌頁面 |

**步驟：**
- [ ] 6C-1：翻譯 `components/` 剩餘 5 個共用 Blade 元件（`forms/` 兩個已在 6A-0 完成）
- [ ] 6C-2：翻譯 `view/`（舊版），補齊 `crowdsourcing/`
- [ ] 6C-3：翻譯 `maps/`、`query_playground/`

---

### Phase 6D：管理員與後台頁面（低優先）

| 目錄/檔案 | 估計行數 | 說明 |
|----------|---------|------|
| `admin/` 7 個檔案 | ~753 | 批次匯入、表維護、關係修復 |
| `cbdbapi/*.blade.php` | ~217 | 外部 API 搜尋結果頁 |
| `manage/` 4 個檔案 | ~229 | 用戶管理、人物合併（含 `_role-descriptions.blade.php`） |

**步驟：**
- [ ] 6D-1：翻譯 `manage/` 四個檔案（用戶管理 + 人物合併，有管理員會用）
- [ ] 6D-2：翻譯 `admin/` 七個頁面（批次工具，低頻使用）
- [ ] 6D-3：翻譯 `cbdbapi/`（API 結果顯示頁）

---

### Phase 6E：Phase 2 殘留補漏（中優先）

**背景：** Phase 2 已完成 `layouts/`、`codes/`、`operations/` 的部分翻譯，但仍有硬編碼中文殘留。

| 檔案 | 說明 |
|------|------|
| `codes/show.blade.php` | `修改`、`刪除`、`沒有資料`、`上一頁`/`下一頁`、`跳轉到 ID`/`跳轉` 按鈕 |
| `operations/index.blade.php` | 部分 badge 文字與說明字串 |
| `layouts/app.blade.php` | 檢查是否有殘留的硬編碼中文 |

**步驟：**
- [ ] 6E-1：補齊 `codes/show.blade.php`（`修改`、`刪除`、分頁導航按鈕、`沒有資料`）
- [ ] 6E-2：補齊 `operations/index.blade.php` 殘留字串
- [ ] 6E-3：掃描 `layouts/app.blade.php`，補齊任何殘留中文

---

### Phase 6 整體統計

| Phase | 優先 | 檔案數 | 估計行數 | 狀態 |
|-------|------|--------|---------|------|
| 6A biogmains 人物表單 | 最高 | 55 | ~1,314 | ✅ 完成（2026-06-02） |
| 6B 使用者流程頁面 | 高 | ~10 | ~371 | ✅ 完成（2026-06-02） |
| 6C 標準功能頁面 | 中 | ~8 | ~402 | ✅ 完成（2026-06-02） |
| 6D 管理員後台頁面 | 低 | ~15 | ~1,350 | ✅ 完成（2026-06-02） |
| 6E Phase 2 殘留補漏 | 中 | ~3 | ~30 | ✅ 完成（確認已無殘留，前 Phase 已補齊） |
| **合計** | | **~91** | **~3,450** | |

> 掃描日期：2026-06-02。行數為估計值（含部分需跳過的 PHP 動態變數）。6A 包含 components/forms/ 兩個共用元件（6A-0）。

### 預設語言（config/app.php）

```php
'locale'           => 'zh-TW',        // 系統預設語言為繁體中文
'available_locales' => ['zh-TW', 'en'], // 可用語言（自訂 key，由 SetLocaleMiddleware 讀取）
'fallback_locale'  => 'en',            // 當 zh-TW 某 key 缺失時，回退到英文
```

此設定於 Phase 0 完成。系統啟動時以繁體中文為預設；用戶首次訪問若瀏覽器語言偏好為 `zh-*`，維持繁體中文；若偏好為其他語言，SetLocaleMiddleware 會讀取並設為英文。

---

## 附錄：參考文件

| 文件 | 路徑 | 用途 |
|------|------|------|
| FormLabels.xlsx | `C:\Users\how612\Desktop\translation\FormLabels.xlsx` | 三語術語對照（英/簡/繁） |
| 英文用戶手冊 | `C:\Users\how612\Desktop\translation\Users Guide 20260413 draft.docx` | 英文術語與說明 |
| 中文用戶手冊 | `C:\Users\how612\Desktop\translation\【Chinese】User's Guide中文版 update_2025_Zhang_Ruoxi.docx` | 中文術語基準 |
| Harvard CBDB 英文界面 | https://cbdb.fas.harvard.edu/ | 界面術語參考 |
