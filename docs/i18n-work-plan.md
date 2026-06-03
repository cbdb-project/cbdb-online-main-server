# CBDB Online ä¸­è‹±æ–‡åˆ‡æ›ä»‹é¢ â€” å¯¦æ–½è¨ˆåŠƒ

**ç‹€æ…‹ï¼š** ðŸ”„ Phase 8 è¨ˆåŠƒä¸­ï¼ˆåˆ†æ”¯ï¼š`feature/i18n-phase8-react-components-operations`ï¼‰  
**è¨ˆåŠƒæ—¥æœŸï¼š** 2026-06-01  
**Phase 0â€“5 å®Œæˆï¼š** 2026-06-02  
**Phase 6 å®Œæˆï¼š** 2026-06-02  
**Phase 7 å®Œæˆï¼š** 2026-06-03  
**Phase 8 è¨ˆåŠƒï¼š** 2026-06-03  
**ä½œè€…ï¼š** AI å”ä½œè‰ç¨¿ï¼ˆçŽ‹å®ç”¦å¯©é–±ï¼‰

---

## ç›®éŒ„

1. [èƒŒæ™¯èˆ‡ç›®æ¨™](#1-èƒŒæ™¯èˆ‡ç›®æ¨™)
2. [ç¾ç‹€åˆ†æž](#2-ç¾ç‹€åˆ†æž)
3. [æ–¹æ¡ˆæ¯”è¼ƒèˆ‡é¸åž‹](#3-æ–¹æ¡ˆæ¯”è¼ƒèˆ‡é¸åž‹)
4. [æŽ¨è–¦æž¶æ§‹è¨­è¨ˆ](#4-æŽ¨è–¦æž¶æ§‹è¨­è¨ˆ)
5. [ç¿»è­¯è¡“èªžå°ç…§è¡¨](#5-ç¿»è­¯è¡“èªžå°ç…§è¡¨)
6. [å¾…è¨Žè«–è¡“èªž](#6-å¾…è¨Žè«–è¡“èªž)
7. [å¯¦æ–½è¨ˆåŠƒï¼ˆåˆ† Phaseï¼‰](#7-å¯¦æ–½è¨ˆåŠƒåˆ†-phase)
8. [é¢¨éšªèˆ‡æ³¨æ„äº‹é …](#8-é¢¨éšªèˆ‡æ³¨æ„äº‹é …)
9. [Phase 6ï¼šBlade è¦–åœ–å…¨é¢ç¿»è­¯](#9-phase-6blade-è¦–åœ–å…¨é¢ç¿»è­¯)

---

## 1. èƒŒæ™¯èˆ‡ç›®æ¨™

CBDB Online ç›®å‰ä»‹é¢ä»¥ç¹é«”ä¸­æ–‡ç‚ºä¸»ï¼Œä½† CBDBï¼ˆä¸­åœ‹æ­·ä»£äººç‰©å‚³è¨˜è³‡æ–™åº«ï¼‰çš„ä½¿ç”¨è€…ç¾¤é«”è·¨è¶Šäºžæ´²ã€åŒ—ç¾Žã€æ­æ´²ç­‰åœ°ï¼Œè¨±å¤šéžä¸­æ–‡ä½¿ç”¨è€…éœ€è¦æ“ä½œé€™å¥—ç³»çµ±ã€‚

**ç›®æ¨™ï¼š**
- åœ¨ç¾æœ‰ä»‹é¢é ‚éƒ¨æˆ–å´æ¬„æ–°å¢žä¸€å€‹èªžè¨€åˆ‡æ›æŒ‰éˆ•ï¼ˆç¹é«”ä¸­æ–‡ â‡„ Englishï¼‰
- åˆ‡æ›å¾Œé é¢æ›´æ–°ç¿»è­¯ï¼Œä¸éœ€å®Œæ•´é‡æ–°æ•´ç†é é¢
- ä½¿ç”¨è€…èªžè¨€åå¥½ä»¥ session å„²å­˜ï¼Œè·¨é é¢ä¿æŒ
- ç¿»è­¯è¡“èªžä»¥ FormLabels.xlsx åŠå…©ä»½ä½¿ç”¨è€…æ‰‹å†Šï¼ˆ2025/2026 ç‰ˆï¼‰ç‚ºä¾æ“šï¼Œç¢ºä¿è¡“èªžèˆ‡å­¸è¡“æ…£ä¾‹ä¸€è‡´

**ç¯„åœï¼š**
- å‰å°ä»‹é¢ï¼ˆBlade ç‰ˆé¢ + React/Inertia é é¢ï¼‰
- å¾Œç«¯å›žå‚³çš„è¨Šæ¯ï¼ˆFlash è¨Šæ¯ã€é©—è­‰éŒ¯èª¤ç­‰ï¼‰
- **ä¸åŒ…å«** è³‡æ–™åº«å…§å®¹æœ¬èº«ï¼ˆäººç‰©å§“åã€æœä»£åç­‰æ­·å²è³‡æ–™ä¸åšç¿»è­¯ï¼‰

---

## 2. ç¾ç‹€åˆ†æž

### 2.1 ç¾æœ‰ i18n åŸºç¤Ž

| é …ç›® | ç¾æ³ |
|------|------|
| `config/app.php` locale | è¨­ç‚º `'en'`ï¼Œä½† UI å…¨æ˜¯ç¹é«”ä¸­æ–‡ï¼ˆä¸ä¸€è‡´ï¼‰ |
| `resources/lang/` | åªæœ‰ `en/`ï¼ˆauthã€validationã€paginationã€passwordsï¼‰ï¼Œç„¡ç¹é«”ä¸­æ–‡è³‡æ–™å¤¾ |
| Blade å­—ä¸² | å…¨éƒ¨ç¡¬ç·¨ç¢¼ç¹é«”ä¸­æ–‡ï¼Œä¼°è¨ˆ 300â€“500 å€‹å­—ä¸² |
| React/Inertia å­—ä¸² | å…¨éƒ¨ç¡¬ç·¨ç¢¼ç¹é«”ä¸­æ–‡ï¼Œä¼°è¨ˆ 150â€“200 å€‹å­—ä¸² |
| HandleInertiaRequests | åªå…±äº« app version å’Œ auth userï¼Œç„¡ locale è³‡è¨Š |
| Composer å¥—ä»¶ | ç„¡ä»»ä½• i18n å¥—ä»¶ |
| npm å¥—ä»¶ | ç„¡ä»»ä½• i18n å¥—ä»¶ |

### 2.2 å­—ä¸²åˆ†ä½ˆ

**Blade é‡é»žæ–‡ä»¶ï¼š**
- `resources/views/layouts/sidebar-v3.blade.php`ï¼ˆ490 è¡Œï¼Œ~60 å€‹å°Žè¦½æ–‡å­—ï¼‰
- `resources/views/layouts/header-v3.blade.php`ï¼ˆ87 è¡Œï¼Œå°‘é‡ UI æ–‡å­—ï¼‰
- `resources/views/biogmains/`ï¼ˆ50+ å€‹äººç‰©ç·¨è¼¯è¡¨å–®ï¼‰
- `resources/views/codes/`ï¼ˆä»£ç¢¼è¡¨ç®¡ç†ï¼‰
- `resources/views/operations/`ï¼ˆæ“ä½œè¨˜éŒ„ï¼‰

**React/Inertia é‡é»žæ–‡ä»¶ï¼š**
- `resources/js/inertia/Layouts/AppShell.tsx`ï¼ˆç³»çµ±æ¨™é¡Œï¼‰
- `resources/js/inertia/components/PersonBrowser/`ï¼ˆ14 å€‹çµ„ä»¶ï¼‰
- `resources/js/inertia/components/QueryPlayground/`ï¼ˆ8 å€‹çµ„ä»¶ï¼‰
- `resources/js/inertia/Pages/`ï¼ˆ5 å€‹é é¢æ–‡ä»¶ï¼‰

---

## 3. æ–¹æ¡ˆæ¯”è¼ƒèˆ‡é¸åž‹

### 3.1 å¾Œç«¯æ–¹æ¡ˆ

| æ–¹æ¡ˆ | ç¶­è­·ç‹€æ…‹ | å„ªé»ž | ç¼ºé»ž | é©åˆåº¦ |
|------|----------|------|------|--------|
| **Laravel å…§å»º i18n**ï¼ˆ`lang/` ç›®éŒ„ï¼‰ | æ¡†æž¶æ ¸å¿ƒï¼Œé•·æœŸç¶­è­· | é›¶å¤–éƒ¨ä¾è³´ï¼›Blade `__()` åŽŸç”Ÿæ”¯æ´ï¼›JSON æ ¼å¼å¯é¸ | ä¸å« URL è·¯ç”±å‰ç¶´ï¼›éœ€æ‰‹å‹•å‚³éžçµ¦ React | **â˜…â˜…â˜…â˜…â˜…** |
| `mcamara/laravel-localization` | æ´»èºï¼ˆv2.4.0, 2026-03ï¼‰ | URL å‰ç¶´ï¼ˆ`/en/...`ï¼‰ï¼›SEO å‹å¥½ï¼›è‡ªå‹•è·¯ç”±ç”Ÿæˆ | ç„¡æ³•ä½¿ç”¨ `route:cache`ï¼›Inertia æ•´åˆè¤‡é›œï¼›å°ç´” session åˆ‡æ›åè€ŒéŽé‡ | **â˜…â˜…â˜…** |
| `spatie/laravel-translation-loader` | è¼ƒå°‘æ›´æ–° | è³‡æ–™åº«é©…å‹•ï¼Œå¯ç†±æ›´æ–° | å­¸è¡“å·¥å…·ï¼Œç¿»è­¯å…§å®¹ç©©å®šï¼Œç„¡éœ€ç†±æ›´æ–°ï¼›å¢žåŠ  DB æŸ¥è©¢ | **â˜…â˜…** |

**å¾Œç«¯æ±ºç­–ï¼šä½¿ç”¨ Laravel å…§å»º i18n**ï¼Œä¸å¼•å…¥å¤–éƒ¨å¥—ä»¶ã€‚

### 3.2 å‰ç«¯æ–¹æ¡ˆï¼ˆReact/Inertiaï¼‰

| æ–¹æ¡ˆ | ç¶­è­·ç‹€æ…‹ | å„ªé»ž | ç¼ºé»ž | é©åˆåº¦ |
|------|----------|------|------|--------|
| **Inertia shared data + è‡ªè¨‚ Hook** | Inertia æ ¸å¿ƒåŠŸèƒ½ | é›¶ä¾è³´ï¼›Laravel ç‚ºå–®ä¸€çœŸå¯¦ä¾†æºï¼›bundle å½±éŸ¿æ¥µå° | éœ€æ‰‹å‹•ç®¡ç†ç¿»è­¯ Key æ¸…å–®ï¼›ç„¡å…§å»ºè¤‡æ•¸è¦å‰‡ | **â˜…â˜…â˜…â˜…â˜…** |
| `react-i18next` + `i18next` | æ´»èºï¼ˆv14.x, 2025ï¼‰ | æ¥­ç•Œæ¨™æº–ï¼›å®Œæ•´è¤‡æ•¸/æ’å€¼æ”¯æ´ | ç¿»è­¯èˆ‡ Laravel é‡è¤‡ç®¡ç†ï¼›bundle å¢žåŠ  50â€“60 KBï¼›å°æœ¬å°ˆæ¡ˆåé‡ | **â˜…â˜…â˜…** |
| `eramitgupta/laravel-lang-sync-inertia` | è¼ƒæ–°ï¼ˆ2025 å¹´ï¼‰| è‡ªå‹•åŒæ­¥ Laravel lang è‡³ Inertia | ç¤¾ç¾¤å°ï¼›ç©©å®šæ€§å¾…é©—è­‰ | **â˜…â˜…â˜…** |

**å‰ç«¯æ±ºç­–ï¼šInertia shared data + è‡ªè¨‚ `useTranslation()` Hook**ï¼Œä¸å¼•å…¥æ–° npm å¥—ä»¶ã€‚

### 3.3 Locale åˆ‡æ›æ©Ÿåˆ¶

| æ©Ÿåˆ¶ | SEO | å¯¦ç¾è¤‡é›œåº¦ | Inertia å‹å¥½åº¦ |
|------|-----|------------|----------------|
| URL å‰ç¶´ï¼ˆ`/en/...`ï¼‰ | æœ€ä½³ | é«˜ï¼ˆéœ€é‡æ–°ç”Ÿæˆè·¯ç”±ï¼‰ | ä¸€èˆ¬ï¼ˆéœ€å…¨é åˆ·æ–°ï¼‰ |
| Query Stringï¼ˆ`?locale=en`ï¼‰ | æ™®é€š | ä½Ž | ä½³ |
| **Session + Cookieï¼ˆæŽ¨è–¦ï¼‰** | æ™®é€š | ä½Ž | **æœ€ä½³**ï¼ˆInertia partial reloadï¼‰ |

**åˆ‡æ›æ©Ÿåˆ¶æ±ºç­–ï¼šSession + Cookie å„²å­˜èªžè¨€åå¥½ï¼›åˆ‡æ›æ™‚ä»¥ `router.post('/locale')` è§¸ç™¼ä¸€æ¬¡ Inertia visitï¼ŒInertia v2 åœ¨ `back()` redirect å¾Œè‡ªå‹•æ›´æ–°æ‰€æœ‰ shared propsï¼ˆä¸éœ€è¦é¡å¤– `router.reload()`ï¼‰ã€‚**

---

## 4. æŽ¨è–¦æž¶æ§‹è¨­è¨ˆ

### 4.1 ç›®éŒ„çµæ§‹

```
resources/lang/
â”œâ”€â”€ en/
â”‚   â”œâ”€â”€ common.php        # é€šç”¨æŒ‰éˆ•ã€æ¨™ç±¤ã€è¨Šæ¯
â”‚   â”œâ”€â”€ nav.php           # å´æ¬„èˆ‡é ‚éƒ¨å°Žè¦½
â”‚   â”œâ”€â”€ person.php        # äººç‰©ç›¸é—œæ¬„ä½èˆ‡å‹•ä½œ
â”‚   â”œâ”€â”€ codes.php         # ä»£ç¢¼è¡¨é é¢
â”‚   â”œâ”€â”€ views.php         # æª¢è¦–è¡¨é é¢
â”‚   â”œâ”€â”€ query.php         # Query Playground
â”‚   â”œâ”€â”€ operations.php    # æ“ä½œè¨˜éŒ„/ææ¡ˆ
â”‚   â”œâ”€â”€ admin.php         # ç®¡ç†å“¡å·¥å…·ï¼ˆä½Žå„ªå…ˆï¼Œè¦‹ Â§5.10ï¼‰
â”‚   â”œâ”€â”€ auth.php          # ï¼ˆå·²æœ‰ï¼Œä¿ç•™ï¼‰
â”‚   â”œâ”€â”€ validation.php    # ï¼ˆå·²æœ‰ï¼Œä¿ç•™ï¼‰
â”‚   â””â”€â”€ pagination.php    # ï¼ˆå·²æœ‰ï¼Œä¿ç•™ï¼‰
â””â”€â”€ zh-TW/
    â”œâ”€â”€ common.php
    â”œâ”€â”€ nav.php
    â”œâ”€â”€ person.php
    â”œâ”€â”€ codes.php
    â”œâ”€â”€ views.php
    â”œâ”€â”€ query.php
    â”œâ”€â”€ operations.php
    â”œâ”€â”€ admin.php         # ä½Žå„ªå…ˆ
    â”œâ”€â”€ auth.php
    â”œâ”€â”€ validation.php
    â””â”€â”€ pagination.php
```

> ç¹é«”ä¸­æ–‡ä½¿ç”¨ `zh-TW` ä½œç‚º locale keyï¼ˆç¬¦åˆ IETF BCP 47 æ¨™æº–ï¼‰ã€‚

### 4.2 å¾Œç«¯ Locale æµç¨‹

```
è«‹æ±‚é€²å…¥
  â””â”€â”€ SetLocaleMiddleware
        â”œâ”€â”€ è®€å– Session key 'locale'
        â”œâ”€â”€ è‹¥ç„¡ï¼Œè®€å– Cookie 'locale'
        â”œâ”€â”€ è‹¥ç„¡ï¼Œè®€å– Accept-Language headerï¼ˆå„ªå…ˆ zh-TW/zh â†’ zh-TWï¼›å…¶ä»– â†’ enï¼‰
        â””â”€â”€ App::setLocale($locale)

åˆ‡æ›è«‹æ±‚ï¼ˆPOST /localeï¼‰
  â””â”€â”€ LocaleController@switch
        â”œâ”€â”€ é©—è­‰ locale âˆˆ ['zh-TW', 'en']
        â”œâ”€â”€ Session::put('locale', $locale)
        â”œâ”€â”€ Cookie::queue('locale', $locale, 525600)  // ä¸€å¹´
        â””â”€â”€ return redirect()->back(fallback: url('/'))
              // âš ï¸ åªå¯å›žå‚³ redirect()->back()ï¼Œä¸å¯ç”¨ Inertia::location()ï¼ˆå…¨é è·³è½‰ï¼‰ã€‚
              // å¿…é ˆåŠ  fallback åƒæ•¸ï¼šè‹¥ Referer header éºå¤±ï¼ˆéš±ç§è¨­å®šã€proxy æ¸…é™¤ï¼‰ï¼Œ
              //   back() æœƒéœé»˜é‡å°Žè‡³ /ï¼›æŒ‡å®š fallback: url('/') è®“è¡Œç‚ºæ˜Žç¢ºã€‚
              // back() å° Blade è¡¨å–® POST â†’ 302 â†’ ç€è¦½å™¨è·Ÿéš¨ redirectï¼ˆæ­£å¸¸é‡æ•´ï¼‰ã€‚
              // back() å° Inertia XHR POST â†’ 302 â†’ Inertia v2 è¦–ç‚ºä¸€æ¬¡ visitï¼Œ
              //   è‡ªå‹•æ›´æ–°æ‰€æœ‰ shared propsï¼ˆå« localeã€translationsï¼‰ï¼Œä¿æŒ SPA æ¨¡å¼ã€‚
```

### 4.3 Inertia å…±äº«è³‡æ–™

```php
// app/Http/Middleware/HandleInertiaRequests.phpï¼ˆä¿®æ”¹å¾Œï¼‰
public function share(Request $request): array {
    return array_merge(parent::share($request), [
        'app' => ['version' => get_app_version()],
        'auth' => [...],
        'locale' => app()->getLocale(),
        // æ³¨æ„ï¼štrans('group') åƒ…åœ¨ lang/{locale}/group.php å­˜åœ¨æ™‚æ‰å›žå‚³ arrayã€‚
        // è‹¥æª”æ¡ˆä¸å­˜åœ¨ï¼ŒLaravel å›žå‚³å­—ä¸² 'group'ï¼ˆfallback_locale å°æ•´æª”å–ç”¨ä¸ç”Ÿæ•ˆï¼‰ã€‚
        // å› æ­¤ Phase 1 å»ºç«‹ zh-TW lang æª”ä¹‹å‰ï¼Œä¸å¯å°‡ locale åˆ‡æ›ç‚º zh-TWï¼ˆè¦‹ Â§7 Phase 0 æ³¨æ„ï¼‰ã€‚
        // inertia-laravel ä»¥ array_mergeï¼ˆæ·ºåˆä½µï¼‰åˆå¹¶ shared props èˆ‡é é¢ propsã€‚
        // è‹¥é é¢ props ä¹Ÿæœ‰ 'translations' keyï¼Œæœƒå®Œå…¨è¦†è“‹æ­¤è™•çš„ shared translationsï¼Œ
        // å°Žè‡´ common/nav/person/query åœ¨è©²é æ¶ˆå¤±ã€‚
        // âš ï¸ è§£æ±ºæ–¹æ¡ˆï¼šshared props å›ºå®šç”¨ 'translations' keyï¼›
        //   é é¢ç‰¹å®šç¾¤çµ„ï¼ˆviews, codes, operationsï¼‰ç”±æŽ§åˆ¶å™¨ä»¥ç¨ç«‹ key å‚³å…¥ï¼Œ
        //   ä¾‹å¦‚ 'page_translations'ï¼Œå‰ç«¯ä»¥ useTranslation() çš„ group åƒæ•¸å€åˆ†ã€‚
        'translations' => [
            'common'     => (array) trans('common'),
            'nav'        => (array) trans('nav'),
            'person'     => (array) trans('person'),
            'query'      => (array) trans('query'),
        ],
    ]);
}
```

> **é‡è¦ï¼š** `(array) trans('group')` æ˜¯é˜²ç¦¦æ€§åž‹åˆ¥è½‰æ›ã€‚PHP è¡Œç‚ºï¼š`(array) 'string'` ç”¢ç”Ÿ `[0 => 'string']`ï¼ˆç´¢å¼•é™£åˆ—ï¼‰ï¼Œ**ä¸æ˜¯ç©º dict**ã€‚ä½†å‰ç«¯çš„ `translations?.[group]?.[key]` ç”¨å­—ä¸² key æŸ¥è©¢æ™‚ï¼Œæ­¤é™£åˆ—ä¸­ä¸å­˜åœ¨å°æ‡‰ keyï¼Œæœƒå›žå‚³ `undefined` ä¸¦é€€åŒ–ç‚º key æœ¬èº«â€”â€”è¡Œç‚ºå®‰å…¨ï¼Œä¸æœƒå´©æ½°ã€‚æ ¹æœ¬è§£æ³•æ˜¯**å‹™å¿…åœ¨ Phase 1 å®Œæˆå¾Œæ‰åœ¨ç”Ÿç”¢ç’°å¢ƒå•Ÿç”¨ zh-TW locale**ï¼ˆè¦‹ Â§7 Phase 0 æ³¨æ„äº‹é …ï¼‰ã€‚

### 4.4 React è‡ªè¨‚ Hook

```typescript
// resources/js/inertia/hooks/useTranslation.ts
import { useMemo } from 'react';
import { usePage } from '@inertiajs/react';

type TranslationGroup = Record<string, string>;
type Translations = Record<string, TranslationGroup>;

interface PageProps {
    translations?: Translations;       // shared propsï¼ˆHandleInertiaRequestsï¼‰
    page_translations?: Translations;  // é é¢ç‰¹å®š propsï¼ˆå„æŽ§åˆ¶å™¨å‚³å…¥ï¼‰
}

export function useTranslation(group: string) {
    const { translations, page_translations } =
        usePage<PageProps>().props;
    // å„ªå…ˆæŸ¥ page_translationsï¼ˆé é¢ç‰¹å®šï¼‰ï¼Œå†æŸ¥ shared translationsã€‚
    // å…©è€…ä½¿ç”¨ä¸åŒ key æ˜¯ç‚ºäº†é¿å… inertia-laravel æ·ºåˆä½µæ™‚ shared props è¢«è¦†è“‹ã€‚
    const groupDict = page_translations?.[group] ?? translations?.[group];
    // useMemo ç¢ºä¿ t å‡½å¼å¼•ç”¨ç©©å®šï¼Œé¿å…ä¸‹æ¸¸ useCallback/useEffect dep array æ¯æ¬¡å¤±æ•ˆã€‚
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

**ä½¿ç”¨æ–¹å¼ï¼š**
```tsx
// åœ¨ React çµ„ä»¶ä¸­
const t = useTranslation('person');
<button>{t('edit_basic_info')}</button>  // è¼¸å‡ºï¼šEdit Basic Info / ç·¨è¼¯åŸºæœ¬ä¿¡æ¯
```

### 4.5 èªžè¨€åˆ‡æ›æŒ‰éˆ•ä½ç½®

æ”¾ç½®æ–¼ `header-v3.blade.php` é ‚éƒ¨å³å´ï¼Œç·Šé„°ç¾æœ‰æ·±è‰²æ¨¡å¼åˆ‡æ›æŒ‰éˆ•ï¼š

```html
<!-- èªžè¨€åˆ‡æ›æŒ‰éˆ•ï¼ˆæ”¾åœ¨æ·±è‰²æ¨¡å¼æŒ‰éˆ•æ—ï¼‰ -->
<li class="nav-item">
    <form action="{{ route('locale.switch') }}" method="POST" style="display:inline">
        @csrf
        <input type="hidden" name="locale"
               value="{{ app()->getLocale() === 'zh-TW' ? 'en' : 'zh-TW' }}">
        <button type="submit" class="nav-link btn btn-link" title="Switch language">
            {{ app()->getLocale() === 'zh-TW' ? 'EN' : 'ä¸­æ–‡' }}
        </button>
    </form>
</li>
```

**âš ï¸ å…©å€‹ layout å„è‡ªè² è²¬å„è‡ªçš„åˆ‡æ›æŒ‰éˆ•ï¼š**
- Blade é é¢ï¼ˆdashboardã€biogmainsã€codesã€operations ç­‰ï¼‰â†’ æŒ‰éˆ•åœ¨ `header-v3.blade.php`
- Inertia é é¢ï¼ˆPersonBrowserã€QueryPlaygroundã€ViewTablesã€SearchByEntryï¼‰â†’ æŒ‰éˆ•åœ¨ `AppShell.tsx`ï¼ˆé€™äº›é é¢å®Œå…¨ä¸æ¸²æŸ“ `header-v3.blade.php`ï¼Œå®ƒå€‘ä½¿ç”¨ç¨ç«‹çš„ React layoutï¼‰

å° Inertia é é¢ï¼Œåˆ‡æ›æŒ‰éˆ•æ”¾åœ¨ `AppShell.tsx` ä¸¦ä½¿ç”¨ Inertia routerï¼š

```tsx
// åœ¨ AppShell.tsx çš„èªžè¨€åˆ‡æ›ï¼ˆInertia router ç‰ˆï¼‰
import { router, usePage } from '@inertiajs/react';

const { locale } = usePage<{ locale: string }>().props;
const switchLocale = () => {
    const next = locale === 'zh-TW' ? 'en' : 'zh-TW';
    // LocaleController å›žå‚³ back()ï¼ˆInertia redirectï¼‰æ™‚ï¼ŒInertia v2 æœƒè‡ªå‹•å®Œæˆ
    // ä¸€æ¬¡å®Œæ•´çš„ Inertia visit ä¸¦æ›´æ–°æ‰€æœ‰ shared propsï¼ˆå« translationsã€localeï¼‰ã€‚
    // ä¸éœ€è¦åœ¨ onSuccess å†å‘¼å« router.reload()â€”â€”é‚£æœƒé€ æˆç¬¬äºŒæ¬¡å¤šé¤˜è«‹æ±‚ã€‚
    router.post('/locale', { locale: next }, { preserveScroll: true });
    // æ³¨æ„ï¼šLocaleController ä¸å¯å›žå‚³ Inertia::location()ï¼ˆå…¨é è·³è½‰ï¼‰ï¼Œ
    // æ‡‰å›žå‚³ back()ï¼Œè®“ Inertia ä¿æŒ SPA æ¨¡å¼ä¸¦æ›´æ–° propsã€‚
};
```

---

## 5. ç¿»è­¯è¡“èªžå°ç…§è¡¨

> **ä¾æ“šï¼š** FormLabels.xlsxï¼ˆä¸‰èªžå°ç…§è¡¨ï¼‰ã€è‹±æ–‡ç‰ˆç”¨æˆ¶æ‰‹å†Šï¼ˆ2026-04-13ï¼‰ã€ä¸­æ–‡ç‰ˆç”¨æˆ¶æ‰‹å†Šï¼ˆ2025 å¹´å¼µè‹¥æºªè­¯ã€çŽ‹å®ç”¦æ ¡ï¼‰
>
> **æ¨™è¨˜èªªæ˜Žï¼š** `[?]` = æœ‰ç–‘å•ï¼Œè¦‹ç¬¬ 6 ç¯€è¨Žè«–

### 5.1 ç³»çµ±èˆ‡å°Žè¦½ï¼ˆnav.phpï¼‰

| Key | ç¹é«”ä¸­æ–‡ | English | å‚™è¨» |
|-----|---------|---------|------|
| `app_title` | ä¸­åœ‹æ­·ä»£äººç‰©å‚³è¨˜è³‡æ–™åº« | China Biographical Database (CBDB) | å®˜æ–¹è‹±æ–‡å |
| `app_title_short` | CBDB | CBDB | â€” |
| `dashboard` | ç³»çµ±ç¸½è¦½ | Dashboard | â€” |
| `person_editing` | äººç‰©ç·¨è¼¯ | Edit Person | â€” |
| `recent_operations` | æœ€è¿‘æ“ä½œè¨˜éŒ„ | Recent Changes | â€” |
| `recent_proposals` | æœ€è¿‘ææ¡ˆåˆ—è¡¨ | Recent Proposals | â€” |
| `pending_review` | å¾…å¯©æ ¸ | Pending Review | â€” |
| `crowdsourcing_records` | æœ€è¿‘çœ¾åŒ…éŒ„å…¥è¨˜éŒ„ | Recent Crowdsourcing Records | âœ“ |
| `all_tables` | å…¨éƒ¨è¡¨æ ¼ | All Tables | â€” |
| `all_tables_home` | å…¨éƒ¨è¡¨æ ¼é¦–é  | All Tables Home | â€” |
| `views` | æª¢è¦–è¡¨ | Views | â€” |
| `views_overview` | æª¢è¦–è¡¨ç¸½è¦½ | Views Overview | â€” |
| `views_overview_new` | æª¢è¦–è¡¨ç¸½è¦½ï¼ˆæ–°ç‰ˆï¼‰ | Views Overview (New) | â€” |
| `expert_tools` | å°ˆå®¶å·¥å…· | Expert Tools | â€” |
| `sql_query_playground` | SQL æŸ¥è©¢ç·´ç¿’å ´ | SQL Query Playground | â€” |
| `admin_only_tools` | éžå…¬é–‹å·¥å…· | Restricted Tools | âœ“ï¼ˆå´æ¬„ç„¡ç¨ç«‹æ¨™ç±¤ï¼Œåƒ…å­é …é¡¯ç¤ºï¼‰ |
| `person_browser` | äººç‰©ç€è¦½ | Person Browser | â€” |
| `search_by_entry` | æŒ‰å…¥ä»•æŸ¥è©¢ | Search by Entry Type | âœ“ |
| `historical_maps` | æ­·å²åœ°åœ– | Historical Maps | â€” |
| `admin_tools` | ç®¡ç†å“¡å·¥å…· | Admin Tools | â€” |
| `user_management` | ç”¨æˆ¶ç®¡ç† | User Management | â€” |
| `language_switch_to_en` | EN | EN | æŒ‰éˆ•æ¨™ç±¤ |
| `language_switch_to_zh` | ä¸­æ–‡ | ä¸­æ–‡ | æŒ‰éˆ•æ¨™ç±¤ |

### 5.2 é€šç”¨æŒ‰éˆ•èˆ‡æ¨™ç±¤ï¼ˆcommon.phpï¼‰

| Key | ç¹é«”ä¸­æ–‡ | English | å‚™è¨» |
|-----|---------|---------|------|
| `save` | ä¿å­˜ | Save | â€” |
| `confirm` | ç¢ºå®š | Confirm | â€” |
| `cancel` | å–æ¶ˆ | Cancel | â€” |
| `search` | æœå°‹ | Search | â€” |
| `delete` | åˆªé™¤ | Delete | â€” |
| `add` | æ–°å¢ž | Add | â€” |
| `edit` | ç·¨è¼¯ | Edit | â€” |
| `create` | å»ºç«‹ | Create | â€” |
| `update` | æ›´æ–° | Update | â€” |
| `reset` | é‡ç½® | Reset | â€” |
| `close` | é—œé–‰ | Close | â€” |
| `back` | è¿”å›ž | Back | â€” |
| `submit` | æäº¤ | Submit | â€” |
| `loading` | è¼‰å…¥ä¸­â€¦ | Loadingâ€¦ | â€” |
| `no_data` | ç„¡è³‡æ–™ | No data | â€” |
| `page_of` | ç¬¬ :current é ï¼Œå…± :total é  | Page :current of :total | â€” |
| `profile_settings` | å€‹äººè¨­å®š | Profile Settings | â€” |
| `sign_out` | ç™»å‡º | Sign out | â€” |
| `dark_mode_toggle` | åˆ‡æ›æ·±è‰²æ¨¡å¼ | Toggle dark mode | â€” |
| `home` | é¦–é  | Home | â€” |
| `yes` | æ˜¯ | Yes | â€” |
| `no` | å¦ | No | â€” |

### 5.3 äººç‰©è³‡æ–™æ¬„ä½ï¼ˆperson.phpï¼‰

> å‡æºè‡ª FormLabels.xlsx çš„ `c_english`ã€`c_fanti` æ¬„ä½ã€‚

| Key | ç¹é«”ä¸­æ–‡ | English | FormLabels ä¾æ“š |
|-----|---------|---------|----------------|
| `person` | äººç‰© | Person | â€” |
| `person_id` | äººç‰© ID | Person ID | â€” |
| `full_name` | å…¨å | Full Name | `Full Name` |
| `name` | å§“å | Name | â€” |
| `alt_name` | åˆ¥å | Alternative Name | â€” |
| `alt_name_type` | åˆ¥åé¡žåž‹ | Alt. Name Type | â€” |
| `pinyin` | æ‹¼éŸ³ | Pinyin | â€” |
| `gender` | æ€§åˆ¥ | Gender | â€” |
| `female` | å¥³ | Female | `Female` |
| `male` | ç”· | Male | â€” |
| `birth_year` | ç”Ÿå¹´ | Birth Year | `Born` |
| `death_year` | å’å¹´ | Death Year | `Died` |
| `age_at_death` | äº«å¹´ | Age at Death | `Age at Death` |
| `active_from` | åœ¨ä¸–å§‹å¹´ | Active From | `Active from` |
| `active_until` | åœ¨ä¸–çµ‚å¹´ | Active Until | `Active until` |
| `index_year` | æŒ‡æ•¸å¹´ | Index Year | `Index Year` |
| `dynasty` | æœä»£ | Dynasty | `Dynasty` |
| `choronym` | éƒ¡æœ› | Choronym | `Choronym` âœ“ |
| `ethnicity` | ç¨®æ— | Ethnicity | `Ethnicity` |
| `source` | å‡ºè™• | Source | `Source` |
| `pages` | é ç¢¼/æ¢ç›® | Pages | `Pages`ï¼ˆé¿å…èˆ‡ `Entry`=å…¥ä»• æ··æ·†ï¼Œä¸å¯«æ–œç·šé›™ç¾©ï¼‰ |
| `reign_year` | å¹´è™Ÿ | Reign Year | `Reign Year` âœ“ |
| `tribe` | éƒ¨ã€æ— | Tribe | `Tribe` |
| `basic_info` | åŸºæœ¬è³‡æ–™ | Basic Information | â€” |
| `edit_basic_info` | ç·¨è¼¯åŸºæœ¬ä¿¡æ¯ | Edit Basic Information | â€” |
| `delete_person` | åˆªé™¤äººç‰© | Delete Person | â€” |
| `create_or_modify` | å»ºç«‹ / ä¿®æ”¹è³‡è¨Š | Create / Modify Information | â€” |
| `search_person_placeholder` | æœå°‹äººç‰©ï¼ˆID / å§“å / æ‹¼éŸ³ï¼‰ | Search Person (ID / Name / Pinyin) | â€” |

### 5.4 äººç‰©é—œä¿‚æ¬„ä½ï¼ˆperson.php çºŒï¼‰

| Key | ç¹é«”ä¸­æ–‡ | English | FormLabels ä¾æ“š |
|-----|---------|---------|----------------|
| `kinship` | è¦ªå±¬é—œä¿‚ | Kinship | `Kinship` |
| `associations` | ç¤¾æœƒé—œä¿‚ | Associations | `Associations` |
| `assoc_type_friendship` | æœ‹å‹ | Friendship | `Friendship` |
| `assoc_type_family` | å®¶åº­ | Family | `Family` |
| `assoc_type_religion` | å®—æ•™ | Religion | `Religion` |
| `assoc_type_finance` | è²¡å‹™ | Finance | `Finance` |
| `assoc_type_medicine` | é†«ç™‚ | Medicine | `Medicine` |
| `assoc_type_military` | è»äº‹ | Military | `Military` |
| `assoc_type_scholarship` | å­¸è¡“ | Scholarship | `Scholarship` |
| `assoc_type_teacher_student` | å¸«ç”Ÿé—œä¿‚ | Teacher-Student | `Teacher-Student` |
| `assoc_type_scholarly_affiliation` | å­¸è¡“äº¤å¾€ | Scholarly Affiliation | `Scholarly Affiliation` |
| `assoc_type_scholarly_topic` | ä¸»é¡Œç›¸è¿‘ | Scholarly Topic | `Scholarly Topic` |
| `assoc_type_literary_artistic` | æ–‡å­¸è—è¡“äº¤å¾€ | Literary/Artistic Affiliations | `Literary / Artistic Affiliations` |
| `assoc_type_politics` | æ”¿æ²» | Politics | `Politics` |
| `assoc_type_equal_relation` | å®˜å ´å¹³ç­‰é—œä¿‚ | Equal Relations (Official Sphere) | `Equal Relations` |
| `assoc_type_subordinate` | å®˜å ´ä¸‹å±¬é—œä¿‚ | Subordinate Relation | `Subordinate Relation` |
| `assoc_type_superior` | å®˜å ´ä¸Šå¸é—œä¿‚ | Superior Relation | `Superior Relation` |
| `assoc_type_recommendation` | è–¦èˆ‰ä¿ä»» | Recommendation/Sponsorship | `Recommendation / Sponsorship` |
| `postings` | è·å®˜ | Postings | `Postings` |
| `status` | ç¤¾æœƒå€åˆ† | Status | `Status` âœ“ |
| `entry` | å…¥ä»• | Entry | `Entry` âœ“ |
| `addresses` | åœ°å€ | Addresses | â€” |
| `texts` | è‘—ä½œ | Texts | âœ“ |
| `sources` | ä¾†æº | Sources | â€” |
| `events` | äº‹ä»¶ | Events | â€” |
| `possessions` | è²¡ç”¢ | Possessions | â€” |

### 5.5 è‘—ä½œé¡žåž‹ï¼ˆperson.php çºŒï¼‰

| Key | ç¹é«”ä¸­æ–‡ | English | FormLabels ä¾æ“š |
|-----|---------|---------|----------------|
| `text_type_commemorative` | è¨˜è©  | Commemorative Texts | `Commemorative Texts` |
| `text_type_epitaph` | å¢“èªŒé¡ž | Epitaphs | `Epitaphs` |
| `text_type_preface_postface` | åºè·‹ | Prefaces/Postfaces | `Prefaces / Postfaces` |
| `text_type_biography` | å‚³è¨˜ | Biographical Texts | `Biographical Texts` |
| `text_type_explanatory` | è«–èªª | Explanatory Texts | `Explanatory Texts` |

### 5.6 ä»£ç¢¼è¡¨ï¼ˆcodes.phpï¼‰

| Key | ç¹é«”ä¸­æ–‡ | English | å‚™è¨» |
|-----|---------|---------|------|
| `codes_home` | å…¨éƒ¨è¡¨æ ¼é¦–é  | Tables Home | â€” |
| `addr_belongs_data` | åœ°å€å¾žå±¬è¡¨ | Address Hierarchy Table | â€” |
| `addr_codes` | åœ°å€ç·¨ç¢¼è¡¨ | Address Codes | â€” |
| `altname_codes` | åˆ¥åç·¨ç¢¼è¡¨ | Alternative Name Codes | â€” |
| `appointment_codes` | ä»»å‘½é¡žåž‹ç·¨ç¢¼è¡¨ | Appointment Type Codes | â€” |
| `office_codes` | ä»»å®˜ç·¨ç¢¼è¡¨ | Office Codes | â€” |
| `social_institution_codes` | ç¤¾æœƒæ©Ÿæ§‹ç·¨ç¢¼è¡¨ | Social Institution Codes | â€” |
| `text_codes` | è‘—ä½œç·¨ç¢¼è¡¨ | Text Codes | â€” |
| `text_instance_data` | è‘—ä½œç‰ˆæœ¬è¡¨ | Text Instance Data | â€” |

### 5.7 æª¢è¦–è¡¨ï¼ˆviews.phpï¼‰

| Key | ç¹é«”ä¸­æ–‡ | English | å‚™è¨» |
|-----|---------|---------|------|
| `view_altname_data` | åˆ¥åè³‡æ–™æª¢è¦– | Alternative Names View | â€” |
| `view_assoc_data` | ç¤¾æœƒé—œä¿‚è³‡æ–™æª¢è¦– | Associations View | â€” |
| `view_biog_addr_data` | äººç‰©åœ°å€è³‡æ–™æª¢è¦– | Person Addresses View | â€” |
| `view_biog_inst_addr_data` | äººç‰©/ç¤¾æœƒæ©Ÿæ§‹/åœ°å€è³‡æ–™æª¢è¦– | Person / Institution / Address View | â€” |
| `view_biog_inst_data` | äººç‰©ç¤¾æœƒæ©Ÿæ§‹è³‡æ–™æª¢è¦– | Person Social Institutions View | â€” |
| `view_biog_source_data` | äººç‰©ä¾†æºè³‡æ–™æª¢è¦– | Person Sources View | â€” |
| `view_biog_text_data` | äººç‰©è‘—ä½œè³‡æ–™æª¢è¦– | Person Texts View | â€” |
| `view_entry_data` | äººç‰©å…¥ä»•è³‡æ–™æª¢è¦– | Person Entries View | â€” |
| `view_event_addr_data` | äººç‰©äº‹ä»¶åœ°å€æª¢è¦– | Person Event Addresses View | â€” |
| `view_events_data` | äººç‰©äº‹ä»¶è³‡æ–™æª¢è¦– | Person Events View | â€” |
| `view_kin_addr_data` | äººç‰©è¦ªå±¬è³‡æ–™æª¢è¦– | Person Kinship View | â€” |
| `view_people_data` | äººç‰©åŸºæœ¬è³‡æ–™æª¢è¦– | Person Basic Data View | â€” |
| `view_people_addr_data` | äººç‰©ç´¢å¼•åœ°å€æª¢è¦– | Person Index Addresses View | â€” |
| `view_possessions_addr_data` | äººç‰©è²¡ç”¢åœ°å€æª¢è¦– | Person Possessions Addresses View | â€” |
| `view_possessions_data` | äººç‰©è²¡ç”¢è³‡æ–™æª¢è¦– | Person Possessions View | â€” |
| `view_posting_addr_data` | ä»»å®˜åœ°å€è³‡æ–™æª¢è¦– | Posting Addresses View | â€” |
| `view_posting_office_data` | ä»»å®˜è·å‹™è³‡æ–™æª¢è¦– | Posting Offices View | â€” |
| `view_status_data` | äººç‰©èº«ä»½è³‡æ–™æª¢è¦– | Person Status View | â€” |

### 5.8 Query Playgroundï¼ˆquery.phpï¼‰

| Key | ç¹é«”ä¸­æ–‡ | English | å‚™è¨» |
|-----|---------|---------|------|
| `query_playground_title` | SQL æŸ¥è©¢ç·´ç¿’å ´ | SQL Query Playground | â€” |
| `mode_sql` | SQL æŸ¥è©¢ | SQL Query | â€” |
| `mode_qbe` | æŸ¥è©¢è¨­è¨ˆ (QBE) | Query Builder (QBE) | â€” |
| `nl_query_placeholder` | ç”¨è‡ªç„¶èªžè¨€æè¿°æ‚¨æƒ³æŸ¥è©¢çš„å…§å®¹ | Describe what you want to query in natural language | â€” |
| `sql_editor_placeholder` | è¼¸å…¥ SQL æŸ¥è©¢èªžå¥ | Enter SQL query | â€” |
| `querying` | æŸ¥è©¢ä¸­â€¦ | Queryingâ€¦ | â€” |
| `run_query` | â–¶ åŸ·è¡ŒæŸ¥è©¢ | â–¶ Run Query | â€” |
| `no_results_yet` | å°šç„¡æŸ¥è©¢çµæžœ | No results yet | â€” |
| `empty_results` | æŸ¥è©¢çµæžœç‚ºç©º | Query returned no results | â€” |
| `qbe_autosave` | QBE è‰ç¨¿æœƒè‡ªå‹•å„²å­˜ | QBE draft is auto-saved | â€” |
| `account_inactive` | æ‚¨çš„å¸³è™Ÿå°šæœªå•Ÿç”¨ï¼Œç„¡æ³•ä½¿ç”¨æ­¤åŠŸèƒ½ã€‚ | Your account is not yet activated. | å¾Œç«¯ Flash è¨Šæ¯ |
| `historical_qa_log_note` | ä¸¦æœƒè¨˜éŒ„æŸ¥è©¢æ—¥èªŒ | Query logs will be recorded | â€” |

### 5.9 æ“ä½œè¨˜éŒ„èˆ‡ææ¡ˆï¼ˆoperations.phpï¼‰

| Key | ç¹é«”ä¸­æ–‡ | English | å‚™è¨» |
|-----|---------|---------|------|
| `operations_title` | æ“ä½œè¨˜éŒ„ | Operations | â€” |
| `proposals_title` | ææ¡ˆåˆ—è¡¨ | Proposals | â€” |
| `pending_review` | å¾…å¯©æ ¸ | Pending Review | â€” |
| `proposal_create` | å»ºç«‹ææ¡ˆ | Create Proposal | â€” |
| `proposal_update` | æ›´æ–°ææ¡ˆ | Update Proposal | â€” |
| `approved` | å·²æ‰¹å‡† | Approved | â€” |
| `rejected` | å·²æ‹’çµ• | Rejected | â€” |
| `crowdsourcing` | çœ¾åŒ…éŒ„å…¥ | Crowdsourcing | [?] |

### 5.10 ç®¡ç†å“¡å·¥å…·ï¼ˆadmin.phpï¼Œä½Žå„ªå…ˆï¼‰

| Key | ç¹é«”ä¸­æ–‡ | English | å‚™è¨» |
|-----|---------|---------|------|
| `batch_load_books` | æ‰¹æ¬¡è¼‰å…¥æ›¸ç±æ¨™é¡Œ | Batch Load Book Titles | â€” |
| `batch_load_offices` | æ‰¹æ¬¡è¼‰å…¥å®˜è· | Batch Load Offices | â€” |
| `wiki_maintenance` | ç¶­åŸºç¶­è­· | Wiki Maintenance | â€” |
| `table_maintenance` | è¡¨æ ¼ç¶­è­· | Table Maintenance | â€” |
| `audit_logs` | ç¨½æ ¸æ—¥èªŒ | Audit Logs | â€” |
| `ai_fill_logs` | AI å¡«å……æ—¥èªŒ | AI Fill Logs | â€” |

---

## 6. è¡“èªžç¢ºèªè¨˜éŒ„

ä»¥ä¸‹è¡“èªžå·²æ–¼ 2026-06-01 èˆ‡çŽ‹å®ç”¦ç¢ºèªï¼Œå¯ç›´æŽ¥é€²å…¥å¯¦æ–½ã€‚

| # | ç¹é«”ä¸­æ–‡ | æœ€çµ‚è‹±æ–‡è­¯å | èªªæ˜Ž |
|---|---------|------------|------|
| 6.1 | éƒ¡æœ› | **Choronym** | èˆ‡ Harvard CBDB è‹±æ–‡ç‰ˆåŠç”¨æˆ¶æ‰‹å†Šä¸€è‡´ |
| 6.2 | å…¥ä»• | **Entry** | çµ±ä¸€ç”¨ `Entry`ï¼Œæ²¿ç”¨ FormLabelsï¼›ä¸åœ¨ä¸åŒèªžå¢ƒåˆ†ç”¨ Government Entry |
| 6.3 | ç¤¾æœƒå€åˆ† | **Status** | ä»‹é¢çµ±ä¸€ç”¨ `Status`ï¼ˆFormLabels æ¨™æº–ï¼‰ |
| 6.4 | è‘—ä½œ | **Texts** | èˆ‡ Harvard CBDB è‹±æ–‡ç‰ˆä¸€è‡´ï¼›`Writings` èªžç¾©åçª„ |
| 6.5 | çœ¾åŒ…éŒ„å…¥ | **Crowdsourcing** | ç³»çµ±åŠŸèƒ½ç”¨èªž |
| 6.6 | æŒ‰å…¥ä»•æŸ¥è©¢ | **Search by Entry Type** | é¿å…èˆ‡ã€Œè¨˜éŒ„æ¢ç›®ã€çš„ Entry æ··æ·† |
| 6.7 | éžå…¬é–‹å·¥å…·ï¼ˆå´æ¬„ç¾¤çµ„ï¼‰ | ç„¡ç¨ç«‹æ¨™ç±¤ | ç¶­æŒç¾è¡Œï¼šç„¡çˆ¶é¸å–®æ¨™ç±¤ï¼Œå­é …ç›´æŽ¥é¡¯ç¤º |
| 6.8 | å¹´è™Ÿ | **Reign Year** | æ²¿ç”¨ FormLabelsï¼›è¡¨ç¤ºã€ŒæŸå¹´è™Ÿä¸‹çš„å¹´ä»½ã€è€Œéžå¹´è™Ÿæœ¬èº« |

---

## 7. å¯¦æ–½è¨ˆåŠƒï¼ˆåˆ† Phaseï¼‰

### Phase 0ï¼šåŸºç¤Žè¨­æ–½ï¼ˆç´„ 2 å¤©ï¼‰

- [ ] ä¿®æ”¹ `config/app.php`ï¼šlocale æ”¹ç‚º `zh-TW`ï¼ˆèˆ‡ç¾æœ‰ UI ä¸€è‡´ï¼‰ï¼ŒåŠ å…¥ `'available_locales' => ['zh-TW', 'en']`ï¼ˆè‡ªè¨‚ keyï¼Œéœ€åœ¨ SetLocaleMiddleware ä¸­ä»¥ `config('app.available_locales')` è®€å–ï¼‰
  - **âš ï¸ æ³¨æ„ï¼š** locale æ”¹ç‚º zh-TW å¾Œï¼Œè‹¥ `lang/zh-TW/` ç›®éŒ„å°šæœªå»ºç«‹ï¼Œ`trans('group')` æ•´æª”å–ç”¨æœƒå›žå‚³å­—ä¸²è€Œéž arrayï¼ˆ`fallback_locale` åƒ…å°é»žè¨˜æ³• key ç”Ÿæ•ˆï¼‰ã€‚**Phase 0 éƒ¨ç½²å¾Œè«‹ç«‹å³æš«åœï¼Œç­‰ Phase 1 å®Œæˆ lang/zh-TW/ å…¨éƒ¨æª”æ¡ˆå¾Œå†å•Ÿç”¨ zh-TW localeã€‚**
- [ ] å»ºç«‹ `app/Http/Middleware/SetLocaleMiddleware.php`
- [ ] åœ¨ `app/Http/Kernel.php` çš„ `$middlewareGroups['web']` ä¸­æ³¨å†Š `SetLocaleMiddleware`ï¼ˆæœ¬å°ˆæ¡ˆä½¿ç”¨ Laravel 8.x èˆŠå¼ bootstrapï¼Œ**ä¸æ”¯æ´** L11+ çš„ `bootstrap/app.php â†’withMiddleware()` APIï¼‰
  - **âš ï¸ é †åºï¼š** å¿…é ˆæ”¾åœ¨ `StartSession::class` **ä¹‹å¾Œ**ï¼ˆå¦å‰‡ `Session::get('locale')` è®€åˆ°çš„æ˜¯æœªå•Ÿå‹•çš„ sessionï¼Œæ°¸é å›žå‚³ nullï¼‰ã€‚å»ºè­°æ’åœ¨ `ShareErrorsFromSession::class` ä¹‹å‰ã€‚
- [ ] å»ºç«‹ `app/Http/Controllers/LocaleController.php`ï¼ˆPOST `/locale`ï¼‰
- [ ] æ–°å¢žè·¯ç”±ï¼š`Route::post('/locale', [LocaleController::class, 'switch'])->name('locale.switch')`
  - **âš ï¸ ä½ç½®ï¼š** å¿…é ˆæ”¾åœ¨ `routes/web.php` çš„ `auth` middleware group **ä¹‹å¤–**ï¼ˆè®“è¨ªå®¢åœ¨ç™»å…¥é ä¹Ÿèƒ½åˆ‡æ›èªžè¨€ï¼‰ã€‚æ”¾åœ¨ `Route::middleware(['auth'])->group(...)` å…§éƒ¨æœƒå°Žè‡´ guest æ”¶åˆ° 302 é‡å°Žè‡³ `/login`ï¼Œåˆ‡æ›éœé»˜å¤±æ•—ã€‚
  - **âš ï¸ CSRFï¼š** Inertia çš„ `router.post()` è‡ªå‹•å¸¶ X-XSRF-TOKENï¼›Blade çš„ `<form>` å¸¶ `@csrf`ã€‚**ä¸è¦å°‡ `/locale` åŠ å…¥ `VerifyCsrfToken::$except`**ï¼Œå¦å‰‡ç§»é™¤ CSRF ä¿è­·ï¼Œå…è¨±ä»»æ„ç«™é»žéœé»˜åˆ‡æ›ä½¿ç”¨è€…èªžè¨€åå¥½ã€‚
- [ ] ä¿®æ”¹ `HandleInertiaRequests::share()` åŠ å…¥ `locale` å’Œ `translations`
- [ ] å»ºç«‹ `resources/js/inertia/hooks/useTranslation.ts`

### Phase 1ï¼šå»ºç«‹ç¿»è­¯æª”æ¡ˆï¼ˆç´„ 3 å¤©ï¼‰

- [ ] å»ºç«‹ `resources/lang/zh-TW/` ç›®éŒ„åŠæ‰€æœ‰æ¨¡çµ„æ–‡ä»¶ï¼ˆnav, common, person, codes, views, query, operationsï¼‰
- [ ] å»ºç«‹ `resources/lang/en/` ç›®éŒ„åŠå°æ‡‰è‹±æ–‡æ–‡ä»¶
- [ ] ç¿»è­¯ä¾†æºä»¥æœ¬æ–‡ä»¶ç¬¬ 5 ç¯€è¡“èªžè¡¨ç‚ºä¾æ“š
- [ ] å»ºç«‹ `resources/lang/zh-TW/auth.php`ã€`validation.php`ã€`pagination.php`ã€`passwords.php`ï¼ˆå¾Œè€…ç”¨æ–¼å¯†ç¢¼é‡è¨­æµç¨‹ flash è¨Šæ¯ï¼ŒLaravel PasswordBroker å…§éƒ¨å‘¼å« `trans('passwords.sent')` ç­‰ keyï¼‰

### Phase 1.5ï¼šé é¢ç´šç¿»è­¯ prop è¦åŠƒï¼ˆå«æ–¼ Phase 1ï¼Œå‹¿è·³éŽï¼‰

`views`ã€`codes`ã€`operations`ã€`admin` å››å€‹ç¾¤çµ„**ä¸æ”¾å…¥** `HandleInertiaRequests::share()`ï¼ŒåŽŸå› æ˜¯ inertia-laravel ç”¨ `array_merge`ï¼ˆæ·ºåˆä½µï¼‰åˆå¹¶ shared props èˆ‡é é¢ propsâ€”â€”è‹¥é é¢ props ä½¿ç”¨åŒä¸€å€‹ `'translations'` keyï¼Œshared translations æœƒè¢«å®Œå…¨è¦†è“‹ï¼Œ`common`/`nav` ç­‰é—œéµç¾¤çµ„æ¶ˆå¤±ã€‚

**è§£æ±ºæ–¹æ¡ˆï¼šé é¢ç‰¹å®šç¾¤çµ„ç”¨ç¨ç«‹çš„ `page_translations` key å‚³å…¥ï¼š**

```php
// ViewTableController::appIndex
return Inertia::render('ViewTables/List', [
    'tables'           => $tables,
    'page_translations' => ['views' => (array) trans('views')],
]);
```

å°æ‡‰ `useTranslation` hook åŒæ™‚æ”¯æ´å…©å€‹ prop keyï¼š

```typescript
export function useTranslation(group: string) {
    const { translations, page_translations } = usePage<{
        translations?: Translations;
        page_translations?: Translations;
    }>().props;
    // å…ˆæŸ¥ page_translationsï¼ˆé é¢ç‰¹å®šï¼‰ï¼Œå†æŸ¥ shared translations
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

### Phase 2ï¼šBlade æ¨¡æ¿æå–ï¼ˆç´„ 5 å¤©ï¼‰

ä¾å„ªå…ˆåº¦æŽ’åºï¼š

| å„ªå…ˆ | æ–‡ä»¶ | ä¼°è¨ˆå­—ä¸²æ•¸ |
|------|------|-----------|
| é«˜ | `sidebar-v3.blade.php` | ~60 |
| é«˜ | `header-v3.blade.php` | ~10 |
| é«˜ | `dashboard-v3.blade.php` | ~20 |
| ä¸­ | `biogmains/*.blade.php` | ~150 |
| ä¸­ | `codes/*.blade.php` | ~40 |
| ä¸­ | `operations/*.blade.php` | ~30 |
| ä½Ž | `admin/*.blade.php` | ~50 |
| ä½Ž | `crowdsourcing/*.blade.php` | ~30 |

**æ“ä½œæ–¹å¼ï¼š** ç”¨æ­£å‰‡æœå°‹æ‰€æœ‰ `>ä¸­æ–‡æ–‡å­—<` åŠ `"ä¸­æ–‡æ–‡å­—"` æ¨¡å¼ï¼Œé€ä¸€æå–ç‚º `{{ __('nav.dashboard') }}` ç­‰å½¢å¼ã€‚

### Phase 3ï¼šReact/Inertia æå–ï¼ˆç´„ 4 å¤©ï¼‰

ä¾å„ªå…ˆåº¦æŽ’åºï¼š

| å„ªå…ˆ | æ–‡ä»¶/çµ„ä»¶ | ä¼°è¨ˆå­—ä¸²æ•¸ |
|------|---------|-----------|
| é«˜ | `Layouts/AppShell.tsx` | ~10 |
| é«˜ | `components/QueryPlayground/*.tsx` | ~30 |
| é«˜ | `components/PersonBrowser/PeopleSearchPanel.tsx` | ~10 |
| é«˜ | `components/PersonBrowser/BasicInfoView.tsx` | ~15 |
| é«˜ | `components/PersonBrowser/BrowserTabs.tsx` | ~10 |
| ä¸­ | `components/PersonBrowser/*Tab.tsx`ï¼ˆ9 å€‹ Tabï¼‰ | ~60 |
| ä¸­ | `Pages/QueryPlayground/Index.tsx` | ~20 |
| ä¸­ | `Pages/PersonBrowser/Index.tsx` | ~10 |
| ä½Ž | `Pages/ViewTables/*.tsx` | ~20 |
| ä½Ž | `Pages/SearchByEntry/Index.tsx` | ~10 |

**æ“ä½œæ–¹å¼ï¼š** åœ¨æ¯å€‹çµ„ä»¶åŠ  `const t = useTranslation('person')` ç­‰ï¼Œæ›¿æ›å­—ä¸²å­—é¢å€¼ã€‚

### Phase 4ï¼šèªžè¨€åˆ‡æ› UIï¼ˆç´„ 1 å¤©ï¼‰

- [ ] åœ¨ `header-v3.blade.php` åŠ å…¥èªžè¨€åˆ‡æ›æŒ‰éˆ•ï¼ˆBlade form submit ç‰ˆï¼Œç”¨æ–¼éž Inertia é é¢ï¼‰
- [ ] åœ¨ `AppShell.tsx` åŠ å…¥ `router.post('/locale')` ç‰ˆï¼ˆç”¨æ–¼ Inertia é é¢ï¼Œç„¡å…¨é åˆ·æ–°ï¼‰
- [ ] ç¢ºèªåˆ‡æ›å¾Œ URL ä¸è®Šï¼ˆåƒ… session/cookie æ”¹è®Šï¼‰

### Phase 5ï¼šæ¸¬è©¦èˆ‡ QAï¼ˆç´„ 2 å¤©ï¼‰

- [ ] æ¯å€‹ Blade é é¢åˆ†åˆ¥ä»¥ `zh-TW` å’Œ `en` ç€è¦½ï¼Œç¢ºèªç„¡ç¼ºæ¼ï¼ˆmissing key é¡¯ç¤ºç‚º key æœ¬èº«ï¼‰
- [ ] æ¯å€‹ Inertia é é¢åˆ‡æ›èªžè¨€å¾Œç¢ºèª translations props æ›´æ–°ï¼Œä¸”ç‚ºå–®æ¬¡è«‹æ±‚ï¼ˆä¸è§¸ç™¼ç¬¬äºŒæ¬¡ reloadï¼‰
- [ ] æ–°å¢ž `LocaleControllerTest`ï¼šé©—è­‰ POST /locale è¨­å®š sessionã€cookieï¼Œä»¥åŠç„¡æ•ˆ locale è¢«æ‹’çµ•
- [ ] **âš ï¸ æ¸¬è©¦ç’°å¢ƒ locale å›ºå®šï¼š** åœ¨ `tests/TestCase.php` çš„ `setUp()` ä¸­åŠ å…¥ `App::setLocale('en')`ï¼Œé¿å…ç¾æœ‰æ¸¬è©¦å›  zh-TW æˆç‚ºé è¨­ locale è€Œæ–·è¨€å¤±æ•—ã€‚Phase 1 å»ºç«‹ zh-TW ç¿»è­¯æª”å¾Œï¼Œä»»ä½•æ–·è¨€è‹±æ–‡ Flash è¨Šæ¯æˆ–é©—è­‰éŒ¯èª¤å­—ä¸²çš„æ¸¬è©¦éƒ½éœ€è¦è¤‡æŸ¥ã€‚
- [ ] å…¨è·‘ `./vendor/bin/phpunit`ï¼Œç¢ºèªç„¡ locale ç›¸é—œå›žæ­¸

---

## 8. é¢¨éšªèˆ‡æ³¨æ„äº‹é …

### 8.1 `config/app.php` locale ç›®å‰ç‚º `'en'`

ç›®å‰ `locale` è¨­å®šå€¼èˆ‡å¯¦éš› UI èªžè¨€çŸ›ç›¾ï¼ˆUI æ˜¯ç¹é«”ä¸­æ–‡ï¼‰ã€‚Phase 0 éœ€è¦æ”¹ç‚º `'zh-TW'`ã€‚

**fallback_locale çš„ä½œç”¨ç¯„åœï¼š** `fallback_locale = 'en'` å°**é»žè¨˜æ³• key æŸ¥è©¢**ï¼ˆå¦‚ `trans('auth.failed')`ï¼‰ç”Ÿæ•ˆâ€”â€”è‹¥ `lang/zh-TW/auth.php` ä¸å­˜åœ¨ï¼ŒLaravel æœƒæ”¹è®€ `lang/en/auth.php` ä¸­çš„åŒå keyï¼Œæ­£å¸¸å›žå‚³è‹±æ–‡å­—ä¸²ã€‚ä½† Â§4.3 æŽ¡ç”¨çš„**æ•´æª”å–ç”¨**ï¼ˆ`trans('common')` ç„¡é»žè¨˜æ³•ï¼‰ä¸åœ¨ fallback æ©Ÿåˆ¶çš„ä¿è­·ç¯„åœå…§ï¼šè‹¥ `lang/zh-TW/common.php` ä¸å­˜åœ¨ï¼Œå›žå‚³å€¼æ˜¯å­—ä¸² `'common'` è€Œéž arrayï¼Œå°Žè‡´ Inertia translations prop å´©æ½°ã€‚

**çµè«–ï¼š** locale æ”¹ç‚º zh-TW å¾Œï¼Œ**å¿…é ˆç«‹å³å»ºç«‹ `lang/zh-TW/` ç›®éŒ„çš„æ‰€æœ‰æª”æ¡ˆï¼ˆPhase 1ï¼‰**ï¼Œä¸å¯åœ¨å…©å€‹ Phase ä¹‹é–“ä¸Šç·šã€‚Phase 1 çš„å»ºç«‹é †åºï¼šå…ˆå»º common.php å’Œ nav.phpï¼ˆæœ€å…ˆè¢« Inertia shared data å¼•ç”¨ï¼‰ï¼Œå†å»ºå…¶é¤˜æª”æ¡ˆã€‚

### 8.2 Blade é é¢çš„ç¿»è­¯å‚³éž

Blade é é¢é€éŽ `__()` ç›´æŽ¥å‘¼å«ï¼Œä¸éœ€é¡å¤–å‚³éžï¼›ä½†å¦‚æžœ Blade å…§æœ‰å‹•æ…‹ç”Ÿæˆçš„ JS å­—ä¸²ï¼ˆ`@json`ã€`<script>` å€å¡Šï¼‰ï¼Œéœ€è¦å¦å¤–è™•ç†ã€‚

### 8.3 æ··åˆç¹/ç°¡ä¸­æ–‡å•é¡Œ

`FormLabels.xlsx` çš„ `c_jianti`ï¼ˆç°¡é«”ï¼‰èˆ‡ `c_fanti`ï¼ˆç¹é«”ï¼‰éƒ¨åˆ†æœ‰å·®ç•°ï¼Œæœ¬è¨ˆåŠƒä»¥ç¹é«”ä¸­æ–‡ï¼ˆ`zh-TW`ï¼‰ç‚ºæº–ã€‚å¾ŒçºŒè‹¥éœ€æ”¯æ´ç°¡é«”ï¼Œå¯ç›´æŽ¥æ–°å¢ž `lang/zh-CN/` ç›®éŒ„ã€‚

**Accept-Language æ˜ å°„è¦å‰‡ï¼š** `SetLocaleMiddleware` è®€å– header æ™‚ï¼Œå»ºè­°ä½¿ç”¨ `str_starts_with($lang, 'zh')` å°‡**æ‰€æœ‰** `zh-*` å­æ¨™ç±¤ï¼ˆåŒ…å« `zh-CN`ã€`zh-Hans`ã€`zh-SG`ï¼‰çµ±ä¸€æ˜ å°„è‡³ `zh-TW`ã€‚ç†ç”±ï¼šç›®å‰åƒ…æœ‰ç¹é«”ä¸­æ–‡ç‰ˆï¼Œè®“ç°¡é«”ä½¿ç”¨è€…çœ‹ç¹é«”ä¸­æ–‡å„ªæ–¼çœ‹è‹±æ–‡ã€‚è‹¥æ—¥å¾Œæ–°å¢ž `zh-CN` èªžè¨€æ”¯æ´ï¼Œå†ç´°åŒ–æ­¤è¦å‰‡ã€‚

### 8.4 Flash è¨Šæ¯èˆ‡å¾Œç«¯éŒ¯èª¤

`laracasts/flash` å¥—ä»¶çš„ Flash è¨Šæ¯ç”±å¾Œç«¯æŽ§åˆ¶å™¨ç”¢ç”Ÿï¼Œé€™äº›å­—ä¸²ä¹Ÿéœ€è¦æå–é€²ç¿»è­¯æ–‡ä»¶ã€‚å·²åœ¨ Phase 1 æé†’ï¼Œä½†æ•¸é‡è¼ƒå°ï¼ˆ~20 æ¢ï¼‰ï¼Œå¯åœ¨ Phase 2 ä¸€ä½µè™•ç†ã€‚

### 8.5 å‰ç«¯é‡æ–°ç·¨è­¯

ä¿®æ”¹ `resources/js/` å¾Œéœ€åŸ·è¡Œ `npm run build`ã€‚å»ºè­°åœ¨ Phase 3 çµæŸæ™‚çµ±ä¸€åŸ·è¡Œï¼Œé¿å…å¤šæ¬¡ç·¨è­¯ã€‚

### 8.6 `mcamara/laravel-localization` æ”¾æ£„ç†ç”±å­˜æª”

- è‹¥æœªä¾†éœ€è¦ SEO å‹å¥½çš„ URL è·¯ç”±ï¼ˆ`/en/person/123`ï¼‰ï¼Œå¯é‡æ–°è©•ä¼°æ­¤å¥—ä»¶ï¼ˆv2.4.0, 2026-03ï¼Œä»æ´»èºï¼‰ã€‚
- ç›®å‰ CBDB Online ç‚ºéœ€ç™»å…¥çš„å·¥å…·ï¼ŒSEO å„ªå…ˆåº¦ä½Žï¼Œä¸å€¼å¾—å¢žåŠ æ­¤è¤‡é›œåº¦ã€‚

---

---

## 9. Phase 6ï¼šBlade è¦–åœ–å…¨é¢ç¿»è­¯

**èƒŒæ™¯ï¼š** Phase 2 åªç¿»è­¯äº†ä¸»è¦ç‰ˆé¢ï¼ˆ`layouts/`ï¼‰èˆ‡å°‘æ•¸ç‰¹å®šé é¢ï¼ˆcodesã€operationsã€crowdsourcingã€biogmains/bannerï¼‰ã€‚2026-06-02 å…¨é¢æŽƒæå¾Œç™¼ç¾ **96 å€‹ Blade æª”æ¡ˆã€ç´„ 3,006 è¡Œ**ä¸­æ–‡å­—ä¸²å°šæœªç¿»è­¯ï¼Œå…¶ä¸­æœ€é‡è¦çš„æ˜¯ `biogmains/` ä¸‹çš„äººç‰©ç·¨è¼¯è¡¨å–®ï¼ˆç”¨æˆ¶æœ€å¸¸æŽ¥è§¸çš„ä»‹é¢ï¼‰ã€‚

**ç¿»è­¯ç­–ç•¥ï¼š** é‡å°ç¾æœ‰ç¿»è­¯ç¾¤çµ„ï¼Œå„ªå…ˆé‡ç”¨ `person.php`ã€`common.php`ï¼›æ–°å¢ž `biogmains.php` ç¾¤çµ„å­˜æ”¾è¡¨å–®ç‰¹æœ‰å­—ä¸²ï¼ˆæ¨™ç±¤ã€æç¤ºã€å‹•ä½œæŒ‰éˆ•ç­‰ï¼‰ï¼›`admin.php` å·²æœ‰ä½†ä»éœ€æ“´å……ï¼›`auth.php` å·²æœ‰ä½†éœ€è£œå……ç™»å…¥æµç¨‹å­—ä¸²ã€‚

---

### Phase 6Aï¼šbiogmains äººç‰©ç·¨è¼¯è¡¨å–®ï¼ˆæœ€é«˜å„ªå…ˆï¼‰

**ç¯„åœï¼š** 54 å€‹æª”æ¡ˆã€~1,314 è¡Œæœªç¿»è­¯å­—ä¸²ã€‚é€™æ˜¯ç›®å‰æœ€å¸¸è¢«ç”¨æˆ¶ä½¿ç”¨çš„ç·¨è¼¯ä»‹é¢ã€‚

| å­ç›®éŒ„ | æª”æ¡ˆæ•¸ | ä¼°è¨ˆè¡Œæ•¸ | èªªæ˜Ž |
|--------|--------|---------|------|
| `basicinformation/` | 4 | ~120 | åŸºæœ¬è³‡æ–™ create/edit/indexï¼ˆ`show.blade.php` ç‚º React/Inertiaï¼Œè·³éŽï¼‰ |
| `addresses/` | 4 | ~71 | åœ°å€è³‡æ–™ CRUD |
| `altname/` | 4 | ~69 | åˆ¥åè³‡æ–™ CRUD |
| `assoc/` | 4 | ~256 | ç¤¾æœƒé—œä¿‚ï¼ˆå«æ™ºèƒ½å¡«å…… UIï¼‰ |
| `entries/` | 4 | ~79 | å…¥ä»•è³‡æ–™ CRUD |
| `events/` | 4 | ~56 | äº‹ä»¶ç®¡ç† CRUD |
| `kinship/` | 4 | ~85 | è¦ªå±¬é—œä¿‚ CRUD |
| `offices/` | 4 | ~310 | å®˜è·ç®¡ç†ï¼ˆå«æ™ºèƒ½å¡«å…… UIï¼Œå­—ä¸²æœ€å¤šï¼‰ |
| `possession/` | 4 | ~66 | è²¡ç”¢è¨˜éŒ„ CRUD |
| `socialinst/` | 4 | ~57 | ç¤¾äº¤æ©Ÿæ§‹ CRUD |
| `sources/` | 4 | ~36 | å‡ºè™•è³‡æ–™ CRUD |
| `statuses/` | 4 | ~131 | ç¤¾æœƒå€åˆ†ï¼ˆå«æ™ºèƒ½è­˜åˆ¥ï¼‰ |
| `texts/` | 4 | ~55 | è‘—è¿°è³‡æ–™ CRUD |
| `partials/` | 1 | ~18 | `list-order-toolbar.blade.php` |
| `defense.blade.php` | 1 | ~53 | è¤‡åˆä¸»éµèªªæ˜Žï¼ˆé–‹ç™¼è€…é ï¼‰ |
| `history-button.blade.php` | 1 | ~1 | æ­·å²è¨˜éŒ„æŒ‰éˆ• |

**æ–°å¢žç¿»è­¯ç¾¤çµ„ï¼š** `biogmains.php`ï¼ˆzh-TW + enï¼‰ï¼Œå­˜æ”¾è¡¨å–®é€šç”¨æ¨™ç±¤ï¼ˆå¦‚ï¼šä¾†æºåºè™Ÿã€ä¿®æ”¹èªªæ˜Žã€æ–°å¢žè¨˜éŒ„ã€æ™ºèƒ½å¡«å……æŒ‰éˆ•æ–‡å­—ç­‰ï¼‰ã€‚è¡¨å–®æ¬„ä½æ¨™ç±¤å„˜é‡é‡ç”¨ç¾æœ‰ `person.php` çš„ keyã€‚

**JS å­—ä¸²è™•ç†æ–¹å¼ï¼ˆå·²ç¢ºèªï¼‰ï¼š** `addresses/`ã€`altname/`ã€`assoc/`ã€`entries/`ã€`events/`ã€`kinship/`ã€`offices/`ã€`statuses/` ç­‰ç›®éŒ„çš„ `<script>` å€å¡Šå‡æœ‰ä¸­æ–‡ alert/confirm å­—ä¸²ï¼Œçµ±ä¸€ç”¨ `{!! Js::from(__('biogmains.xxx')) !!}` æ³¨å…¥ç‚º JS è®Šæ•¸ï¼ˆ`Js::from()` æ¯” `@json()` æ›´æ¸…æ™°ä¸”å·²æœ‰ XSS ä¿è­·ï¼‰ï¼Œå†æ–¼ alert/confirm ä¸­å¼•ç”¨è©²è®Šæ•¸ã€‚

**æ³¨æ„ï¼š** `components/forms/audit-fields.blade.php`ã€`components/forms/person-id-display.blade.php` è¢«å¤šå€‹ biogmains è¡¨å–® `@include`ï¼Œ**é ˆåœ¨å…¶ä»– 6A æ­¥é©Ÿä¹‹å‰ç¿»è­¯ï¼ˆ6A-0ï¼‰**ï¼Œå¦å‰‡ 6A-2 ä¹‹å¾Œä»é¡¯ç¤ºä¸­æ–‡ã€‚

**æ­¥é©Ÿï¼š**
- [ ] 6A-0ï¼šç¿»è­¯å…±ç”¨å…ƒä»¶ `components/forms/audit-fields.blade.php` èˆ‡ `components/forms/person-id-display.blade.php`ï¼ˆbiogmains è¡¨å–®å…±ç”¨ï¼Œé ˆå…ˆè¡Œï¼‰
- [ ] 6A-1ï¼šå»ºç«‹ `resources/lang/zh-TW/biogmains.php` èˆ‡ `resources/lang/en/biogmains.php`
- [ ] 6A-2ï¼šç¿»è­¯ `basicinformation/` ä¸‰å€‹æª”æ¡ˆï¼ˆcreate/edit/indexï¼›show.blade.php è·³éŽï¼‰
- [ ] 6A-3ï¼šç¿»è­¯ `addresses/`ã€`altname/`ã€`sources/` è¡¨å–®ï¼ˆå­—ä¸²è¼ƒå°‘ï¼Œåˆæ‰¹ï¼‰
- [ ] 6A-4ï¼šç¿»è­¯ `entries/`ã€`events/`ã€`kinship/`ã€`texts/`ï¼ˆåˆæ‰¹ï¼‰
- [ ] 6A-5ï¼šç¿»è­¯ `possession/`ã€`socialinst/`ï¼ˆåˆæ‰¹ï¼‰
- [ ] 6A-6ï¼šç¿»è­¯ `statuses/`ï¼ˆå«æ™ºèƒ½è­˜åˆ¥ UIï¼ŒJS å­—ä¸²ç”¨ Js::from() æ³¨å…¥ï¼‰
- [ ] 6A-7ï¼šç¿»è­¯ `assoc/`ï¼ˆæœ€è¤‡é›œï¼Œå«æ™ºèƒ½å¡«å…… JS alert å­—ä¸²ï¼Œç”¨ Js::from() æ³¨å…¥ï¼‰
- [ ] 6A-8ï¼šç¿»è­¯ `offices/`ï¼ˆæœ€å¤šå­—ä¸²ï¼Œæ™ºèƒ½å¡«å…… + å®˜è·æœå°‹ UIï¼Œç”¨ Js::from() æ³¨å…¥ï¼‰
- [ ] 6A-9ï¼šç¿»è­¯ `partials/`ã€`history-button.blade.php`ï¼ˆ`defense.blade.php` ç‚ºé–‹ç™¼è€…é ï¼Œè·³éŽï¼‰

---

### Phase 6Bï¼šä½¿ç”¨è€…æµç¨‹é é¢ï¼ˆé«˜å„ªå…ˆï¼‰

| æª”æ¡ˆ | ä¼°è¨ˆè¡Œæ•¸ | èªªæ˜Ž |
|------|---------|------|
| `auth/login.blade.php` ç­‰ 4 å€‹æª”æ¡ˆ | ~65 | ç™»å…¥ã€è¨»å†Šã€å¯†ç¢¼é‡è¨­ |
| `profile/*.blade.php` | ~249 | å€‹äººè¨­å®šã€API ä»¤ç‰Œç®¡ç† |
| `home.blade.php` | ~17 | ç™»å…¥å¾Œé¦–é æ­¡è¿Žè¨Šæ¯ |
| `welcome.blade.php` | ~17 | æœªç™»å…¥æ­¡è¿Žé  |
| `dashboard/*.blade.php` | ~23 | å„€è¡¨æ¿çµ±è¨ˆ |

**æ³¨æ„ï¼š** `auth/` éƒ¨åˆ†å­—ä¸²ï¼ˆ`__('auth.failed')` ç­‰ï¼‰å·²é€éŽ `lang/zh-TW/auth.php` ç¿»è­¯ï¼›éœ€è£œé½Š Blade æ¨¡æ¿ä¸­ç›´æŽ¥ç¡¬ç·¨ç¢¼çš„ä¸­æ–‡ï¼ˆè¡¨å–® labelã€æç¤ºæ–‡å­—ï¼‰ã€‚

**æ­¥é©Ÿï¼š**
- [ ] 6B-1ï¼šç¿»è­¯ `auth/` å››å€‹æª”æ¡ˆï¼ˆloginã€registerã€passwordsã€emailï¼‰
- [ ] 6B-2ï¼šç¿»è­¯ `profile/`ï¼ˆå«ä»¤ç‰Œç®¡ç†çš„ JS confirm å°è©±æ¡†å­—ä¸²ï¼‰
- [ ] 6B-3ï¼šç¿»è­¯ `home.blade.php`ã€`welcome.blade.php`ã€`dashboard/`

---

### Phase 6Cï¼šæ¨™æº–åŠŸèƒ½é é¢ï¼ˆä¸­å„ªå…ˆï¼‰

| ç›®éŒ„/æª”æ¡ˆ | ä¼°è¨ˆè¡Œæ•¸ | èªªæ˜Ž |
|----------|---------|------|
| `components/*.blade.php`ï¼ˆ5 å€‹ï¼‰+ `components/forms/`ï¼ˆ2 å€‹ï¼Œå·²åœ¨ 6A-0 å®Œæˆï¼‰ | ~139 | å…±ç”¨å…ƒä»¶ï¼ˆ`forms/` å·²æå‰è‡³ 6A-0ï¼‰ |
| `view/*.blade.php`ï¼ˆ2 å€‹ï¼‰ | ~58 | èˆŠç‰ˆæª¢è¦–è¡¨é é¢ |
| `crowdsourcing/index.blade.php` | ~24 | å·²éƒ¨åˆ†ç¿»è­¯ï¼Œè£œé½Šå‰©é¤˜ |
| `maps/index.blade.php` | ~54 | æ­·å²åœ°åœ–é é¢ |
| `query_playground/` | ~127 | æŸ¥è©¢ç·´ç¿’å ´æ—¥èªŒé é¢ |

**æ­¥é©Ÿï¼š**
- [ ] 6C-1ï¼šç¿»è­¯ `components/` å‰©é¤˜ 5 å€‹å…±ç”¨ Blade å…ƒä»¶ï¼ˆ`forms/` å…©å€‹å·²åœ¨ 6A-0 å®Œæˆï¼‰
- [ ] 6C-2ï¼šç¿»è­¯ `view/`ï¼ˆèˆŠç‰ˆï¼‰ï¼Œè£œé½Š `crowdsourcing/`
- [ ] 6C-3ï¼šç¿»è­¯ `maps/`ã€`query_playground/`

---

### Phase 6Dï¼šç®¡ç†å“¡èˆ‡å¾Œå°é é¢ï¼ˆä½Žå„ªå…ˆï¼‰

| ç›®éŒ„/æª”æ¡ˆ | ä¼°è¨ˆè¡Œæ•¸ | èªªæ˜Ž |
|----------|---------|------|
| `admin/` 7 å€‹æª”æ¡ˆ | ~753 | æ‰¹æ¬¡åŒ¯å…¥ã€è¡¨ç¶­è­·ã€é—œä¿‚ä¿®å¾© |
| `cbdbapi/*.blade.php` | ~217 | å¤–éƒ¨ API æœå°‹çµæžœé  |
| `manage/` 4 å€‹æª”æ¡ˆ | ~229 | ç”¨æˆ¶ç®¡ç†ã€äººç‰©åˆä½µï¼ˆå« `_role-descriptions.blade.php`ï¼‰ |

**æ­¥é©Ÿï¼š**
- [ ] 6D-1ï¼šç¿»è­¯ `manage/` å››å€‹æª”æ¡ˆï¼ˆç”¨æˆ¶ç®¡ç† + äººç‰©åˆä½µï¼Œæœ‰ç®¡ç†å“¡æœƒç”¨ï¼‰
- [ ] 6D-2ï¼šç¿»è­¯ `admin/` ä¸ƒå€‹é é¢ï¼ˆæ‰¹æ¬¡å·¥å…·ï¼Œä½Žé »ä½¿ç”¨ï¼‰
- [ ] 6D-3ï¼šç¿»è­¯ `cbdbapi/`ï¼ˆAPI çµæžœé¡¯ç¤ºé ï¼‰

---

### Phase 6Eï¼šPhase 2 æ®˜ç•™è£œæ¼ï¼ˆä¸­å„ªå…ˆï¼‰

**èƒŒæ™¯ï¼š** Phase 2 å·²å®Œæˆ `layouts/`ã€`codes/`ã€`operations/` çš„éƒ¨åˆ†ç¿»è­¯ï¼Œä½†ä»æœ‰ç¡¬ç·¨ç¢¼ä¸­æ–‡æ®˜ç•™ã€‚

| æª”æ¡ˆ | èªªæ˜Ž |
|------|------|
| `codes/show.blade.php` | `ä¿®æ”¹`ã€`åˆªé™¤`ã€`æ²’æœ‰è³‡æ–™`ã€`ä¸Šä¸€é `/`ä¸‹ä¸€é `ã€`è·³è½‰åˆ° ID`/`è·³è½‰` æŒ‰éˆ• |
| `operations/index.blade.php` | éƒ¨åˆ† badge æ–‡å­—èˆ‡èªªæ˜Žå­—ä¸² |
| `layouts/app.blade.php` | æª¢æŸ¥æ˜¯å¦æœ‰æ®˜ç•™çš„ç¡¬ç·¨ç¢¼ä¸­æ–‡ |

**æ­¥é©Ÿï¼š**
- [ ] 6E-1ï¼šè£œé½Š `codes/show.blade.php`ï¼ˆ`ä¿®æ”¹`ã€`åˆªé™¤`ã€åˆ†é å°ŽèˆªæŒ‰éˆ•ã€`æ²’æœ‰è³‡æ–™`ï¼‰
- [ ] 6E-2ï¼šè£œé½Š `operations/index.blade.php` æ®˜ç•™å­—ä¸²
- [ ] 6E-3ï¼šæŽƒæ `layouts/app.blade.php`ï¼Œè£œé½Šä»»ä½•æ®˜ç•™ä¸­æ–‡

---

### Phase 6 æ•´é«”çµ±è¨ˆ

| Phase | å„ªå…ˆ | æª”æ¡ˆæ•¸ | ä¼°è¨ˆè¡Œæ•¸ | ç‹€æ…‹ |
|-------|------|--------|---------|------|
| 6A biogmains äººç‰©è¡¨å–® | æœ€é«˜ | 55 | ~1,314 | âœ… å®Œæˆï¼ˆ2026-06-02ï¼‰ |
| 6B ä½¿ç”¨è€…æµç¨‹é é¢ | é«˜ | ~10 | ~371 | âœ… å®Œæˆï¼ˆ2026-06-02ï¼‰ |
| 6C æ¨™æº–åŠŸèƒ½é é¢ | ä¸­ | ~8 | ~402 | âœ… å®Œæˆï¼ˆ2026-06-02ï¼‰ |
| 6D ç®¡ç†å“¡å¾Œå°é é¢ | ä½Ž | ~15 | ~1,350 | âœ… å®Œæˆï¼ˆ2026-06-02ï¼‰ |
| 6E Phase 2 æ®˜ç•™è£œæ¼ | ä¸­ | ~3 | ~30 | âœ… å®Œæˆï¼ˆç¢ºèªå·²ç„¡æ®˜ç•™ï¼Œå‰ Phase å·²è£œé½Šï¼‰ |
| **åˆè¨ˆ** | | **~91** | **~3,450** | |

> æŽƒææ—¥æœŸï¼š2026-06-02ã€‚è¡Œæ•¸ç‚ºä¼°è¨ˆå€¼ï¼ˆå«éƒ¨åˆ†éœ€è·³éŽçš„ PHP å‹•æ…‹è®Šæ•¸ï¼‰ã€‚6A åŒ…å« components/forms/ å…©å€‹å…±ç”¨å…ƒä»¶ï¼ˆ6A-0ï¼‰ã€‚

### é è¨­èªžè¨€ï¼ˆconfig/app.phpï¼‰

```php
'locale'           => 'zh-TW',        // ç³»çµ±é è¨­èªžè¨€ç‚ºç¹é«”ä¸­æ–‡
'available_locales' => ['zh-TW', 'en'], // å¯ç”¨èªžè¨€ï¼ˆè‡ªè¨‚ keyï¼Œç”± SetLocaleMiddleware è®€å–ï¼‰
'fallback_locale'  => 'en',            // ç•¶ zh-TW æŸ key ç¼ºå¤±æ™‚ï¼Œå›žé€€åˆ°è‹±æ–‡
```

æ­¤è¨­å®šæ–¼ Phase 0 å®Œæˆã€‚ç³»çµ±å•Ÿå‹•æ™‚ä»¥ç¹é«”ä¸­æ–‡ç‚ºé è¨­ï¼›ç”¨æˆ¶é¦–æ¬¡è¨ªå•è‹¥ç€è¦½å™¨èªžè¨€åå¥½ç‚º `zh-*`ï¼Œç¶­æŒç¹é«”ä¸­æ–‡ï¼›è‹¥åå¥½ç‚ºå…¶ä»–èªžè¨€ï¼ŒSetLocaleMiddleware æœƒè®€å–ä¸¦è¨­ç‚ºè‹±æ–‡ã€‚

---

---

## 10. Phase 7ï¼šæŽ§åˆ¶å™¨å­—ä¸²ç¿»è­¯ + æ®˜ç•™ä¿®è£œ

**èƒŒæ™¯ï¼š** 2026-06-03 ç³»çµ±æ€§æŽƒæç™¼ç¾ï¼ŒPhase 6 é›–ç„¶æ¶µè“‹äº† Blade è¦–åœ–çš„ç¿»è­¯ï¼Œä½†ä»¥ä¸‹å…©å¤§é¡žåˆ¥è¢«éºæ¼ï¼š  
ï¼ˆ1ï¼‰æŽ§åˆ¶å™¨é€éŽ `page_title`ã€`page_description`ã€`breadcrumbs` ç­‰ PHP å­—ä¸²å‚³å…¥è¦–åœ–çš„ç¡¬ç·¨ç¢¼ä¸­æ–‡ï¼›  
ï¼ˆ2ï¼‰codes/edit.blade.php çš„è¡¨å–®èªªæ˜Žæ–‡å­—åŠéƒ¨åˆ† JS å­—ä¸²ã€‚  
æ­¤å¤–ä½¿ç”¨è€…ä¹Ÿè¦æ±‚èª¿æ•´è‹¥å¹² UI ç´°ç¯€ï¼ˆAdd â†’ New æŒ‰éˆ•ç­‰ï¼‰ã€‚

**åˆ†æ”¯ï¼š** `feature/i18n-phase7-controller-strings`  
**è¨ˆåŠƒæ—¥æœŸï¼š** 2026-06-03  

---

### Phase 7Aï¼šBasicInformation åŠå­æŽ§åˆ¶å™¨ï¼ˆæœ€é«˜å„ªå…ˆï¼‰

**ç¯„åœï¼š** ä¸‹åˆ— 13 å€‹æŽ§åˆ¶å™¨çš„ `index`ï¼`create`ï¼`show`ï¼`edit` å››å€‹æ–¹æ³•ï¼Œå‡ä»¥ PHP å­—ä¸²å‚³éž `page_title`ã€`page_description`ã€`breadcrumbs`ï¼Œå°Žè‡´èªžè¨€åˆ‡æ›å¾Œä»é¡¯ç¤ºä¸­æ–‡ã€‚

| æŽ§åˆ¶å™¨ï¼ˆ`app/Http/Controllers/`ï¼‰ | ç•¶å‰ `page_title`ï¼ˆzh-TW ç¡¬ç·¨ç¢¼ï¼‰ | æ”¹ç”¨çš„ lang key |
|----------------------------------|----------------------------------|----------------|
| `BasicInformationController` | `'äººç‰©åŸºæœ¬è³‡æ–™'` | `person.person_records`ï¼ˆæ–°å¢žï¼‰ |
| `BasicInformationOfficesController` | `'å®˜å'` | `person.tab_postings` |
| `BasicInformationAddressesController` | `'åœ°å€'` | `person.addresses` |
| `BasicInformationAltnamesController` | `'åˆ¥å'` | `person.alt_name` |
| `BasicInformationAssocController` | `'ç¤¾æœƒé—œä¿‚'` | `person.associations` |
| `BasicInformationEntriesController` | `'å…¥ä»•'` | `person.entry` |
| `BasicInformationEventsController` | `'äº‹ä»¶'` | `person.events` |
| `BasicInformationKinshipController` | `'è¦ªå±¬'` | `person.tab_kinship` |
| `BasicInformationPossessionController` | `'è²¡ç”¢'` | `person.possessions` |
| `BasicInformationSocialInstController` | `'ç¤¾äº¤æ©Ÿæ§‹'` | `person.tab_social_institutions` |
| `BasicInformationSourcesController` | `'å‡ºè™•'` | `person.tab_sources` |
| `BasicInformationStatusesController` | `'ç¤¾æœƒå€åˆ†'` | `person.status` |
| `BasicInformationTextsController` | `'è‘—è¿°'` | `person.tab_texts` |

**Breadcrumb æ›¿æ›è¦å‰‡ï¼ˆæ¯å€‹æŽ§åˆ¶å™¨çš„æ¯å€‹ action å‡é©ç”¨ï¼‰ï¼š**

```php
// æ”¹å‰ï¼ˆç¡¬ç·¨ç¢¼ç¤ºä¾‹ï¼Œä»¥ Offices ç‚ºä¾‹ï¼‰
'page_title'       => 'å®˜å',
'page_description' => 'åŸºæœ¬ä¿¡æ¯è¡¨ å®˜å',
'breadcrumbs' => [
    ['label' => 'äººç‰©åŸºæœ¬è³‡æ–™', 'url' => route('basicinformation.index')],
    ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
    ['label' => 'å®˜å', 'url' => route('basicinformation.offices.index', $id)],
    ['label' => 'æ–°å¢ž', 'url' => '#'],
],

// æ”¹å¾Œï¼ˆä½¿ç”¨ __() ç¿»è­¯ helperï¼‰
'page_title'       => __('person.tab_postings'),
'page_description' => __('person.person_records') . ' â€“ ' . __('person.tab_postings'),
'breadcrumbs' => [
    ['label' => __('person.person_records'), 'url' => route('basicinformation.index')],
    ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
    ['label' => __('person.tab_postings'), 'url' => route('basicinformation.offices.index', $id)],
    ['label' => __('common.add'), 'url' => '#'],
],
```

**éœ€æ–°å¢ž lang keyï¼š**

| Key | zh-TW | en |
|-----|-------|----|
| `person.person_records` | `'äººç‰©åŸºæœ¬è³‡æ–™'` | `'Person Records'` |

> **æ³¨æ„ï¼š** `page_title` å‚³å…¥è¦–åœ–å¾Œç›´æŽ¥è¼¸å‡ºç‚º PHP å­—ä¸²ï¼Œåœ¨æŽ§åˆ¶å™¨ä¸­å‘¼å« `__()` æ™‚å·²æ ¹æ“šç•¶å‰ locale ç¿»è­¯ï¼Œä¸éœ€è¦é¡å¤–è™•ç†ã€‚

**æ­¥é©Ÿï¼š**
- [x] 7A-0ï¼šåœ¨ `resources/lang/zh-TW/person.php` èˆ‡ `resources/lang/en/person.php` æ–°å¢ž `person_records` key
- [x] 7A-1ï¼šæ›´æ–° `BasicInformationController` å››å€‹ action
- [x] 7A-2ï¼šæ›´æ–° `BasicInformationOfficesController` å››å€‹ action
- [x] 7A-3ï¼šæ›´æ–° `BasicInformationAddressesController` å››å€‹ action
- [x] 7A-4ï¼šæ›´æ–° `BasicInformationAltnamesController` å››å€‹ action
- [x] 7A-5ï¼šæ›´æ–° `BasicInformationAssocController` å››å€‹ action
- [x] 7A-6ï¼šæ›´æ–° `BasicInformationEntriesController` å››å€‹ action
- [x] 7A-7ï¼šæ›´æ–° `BasicInformationEventsController` å››å€‹ action
- [x] 7A-8ï¼šæ›´æ–° `BasicInformationKinshipController` å››å€‹ action
- [x] 7A-9ï¼šæ›´æ–° `BasicInformationPossessionController` å››å€‹ action
- [x] 7A-10ï¼šæ›´æ–° `BasicInformationSocialInstController` å››å€‹ action
- [x] 7A-11ï¼šæ›´æ–° `BasicInformationSourcesController` å››å€‹ action
- [x] 7A-12ï¼šæ›´æ–° `BasicInformationStatusesController` å››å€‹ action
- [x] 7A-13ï¼šæ›´æ–° `BasicInformationTextsController` å››å€‹ action

---

### Phase 7Bï¼šDashboard æ“ä½œé¡žåž‹æ¨™ç±¤ï¼ˆé«˜å„ªå…ˆï¼‰

**å•é¡Œï¼š** `DashboardController` ä¸­çš„ `$typeNames` å°ç…§è¡¨ä½¿ç”¨ç¡¬ç·¨ç¢¼ä¸­æ–‡ï¼Œå°Žè‡´ Dashboard çš„ã€Œæ“ä½œé¡žåž‹çµ±è¨ˆã€å¡ç‰‡åœ¨è‹±æ–‡æ¨¡å¼ä¸‹ä»é¡¯ç¤ºã€Œæ–°å¢žã€ã€ã€Œä¿®æ”¹ã€ã€ã€Œåˆªé™¤ã€ç­‰ä¸­æ–‡ã€‚

**ä½ç½®ï¼š** `app/Http/Controllers/DashboardController.php`ï¼Œ`page_title` åŠ `$typeNames` é™£åˆ—

```php
// æ”¹å‰
'page_title' => 'ç³»çµ±ç¸½è¦½',
$typeNames = [
    Operation::TYPE_CREATE       => 'æ–°å¢ž',
    Operation::TYPE_UPDATE_FULL  => 'ä¿®æ”¹',
    Operation::TYPE_UPDATE       => 'ä¿®æ”¹',
    Operation::TYPE_DELETE       => 'åˆªé™¤',
    Operation::TYPE_PROPOSAL_CREATE => 'ææ¡ˆï¼ˆæ–°å¢žï¼‰',
    Operation::TYPE_PROPOSAL_UPDATE => 'ææ¡ˆï¼ˆä¿®æ”¹ï¼‰',
];
$typeName = $typeNames[$item->op_type] ?? 'æœªçŸ¥';

// æ”¹å¾Œ
'page_title' => __('nav.dashboard'),
$typeNames = [
    Operation::TYPE_CREATE       => __('operations.op_create'),
    Operation::TYPE_UPDATE_FULL  => __('operations.op_update'),
    Operation::TYPE_UPDATE       => __('operations.op_update'),
    Operation::TYPE_DELETE       => __('operations.op_delete'),
    Operation::TYPE_PROPOSAL_CREATE => __('operations.op_proposal_create'),
    Operation::TYPE_PROPOSAL_UPDATE => __('operations.op_proposal_update'),
];
$typeName = $typeNames[$item->op_type] ?? __('common.unknown');
```

> **æ³¨æ„ï¼š** `operations.php` å·²æœ‰ `op_create`ã€`op_update`ã€`op_delete`ã€`op_proposal_create`ã€`op_proposal_update` ç­‰ keyï¼ˆzh-TW èˆ‡ en å‡å·²å­˜åœ¨ï¼‰ï¼Œä¸éœ€æ–°å¢ž lang keyã€‚

**æ­¥é©Ÿï¼š**
- [x] 7B-1ï¼šæ›´æ–° `DashboardController`ï¼ˆpage_title + typeNamesï¼‰

---

### Phase 7Cï¼šViewTableController é é¢æ¨™é¡Œï¼ˆé«˜å„ªå…ˆï¼‰

**å•é¡Œï¼š**
1. `/view` æ¸…å–®é ï¼š`page_title => 'æª¢è¦–è¡¨ç¸½è¦½'` ç¡¬ç·¨ç¢¼
2. `/view/{key}` è©³ç´°é ï¼ˆå¦‚ `/view/biog-addr-data`ï¼‰ï¼š`page_title` åŠ `page_description` å–è‡ª `$definition['title']`ï¼`$definition['description']`ï¼ˆä¸­æ–‡ç¡¬ç·¨ç¢¼ï¼‰ï¼›Archer éºµåŒ…å±‘ `<a href='/view'>æª¢è¦–è¡¨ç¸½è¦½</a>` ç¡¬ç·¨ç¢¼

**ä½ç½®ï¼š** `app/Http/Controllers/ViewTableController.php`

```php
// æ”¹å‰ï¼ˆæ¸…å–®é ï¼‰
'page_title' => 'æª¢è¦–è¡¨ç¸½è¦½',

// æ”¹å¾Œ
'page_title' => __('nav.views_overview'),
```

```php
// æ”¹å‰ï¼ˆè©³ç´°é ï¼Œarrow = breadcrumbï¼‰
'page_title'       => $definition['title'] ?? $effectiveKey,
'page_description' => $definition['description'] ?? '',
'archer'           => "<li class='breadcrumb-item'><a href='/view'>æª¢è¦–è¡¨ç¸½è¦½</a></li>",

// æ”¹å¾Œï¼šåˆ©ç”¨ views.php å·²æœ‰çš„ç¿»è­¯
$viewLangKey = 'view_' . str_replace('-', '_', $effectiveKey);
// views.php å·²æœ‰æ‰€æœ‰ view_* çš„æ¨™é¡Œç¿»è­¯ï¼Œkey ä¸å­˜åœ¨æ™‚ __() å›žå‚³ key æœ¬èº«
'page_title'       => __('views.' . $viewLangKey) !== 'views.' . $viewLangKey
                          ? __('views.' . $viewLangKey)
                          : ($definition['title'] ?? $effectiveKey),
'page_description' => __('views.' . $viewLangKey . '_desc', [], null)  // å¾…è£œï¼ˆè¦‹ä¸‹æ–¹èªªæ˜Žï¼‰
                          ?: ($definition['description'] ?? ''),
'archer'           => "<li class='breadcrumb-item'><a href='/view'>" . __('nav.views_overview') . "</a></li>",
```

**å¾…è£œèªªæ˜Žï¼š** `views.php` ç›®å‰åªæœ‰æ¨™é¡Œï¼ˆ`view_biog_addr_data`ï¼‰ç„¡èªªæ˜Žï¼ˆ`view_biog_addr_data_desc`ï¼‰ã€‚èªªæ˜Žç¿»è­¯ç‚º 17 æ¢é•·å¥ï¼Œéœ€äººå·¥æ’°å¯«å¾Œæ–°å¢žè‡³ `resources/lang/zh-TW/views.php` èˆ‡ `resources/lang/en/views.php`ã€‚å¯å…ˆä»¥ `''`ï¼ˆç©ºå­—ä¸²ï¼‰ä½œç‚ºè‹±æ–‡èªªæ˜Žï¼Œå¾…ç¿»è­¯å¾Œè£œé½Šã€‚

**æ­¥é©Ÿï¼š**
- [x] 7C-1ï¼šæ›´æ–° `ViewTableController`ï¼ˆæ¸…å–®é  page_titleï¼Œè©³ç´°é  title/breadcrumbï¼‰
- [x] 7C-2ï¼šæ–°å¢ž `views.php` èªªæ˜Ž keyï¼ˆ`view_*_desc`ï¼Œ18 æ¢ï¼‰ä¸¦è£œé½Š enï¼zh-TW ç¿»è­¯

---

### Phase 7Dï¼šcodes/edit.blade.php è¡¨å–®èªªæ˜Žæ–‡å­—ï¼ˆé«˜å„ªå…ˆï¼‰

**å•é¡Œï¼š** `resources/views/codes/edit.blade.php` ä¸­å¤šè™•ç¡¬ç·¨ç¢¼ä¸­æ–‡èªªæ˜Žï¼ŒåŒ…å«ï¼š

| ä½ç½® | ç•¶å‰ç¡¬ç·¨ç¢¼ä¸­æ–‡ | æ”¹ç”¨ key |
|------|---------------|---------|
| L21ï¼š`TEXT_CODES` è¡¨çš„ä½œè€…æ¨™ç±¤ | `ä½œè€…` | `codes.author_label` |
| L23ï¼šè¼‰å…¥ä½œè€…ç‹€æ…‹ | `è¼‰å…¥ä½œè€…ä¸­...` | `codes.loading_author` |
| L39/41ï¼šmodified_by/date æç¤ºï¼ˆPHP å­—ä¸²ï¼‰ | `'æ¬„ä½å…§å®¹æäº¤å¾Œæœƒè¢«æ›¿æ›ç‚ºï¼š'` | `codes.field_will_be_replaced` |
| L56ï¼šTEXT_INSTANCE_DATA c_textid èªªæ˜Ž | `è«‹ç¢ºä¿ TEXT_CODES è¡¨ä¸­å­˜åœ¨é€™æœ¬æ›¸çš„ c_textidï¼Œå†è¤‡è£½ ID å¡«å…¥` | `codes.text_codes_copy_hint` |
| L80/89ï¼šADDR_BELONGS_DATA c_addr_id / c_belongs_to èªªæ˜Ž | `è«‹å¾ž ADDR_CODES è¡¨ä¸­è¤‡è£½ c_addr_id å¡«å…¥` | `codes.addr_copy_hint` |
| L110ï¼šææ¡ˆèªªæ˜Žæ¬„ä½å¿½ç•¥æç¤º | `å¦‚æžœç›´æŽ¥å„²å­˜ï¼Œæ­¤æ¬„ä½æœƒè¢«å¿½ç•¥ã€‚` | `codes.proposal_ignore_hint` |

> **æ³¨æ„ï¼š** L56ã€L80ã€L89 çš„èªªæ˜Žå«æœ‰ HTML é€£çµï¼Œä½¿ç”¨ `{!! __('codes.xxx') !!}` æ³¨å…¥ï¼ˆlang æª”ç”±æˆ‘å€‘æŽ§åˆ¶ï¼Œç„¡ XSS é¢¨éšªï¼‰ã€‚

**å¦ï¼šJS å­—ä¸²ï¼ˆ`<script>` å€å¡Šå…§ï¼‰ï¼š**

| ä½ç½® | ç•¶å‰ç¡¬ç·¨ç¢¼ | å»ºè­°è™•ç†æ–¹å¼ |
|------|-----------|------------|
| L159ï¼šç„¡ c_textid æç¤º | `ç„¡ c_textidï¼Œç„¡æ³•è¼‰å…¥ä½œè€…` | `{!! Js::from(__('codes.no_textid_msg')) !!}` æ³¨å…¥ |
| L205/208ï¼šç„¡ä½œè€…è³‡æ–™ / è¼‰å…¥å¤±æ•— | `ç„¡ä½œè€…è³‡æ–™` / `è¼‰å…¥å¤±æ•—` | åŒä¸Š |
| L248/256/259ï¼šLoad Data çµæžœ alert | æ··ç”¨ä¸­è‹± | åŒä¸Š |
| L284ï¼šäººç‰©æœå°‹ placeholder | `è¼¸å…¥å§“åæˆ– ID æœå°‹äººç‰©` | åŒä¸Š |

**éœ€æ–°å¢ž lang keyï¼ˆ`resources/lang/zh-TW/codes.php` èˆ‡ `resources/lang/en/codes.php`ï¼‰ï¼š**

| Key | zh-TW | en |
|-----|-------|----|
| `author_label` | `'ä½œè€…'` | `'Author'` |
| `loading_author` | `'è¼‰å…¥ä½œè€…ä¸­...'` | `'Loading author...'` |
| `field_will_be_replaced` | `'æ¬„ä½å…§å®¹æäº¤å¾Œæœƒè¢«æ›¿æ›ç‚ºï¼š'` | `'This field will be replaced upon submission with: '` |
| `text_codes_copy_hint` | `'è«‹ç¢ºä¿ <a href="/codes/TEXT_CODES" target="_blank">TEXT_CODES</a> è¡¨ä¸­å­˜åœ¨é€™æœ¬æ›¸çš„ c_textidï¼Œå†è¤‡è£½ ID å¡«å…¥'` | `'Make sure the book\'s c_textid exists in the <a href="/codes/TEXT_CODES" target="_blank">TEXT_CODES</a> table, then copy the ID here.'` |
| `addr_copy_hint` | `'è«‹å¾ž <a href="/codes/ADDR_CODES" target="_blank">ADDR_CODES</a> è¡¨ä¸­è¤‡è£½ c_addr_id å¡«å…¥'` | `'Copy c_addr_id from the <a href="/codes/ADDR_CODES" target="_blank">ADDR_CODES</a> table.'` |
| `proposal_ignore_hint` | `'å¦‚æžœç›´æŽ¥å„²å­˜ï¼Œæ­¤æ¬„ä½æœƒè¢«å¿½ç•¥ã€‚'` | `'If you save directly, this field will be ignored.'` |
| `no_textid_msg` | `'ç„¡ c_textidï¼Œç„¡æ³•è¼‰å…¥ä½œè€…'` | `'No c_textid, cannot load author.'` |
| `no_author_data` | `'ç„¡ä½œè€…è³‡æ–™'` | `'No author data'` |
| `load_failed` | `'è¼‰å…¥å¤±æ•—'` | `'Load failed'` |
| `load_no_data_alert` | `'Load Dataï¼šç„¡æŸ¥è©¢çµæžœ'` | `'Load Data: No results found'` |
| `load_success_alert` | `'Load Dataï¼šå·²æ›´æ–° c_instance_title_chn èˆ‡ c_instance_title'` | `'Load Data: Updated c_instance_title_chn and c_instance_title'` |
| `load_failed_alert` | `'Load Dataï¼šæŸ¥è©¢å¤±æ•—'` | `'Load Data: Query failed'` |
| `person_search_placeholder` | `'è¼¸å…¥å§“åæˆ– ID æœå°‹äººç‰©'` | `'Enter name or ID to search'` |

**æ­¥é©Ÿï¼š**
- [x] 7D-1ï¼šæ–°å¢žä¸Šè¿° 13 å€‹ lang key è‡³ zh-TW/codes.php èˆ‡ en/codes.php
- [x] 7D-2ï¼šæ›´æ–° `codes/edit.blade.php`ï¼ˆBlade èªªæ˜Žæ–‡å­— 6 è™• + JS å­—ä¸² 7 è™•ï¼‰

---

### Phase 7Eï¼šUI ç´°ç¯€èª¿æ•´ï¼ˆä¸­å„ªå…ˆï¼‰

#### 7E-1ï¼šAdd â†’ New æŒ‰éˆ•ï¼ˆä½¿ç”¨è€…æŒ‡å®šï¼‰âœ…

**å•é¡Œï¼š** `basicinformation/index.blade.php`ï¼ˆåŠæ‰€æœ‰å­æ¨¡çµ„ index é é¢ï¼‰çš„ã€Œæ–°å¢žã€æŒ‰éˆ•ï¼Œè‹±æ–‡æ¨¡å¼é¡¯ç¤º `Add`ï¼Œä½¿ç”¨è€…å¸Œæœ›æ”¹ç‚º `New`ã€‚

**æ–¹æ¡ˆï¼š** å°‡ `resources/lang/en/common.php` çš„ `'add' => 'Add'` æ”¹ç‚º `'add' => 'New'`ã€‚  
ï¼ˆzh-TW `'add' => 'æ–°å¢ž'` ä¸è®Šï¼›æ‰€æœ‰ä½¿ç”¨ `__('common.add')` çš„æŒ‰éˆ•çµ±ä¸€å—ç›Šã€‚ï¼‰

**å·²å®Œæˆï¼š** 2026-06-03

#### 7E-2ï¼šoffices/index.blade.php è¡¨é ­ï¼ˆä½Žå„ªå…ˆï¼‰âœ…

**å•é¡Œï¼š** `resources/views/biogmains/offices/index.blade.php` L26â€“27 çš„è¡¨é ­ `sequence`ã€`posting_id` ç‚ºè‹±æ–‡è³‡æ–™åº«æ¬„ä½åç¨±ï¼Œæœªä½¿ç”¨ç¿»è­¯ helperã€‚  
**ä¿®æ­£ï¼š**  
- `<th>sequence</th>` â†’ `<th>{{ __('biogmains.sequence') }}</th>`ï¼ˆ`biogmains.sequence` å·²æœ‰ç¿»è­¯ï¼šzh-TW 'æ¬¡åº' / en 'Sequence'ï¼‰  
- `<th>posting_id</th>` â†’ `<th>{{ __('person.posting_id') }}</th>`ï¼ˆ`person.posting_id` å·²æœ‰ç¿»è­¯ï¼šzh-TW 'ä»»å®˜ ID' / en 'Posting ID'ï¼‰

**å·²å®Œæˆï¼š** 2026-06-03

#### 7E-3ï¼šbasicinformation/edit.blade.php Xing/Ming æ¨™ç±¤ï¼ˆä½Žå„ªå…ˆï¼‰

**å•é¡Œï¼š** L55ã€L65 æœ‰ç¡¬ç·¨ç¢¼è‹±æ–‡æ¨™ç±¤ `Xing`ã€`Ming`ï¼ˆå¤–æ–‡å§“/åæ¬„ä½ï¼‰ã€‚  
**ç¾ç‹€ï¼š** é€™å…©å€‹æ¬„ä½æ˜¯æ¼¢èªžæ‹¼éŸ³å­¸è¡“è¡“èªžï¼Œè‹±æ–‡ä½¿ç”¨è€…åŒæ¨£ä»¥ Xing/Ming ç†è§£ï¼›ä¸éœ€ç¿»è­¯ï¼Œä½†å¯åŠ  title æç¤ºã€‚  
**æ±ºå®šï¼š** æš«ä¸ä¿®æ”¹ï¼Œå¯åœ¨ Phase 7 å¾ŒæœŸè¦–éœ€è¦è£œå……ã€‚

---

### Phase 7Fï¼šå…¶ä»–æ®˜ç•™æŽ§åˆ¶å™¨ï¼ˆä½Žå„ªå…ˆï¼Œå¯æ‰¹æ¬¡ï¼‰

ä»¥ä¸‹æŽ§åˆ¶å™¨åŒæ¨£æœ‰ `page_title`ï¼`page_description` ç¡¬ç·¨ç¢¼ä¸­æ–‡ï¼Œä½†ä½¿ç”¨é »çŽ‡è¼ƒä½Žï¼š

| æŽ§åˆ¶å™¨ | ç•¶å‰ page_title | å»ºè­° key |
|--------|----------------|---------|
| `CrowdsourcingController` | `'æœ€è¿‘çœ¾åŒ…éŒ„å…¥è¨˜éŒ„'` | `nav.crowdsourcing_records` |
| `ManagementController` | `'ç”¨æˆ¶ç®¡ç†'` | `nav.user_management` |
| `UserProfileController` | `'å€‹äººè³‡æ–™è¨­å®š'` | `common.profile_settings` |
| `AdminAuditLogController` | `'å¯©è¨ˆæ—¥èªŒ'` | `admin.audit_logs` |
| `AiFillLogController` | `'AI å¡«å……æ—¥èªŒ'` | `admin.ai_fill_logs` |
| `CbdbTableMaintenanceController` | `'CBDB å…§éƒ¨è¡¨ç¶­è­·'` | `admin.table_maintenance` |
| `AdminBatchLoad*Controller` (3 å€‹) | å„è‡ªçš„æ‰¹æ¬¡å·¥å…·æ¨™é¡Œ | `admin.*` |
| `WikiMaintenanceController` | `'Wiki å°ç…§è³‡æ–™ç¶­è­·'` | `admin.wiki_maintenance` |
| `CodesController` | `'å…¨éƒ¨è¡¨æ ¼'` | `nav.all_tables` |
| `UnidirectionalRelationshipRepairController` | `'å–®å‘é—œä¿‚ä¿®å¾©'` | ï¼ˆå¯æ–°å¢ž admin keyï¼‰ |

**æ­¥é©Ÿï¼š**
- [x] 7F-1ï¼šé€ä¸€æ›´æ–°ä¸Šè¿°æŽ§åˆ¶å™¨ï¼ˆåƒè€ƒ 7A çš„æ›¿æ›è¦å‰‡ï¼‰

---

### Phase 7 æ•´é«”çµ±è¨ˆ

| Sub-phase | å„ªå…ˆ | æ¶‰åŠæª”æ¡ˆ | èªªæ˜Ž | ç‹€æ…‹ |
|-----------|------|---------|------|------|
| 7A biogmains 13 å€‹æŽ§åˆ¶å™¨ | æœ€é«˜ | 13 controllers + 2 lang | page_title/description/breadcrumbs | âœ… å®Œæˆï¼ˆ2026-06-03ï¼‰ |
| 7B Dashboard æ“ä½œé¡žåž‹ | é«˜ | 1 controller | typeNames + page_title | âœ… å®Œæˆï¼ˆ2026-06-03ï¼‰ |
| 7C ViewTableController | é«˜ | 1 controller + 2 lang | æ¸…å–®é  + è©³ç´°é  title/breadcrumb | âœ… å®Œæˆï¼ˆ2026-06-03ï¼‰ |
| 7D codes/edit.blade.php | é«˜ | 1 view + 2 lang | è¡¨å–®èªªæ˜Ž + JS å­—ä¸² | âœ… å®Œæˆï¼ˆ2026-06-03ï¼‰ |
| 7E UI ç´°ç¯€ï¼ˆAddâ†’New ç­‰ï¼‰ | ä¸­ | 1 lang + 2 view | æŒ‰éˆ•æ–‡å­—ã€è¡¨é ­ | âœ… å®Œæˆï¼ˆ2026-06-03ï¼‰ |
| 7F å…¶ä»–ä½Žé »æŽ§åˆ¶å™¨ | ä½Ž | 12 controllers | ç®¡ç†å“¡å·¥å…·ç­‰ | âœ… å®Œæˆï¼ˆ2026-06-03ï¼‰ |

> æŽƒææ—¥æœŸï¼š2026-06-03ã€‚å„ªå…ˆç´šä¾ä½¿ç”¨è€…æŽ¥è§¸é »çŽ‡æŽ’åºï¼›7D å› ç‚ºä½¿ç”¨è€…æŒ‡å®š ADDR_BELONGS_DATA å•é¡Œï¼Œç‰¹åˆ¥æå‡ç‚ºé«˜å„ªå…ˆã€‚

---

---

## 11. Phase 8ï¼šQueryPlayground React å…ƒä»¶ + Operations æŽ§åˆ¶å™¨ i18n

**èƒŒæ™¯ï¼š** Phase 7 å®Œæˆäº†æŽ§åˆ¶å™¨å­—ä¸²ç¿»è­¯ï¼Œä½†ä»¥ä¸‹å…©å€‹å€å¡Šè¢«éºæ¼ï¼š  
ï¼ˆ1ï¼‰QueryPlayground çš„ä¸‰å€‹æ ¸å¿ƒ React å…ƒä»¶ï¼ˆ`NlQueryPanel.tsx`ã€`QbeBuilder.tsx`ã€`HistoricalQaPanel.tsx`ï¼‰å®Œå…¨æœªæŽ¥å…¥ `useTranslation`ï¼Œæ‰€æœ‰ UI å­—ä¸²ä»ç‚ºç¡¬ç·¨ç¢¼ä¸­æ–‡ã€‚`en/query.php` ä¸­å·²å­˜åœ¨è¨±å¤šå°æ‡‰çš„ç¿»è­¯ keyï¼Œä½†å…ƒä»¶å¾žæœªå‘¼å« `t()`ã€‚  
ï¼ˆ2ï¼‰`OperationsController` çš„ `index` æ–¹æ³• `page_title`ï¼`page_description` èˆ‡ `revert` æ–¹æ³•çš„ flash è¨Šæ¯åŠ RuntimeException è¨Šæ¯ï¼Œä»ä»¥ç¡¬ç·¨ç¢¼ä¸­æ–‡å‚³éžã€‚

**ç™¼ç¾æ—¥æœŸï¼š** 2026-06-03ï¼ˆåŸºæ–¼ https://input.cbdb.fas.harvard.edu/operations èˆ‡ /app/query-playground çš„å¯¦éš›æˆªåœ–ï¼‰  
**åˆ†æ”¯ï¼š** `feature/i18n-phase8-react-components-operations`

---

### Phase 8Aï¼šNlQueryPanel.tsx i18n æŽ¥å…¥ï¼ˆé«˜å„ªå…ˆï¼‰

**å•é¡Œï¼š** `resources/js/inertia/components/QueryPlayground/NlQueryPanel.tsx` ç„¡ä»»ä½• `useTranslation` å‘¼å«ï¼Œæ‰€æœ‰å­—ä¸²å‡ç¡¬ç·¨ç¢¼ã€‚

**å·²å­˜åœ¨æ–¼ `en/query.php` çš„ keyï¼ˆåªéœ€æŽ¥ç·šï¼Œä¸é ˆæ–°å¢žï¼‰ï¼š**

| å…ƒä»¶ä¸­çš„ä¸­æ–‡å­—ä¸² | å°æ‡‰ç¾æœ‰ key |
|----------------|------------|
| `ç”¨è‡ªç„¶èªžè¨€æè¿°æ‚¨æƒ³æŸ¥è©¢çš„å…§å®¹`ï¼ˆlabelï¼‰ | `nl_query_placeholder` |
| `ä¾‹å¦‚ï¼šæ‰¾å‡ºæ‰€æœ‰å®‹æœé€²å£«çš„å§“åå’Œç±è²«`ï¼ˆtextarea placeholderï¼‰ | `nl_example` |
| `æˆ‘å·²é–±è®€ä¸¦åŒæ„ä¸Šè¿°éš±ç§æç¤º` | `nl_agree_privacy` |
| `ä½¿ç”¨å·¥å…·è¼”åŠ©ï¼ˆå¯æŸ¥çœ‹è³‡æ–™è¡¨çµæ§‹ï¼‰` | `nl_use_tools` |
| `ä¸²æµæ¨¡å¼` | `nl_stream` |
| `ç”Ÿæˆä¸­â€¦`ï¼ˆæŒ‰éˆ• loading ç‹€æ…‹ï¼‰ | `nl_generating` |
| `ðŸ¤– ç”Ÿæˆ SQL`ï¼ˆæŒ‰éˆ•æ­£å¸¸ç‹€æ…‹ï¼‰ | `nl_generate` |
| `å–æ¶ˆ` | `nl_cancel` |
| `ç”Ÿæˆçš„ SQL`ï¼ˆçµæžœå€æ¨™é¡Œï¼‰ | `nl_generated_sql` |
| `â–¶ å¸¶åˆ° SQL æ¨¡å¼åŸ·è¡Œ` | `nl_send_to_sql` |
| `èªªæ˜Žï¼š` | `nl_explanation` |

**éœ€æ–°å¢žè‡³ `resources/lang/zh-TW/query.php` èˆ‡ `resources/lang/en/query.php` çš„ keyï¼š**

| Key | zh-TW | en |
|-----|-------|----|
| `nl_privacy_label` | `'âš  éš±ç§æç¤ºï¼š'` | `'âš  Privacy Notice:'` |
| `nl_privacy_body` | `'æ­¤åŠŸèƒ½ä½¿ç”¨ AI æ¨¡åž‹ï¼ˆ:modelï¼‰ç”Ÿæˆ SQLã€‚æ‚¨çš„å•é¡Œå…§å®¹å°‡å‚³é€è‡³ Google Gemini API é€²è¡Œè™•ç†ï¼Œä¸¦æœƒè¨˜éŒ„æŸ¥è©¢æ—¥èªŒä»¥æ”¹å–„æœå‹™å“è³ªã€‚è«‹å‹¿è¼¸å…¥æ•æ„Ÿå€‹äººè³‡è¨Šã€‚'` | `'This feature uses the AI model (:model) to generate SQL. Your question will be sent to Google Gemini API for processing, and query logs will be recorded to improve service quality. Please do not enter sensitive personal information.'` |
| `nl_stream_failed` | `'ç„¡æ³•è®€å–å›žæ‡‰ä¸²æµ'` | `'Unable to read response stream'` |
| `nl_generate_failed` | `'ç”Ÿæˆå¤±æ•—'` | `'Generation failed'` |
| `nl_generate_error` | `'ç”Ÿæˆç™¼ç”ŸéŒ¯èª¤'` | `'Generation error occurred'` |

**æŽ¥ç·šæ–¹å¼ï¼š**
```tsx
// åœ¨å…ƒä»¶é ‚å±¤åŠ å…¥
const t = useTranslation('query');

// éš±ç§æç¤ºæ¡†
<strong>{t('nl_privacy_label')}</strong>{t('nl_privacy_body', { model: nlModel })}

// éŒ¯èª¤è¨Šæ¯ï¼ˆasync callback ä¸­å¯ç›´æŽ¥å‘¼å« tï¼Œå› ç‚º t æ˜¯ç©©å®š useMemo å¼•ç”¨ï¼‰
setError(t('nl_generate_failed'));
```

**æ­¥é©Ÿï¼š**
- [ ] 8A-1ï¼šæ–°å¢žä¸Šè¿° 5 å€‹ lang key è‡³ zh-TW èˆ‡ en query.php
- [ ] 8A-2ï¼šåœ¨ NlQueryPanel.tsx é ‚å±¤åŠ å…¥ `const t = useTranslation('query')`ï¼Œæ›¿æ›æ‰€æœ‰ä¸­æ–‡å­—ä¸²

---

### Phase 8Bï¼šHistoricalQaPanel.tsx i18n æŽ¥å…¥ï¼ˆé«˜å„ªå…ˆï¼‰

**å•é¡Œï¼š** `resources/js/inertia/components/QueryPlayground/HistoricalQaPanel.tsx` ç„¡ä»»ä½• `useTranslation` å‘¼å«ã€‚

**å·²å­˜åœ¨æ–¼ `en/query.php` çš„ keyï¼ˆåªéœ€æŽ¥ç·šï¼‰ï¼š**

| å…ƒä»¶ä¸­çš„ä¸­æ–‡å­—ä¸² | å°æ‡‰ç¾æœ‰ key |
|----------------|------------|
| `è«‹è¼¸å…¥æ‚¨çš„æ­·å²äººç‰©å•é¡Œ`ï¼ˆlabelï¼‰ | `qa_placeholder` |
| `ä¾‹å¦‚ï¼šæŽç™½æ˜¯ä»€éº¼æ™‚ä»£çš„äººï¼Ÿâ€¦`ï¼ˆtextarea placeholderï¼‰ | `qa_example` |
| `æˆ‘å·²é–±è®€ä¸¦åŒæ„ä¸Šè¿°éš±ç§æç¤º` | `qa_agree_privacy` |
| `ä½¿ç”¨å·¥å…·æŸ¥è©¢è³‡æ–™åº«ï¼ˆå»ºè­°é–‹å•Ÿï¼‰` | `qa_use_tools` |
| `ä¸²æµæ¨¡å¼` | `qa_stream` |
| `å›žç­”ç”Ÿæˆä¸­â€¦`ï¼ˆæŒ‰éˆ• loading ç‹€æ…‹ï¼‰ | `qa_answering` |
| `ðŸ“– å›žç­”å•é¡Œ`ï¼ˆæŒ‰éˆ•æ­£å¸¸ç‹€æ…‹ï¼‰ | `qa_ask` |
| `å–æ¶ˆ` | `nl_cancel`ï¼ˆå…±ç”¨ï¼‰æˆ–åŠ  `qa_cancel` |
| `ðŸ“– å›žç­”`ï¼ˆå›žç­”å€æ¨™é¡Œï¼‰ | `qa_answer` |
| `â–¼ éš±è—è©³ç´°è³‡è¨Š` | `qa_hide_details` |
| `â–¶ é¡¯ç¤ºè©³ç´°è³‡è¨Šï¼ˆSQLã€è­‰æ“šä¾†æºï¼‰` | `qa_show_details` |
| `ä½¿ç”¨çš„ SQL æŸ¥è©¢` | `qa_sql_used` |
| `è³‡æ–™ä¾†æº` | `qa_sources` |
| `ðŸ“‹ è³‡æ–™åº«` | `qa_db` |
| `ðŸ“š æ¨¡åž‹è£œå……` | `qa_model` |

**éœ€æ–°å¢žçš„ keyï¼š**

| Key | zh-TW | en |
|-----|-------|----|
| `qa_privacy_label` | `'âš  éš±ç§æç¤ºï¼š'` | `'âš  Privacy Notice:'` |
| `qa_privacy_body` | `'æ­¤åŠŸèƒ½ä½¿ç”¨ AI æ¨¡åž‹ï¼ˆ:modelï¼‰å›žç­”æ­·å²äººç‰©å•é¡Œã€‚æ‚¨çš„å•é¡Œå…§å®¹å°‡å‚³é€è‡³ Google Gemini API é€²è¡Œè™•ç†ï¼Œä¸¦æœƒè¨˜éŒ„æŸ¥è©¢æ—¥èªŒä»¥æ”¹å–„æœå‹™å“è³ªã€‚è«‹å‹¿è¼¸å…¥æ•æ„Ÿå€‹äººè³‡è¨Šã€‚'` | `'This feature uses the AI model (:model) to answer historical questions. Your question will be sent to Google Gemini API for processing, and query logs will be recorded to improve service quality. Please do not enter sensitive personal information.'` |
| `qa_stream_failed` | `'ç„¡æ³•è®€å–å›žæ‡‰ä¸²æµ'` | `'Unable to read response stream'` |
| `qa_failed` | `'å•ç­”ç”Ÿæˆå¤±æ•—'` | `'Q&A generation failed'` |
| `qa_error` | `'ç”Ÿæˆç™¼ç”ŸéŒ¯èª¤'` | `'Generation error occurred'` |
| `qa_querying` | `'æ­£åœ¨æŸ¥è©¢è³‡æ–™åº«ä¸¦ç”Ÿæˆå›žç­”â€¦'` | `'Querying database and generating answerâ€¦'` |

**æ­¥é©Ÿï¼š**
- [ ] 8B-1ï¼šæ–°å¢žä¸Šè¿° 6 å€‹ lang key è‡³ zh-TW èˆ‡ en query.php
- [ ] 8B-2ï¼šåœ¨ HistoricalQaPanel.tsx é ‚å±¤åŠ å…¥ `const t = useTranslation('query')`ï¼Œæ›¿æ›æ‰€æœ‰ä¸­æ–‡å­—ä¸²

---

### Phase 8Cï¼šQbeBuilder.tsx i18n æŽ¥å…¥ï¼ˆé«˜å„ªå…ˆï¼‰

**å•é¡Œï¼š** `resources/js/inertia/components/QueryPlayground/QbeBuilder.tsx` ç„¡ä»»ä½• `useTranslation` å‘¼å«ã€‚æ­¤å…ƒä»¶å­—ä¸²æœ€å¤šï¼Œä¸”å¤§å¤šæ•¸ key å°šä¸å­˜åœ¨æ–¼ç¿»è­¯æª”ä¸­ã€‚

**éœ€æ–°å¢žè‡³ zh-TW èˆ‡ en query.php çš„ keyï¼ˆæŒ‰å…ƒä»¶ä¸­å‡ºç¾é †åºï¼‰ï¼š**

| Key | zh-TW | en |
|-----|-------|----|
| `qbe_schema_failed` | `'Schema è¼‰å…¥å¤±æ•—'` | `'Schema loading failed'` |
| `qbe_notice_restored_draft` | `'å·²é‚„åŽŸ :time çš„ QBE è‰ç¨¿'` | `'Restored QBE draft from :time'` |
| `qbe_notice_saved` | `'å·²å„²å­˜ç›®å‰ç‰ˆæœ¬ï¼ˆ:timeï¼‰'` | `'Saved current version (:time)'` |
| `qbe_notice_saved_before_sql` | `'å·²å„²å­˜ç”¢ç”Ÿ SQL å‰çš„ç‰ˆæœ¬ï¼ˆ:timeï¼‰'` | `'Saved version before SQL generation (:time)'` |
| `qbe_notice_saved_before_reset` | `'å·²å„²å­˜é‡è¨­å‰çš„ç‰ˆæœ¬ï¼ˆ:timeï¼‰'` | `'Saved version before reset (:time)'` |
| `qbe_notice_restored_version` | `'å·²é‚„åŽŸ :time çš„ç‰ˆæœ¬'` | `'Restored version from :time'` |
| `qbe_notice_cleared` | `'å·²æ¸…é™¤ QBE è‰ç¨¿èˆ‡æ­·å²ç´€éŒ„'` | `'QBE draft and history cleared'` |
| `qbe_autosave_hint` | `'QBE è‰ç¨¿æœƒè‡ªå‹•å„²å­˜åœ¨ç›®å‰ç€è¦½å™¨ï¼Œé›¢é–‹é é¢å¾Œå¯å›žä¾†ç¹¼çºŒç·¨è¼¯ã€‚'` | `'QBE draft is auto-saved in your browser. You can return to continue editing after leaving the page.'` |
| `qbe_last_saved` | `'æœ€è¿‘å„²å­˜ï¼š:time'` | `'Last saved: :time'` |
| `qbe_save_current` | `'å„²å­˜ç›®å‰ç‰ˆæœ¬'` | `'Save current version'` |
| `qbe_no_history` | `'-- å°šç„¡æ­·å²ç‰ˆæœ¬ --'` | `'-- No history versions --'` |
| `qbe_select_history` | `'-- é¸æ“‡æ­·å²ç‰ˆæœ¬ --'` | `'-- Select history version --'` |
| `qbe_restore_version` | `'é‚„åŽŸç‰ˆæœ¬'` | `'Restore version'` |
| `qbe_clear_history` | `'æ¸…é™¤è‰ç¨¿èˆ‡æ­·å²'` | `'Clear draft and history'` |
| `qbe_base_table` | `'ä¸»è¡¨ (Base Table)'` | `'Base Table'` |
| `qbe_select_base_table` | `'-- é¸æ“‡ä¸»è¡¨ --'` | `'-- Select base table --'` |
| `qbe_tables_group` | `'è³‡æ–™è¡¨'` | `'Tables'` |
| `qbe_internal_tables_group` | `'å…§éƒ¨è¡¨ (CBDB__)'` | `'Internal tables (CBDB__)'` |
| `qbe_loading_schema` | `'è¼‰å…¥ Schema ä¸­â€¦'` | `'Loading schemaâ€¦'` |
| `qbe_join_optional` | `'JOINï¼ˆå¯é¸ï¼‰'` | `'JOIN (optional)'` |
| `qbe_add_join` | `'+ æ–°å¢ž JOIN'` | `'+ Add JOIN'` |
| `qbe_select_join_table` | `'-- é¸æ“‡è¡¨ --'` | `'-- Select table --'` |
| `qbe_join_alias_hint` | `'åˆ¥åï¼Œä¾‹å¦‚ ALTNAME_DATA_2'` | `'Alias, e.g. ALTNAME_DATA_2'` |
| `qbe_left_col` | `'-- å·¦æ¬„ä½ --'` | `'-- Left column --'` |
| `qbe_right_col` | `'-- å³æ¬„ä½ --'` | `'-- Right column --'` |
| `qbe_select_cols` | `'SELECT æ¬„ä½ï¼ˆä¸é¸å‰‡ç‚º *ï¼‰'` | `'SELECT columns (default: all *)'` |
| `qbe_no_cols` | `'ç„¡å¯ç”¨æ¬„ä½'` | `'No available columns'` |
| `qbe_where_conditions` | `'WHERE æ¢ä»¶'` | `'WHERE conditions'` |
| `qbe_add_condition` | `'+ æ–°å¢žæ¢ä»¶'` | `'+ Add condition'` |
| `qbe_generate_sql_btn` | `'ç”¢ç”Ÿ SQL ä¸¦åˆ‡æ›è‡³ SQL æ¨¡å¼'` | `'Generate SQL and switch to SQL mode'` |

> **æ³¨æ„ï¼š** ç¾æœ‰ `qbe_autosave` keyï¼ˆå€¼ç‚ºçŸ­å¥ `'QBE draft is auto-saved'`ï¼‰èˆ‡å…ƒä»¶ä¸­å¯¦éš›é¡¯ç¤ºçš„å®Œæ•´å¥å­ä¸åŒï¼Œæ–°å¢ž `qbe_autosave_hint` key ä½¿ç”¨å®Œæ•´å¥å­ï¼›èˆŠ key ä¸åˆªé™¤ï¼ˆå¯èƒ½ä»è¢«å…¶ä»–åœ°æ–¹ä½¿ç”¨ï¼‰ã€‚

**persistenceNotice è™•ç†æ–¹å¼ï¼š** `persistenceNotice` æ˜¯ä¸€å€‹ç‹€æ…‹å­—ä¸²ï¼Œåœ¨å¤šå€‹åœ°æ–¹çš„ `setPersistenceNotice(...)` ä¸­è¨­å®šã€‚ç”±æ–¼ `t` æ˜¯ç©©å®šçš„ `useMemo` å¼•ç”¨ï¼Œå¯ä»¥ç›´æŽ¥åœ¨ setter å‘¼å«ä¸­ä½¿ç”¨ï¼š
```tsx
const t = useTranslation('query');
setPersistenceNotice(t('qbe_notice_saved', { time: formatSavedAt(savedAt) }));
```

**æ­¥é©Ÿï¼š**
- [ ] 8C-1ï¼šæ–°å¢žä¸Šè¿° 30 å€‹ lang key è‡³ zh-TW èˆ‡ en query.php
- [ ] 8C-2ï¼šåœ¨ QbeBuilder.tsx é ‚å±¤åŠ å…¥ `const t = useTranslation('query')`ï¼Œæ›¿æ›æ‰€æœ‰ä¸­æ–‡å­—ä¸²ï¼ˆå« async callback åŠ setter ä¸­çš„å­—ä¸²ï¼‰

---

### Phase 8Dï¼šOperationsController å­—ä¸²ç¿»è­¯ï¼ˆä¸­å„ªå…ˆï¼‰

**å•é¡Œï¼š** `app/Http/Controllers/OperationsController.php` ä¸­ä»æœ‰ç¡¬ç·¨ç¢¼ä¸­æ–‡ï¼š

| ä½ç½® | ç•¶å‰ç¡¬ç·¨ç¢¼ | æ”¹ç”¨ |
|------|-----------|------|
| L651ï¼ˆpage_titleï¼‰ | `'æœ€è¿‘ææ¡ˆåˆ—è¡¨'` / `'æœ€è¿‘æ“ä½œè¨˜éŒ„'` | `__('nav.recent_proposals')` / `__('nav.recent_operations')` |
| L652ï¼ˆpage_descriptionï¼‰ | `'æœ€è¿‘ææ¡ˆåˆ—è¡¨'` / `'æœ€è¿‘ç·¨è¼¯åˆ—è¡¨'` | `__('operations.page_desc_proposals')` / `__('operations.page_desc_operations')` |
| L670ï¼ˆflash errorï¼‰ | `'è«‹ç™»å…¥å¾Œå†è©¦ã€‚'` | `__('operations.restore_login_required')` |
| L675ï¼ˆflash errorï¼‰ | `'è©²ç”¨æˆ¶æ²’æœ‰æ¬Šé™ï¼Œè«‹è¯çµ¡ç®¡ç†å“¡ã€‚'` | `__('operations.restore_permission_denied')` |
| L681ï¼ˆflash warningï¼‰ | `'è©²é¡žæ“ä½œæš«ä¸æ”¯æ´å¾©åŽŸã€‚'` | `__('operations.restore_not_supported')` |
| L691ï¼ˆflash successï¼‰ | `'æ¢å¾©æˆåŠŸ @ '.Carbon::now()` | `__('operations.restore_success', ['time' => Carbon::now()])` |
| L697ï¼ˆflash errorï¼‰ | `'æ¢å¾©å¤±æ•—ï¼š'.$e->getMessage().' @ '.Carbon::now()` | `__('operations.restore_failed', ['error' => $e->getMessage(), 'time' => Carbon::now()])` |
| L710ï¼ˆRuntimeExceptionï¼‰ | `'å°šæœªæ”¯æ´çš„æ“ä½œé¡žåž‹'` | `__('operations.restore_unsupported_type')` |
| L728ï¼ˆRuntimeExceptionï¼‰ | `'æ‰¾ä¸åˆ°å¯æ¢å¾©çš„è³‡æ–™å…§å®¹'` | `__('operations.restore_no_data')` |
| L732ï¼ˆRuntimeExceptionï¼‰ | `'ç¼ºå°‘ä¸»éµæ¢ä»¶ï¼Œç„¡æ³•æ›´æ–°è¨˜éŒ„'` | `__('operations.restore_no_pk')` |
| L736ï¼ˆRuntimeExceptionï¼‰ | `'æ¢å¾©å…§å®¹ç¶“éŽéŽæ¿¾å¾Œç‚ºç©º'` | `__('operations.restore_empty_data')` |
| L745ï¼ˆRuntimeExceptionï¼‰ | `'æ‰¾ä¸åˆ°å¯é‚„åŽŸçš„åˆªé™¤è³‡æ–™'` | `__('operations.restore_no_delete_data')` |

**éœ€æ–°å¢žè‡³ `resources/lang/zh-TW/operations.php` èˆ‡ `resources/lang/en/operations.php` çš„ keyï¼š**

| Key | zh-TW | en |
|-----|-------|----|
| `page_desc_proposals` | `'æœ€è¿‘ææ¡ˆåˆ—è¡¨'` | `'Recent Proposals'` |
| `page_desc_operations` | `'æœ€è¿‘ç·¨è¼¯åˆ—è¡¨'` | `'Recent Edit History'` |
| `restore_login_required` | `'è«‹ç™»å…¥å¾Œå†è©¦ã€‚'` | `'Please log in first.'` |
| `restore_permission_denied` | `'è©²ç”¨æˆ¶æ²’æœ‰æ¬Šé™ï¼Œè«‹è¯çµ¡ç®¡ç†å“¡ã€‚'` | `'This user does not have permission. Please contact an administrator.'` |
| `restore_not_supported` | `'è©²é¡žæ“ä½œæš«ä¸æ”¯æ´å¾©åŽŸã€‚'` | `'Revert is not supported for this operation type.'` |
| `restore_success` | `'æ¢å¾©æˆåŠŸ @ :time'` | `'Restore succeeded @ :time'` |
| `restore_failed` | `'æ¢å¾©å¤±æ•—ï¼š:error @ :time'` | `'Restore failed: :error @ :time'` |
| `restore_unsupported_type` | `'å°šæœªæ”¯æ´çš„æ“ä½œé¡žåž‹'` | `'Unsupported operation type'` |
| `restore_no_data` | `'æ‰¾ä¸åˆ°å¯æ¢å¾©çš„è³‡æ–™å…§å®¹'` | `'No recoverable data found'` |
| `restore_no_pk` | `'ç¼ºå°‘ä¸»éµæ¢ä»¶ï¼Œç„¡æ³•æ›´æ–°è¨˜éŒ„'` | `'Missing primary key conditions, cannot update record'` |
| `restore_empty_data` | `'æ¢å¾©å…§å®¹ç¶“éŽæ¿¾å¾Œç‚ºç©º'` | `'Recovered content is empty after filtering'` |
| `restore_no_delete_data` | `'æ‰¾ä¸åˆ°å¯é‚„åŽŸçš„åˆªé™¤è³‡æ–™'` | `'No deleted data to restore'` |

> **æ³¨æ„ï¼š** `nav.recent_operations` = `'Recent Changes'` èˆ‡ `nav.recent_proposals` = `'Recent Proposals'` å·²å­˜åœ¨ï¼Œç›´æŽ¥ä½¿ç”¨ã€‚  
> RuntimeException è¨Šæ¯æœƒåœ¨ catch ä¸­è¢« `$e->getMessage()` å–å‡ºï¼Œæœ€çµ‚å‡ºç¾åœ¨ flash è¨Šæ¯ä¸­ï¼ˆ`restore_failed` çš„ `:error` åƒæ•¸ï¼‰ã€‚è‹¥è¦è®“éŒ¯èª¤è¨Šæ¯æœ¬èº«ä¹Ÿç¿»è­¯ï¼Œéœ€è¦åœ¨ throw å‰ç”¨ `__()` åŒ…è£ï¼Œä¸¦åœ¨ `restore_failed` çš„è‹±æ–‡å€¼ä¸­åªä¿ç•™ `:error` éƒ¨åˆ†ã€‚

**æ­¥é©Ÿï¼š**
- [ ] 8D-1ï¼šæ–°å¢žä¸Šè¿° 12 å€‹ lang key è‡³ zh-TW èˆ‡ en operations.php
- [ ] 8D-2ï¼šæ›´æ–° `OperationsController`ï¼ˆL651ã€L652ã€L670ã€L675ã€L681ã€L691ã€L697ã€L710ã€L728ã€L732ã€L736ã€L745ï¼‰

---

### Phase 8 æ•´é«”çµ±è¨ˆ

| Sub-phase | å„ªå…ˆ | æ¶‰åŠæª”æ¡ˆ | èªªæ˜Ž | ç‹€æ…‹ |
|-----------|------|---------|------|------|
| 8A NlQueryPanel.tsx | é«˜ | 1 component + 2 lang | 5 å€‹æ–° key + 11 å€‹ç¾æœ‰ key æŽ¥ç·š | â˜ å¾…å¯¦æ–½ |
| 8B HistoricalQaPanel.tsx | é«˜ | 1 component + 2 lang | 6 å€‹æ–° key + 15 å€‹ç¾æœ‰ key æŽ¥ç·š | â˜ å¾…å¯¦æ–½ |
| 8C QbeBuilder.tsx | é«˜ | 1 component + 2 lang | 30 å€‹æ–° keyï¼Œå…¨éƒ¨æŽ¥ç·š | â˜ å¾…å¯¦æ–½ |
| 8D OperationsController | ä¸­ | 1 controller + 2 lang | 12 å€‹æ–° keyï¼Œpage title + flash è¨Šæ¯ | â˜ å¾…å¯¦æ–½ |

> æŽƒææ—¥æœŸï¼š2026-06-03ã€‚ç™¼ç¾ä¾æ“šï¼šhttps://input.cbdb.fas.harvard.edu/operations å’Œ /app/query-playground çš„å¯¦éš›æˆªåœ–ã€‚

---

## é™„éŒ„ï¼šåƒè€ƒæ–‡ä»¶

| æ–‡ä»¶ | è·¯å¾‘ | ç”¨é€” |
|------|------|------|
| FormLabels.xlsx | `C:\Users\how612\Desktop\translation\FormLabels.xlsx` | ä¸‰èªžè¡“èªžå°ç…§ï¼ˆè‹±/ç°¡/ç¹ï¼‰ |
| è‹±æ–‡ç”¨æˆ¶æ‰‹å†Š | `C:\Users\how612\Desktop\translation\Users Guide 20260413 draft.docx` | è‹±æ–‡è¡“èªžèˆ‡èªªæ˜Ž |
| ä¸­æ–‡ç”¨æˆ¶æ‰‹å†Š | `C:\Users\how612\Desktop\translation\ã€Chineseã€‘User's Guideä¸­æ–‡ç‰ˆ update_2025_Zhang_Ruoxi.docx` | ä¸­æ–‡è¡“èªžåŸºæº– |
| Harvard CBDB è‹±æ–‡ç•Œé¢ | https://cbdb.fas.harvard.edu/ | ç•Œé¢è¡“èªžåƒè€ƒ |
