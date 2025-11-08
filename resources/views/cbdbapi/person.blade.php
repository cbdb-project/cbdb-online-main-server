<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>CBDB 人物資料庫 - {{ $personId ? $personId : '搜尋' }}</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        * {
            font-family: 'Noto Sans TC', sans-serif;
        }

        body {
            background-color: #fafafa;
            padding: 0;
            margin: 0;
        }

        .main-container {
            max-width: 1000px;
            margin: 0 auto;
            background-color: white;
        }

        .header-section {
            background-color: #fff;
            border-bottom: 2px solid #333;
            padding: 25px 40px;
        }

        .site-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #222;
            margin-bottom: 5px;
        }

        .site-subtitle {
            color: #666;
            font-size: 0.9rem;
        }

        .search-results-section {
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            padding: 18px;
            margin-top: 20px;
        }

        .search-results-title {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 12px;
            border-bottom: 1px solid #999;
            padding-bottom: 6px;
        }

        .person-link {
            display: inline-block;
            padding: 6px 14px;
            margin: 4px 6px 4px 0;
            background-color: #fff;
            color: #555;
            border: 1px solid #999;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .person-link:hover {
            background-color: #666;
            color: white;
            border-color: #666;
        }

        .person-link.is-current {
            background-color: #333;
            color: white;
            border-color: #333;
            font-weight: 500;
        }

        .content-section {
            padding: 30px 40px;
        }

        .loading-message {
            padding: 40px 0;
            color: #666;
        }

        .section-block {
            margin-bottom: 30px;
            border: 1px solid #ddd;
        }

        .section-header {
            background-color: #f5f5f5;
            border-bottom: 1px solid #999;
            padding: 10px 18px;
            font-size: 1.1rem;
            font-weight: 600;
            color: #222;
        }

        .section-content {
            padding: 18px;
        }

        .info-row {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #444;
            display: inline-block;
            min-width: 180px;
        }

        .info-value {
            color: #333;
        }

        .item-box {
            background-color: #fafafa;
            border: 1px solid #e0e0e0;
            padding: 14px;
            margin-bottom: 10px;
        }

        .item-box:hover {
            background-color: #f5f5f5;
        }

        .badge {
            font-weight: normal;
            font-size: 0.8rem;
            background-color: #999 !important;
            color: white;
        }

        .bg-info {
            background-color: #888 !important;
        }

        .empty {
            color: #aaa;
            font-style: italic;
        }

        .alert-box {
            padding: 14px;
            margin: 20px 0;
            border: 1px solid;
        }

        .alert-info {
            background-color: #f5f5f5;
            border-color: #ccc;
            color: #555;
        }

        .alert-warning {
            background-color: #f8f8f8;
            border-color: #bbb;
            color: #666;
        }

        .alert-danger {
            background-color: #f5f5f5;
            border-color: #999;
            color: #333;
        }

        .api-info-box {
            background-color: #f8f8f8;
            border: 1px solid #ccc;
            padding: 14px;
            margin-top: 30px;
        }

        code {
            background-color: #eee;
            padding: 2px 6px;
            color: #555;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }

        .text-muted {
            color: #777 !important;
        }

        .small-text {
            font-size: 0.9rem;
            color: #666;
        }

        .btn-primary {
            background-color: #666 !important;
            border-color: #666 !important;
            color: white;
        }

        .btn-primary:hover {
            background-color: #555 !important;
            border-color: #555 !important;
        }

        @media (max-width: 768px) {
            .header-section, .content-section {
                padding: 20px 20px;
            }

            .site-title {
                font-size: 1.4rem;
            }

            .info-label {
                display: block;
                min-width: auto;
                margin-bottom: 4px;
            }

            .person-link {
                padding: 5px 12px;
                font-size: 0.85rem;
            }
        }

        hr {
            border-top: 1px solid #ddd;
            margin: 20px 0;
        }

        strong {
            font-weight: 600;
            color: #222;
        }

        .footer-section {
            background-color: #f5f5f5;
            border-top: 1px solid #ddd;
            padding: 20px 40px;
            text-align: center;
            color: #666;
            font-size: 0.85rem;
            line-height: 1.6;
        }

        .footer-section a {
            color: #555;
            text-decoration: underline;
        }

        .footer-section a:hover {
            color: #333;
        }
    </style>
</head>
<body>
@php
    $searchResults = $searchResults ?? [];
    $searchTerm = $searchTerm ?? null;
@endphp

<div class="main-container">
    <!-- Header Section -->
    <div class="header-section">
        <h1 class="site-title">中國歷代人物傳記資料庫</h1>
        <p class="site-subtitle">China Biographical Database Project (CBDB)</p>

        @if(!empty($searchResults))
        <div class="search-results-section">
            <div class="search-results-title">
                搜尋結果@if($searchTerm)：「{{ e($searchTerm) }}」@endif
            </div>
            <div>
                @foreach($searchResults as $result)
                    <a href="#" class="person-link" data-person-id="{{ $result['id'] }}">
                        {{ e($result['label']) }} <span class="badge">{{ $result['id'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
        @elseif($searchTerm)
        <div class="alert-box alert-warning">
            <strong>搜尋結果：</strong>找不到符合「{{ e($searchTerm) }}」的資料。
        </div>
        @endif
    </div>

    <!-- Content Section -->
    <div class="content-section">
        <div id="Div_CBDB_PersonInfo">
            <div id="person-content" class="loading-message">
                <p>載入中…</p>
            </div>
        </div>
    </div>

    <!-- Footer Section -->
    <div class="footer-section">
        © China Biographical Database. Except where otherwise noted, content on this site is licensed under a <a href="https://creativecommons.org/licenses/by-nc-sa/4.0/" target="_blank" rel="noopener noreferrer">Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International license</a>.
    </div>
</div>

<script>
var searchResultsData = @json($searchResults);
var initialPersonIdData = @json($personId);
var searchTermData = @json($searchTerm);

(function () {
    var personId = '';

    function setPersonId(newId) {
        if (!newId) {
            return;
        }
        var normalized = String(newId).replace(/[^0-9]/g, '');
        if (!normalized) {
            return;
        }
        personId = normalized;
        updateSearchResultHighlight();
    }

    function updateSearchResultHighlight() {
        var links = document.querySelectorAll('.person-link');
        Array.prototype.forEach.call(links, function (link) {
            if (link.getAttribute('data-person-id') === personId) {
                link.classList.add('is-current');
                link.setAttribute('aria-current', 'true');
            } else {
                link.classList.remove('is-current');
                link.removeAttribute('aria-current');
            }
        });
    }

    (function () {
        var params = new URLSearchParams(window.location.search);
        var id = params.get('id');
        if (id) {
            setPersonId(id);
        }
    })();

    if (!personId && initialPersonIdData) {
        setPersonId(initialPersonIdData);
    }

    if (!personId && searchResultsData.length > 0) {
        setPersonId(searchResultsData[0].id);
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatIdLabel(label, id) {
        if (!label && !id) {
            return '';
        }
        if (label && id) {
            return escapeHtml(label) + ' <span class="badge">ID: ' + escapeHtml(id) + '</span>';
        }
        return escapeHtml(label || id || '');
    }

    function joinParts(parts, separator) {
        return parts.filter(function (part) { return part && part.trim().length > 0; })
            .join(separator || ', ');
    }

    function line(label, value) {
        return '<div class="info-row"><span class="info-label">' + label + '：</span><span class="info-value">' + (value || '<span class="empty">（空）</span>') + '</span></div>';
    }

    function isValidValue(val) {
        return val && val !== '0' && val !== 0 && val !== '未詳';
    }

    function renderBasicInfo(info) {
        var html = '<div class="section-block"><div class="section-header">基本資訊</div><div class="section-content">';
        html += line('CBDB ID', escapeHtml(info.PersonId));
        var names = escapeHtml(info.ChName || '') + ' / ' + escapeHtml(info.EngName || '');
        html += line('中文名 / 英文名', names);
        html += line('指數年', isValidValue(info.IndexYear) ? escapeHtml(info.IndexYear) : '未詳');
        var indexAddrParts = [];
        if (info.IndexAddr) {
            indexAddrParts.push(formatIdLabel(info.IndexAddr, info.IndexAddrId));
        }
        html += line('指數地址', indexAddrParts.join(' '));

        var birthParts = [];
        birthParts.push(formatIdLabel(info.DynastyBirth, info.DynastyBirthId));
        birthParts.push(formatIdLabel(info.EraBirth, info.EraBirthId));
        if (info.EraYearBirth && isValidValue(info.EraYearBirth)) {
            birthParts.push(escapeHtml(info.EraYearBirth) + ' 年');
        }
        if (info.YearBirth && isValidValue(info.YearBirth)) {
            birthParts.push('(' + escapeHtml(info.YearBirth) + ')');
        }
        html += line('生年', joinParts(birthParts, ' '));

        var deathParts = [];
        deathParts.push(formatIdLabel(info.DynastyDeath, info.DynastyDeathId));
        deathParts.push(formatIdLabel(info.EraDeath, info.EraDeathId));
        if (info.EraYearDeath && isValidValue(info.EraYearDeath)) {
            deathParts.push(escapeHtml(info.EraYearDeath) + ' 年');
        }
        if (info.YearDeath && isValidValue(info.YearDeath)) {
            deathParts.push('(' + escapeHtml(info.YearDeath) + ')');
        }
        html += line('卒年', joinParts(deathParts, ' '));

        html += line('享年', isValidValue(info.YearsLived) ? escapeHtml(info.YearsLived) : '未詳');
        html += line('朝代', formatIdLabel(info.Dynasty, info.DynastyId));
        html += line('性別', info.Gender === '1' ? '女性' : (info.Gender === '0' ? '男性' : '<span class="empty">（空）</span>'));
        html += line('郡望', info.JunWang ? formatIdLabel(info.JunWang, info.JunWangId) : '<span class="empty">（空）</span>');

        if (info.Notes || info.Source || info.SourcePages) {
            if (info.Notes) {
                html += line('註', escapeHtml(info.Notes));
            }
            if (info.Source || info.SourcePages) {
                html += line('人名權威資料鏈接',
                    (info.Source ? escapeHtml(info.Source) : '<span class="empty">（空）</span>') +
                    (info.SourcePages ? '，頁 ' + escapeHtml(info.SourcePages) : ''));
            }
        }

        html += '</div></div>';
        return html;
    }

    function renderSources(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">';
        items.forEach(function (item) {
            var itemHtml = '<div class="item-box">';
            var pieces = [];
            if (item.Source) {
                var sourceLabel = escapeHtml(item.Source);
                if (item.SourceId) {
                    sourceLabel += ' <span class="badge">ID: ' + escapeHtml(item.SourceId) + '</span>';
                }
                pieces.push(sourceLabel);
            }
            if (item.Pages) {
                pieces.push('頁 ' + escapeHtml(item.Pages));
            }
            itemHtml += (pieces.length ? pieces.join('，') : '<span class="empty">（空）</span>');
            if (item.Notes) {
                itemHtml += '<div class="small-text mt-1">註：' + escapeHtml(item.Notes) + '</div>';
            }
            itemHtml += '</div>';
            html += itemHtml;
        });
        html += '</div></div>';
        return html;
    }

    function renderAliases(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">';
        items.forEach(function (item) {
            var parts = [];
            if (item.AliasType) {
                var aliasType = '<strong>' + escapeHtml(item.AliasType) + '</strong>';
                if (item.AliasTypeId) {
                    aliasType += ' <span class="badge">ID: ' + escapeHtml(item.AliasTypeId) + '</span>';
                }
                parts.push(aliasType);
            }
            if (item.AliasName) {
                parts.push(escapeHtml(item.AliasName));
            }
            if (parts.length > 0) {
                html += '<div class="item-box">' + parts.join('：') + '</div>';
            }
        });
        html += '</div></div>';
        return html;
    }

    function buildAddressPath(item) {
        var path = [];
        // 先添加具体地点
        if (item.AddrName) {
            var addr = escapeHtml(item.AddrName);
            if (item.AddrId) {
                addr += ' <span class="badge">' + escapeHtml(item.AddrId) + '</span>';
            }
            path.push(addr);
        }
        // 再添加上级地址，从 belongs1 到 belongs5（从小到大）
        for (var i = 1; i <= 5; i++) {
            var nameKey = 'belongs' + i + '_name';
            var idKey = 'belongs' + i + '_id';
            if (item[nameKey]) {
                // 如果遇到 [未詳] 或 ID 为 0，停止渲染
                if (item[nameKey] === '[未詳]' || item[idKey] === '0' || item[idKey] === 0) {
                    break;
                }
                var label = escapeHtml(item[nameKey]);
                if (item[idKey]) {
                    label += ' <span class="badge">ID: ' + escapeHtml(item[idKey]) + '</span>';
                }
                path.push(label);
            }
        }
        return path.join(' → ');
    }

    function renderAddresses(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">';
        items.forEach(function (item) {
            var itemHtml = '<div class="item-box">';
            if (item.AddrType) {
                var typeLabel = '<strong>' + escapeHtml(item.AddrType) + '</strong>';
                if (item.AddrTypeId !== undefined) {
                    typeLabel += ' <span class="badge">ID: ' + escapeHtml(item.AddrTypeId) + '</span>';
                }
                itemHtml += typeLabel + '<br>';
            }
            var path = buildAddressPath(item);
            if (path) {
                itemHtml += path;
            }
            var extra = [];
            if (item.MoveCount) {
                extra.push('順序：' + escapeHtml(item.MoveCount));
            }
            if (item.FirstYear && isValidValue(item.FirstYear)) {
                extra.push('起始年：' + escapeHtml(item.FirstYear));
            }
            if (item.LastYear && isValidValue(item.LastYear)) {
                extra.push('終止年：' + escapeHtml(item.LastYear));
            }
            if (extra.length) {
                itemHtml += '<div class="small-text mt-2">' + extra.join('，') + '</div>';
            }
            if (item.Source) {
                var src = '出處：' + escapeHtml(item.Source);
                if (item.Pages) {
                    src += '，頁 ' + escapeHtml(item.Pages);
                }
                itemHtml += '<div class="small-text mt-1">' + src + '</div>';
            }
            if (item.Notes) {
                itemHtml += '<div class="small-text mt-1">註：' + escapeHtml(item.Notes) + '</div>';
            }
            itemHtml += '</div>';
            html += itemHtml;
        });
        html += '</div></div>';
        return html;
    }

    function renderEntries(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">';
        items.forEach(function (item) {
            var itemHtml = '<div class="item-box">';
            var pieces = [];
            if (item.EntryType) {
                var entry = '<strong>' + escapeHtml(item.EntryType) + '</strong>';
                if (item.EntryTypeId) {
                    entry += ' <span class="badge">ID: ' + escapeHtml(item.EntryTypeId) + '</span>';
                }
                pieces.push(entry);
            }
            if (item.EntryCode) {
                var code = escapeHtml(item.EntryCode);
                if (item.EntryCodeId) {
                    code += ' <span class="badge">' + escapeHtml(item.EntryCodeId) + '</span>';
                }
                pieces.push(code);
            }
            itemHtml += pieces.join('：');
            var tail = [];
            if (item.RuShiYear && isValidValue(item.RuShiYear)) {
                tail.push('年份：' + escapeHtml(item.RuShiYear));
            }
            if (item.RuShiAge && isValidValue(item.RuShiAge)) {
                tail.push('年齡：' + escapeHtml(item.RuShiAge));
            }
            if (tail.length) {
                itemHtml += '<div class="small-text mt-2">' + tail.join('，') + '</div>';
            }
            if (item.Source) {
                itemHtml += '<div class="small-text mt-1">出處：' + escapeHtml(item.Source);
                if (item.Pages) {
                    itemHtml += '，頁 ' + escapeHtml(item.Pages);
                }
                itemHtml += '</div>';
            }
            if (item.Notes) {
                itemHtml += '<div class="small-text mt-1">註：' + escapeHtml(item.Notes) + '</div>';
            }
            itemHtml += '</div>';
            html += itemHtml;
        });
        html += '</div></div>';
        return html;
    }

    function renderPostings(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }

        var html = '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">';
        items.forEach(function (item) {
            var itemHtml = '<div class="item-box">';

            // 官职名称
            if (item.OfficeName) {
                itemHtml += '<strong>' + escapeHtml(item.OfficeName) + '</strong>';
            }

            var details = [];

            // 起始年
            var startParts = [];
            if (item.FirstYearNianhao && isValidValue(item.FirstYearNianhao)) {
                startParts.push(escapeHtml(item.FirstYearNianhao));
            }
            if (item.FirstYearNiaohaoYear && isValidValue(item.FirstYearNiaohaoYear)) {
                startParts.push(escapeHtml(item.FirstYearNiaohaoYear) + ' 年');
            }
            if (item.FirstYear && isValidValue(item.FirstYear)) {
                startParts.push('(' + escapeHtml(item.FirstYear) + ')');
            }
            var startYearText = startParts.length > 0 ? startParts.join(' ') : '未詳';
            // 只有当年份不是"未詳"时，才添加年份限定詞
            if (startParts.length > 0 && item.FirstYearRange && isValidValue(item.FirstYearRange)) {
                startYearText += '   年份限定詞：' + escapeHtml(item.FirstYearRange);
            }
            details.push('起始年：' + startYearText);

            // 終止年
            var endParts = [];
            if (item.LastYearNianhao && isValidValue(item.LastYearNianhao)) {
                endParts.push(escapeHtml(item.LastYearNianhao));
            }
            if (item.LastYearNianhaoYear && isValidValue(item.LastYearNianhaoYear)) {
                endParts.push(escapeHtml(item.LastYearNianhaoYear) + ' 年');
            }
            if (item.LastYear && isValidValue(item.LastYear)) {
                endParts.push('(' + escapeHtml(item.LastYear) + ')');
            }
            var endYearText = endParts.length > 0 ? endParts.join(' ') : '未詳';
            // 只有当年份不是"未詳"时，才添加年份限定詞
            if (endParts.length > 0 && item.LastYearRange && isValidValue(item.LastYearRange)) {
                endYearText += '   年份限定詞：' + escapeHtml(item.LastYearRange);
            }
            details.push('終止年：' + endYearText);

            // 地點
            if (item.AddrName) {
                details.push('地點：<strong>' + escapeHtml(item.AddrName) + '</strong>');
            }

            // 出處
            if (item.Source) {
                var src = '出處：' + escapeHtml(item.Source);
                if (item.Pages) {
                    src += '，頁 ' + escapeHtml(item.Pages);
                }
                details.push(src);
            }

            // 註
            if (item.Notes) {
                details.push('註：' + escapeHtml(item.Notes));
            }

            itemHtml += '<div class="small-text mt-2">' + details.join('<br>') + '</div>';
            itemHtml += '</div>';
            html += itemHtml;
        });
        html += '</div></div>';
        return html;
    }

    function renderStatuses(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">';
        items.forEach(function (item) {
            var text = [];
            if (item.StatusName) {
                var label = '<strong>' + escapeHtml(item.StatusName) + '</strong>';
                if (item.StatusId) {
                    label += ' <span class="badge">ID: ' + escapeHtml(item.StatusId) + '</span>';
                }
                text.push(label);
            }
            if (item.FirstYear && isValidValue(item.FirstYear)) {
                text.push('起始年：' + escapeHtml(item.FirstYear));
            }
            if (item.LastYear && isValidValue(item.LastYear)) {
                text.push('終止年：' + escapeHtml(item.LastYear));
            }
            html += '<div class="item-box">' + (text.length ? text.join('，') : '<span class="empty">（空）</span>') + '</div>';
        });
        html += '</div></div>';
        return html;
    }

    function renderKinships(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">';
        items.forEach(function (item) {
            var itemHtml = '<div class="item-box">';
            var relation = item.KinRelName || item.KinRel || '親屬';
            var person = item.KinPersonName || '';
            itemHtml += '<strong>' + escapeHtml(relation) + '：</strong>' + escapeHtml(person);
            var extras = [];
            if (item.Source) {
                var src = '出處：' + escapeHtml(item.Source);
                if (item.Pages) {
                    src += '（頁 ' + escapeHtml(item.Pages) + '）';
                }
                extras.push(src);
            }
            if (item.Notes) {
                extras.push('註：' + escapeHtml(item.Notes));
            }
            if (extras.length) {
                itemHtml += '<div class="small-text mt-1">' + extras.join('； ') + '</div>';
            }
            itemHtml += '</div>';
            html += itemHtml;
        });
        html += '</div></div>';
        return html;
    }

    function renderAssociations(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">';
        items.forEach(function (item) {
            var itemHtml = '<div class="item-box">';
            var base = [];
            if (item.AssocName) {
                base.push('<strong>' + escapeHtml(item.AssocName) + '</strong>');
            }
            if (item.AssocPersonName) {
                base.push(escapeHtml(item.AssocPersonName));
            }
            if (item.TextTitle) {
                base.push('【' + escapeHtml(item.TextTitle) + '】');
            }
            if (item.Year && isValidValue(item.Year)) {
                base.push('<span class="badge">年份：' + escapeHtml(item.Year) + '</span>');
            }
            itemHtml += base.join(' ');
            var extras = [];
            if (item.Source) {
                var src = '出處：' + escapeHtml(item.Source);
                if (item.Pages) {
                    src += '（頁 ' + escapeHtml(item.Pages) + '）';
                }
                extras.push(src);
            }
            if (item.Notes) {
                extras.push('註：' + escapeHtml(item.Notes));
            }
            if (extras.length) {
                itemHtml += '<div class="small-text mt-1">' + extras.join('； ') + '</div>';
            }
            itemHtml += '</div>';
            html += itemHtml;
        });
        html += '</div></div>';
        return html;
    }

    function renderTexts(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">';
        items.forEach(function (item) {
            var itemHtml = '<div class="item-box">';
            var line = [];
            if (item.TextName) {
                var name = '<strong>' + escapeHtml(item.TextName) + '</strong>';
                if (item.TextId) {
                    name += ' <span class="badge">ID: ' + escapeHtml(item.TextId) + '</span>';
                }
                line.push(name);
            }
            if (item.Year && isValidValue(item.Year)) {
                line.push('著作年代：' + escapeHtml(item.Year));
            }
            if (item.Role) {
                line.push('角色：' + escapeHtml(item.Role));
            }
            itemHtml += line.join('，');
            if (item.Source) {
                itemHtml += '<div class="small-text mt-1">出處：' + escapeHtml(item.Source);
                if (item.Pages) {
                    itemHtml += '，頁 ' + escapeHtml(item.Pages);
                }
                itemHtml += '</div>';
            }
            if (item.Notes) {
                itemHtml += '<div class="small-text mt-1">註：' + escapeHtml(item.Notes) + '</div>';
            }
            itemHtml += '</div>';
            html += itemHtml;
        });
        html += '</div></div>';
        return html;
    }

    var collectionRenderers = {
        '出處': renderSources,
        '別名': renderAliases,
        '地理資訊': renderAddresses,
        '入仕': renderEntries,
        '任官': renderPostings,
        '社會區分': renderStatuses,
        '親屬關係': renderKinships,
        '社會關係': renderAssociations,
        '著述': renderTexts
    };

    function renderCollection(title, items) {
        var renderer = collectionRenderers[title];
        if (renderer) {
            return renderer(title, items);
        }
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }

        var html = '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">';

        items.forEach(function (item) {
            var segments = [];
            Object.keys(item).forEach(function (key) {
                var value = item[key];
                if (value === null || value === undefined || value === '') {
                    return;
                }
                var label = key;
                segments.push(escapeHtml(label) + '：' + escapeHtml(value));
            });
            html += '<div class="item-box">' + (segments.length ? segments.join('； ') : '<span class="empty">（空）</span>') + '</div>';
        });

        html += '</div></div>';
        return html;
    }

    function renderSourceLinks(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var first = items[0];
        if (!first.Pages) {
            return '';
        }
        var href = 'https://newarchive.ihp.sinica.edu.tw/sncaccgi/sncacFtp?ACTION=TQ,sncacFtpqf,SN=' + encodeURIComponent(first.Pages) + ',2nd,search_simple';
        var link = '<a href="' + escapeHtml(href) + '" class="btn btn-primary btn-sm" target="_blank">跳轉至來源</a>';
        return '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">' + link + '</div></div>';
    }

    function renderPerson(person) {
        if (!person) {
            return '<div class="alert-box alert-danger">找不到對應人物。</div>';
        }

        var html = renderBasicInfo(person.BasicInfo || {});
        html += renderSourceLinks('人名權威資料鏈接', (person.PersonSourcesAs && person.PersonSourcesAs.SourceAs) || []);
        html += renderCollection('出處', (person.PersonSources && person.PersonSources.Source) || []);
        html += renderCollection('別名', (person.PersonAliases && person.PersonAliases.Alias) || []);
        html += renderCollection('地理資訊', (person.PersonAddresses && person.PersonAddresses.Address) || []);
        html += renderCollection('入仕', (person.PersonEntryInfo && person.PersonEntryInfo.Entry) || []);
        html += renderCollection('任官', (person.PersonPostings && person.PersonPostings.Posting) || []);
        html += renderCollection('社會區分', (person.PersonSocialStatus && person.PersonSocialStatus.SocialStatus) || []);
        html += renderCollection('親屬關係', (person.PersonKinshipInfo && person.PersonKinshipInfo.Kinship) || []);
        html += renderCollection('社會關係', (person.PersonSocialAssociation && person.PersonSocialAssociation.Association) || []);
        html += renderCollection('著述', (person.PersonTexts && person.PersonTexts.Text) || []);

        var jsonUrl = '?id=' + escapeHtml(String(personId)) + '&o=json';
        html += '<div class="api-info-box"><strong>需要原始 JSON？</strong>請使用 <a href="' + jsonUrl + '" target="_blank" rel="noopener noreferrer"><code>' + jsonUrl.replace('&', '&amp;') + '</code></a></div>';

        return html;
    }

    function requestPersonData() {
        var content = document.getElementById('person-content');

        if (!personId) {
            if (searchTermData && searchResultsData.length === 0) {
                content.innerHTML = '<div class="alert-box alert-warning">找不到符合條件的資料。</div>';
            } else {
                content.innerHTML = '<div class="alert-box alert-info">請選擇人物。</div>';
            }
            return;
        }

        var url = new URL(window.location.href);
        url.searchParams.set('id', personId);
        url.searchParams.delete('name');
        url.searchParams.set('o', 'json');

        fetch(url.toString(), {
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw response;
                }
                return response.json();
            })
            .then(function (payload) {
                var person = payload && payload.Package && payload.Package.PersonAuthority &&
                    payload.Package.PersonAuthority.PersonInfo &&
                    payload.Package.PersonAuthority.PersonInfo.Person;

                if (person && person.BasicInfo && person.BasicInfo.PersonId) {
                    setPersonId(person.BasicInfo.PersonId);
                }

                content.innerHTML = renderPerson(person);
            })
            .catch(function (error) {
                if (error && typeof error.json === 'function') {
                    error.json().then(function (data) {
                        var message = (data && data.error && data.error.message) || '載入失敗。';
                        content.innerHTML = '<div class="alert-box alert-danger">' + escapeHtml(message) + '</div>';
                    }).catch(function () {
                        content.innerHTML = '<div class="alert-box alert-danger">載入失敗。</div>';
                    });
                } else {
                    content.innerHTML = '<div class="alert-box alert-danger">載入失敗。</div>';
                }
            });
    }

    function loadPerson(newId) {
        if (!newId) {
            return;
        }
        setPersonId(newId);
        requestPersonData();
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        while (target && !target.classList.contains('person-link')) {
            target = target.parentElement;
        }
        if (target && target.classList.contains('person-link')) {
            event.preventDefault();
            loadPerson(target.getAttribute('data-person-id'));
        }
    });

    updateSearchResultHighlight();
    requestPersonData();
})();
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
