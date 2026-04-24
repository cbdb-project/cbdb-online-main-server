---
name: 前端 Click Handler 偵錯指南
description: 為前端按鈕 / click handler 寫 JS 時的預設做法，以及當 click 沒觸發時的偵錯與替代綁定方式（例如使用者裝了會替換 DOM 的瀏覽器擴充）
---

# 前端 Click Handler 偵錯指南

## 預設做法

寫前端 click handler 時，**從 `addEventListener` 開始**：

```html
<button id="my-btn" class="btn btn-primary">Do thing</button>
```

```js
document.getElementById('my-btn').addEventListener('click', function () {
    // ...
});
```

理由：
- HTML 與 JS 行為分離，符合現代寫法
- 可移除（`removeEventListener`）、可重複綁定
- 沒有 inline `onclick` 那種「字串內容是 JS」的潛在 XSS 顧慮

**不要**一開始就用 inline `onclick="..."` 或 `document.addEventListener('click', e => …)` event delegation — 那是「降級方案」，不是預設。

## 何時要降級

當你綁好 `addEventListener` 之後，按鈕點下去 handler 不觸發時：

### 第 1 步：確認 binding 確實成立

加暫時診斷 log：

```js
console.log('[debug] script tag executed at', new Date().toISOString());
(function () {
    var btn = document.getElementById('my-btn');
    console.log('[debug] button found?', !!btn);
    if (btn) {
        btn.addEventListener('click', function () {
            console.log('[debug] click handler fired');
        });
    }
})();
```

可能結果與對應問題：
- `button found? false` → script 在 DOM 出現前就跑了，或 ID 拼錯。檢查 `@stack('scripts')` 是否真的在 body 底部、ID 是否一致。
- `button found? true` 但點下去無 log → **進入第 2 步**。

### 第 2 步：診斷「DOM 元素是否被替換」

```js
window.__boundRef = btn;
// ...在 click handler 或事後檢查
console.log('bound element still in DOM?', document.body.contains(window.__boundRef));
console.log('getElementById now === bound?', document.getElementById('my-btn') === window.__boundRef);
```

如果這兩個都印 `false`，代表你綁定的那個 DOM 節點被換掉了 — 你的 listener 還掛在被棄置的舊節點上。常見肇事者：

- 瀏覽器翻譯擴充（例如 KISS-Translator）— 會 clone 含英文文字的元素以注入翻譯
- 廣告攔截器 / 隱私插件
- 第三方 widget 用 `outerHTML = ...` 重建區塊
- jQuery `replaceWith()`、Vue/React partial render（這些通常你應該已經知道）

### 第 3 步：改用 event delegation

確認是 DOM 被替換造成的問題後，把 listener 從具體節點搬到一個**永遠不會被替換的根節點**（通常是 `document`）：

```js
document.addEventListener('click', function (ev) {
    if (!ev.target.closest('#my-btn')) return;
    // ...你的邏輯
});
```

原理：
- click 事件會沿著 DOM 樹 bubble 上去，不論底下的元素被換成什麼新節點
- `.closest('#my-btn')` 在事件路徑上找匹配選擇器的最近祖先
- listener 寫在 `document` 上，所以無論 button 是原版還是擴充 clone 的版本，都會觸發

**重點**：不要因為「之前綁定不起作用」就反射性改 inline `onclick`。inline 屬性也能抗 DOM 替換（因為它是 HTML 的一部分），但會把行為混回 HTML 標籤裡。Event delegation 是更乾淨的選擇。

## 寫進 commit / PR 的話

如果這次降級是 ad-hoc 修個別 bug，commit message 應該明確寫「改用 event delegation 以對抗 X」（例如 `修正 Copy 按鈕被翻譯擴充替換後失效的問題`），讓未來看 git log 的人知道為什麼這個檔不用一般寫法。

## 反例（不要做的事）

- **不要** 用 `setInterval` 每 500ms 重新 `addEventListener` — 那會疊加 listener、造成多次觸發。
- **不要** 用 `MutationObserver` 監聽全頁 DOM 變化只為了重綁一個按鈕 — 過度工程，event delegation 一行解決。
- **不要** 在偵錯到一半就直接刪掉 console.log — 留著診斷一兩輪確認穩定後再清；過早清掉會在使用者回報「現在又壞了」時又得重加。
