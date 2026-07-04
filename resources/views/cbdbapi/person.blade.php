<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{ __('admin.cbdb_api_page_title') }} - {{ $personId ? $personId : __('admin.cbdb_api_page_title_search') }}</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;600;700&display=fallback" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@400;600&family=Noto+Serif+TC:wght@400;600&display=fallback" rel="stylesheet">
    <link rel="stylesheet" href="https://jigmo.digitalhumanities.dev/jigmo-tc.css">

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

        .cbdb-database-text {
            font-family: 'Source Serif 4', 'Source Han Serif TC', 'Noto Serif TC', 'Jigmo', serif;
            font-variant-east-asian: traditional;
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
            background-color: #ffebee;
            border-color: #ef5350;
            color: #c62828;
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
        <h1 class="site-title">{{ __('admin.cbdb_api_site_title') }}</h1>
        <p class="site-subtitle">China Biographical Database Project (CBDB)</p>

        @if(!empty($validationErrors ?? []))
        <div class="alert-box alert-danger">
            <strong>{{ __('admin.cbdb_api_error_label') }}</strong>
            <ul style="margin: 10px 0 0 20px; padding: 0;">
                @foreach($validationErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @elseif(!empty($searchResults))
        <div class="search-results-section">
            <div class="search-results-title">
                {{ __('admin.cbdb_api_search_results') }}@if($searchTerm)：「{{ e($searchTerm) }}」@endif
            </div>
            <div>
                @foreach($searchResults as $result)
                    <a href="#" class="person-link person-search-result" data-person-id="{{ $result['id'] }}">
                        <span class="cbdb-database-text">{{ e($result['label']) }}</span> <span class="badge">{{ $result['id'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
        @elseif($searchTerm)
        <div class="alert-box alert-warning">
            <strong>{{ __('admin.cbdb_api_search_results') }}：</strong>{{ __('admin.cbdb_api_no_results', ['term' => $searchTerm]) }}
        </div>
        @endif
    </div>

    <!-- Content Section -->
    <div class="content-section">
        <div id="Div_CBDB_PersonInfo">
            <div id="person-content" class="loading-message">
                <p>{{ __('admin.cbdb_api_loading') }}</p>
            </div>
        </div>
    </div>

    <!-- Footer Section -->
    <div class="footer-section">
        © China Biographical Database. Except where otherwise noted, content on this site is licensed under a <a href="https://creativecommons.org/licenses/by-nc-sa/4.0/" target="_blank" rel="noopener noreferrer">Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International license</a>.
    </div>
</div>

<script>
window.cbdbI18n = {
    empty:             @json(__('admin.cbdb_api_empty')),
    unknown:           @json(__('admin.cbdb_api_unknown')),
    sectionBasic:      @json(__('admin.cbdb_api_section_basic')),
    fieldName:         @json(__('admin.cbdb_api_field_name')),
    fieldIndexYear:    @json(__('admin.cbdb_api_field_index_year')),
    fieldIndexAddr:    @json(__('admin.cbdb_api_field_index_addr')),
    fieldBirthYear:    @json(__('admin.cbdb_api_field_birth_year')),
    fieldDeathYear:    @json(__('admin.cbdb_api_field_death_year')),
    fieldYearsLived:   @json(__('admin.cbdb_api_field_years_lived')),
    fieldDynasty:      @json(__('admin.cbdb_api_field_dynasty')),
    fieldGender:       @json(__('admin.cbdb_api_field_gender')),
    genderFemale:      @json(__('admin.cbdb_api_gender_female')),
    genderMale:        @json(__('admin.cbdb_api_gender_male')),
    fieldJunwang:      @json(__('admin.cbdb_api_field_junwang')),
    fieldNotes:        @json(__('admin.cbdb_api_field_notes')),
    fieldSourceLink:   @json(__('admin.cbdb_api_field_source_link')),
    yearSuffix:        @json(__('admin.cbdb_api_year_suffix')),
    pagesPrefix:       @json(__('admin.cbdb_api_pages_prefix')),
    pagesComma:        @json(__('admin.cbdb_api_pages_comma')),
    noteLabel:         @json(__('admin.cbdb_api_note_label')),
    sourceLabel:       @json(__('admin.cbdb_api_source_label')),
    sequenceLabel:     @json(__('admin.cbdb_api_sequence_label')),
    startYearLabel:    @json(__('admin.cbdb_api_start_year_label')),
    endYearLabel:      @json(__('admin.cbdb_api_end_year_label')),
    yearQualifierLabel:@json(__('admin.cbdb_api_year_qualifier_label')),
    yearLabel:         @json(__('admin.cbdb_api_year_label')),
    ageLabel:          @json(__('admin.cbdb_api_age_label')),
    workYearLabel:     @json(__('admin.cbdb_api_work_year_label')),
    roleLabel:         @json(__('admin.cbdb_api_role_label')),
    locationLabel:     @json(__('admin.cbdb_api_location_label')),
    kinshipDefault:    @json(__('admin.cbdb_api_kinship_default')),
    jumpToSource:      @json(__('admin.cbdb_api_jump_to_source')),
    personNotFound:    @json(__('admin.cbdb_api_person_not_found')),
    sectionSources:    @json(__('admin.cbdb_api_section_sources')),
    sectionAliases:    @json(__('admin.cbdb_api_section_aliases')),
    sectionAddresses:  @json(__('admin.cbdb_api_section_addresses')),
    sectionEntries:    @json(__('admin.cbdb_api_section_entries')),
    sectionPostings:   @json(__('admin.cbdb_api_section_postings')),
    sectionStatuses:   @json(__('admin.cbdb_api_section_statuses')),
    sectionKinships:   @json(__('admin.cbdb_api_section_kinships')),
    sectionAssocs:     @json(__('admin.cbdb_api_section_associations')),
    sectionTexts:      @json(__('admin.cbdb_api_section_texts')),
    jsonHint:          @json(__('admin.cbdb_api_json_hint')),
    jsonUse:           @json(__('admin.cbdb_api_json_use')),
    mergeHintLabel:    @json(__('admin.cbdb_api_merge_hint_label')),
    mergeHintText:     @json(__('admin.cbdb_api_merge_hint_text')),
    mergeReasonLabel:  @json(__('admin.cbdb_api_merge_reason_label')),
    noMatch:           @json(__('admin.cbdb_api_no_match')),
    selectPerson:      @json(__('admin.cbdb_api_select_person')),
    loadFailed:        @json(__('admin.cbdb_api_load_failed'))
};
</script>
<script>
var searchResultsData = @json($searchResults);
var initialPersonIdData = @json($personId);
var searchTermData = @json($searchTerm);
var hasValidationErrors = @json(isset($validationErrors) && !empty($validationErrors));

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

    function dbHtml(value) {
        return '<span class="cbdb-database-text">' + (value || '') + '</span>';
    }

    function dbText(value) {
        return dbHtml(escapeHtml(value));
    }

    function dbStrong(value) {
        return '<strong class="cbdb-database-text">' + escapeHtml(value) + '</strong>';
    }

    function formatIdLabel(label, id) {
        if (!label && !id) {
            return '';
        }
        if (label && id) {
            return dbText(label) + ' <span class="badge">ID: ' + escapeHtml(id) + '</span>';
        }
        return dbText(label || id || '');
    }

    function joinParts(parts, separator) {
        return parts.filter(function (part) { return part && part.trim().length > 0; })
            .join(separator || ', ');
    }

    function line(label, value) {
        var output = value || '<span class="empty">' + cbdbI18n.empty + '</span>';
        if (value && value !== cbdbI18n.unknown && String(value).indexOf('<') === -1) {
            output = dbHtml(value);
        }
        return '<div class="info-row"><span class="info-label">' + label + '：</span><span class="info-value">' + output + '</span></div>';
    }

    function isValidValue(val) {
        return val && val !== '0' && val !== 0 && val !== cbdbI18n.unknown;
    }

    function renderBasicInfo(info) {
        var html = '<div class="section-block"><div class="section-header">' + cbdbI18n.sectionBasic + '</div><div class="section-content">';
        html += line('CBDB ID', escapeHtml(info.PersonId));
        var names = escapeHtml(info.ChName || '') + ' / ' + escapeHtml(info.EngName || '');
        html += line(cbdbI18n.fieldName, names);
        html += line(cbdbI18n.fieldIndexYear, isValidValue(info.IndexYear) ? escapeHtml(info.IndexYear) : cbdbI18n.unknown);
        var indexAddrHtml = '';
        if (info.IndexAddr) {
            indexAddrHtml = dbText(info.IndexAddr);
        }
        if (info.IndexAddrId) {
            indexAddrHtml += ' <a href="/codes/ADDR_CODES?search=' + encodeURIComponent(info.IndexAddrId) + '" target="_blank" rel="noopener noreferrer">';
            indexAddrHtml += '<span class="badge">ID: ' + escapeHtml(info.IndexAddrId) + '</span></a>';
        }
        html += line(cbdbI18n.fieldIndexAddr, indexAddrHtml.trim() || '<span class="empty">' + cbdbI18n.empty + '</span>');

        var birthParts = [];
        birthParts.push(formatIdLabel(info.DynastyBirth, info.DynastyBirthId));
        birthParts.push(formatIdLabel(info.EraBirth, info.EraBirthId));
        if (info.EraYearBirth && isValidValue(info.EraYearBirth)) {
            birthParts.push(escapeHtml(info.EraYearBirth) + cbdbI18n.yearSuffix);
        }
        if (info.YearBirth && isValidValue(info.YearBirth)) {
            birthParts.push('(' + escapeHtml(info.YearBirth) + ')');
        }
        html += line(cbdbI18n.fieldBirthYear, joinParts(birthParts, ' '));

        var deathParts = [];
        deathParts.push(formatIdLabel(info.DynastyDeath, info.DynastyDeathId));
        deathParts.push(formatIdLabel(info.EraDeath, info.EraDeathId));
        if (info.EraYearDeath && isValidValue(info.EraYearDeath)) {
            deathParts.push(escapeHtml(info.EraYearDeath) + cbdbI18n.yearSuffix);
        }
        if (info.YearDeath && isValidValue(info.YearDeath)) {
            deathParts.push('(' + escapeHtml(info.YearDeath) + ')');
        }
        html += line(cbdbI18n.fieldDeathYear, joinParts(deathParts, ' '));

        html += line(cbdbI18n.fieldYearsLived, isValidValue(info.YearsLived) ? escapeHtml(info.YearsLived) : cbdbI18n.unknown);
        html += line(cbdbI18n.fieldDynasty, formatIdLabel(info.Dynasty, info.DynastyId));
        html += line(cbdbI18n.fieldGender, info.Gender === '1' ? cbdbI18n.genderFemale : (info.Gender === '0' ? cbdbI18n.genderMale : '<span class="empty">' + cbdbI18n.empty + '</span>'));
        html += line(cbdbI18n.fieldJunwang, info.JunWang ? formatIdLabel(info.JunWang, info.JunWangId) : '<span class="empty">' + cbdbI18n.empty + '</span>');

        if (info.Notes || info.Source || info.SourcePages) {
            if (info.Notes) {
                html += line(cbdbI18n.fieldNotes, escapeHtml(info.Notes));
            }
            if (info.Source || info.SourcePages) {
                html += line(cbdbI18n.fieldSourceLink,
                    (info.Source ? dbText(info.Source) : '<span class="empty">' + cbdbI18n.empty + '</span>') +
                    (info.SourcePages ? cbdbI18n.pagesComma + dbText(info.SourcePages) : ''));
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
                var sourceLabel = dbText(item.Source);
                if (item.SourceId) {
                    sourceLabel += ' <span class="badge">ID: ' + escapeHtml(item.SourceId) + '</span>';
                }
                pieces.push(sourceLabel);
            }
            if (item.Pages) {
                if (item.UrlApi) {
                    var urlPart = encodeURIComponent(item.Pages);
                    var fullUrl = item.UrlApi + urlPart + (item.UrlApiCoda || '');
                    pieces.push(cbdbI18n.pagesPrefix + '<a href="' + escapeHtml(fullUrl) + '" target="_blank" rel="noopener noreferrer">' + dbText(item.Pages) + '</a>');
                } else {
                    pieces.push(cbdbI18n.pagesPrefix + dbText(item.Pages));
                }
            }
            itemHtml += (pieces.length ? pieces.join('，') : '<span class="empty">' + cbdbI18n.empty + '</span>');
            if (item.Notes) {
                itemHtml += '<div class="small-text mt-1">' + cbdbI18n.noteLabel + dbText(item.Notes) + '</div>';
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
                var aliasType = dbStrong(item.AliasType);
                if (item.AliasTypeId) {
                    aliasType += ' <span class="badge">ID: ' + escapeHtml(item.AliasTypeId) + '</span>';
                }
                parts.push(aliasType);
            }
            if (item.AliasName) {
                parts.push(dbText(item.AliasName));
            }
            if (parts.length > 0) {
                html += '<div class="item-box">' + parts.join('：') + '</div>';
            }
        });
        html += '</div></div>';
        return html;
    }

    function buildAddressPath(item) {
        if (!item.AddrName && !item.AddrId) {
            return '';
        }
        var addr = '';
        if (item.AddrName) {
            addr = dbText(item.AddrName);
        }
        if (item.AddrId) {
            var addrLink = '<a href="/codes/ADDR_CODES?search=' + encodeURIComponent(item.AddrId) + '" target="_blank" rel="noopener noreferrer">';
            addrLink += '<span class="badge">' + escapeHtml(item.AddrId) + '</span></a>';
            addr += ' ' + addrLink;
        }
        return addr;
    }

    function renderAddresses(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">';
        items.forEach(function (item) {
            var itemHtml = '<div class="item-box">';
            if (item.AddrType) {
                var typeLabel = dbStrong(item.AddrType);
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
                extra.push(cbdbI18n.sequenceLabel + dbText(item.MoveCount));
            }
            if (item.FirstYear && isValidValue(item.FirstYear)) {
                extra.push(cbdbI18n.startYearLabel + dbText(item.FirstYear));
            }
            if (item.LastYear && isValidValue(item.LastYear)) {
                extra.push(cbdbI18n.endYearLabel + dbText(item.LastYear));
            }
            if (extra.length) {
                itemHtml += '<div class="small-text mt-2">' + extra.join('，') + '</div>';
            }
            if (item.Source) {
                var src = cbdbI18n.sourceLabel + dbText(item.Source);
                if (item.Pages) {
                    src += cbdbI18n.pagesComma + dbText(item.Pages);
                }
                itemHtml += '<div class="small-text mt-1">' + src + '</div>';
            }
            if (item.Notes) {
                itemHtml += '<div class="small-text mt-1">' + cbdbI18n.noteLabel + dbText(item.Notes) + '</div>';
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
                var entry = dbStrong(item.EntryType);
                if (item.EntryTypeId) {
                    entry += ' <span class="badge">ID: ' + escapeHtml(item.EntryTypeId) + '</span>';
                }
                pieces.push(entry);
            }
            if (item.EntryCode) {
                var code = dbText(item.EntryCode);
                if (item.EntryCodeId) {
                    code += ' <span class="badge">' + escapeHtml(item.EntryCodeId) + '</span>';
                }
                pieces.push(code);
            }
            itemHtml += pieces.join('：');
            var tail = [];
            if (item.RuShiYear && isValidValue(item.RuShiYear)) {
                tail.push(cbdbI18n.yearLabel + dbText(item.RuShiYear));
            }
            if (item.RuShiAge && isValidValue(item.RuShiAge)) {
                tail.push(cbdbI18n.ageLabel + dbText(item.RuShiAge));
            }
            if (tail.length) {
                itemHtml += '<div class="small-text mt-2">' + tail.join('，') + '</div>';
            }
            if (item.Source) {
                itemHtml += '<div class="small-text mt-1">' + cbdbI18n.sourceLabel + dbText(item.Source);
                if (item.Pages) {
                    itemHtml += cbdbI18n.pagesComma + dbText(item.Pages);
                }
                itemHtml += '</div>';
            }
            if (item.Notes) {
                itemHtml += '<div class="small-text mt-1">' + cbdbI18n.noteLabel + dbText(item.Notes) + '</div>';
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
                itemHtml += dbStrong(item.OfficeName);
            }

            var details = [];

            // 起始年
            var startParts = [];
            if (item.FirstYearNianhao && isValidValue(item.FirstYearNianhao)) {
                startParts.push(dbText(item.FirstYearNianhao));
            }
            if (item.FirstYearNiaohaoYear && isValidValue(item.FirstYearNiaohaoYear)) {
                startParts.push(dbText(item.FirstYearNiaohaoYear) + cbdbI18n.yearSuffix);
            }
            if (item.FirstYear && isValidValue(item.FirstYear)) {
                startParts.push('(' + dbText(item.FirstYear) + ')');
            }
            var startYearText = startParts.length > 0 ? startParts.join(' ') : cbdbI18n.unknown;
            // 只有当年份不是未詳时，才添加年份限定詞
            if (startParts.length > 0 && item.FirstYearRange && isValidValue(item.FirstYearRange)) {
                startYearText += '   ' + cbdbI18n.yearQualifierLabel + dbText(item.FirstYearRange);
            }
            details.push(cbdbI18n.startYearLabel + startYearText);

            // 終止年
            var endParts = [];
            if (item.LastYearNianhao && isValidValue(item.LastYearNianhao)) {
                endParts.push(dbText(item.LastYearNianhao));
            }
            if (item.LastYearNianhaoYear && isValidValue(item.LastYearNianhaoYear)) {
                endParts.push(dbText(item.LastYearNianhaoYear) + cbdbI18n.yearSuffix);
            }
            if (item.LastYear && isValidValue(item.LastYear)) {
                endParts.push('(' + dbText(item.LastYear) + ')');
            }
            var endYearText = endParts.length > 0 ? endParts.join(' ') : cbdbI18n.unknown;
            // 只有当年份不是未詳时，才添加年份限定詞
            if (endParts.length > 0 && item.LastYearRange && isValidValue(item.LastYearRange)) {
                endYearText += '   ' + cbdbI18n.yearQualifierLabel + dbText(item.LastYearRange);
            }
            details.push(cbdbI18n.endYearLabel + endYearText);

            // 地點
            if (item.AddrName || item.AddrId) {
                var addrHtml = cbdbI18n.locationLabel + dbStrong(item.AddrName || '');
                if (item.AddrId) {
                    addrHtml += ' <a href="/codes/ADDR_CODES?search=' + encodeURIComponent(item.AddrId) + '" target="_blank" rel="noopener noreferrer">';
                    addrHtml += '<span class="badge">' + escapeHtml(item.AddrId) + '</span></a>';
                }
                details.push(addrHtml);
            }

            // 出處
            if (item.Source) {
                var src = cbdbI18n.sourceLabel + dbText(item.Source);
                if (item.Pages) {
                    src += cbdbI18n.pagesComma + dbText(item.Pages);
                }
                details.push(src);
            }

            // 註
            if (item.Notes) {
                details.push(cbdbI18n.noteLabel + dbText(item.Notes));
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
                var label = dbStrong(item.StatusName);
                if (item.StatusId) {
                    label += ' <span class="badge">ID: ' + escapeHtml(item.StatusId) + '</span>';
                }
                text.push(label);
            }
            if (item.FirstYear && isValidValue(item.FirstYear)) {
                text.push(cbdbI18n.startYearLabel + dbText(item.FirstYear));
            }
            if (item.LastYear && isValidValue(item.LastYear)) {
                text.push(cbdbI18n.endYearLabel + dbText(item.LastYear));
            }
            html += '<div class="item-box">' + (text.length ? text.join('，') : '<span class="empty">' + cbdbI18n.empty + '</span>') + '</div>';
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
            var relation = item.KinRelName || item.KinRel || cbdbI18n.kinshipDefault;
            var person = item.KinPersonName || '';
            itemHtml += dbStrong(relation) + '：' + (person ? dbText(person) : '');
            var extras = [];
            if (item.Source) {
                var src = cbdbI18n.sourceLabel + dbText(item.Source);
                if (item.Pages) {
                    src += '（' + cbdbI18n.pagesPrefix + dbText(item.Pages) + '）';
                }
                extras.push(src);
            }
            if (item.Notes) {
                extras.push(cbdbI18n.noteLabel + dbText(item.Notes));
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
                base.push(dbStrong(item.AssocName));
            }
            if (item.AssocPersonName) {
                base.push(dbText(item.AssocPersonName));
            }
            if (item.TextTitle) {
                base.push('【' + dbText(item.TextTitle) + '】');
            }
            if (item.Year && isValidValue(item.Year)) {
                base.push('<span class="badge">' + cbdbI18n.yearLabel + escapeHtml(item.Year) + '</span>');
            }
            itemHtml += base.join(' ');
            var extras = [];
            if (item.Source) {
                var src = cbdbI18n.sourceLabel + dbText(item.Source);
                if (item.Pages) {
                    src += '（' + cbdbI18n.pagesPrefix + dbText(item.Pages) + '）';
                }
                extras.push(src);
            }
            if (item.Notes) {
                extras.push(cbdbI18n.noteLabel + dbText(item.Notes));
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
                var name = dbStrong(item.TextName);
                if (item.TextId) {
                    name += ' <span class="badge">ID: ' + escapeHtml(item.TextId) + '</span>';
                }
                line.push(name);
            }
            if (item.Year && isValidValue(item.Year)) {
                line.push(cbdbI18n.workYearLabel + dbText(item.Year));
            }
            if (item.Role) {
                line.push(cbdbI18n.roleLabel + dbText(item.Role));
            }
            itemHtml += line.join('，');
            if (item.Source) {
                itemHtml += '<div class="small-text mt-1">' + cbdbI18n.sourceLabel + dbText(item.Source);
                if (item.Pages) {
                    itemHtml += cbdbI18n.pagesComma + dbText(item.Pages);
                }
                itemHtml += '</div>';
            }
            if (item.Notes) {
                itemHtml += '<div class="small-text mt-1">' + cbdbI18n.noteLabel + dbText(item.Notes) + '</div>';
            }
            itemHtml += '</div>';
            html += itemHtml;
        });
        html += '</div></div>';
        return html;
    }

    var collectionRenderers = {};
    collectionRenderers[cbdbI18n.sectionSources]    = renderSources;
    collectionRenderers[cbdbI18n.sectionAliases]    = renderAliases;
    collectionRenderers[cbdbI18n.sectionAddresses]  = renderAddresses;
    collectionRenderers[cbdbI18n.sectionEntries]    = renderEntries;
    collectionRenderers[cbdbI18n.sectionPostings]   = renderPostings;
    collectionRenderers[cbdbI18n.sectionStatuses]   = renderStatuses;
    collectionRenderers[cbdbI18n.sectionKinships]   = renderKinships;
    collectionRenderers[cbdbI18n.sectionAssocs]     = renderAssociations;
    collectionRenderers[cbdbI18n.sectionTexts]      = renderTexts;

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
                segments.push(escapeHtml(label) + '：' + dbText(value));
            });
            html += '<div class="item-box">' + (segments.length ? segments.join('； ') : '<span class="empty">' + cbdbI18n.empty + '</span>') + '</div>';
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
        var link = '<a href="' + escapeHtml(href) + '" class="btn btn-primary btn-sm" target="_blank">' + cbdbI18n.jumpToSource + '</a>';
        return '<div class="section-block"><div class="section-header">' + title + '</div><div class="section-content">' + link + '</div></div>';
    }

    function renderPerson(person) {
        if (!person) {
            return '<div class="alert-box alert-danger">' + cbdbI18n.personNotFound + '</div>';
        }

        var html = renderBasicInfo(person.BasicInfo || {});
        html += renderSourceLinks(cbdbI18n.fieldSourceLink, (person.PersonSourcesAs && person.PersonSourcesAs.SourceAs) || []);
        html += renderCollection(cbdbI18n.sectionSources, (person.PersonSources && person.PersonSources.Source) || []);
        html += renderCollection(cbdbI18n.sectionAliases, (person.PersonAliases && person.PersonAliases.Alias) || []);
        html += renderCollection(cbdbI18n.sectionAddresses, (person.PersonAddresses && person.PersonAddresses.Address) || []);
        html += renderCollection(cbdbI18n.sectionEntries, (person.PersonEntryInfo && person.PersonEntryInfo.Entry) || []);
        html += renderCollection(cbdbI18n.sectionPostings, (person.PersonPostings && person.PersonPostings.Posting) || []);
        html += renderCollection(cbdbI18n.sectionStatuses, (person.PersonSocialStatus && person.PersonSocialStatus.SocialStatus) || []);
        html += renderCollection(cbdbI18n.sectionKinships, (person.PersonKinshipInfo && person.PersonKinshipInfo.Kinship) || []);
        html += renderCollection(cbdbI18n.sectionAssocs, (person.PersonSocialAssociation && person.PersonSocialAssociation.Association) || []);
        html += renderCollection(cbdbI18n.sectionTexts, (person.PersonTexts && person.PersonTexts.Text) || []);

        var jsonUrl = '?id=' + escapeHtml(String(personId)) + '&o=json';
        html += '<div class="api-info-box"><strong>' + cbdbI18n.jsonHint + '</strong> ' + cbdbI18n.jsonUse + ' <a href="' + jsonUrl + '" target="_blank" rel="noopener noreferrer"><code>' + jsonUrl.replace('&', '&amp;') + '</code></a></div>';

        return html;
    }

    function renderMergeHintBox(hint) {
        if (!hint || !hint.merged_to_person_id) {
            return '';
        }
        var mergedId = String(hint.merged_to_person_id);
        var link = '<a href="?id=' + encodeURIComponent(mergedId) + '" class="person-link" data-person-id="' + escapeHtml(mergedId) + '">ID ' + escapeHtml(mergedId) + '</a>';
        var html = '<div class="alert-box alert-info"><strong>' + cbdbI18n.mergeHintLabel + '</strong> ' + cbdbI18n.mergeHintText + ' ' + link + '。';
        if (hint.reason) {
            html += '<div class="small-text mt-2">' + cbdbI18n.mergeReasonLabel + escapeHtml(hint.reason) + '</div>';
        }
        html += '</div>';
        return html;
    }

    function requestPersonData() {
        var content = document.getElementById('person-content');

        if (!personId) {
            if (searchTermData && searchResultsData.length === 0) {
                content.innerHTML = '<div class="alert-box alert-warning">' + cbdbI18n.noMatch + '</div>';
            } else {
                content.innerHTML = '<div class="alert-box alert-info">' + cbdbI18n.selectPerson + '</div>';
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
                        var errorPayload = data && data.error ? data.error : null;
                        var message = (errorPayload && errorPayload.message) || cbdbI18n.loadFailed;
                        var html = '<div class="alert-box alert-danger">' + escapeHtml(message) + '</div>';
                        html += renderMergeHintBox(errorPayload && errorPayload.merge_hint);
                        content.innerHTML = html;
                    }).catch(function () {
                        content.innerHTML = '<div class="alert-box alert-danger">' + cbdbI18n.loadFailed + '</div>';
                    });
                } else {
                    content.innerHTML = '<div class="alert-box alert-danger">' + cbdbI18n.loadFailed + '</div>';
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

    // Only request person data if there are no validation errors
    if (!hasValidationErrors) {
        requestPersonData();
    }
})();
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
