<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>CBDB Person Authority - {{ $personId }}</title>
</head>
<body>
@php
    $searchResults = $searchResults ?? [];
    $searchTerm = $searchTerm ?? null;
@endphp

@if(!empty($searchResults))
<div id="search-results">
    <div><b>搜尋結果</b>@if($searchTerm) ：「{{ e($searchTerm) }}」@endif</div>
    <div class="search-result-list">
        @foreach($searchResults as $result)
            <a href="#" class="person-search-result" data-person-id="{{ $result['id'] }}" style="display:inline-block;margin-right:18px;text-decoration:none;">
                {{ e($result['label']) }} ({{ $result['id'] }})
            </a>
        @endforeach
    </div>
</div>
@elseif($searchTerm)
<div id="search-results-message"><b>搜尋結果</b>：找不到符合「{{ e($searchTerm) }}」的資料。</div>
@endif

<div id="AuthorityContainer">
    <div id="person-anchor">
        <span id="person-anchor-text">CBDB</span>
    </div>

    <div id="Div_CBDB_PersonInfo">
        <div id="person-content">載入中…</div>
    </div>
</div>

<script>
var searchResultsData = @json($searchResults);
var initialPersonIdData = @json($personId);
var searchTermData = @json($searchTerm);

(function () {
    var personId = '';

    var anchorText = document.getElementById('person-anchor-text');

    function setPersonId(newId) {
        if (!newId) {
            return;
        }
        var normalized = String(newId).replace(/[^0-9]/g, '');
        if (!normalized) {
            return;
        }
        personId = normalized;
        if (anchorText) {
            anchorText.textContent = personId;
        }
        updateSearchResultHighlight();
    }

    function updateSearchResultHighlight() {
        var links = document.querySelectorAll('.person-search-result');
        Array.prototype.forEach.call(links, function (link) {
            link.style.display = 'inline-block';
            link.style.marginRight = '18px';
            link.style.textDecoration = 'none';
            if (link.getAttribute('data-person-id') === personId) {
                link.classList.add('is-current');
                link.setAttribute('aria-current', 'true');
                link.style.fontWeight = 'bold';
            } else {
                link.classList.remove('is-current');
                link.removeAttribute('aria-current');
                link.style.fontWeight = 'normal';
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
            return escapeHtml(label) + '(Id: ' + escapeHtml(id) + ')';
        }
        return escapeHtml(label || id || '');
    }

    function joinParts(parts, separator) {
        return parts.filter(function (part) { return part && part.trim().length > 0; })
            .join(separator || ', ');
    }

    function line(label, value) {
        return '<div><b>' + label + '</b>: ' + (value || '<span class="empty">（空）</span>') + '</div>';
    }

    function renderBasicInfo(info) {
        var rows = [];
        rows.push(line('CBDB ID', escapeHtml(info.PersonId)));
        var names = '/' + escapeHtml(info.ChName || '') + '/' + escapeHtml(info.EngName || '');
        rows.push(line('索引/中文/英文名稱', names));
        rows.push(line('指數年 (index year)', escapeHtml(info.IndexYear)));
        var indexAddrParts = [];
        if (info.IndexAddr) {
            indexAddrParts.push(formatIdLabel(info.IndexAddr, info.IndexAddrId));
        }
        rows.push(line('指數地址 (index address)', indexAddrParts.join(' ')));

        var birthParts = [];
        birthParts.push(formatIdLabel(info.DynastyBirth, info.DynastyBirthId));
        birthParts.push(formatIdLabel(info.EraBirth, info.EraBirthId));
        if (info.EraYearBirth) {
            birthParts.push(escapeHtml(info.EraYearBirth) + '年');
        }
        if (info.YearBirth) {
            birthParts.push('(' + escapeHtml(info.YearBirth) + ')');
        }
        rows.push(line('生年', joinParts(birthParts, '')));

        var deathParts = [];
        deathParts.push(formatIdLabel(info.DynastyDeath, info.DynastyDeathId));
        deathParts.push(formatIdLabel(info.EraDeath, info.EraDeathId));
        if (info.EraYearDeath) {
            deathParts.push(escapeHtml(info.EraYearDeath) + '年');
        }
        if (info.YearDeath) {
            deathParts.push('(' + escapeHtml(info.YearDeath) + ')');
        }
        rows.push(line('卒年', joinParts(deathParts, '')));

        rows.push(line('享年', escapeHtml(info.YearsLived)));
        rows.push(line('朝代', formatIdLabel(info.Dynasty, info.DynastyId)));
        rows.push(line('為女性', info.Gender === '' ? '<span class="empty">（空）</span>' : escapeHtml(info.Gender)));
        rows.push(line('郡望', info.JunWang ? formatIdLabel(info.JunWang, info.JunWangId) : '<span class="empty">（空）</span>'));

        if (info.Notes || info.Source || info.SourcePages) {
            rows.push('<div>註: ' + (info.Notes ? escapeHtml(info.Notes) : '<span class="empty">（空）</span>') + '</div>');

            if (info.Source || info.SourcePages) {
                rows.push('<div><b>人名權威資料鏈接</b>: ' +
                    (info.Source ? escapeHtml(info.Source) : '<span class="empty">（空）</span>') +
                    (info.SourcePages ? '，頁 ' + escapeHtml(info.SourcePages) : '') +
                    '</div>');
            }
        }

        return '<hr>' + rows.join('');
    }

    function renderSources(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<hr><div><b>' + title + '</b>:</div>';
        items.forEach(function (item) {
            var pieces = [];
            if (item.Source) {
                var sourceLabel = item.Source;
                if (item.SourceId) {
                    sourceLabel += '(Id: ' + item.SourceId + ')';
                }
                pieces.push('<nobr>' + escapeHtml(sourceLabel) + '</nobr>');
            }
            if (item.Pages) {
                pieces.push('頁 ' + escapeHtml(item.Pages));
            }
            if (item.Notes) {
                pieces.push(escapeHtml(item.Notes));
            }
            html += '<div>' + (pieces.length ? pieces.join('，') : '<span class="empty">（空）</span>') + '</div>';
        });
        return html;
    }

    function renderAliases(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var text = items.map(function (item) {
            var parts = [];
            if (item.AliasType) {
                var aliasType = item.AliasType;
                if (item.AliasTypeId) {
                    aliasType += '(Id:' + item.AliasTypeId + ')';
                }
                parts.push(aliasType);
            }
            if (item.AliasName) {
                parts.push(item.AliasName);
            }
            return escapeHtml(parts.join(''));
        }).join('，');
        return '<hr><div><b>' + title + '</b>: ' + (text || '<span class="empty">（空）</span>') + '</div>';
    }

    function buildAddressPath(item) {
        var path = [];
        for (var i = 1; i <= 5; i++) {
            var nameKey = 'belongs' + i + '_name';
            var idKey = 'belongs' + i + '_id';
            if (item[nameKey]) {
                var label = item[nameKey];
                if (item[idKey]) {
                    label += '(Id: ' + item[idKey] + ')';
                }
                path.push(label);
            }
        }
        if (item.AddrName) {
            var addr = item.AddrName;
            if (item.AddrId) {
                addr += '(' + item.AddrId + ')';
            }
            path.push(addr);
        }
        return path.map(escapeHtml).join(' / ');
    }

    function renderAddresses(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<hr><div><b>' + title + '</b>:</div>';
        items.forEach(function (item) {
            var headerParts = [];
            if (item.AddrType) {
                var typeLabel = item.AddrType;
                if (item.AddrTypeId !== undefined) {
                    typeLabel += '(Id:' + item.AddrTypeId + ')';
                }
                headerParts.push('<b>' + escapeHtml(typeLabel) + '</b>');
            }
            var path = buildAddressPath(item);
            var body = headerParts.join(' ') + (path ? ': ' + path : '');
            var extra = [];
            if (item.MoveCount) {
                extra.push('順序 ' + escapeHtml(item.MoveCount));
            }
            if (item.FirstYear) {
                extra.push('起始年 ' + escapeHtml(item.FirstYear));
            }
            if (item.LastYear) {
                extra.push('終止年 ' + escapeHtml(item.LastYear));
            }
            if (extra.length) {
                body += '（' + extra.join('，') + '）';
            }
            if (item.Source) {
                var src = '出處: ' + escapeHtml(item.Source);
                if (item.Pages) {
                    src += '，頁 ' + escapeHtml(item.Pages);
                }
                body += '<br>' + src;
            }
            if (item.Notes) {
                body += '<br>註: ' + escapeHtml(item.Notes);
            }
            html += '<div>▪ ' + body + '</div>';
        });
        return html;
    }

    function renderEntries(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<hr><div><b>' + title + '</b>:</div>';
        items.forEach(function (item, index) {
            var pieces = [];
            if (item.EntryType) {
                var entry = item.EntryType;
                if (item.EntryTypeId) {
                    entry += '(Id:' + item.EntryTypeId + ')';
                }
                pieces.push('<b>' + escapeHtml(entry) + '</b>');
            }
            if (item.EntryCode) {
                var code = item.EntryCode;
                if (item.EntryCodeId) {
                    code += '(' + item.EntryCodeId + ')';
                }
                pieces.push(escapeHtml(code));
            }
            var tail = [];
            if (item.RuShiYear) {
                tail.push('年份 ' + escapeHtml(item.RuShiYear));
            }
            if (item.RuShiAge) {
                tail.push('年齡 ' + escapeHtml(item.RuShiAge));
            }
            var detail = pieces.join(': ');
            if (tail.length) {
                detail += '（' + tail.join('，') + '）';
            }
            if (item.Source) {
                detail += '<br>出處: ' + escapeHtml(item.Source);
                if (item.Pages) {
                    detail += '，頁 ' + escapeHtml(item.Pages);
                }
            }
            if (item.Notes) {
                detail += '<br>註: ' + escapeHtml(item.Notes);
            }
            if (index > 0) {
                html += '<div><hr></div>';
            }
            html += '<div>▪ ' + detail + '</div>';
        });
        return html;
    }

    function renderPostings(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<hr><div><b>' + title + '</b>:</div>';
        items.forEach(function (item) {
            var header = '▪ ';
            if (item.FirstYearRange) {
                header += escapeHtml(item.FirstYearRange) + ' ';
            } else if (item.FirstYear) {
                header += escapeHtml(item.FirstYear) + ' ';
            } else {
                header += '未詳 ';
            }
            if (item.OfficeName) {
                header += '<b>' + escapeHtml(item.OfficeName) + '</b>';
            }
            var details = [];
            if (item.FirstYear || item.FirstYearNianhao || item.FirstYearNiaohaoYear) {
                var startParts = [];
                if (item.FirstYearNianhao) {
                    startParts.push(item.FirstYearNianhao);
                }
                if (item.FirstYearNiaohaoYear) {
                    startParts.push(item.FirstYearNiaohaoYear + '年');
                }
                if (item.FirstYear) {
                    startParts.push('(' + item.FirstYear + ')');
                }
                details.push('起始年: ' + escapeHtml(startParts.join(' ')));
            } else {
                details.push('起始年: 未詳');
            }
            if (item.LastYear || item.LastYearNianhao || item.LastYearNianhaoYear) {
                var endParts = [];
                if (item.LastYearNianhao) {
                    endParts.push(item.LastYearNianhao);
                }
                if (item.LastYearNianhaoYear) {
                    endParts.push(item.LastYearNianhaoYear + '年');
                }
                if (item.LastYear) {
                    endParts.push('(' + item.LastYear + ')');
                }
                details.push('終止年: ' + escapeHtml(endParts.join(' ')));
            } else {
                details.push('終止年: 未詳');
            }
            if (item.AddrName) {
                details.push('地點: <b>' + escapeHtml(item.AddrName) + '</b>');
            }
            if (item.Source) {
                var src = '出處: ' + escapeHtml(item.Source);
                if (item.Pages) {
                    src += '，頁 ' + escapeHtml(item.Pages);
                }
                details.push(src);
            }
            if (item.Notes) {
                details.push('註: ' + escapeHtml(item.Notes));
            }
            html += '<div>' + header + '<br>' + details.join('<br>') + '</div>';
        });
        return html;
    }

    function renderStatuses(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<hr><div><b>' + title + '</b>:</div>';
        items.forEach(function (item) {
            var text = [];
            if (item.StatusName) {
                var label = item.StatusName;
                if (item.StatusId) {
                    label += '(Id: ' + item.StatusId + ')';
                }
                text.push(label);
            }
            if (item.FirstYear) {
                text.push('起始年 ' + item.FirstYear);
            }
            if (item.LastYear) {
                text.push('終止年 ' + item.LastYear);
            }
            html += '<div>' + (text.length ? escapeHtml(text.join('，')) : '<span class="empty">（空）</span>') + '</div>';
        });
        return html;
    }

    function renderKinships(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<hr><div><b>' + title + '</b>:</div>';
        items.forEach(function (item) {
            var relation = item.KinRelName || item.KinRel || '親屬';
            var person = item.KinPersonName || '';
            var line = relation + ': ' + person;
            var extras = [];
            if (item.Source) {
                var src = '出處: ' + item.Source;
                if (item.Pages) {
                    src += '（頁 ' + item.Pages + '）';
                }
                extras.push(src);
            }
            if (item.Notes) {
                extras.push('註: ' + item.Notes);
            }
            html += '<div>' + escapeHtml(line) + (extras.length ? '<br>' + escapeHtml(extras.join('； ')) : '') + '</div>';
        });
        return html;
    }

    function renderAssociations(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<hr><div><b>' + title + '</b>:</div>';
        items.forEach(function (item) {
            var base = [];
            if (item.AssocName) {
                base.push(item.AssocName);
            }
            if (item.AssocPersonName) {
                base.push(item.AssocPersonName);
            }
            if (item.TextTitle) {
                base.push('【' + item.TextTitle + '】');
            }
            if (item.Year) {
                base.push('年份 ' + item.Year);
            }
            var line = base.join(' ');
            var extras = [];
            if (item.Source) {
                var src = '出處: ' + item.Source;
                if (item.Pages) {
                    src += '（頁 ' + item.Pages + '）';
                }
                extras.push(src);
            }
            if (item.Notes) {
                extras.push('註: ' + item.Notes);
            }
            html += '<div>▪ ' + (line ? escapeHtml(line) : '<span class="empty">（空）</span>') +
                (extras.length ? '<br>' + escapeHtml(extras.join('； ')) : '') + '</div>';
        });
        return html;
    }

    function renderTexts(title, items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }
        var html = '<hr><div><b>' + title + '</b>:</div>';
        items.forEach(function (item) {
            var line = [];
            if (item.TextName) {
                var name = item.TextName;
                if (item.TextId) {
                    name += ':';
                }
                line.push('<b>' + escapeHtml(name) + '</b>');
            }
            if (item.Year) {
                line.push('著作年代: ' + escapeHtml(item.Year));
            }
            if (item.Role) {
                line.push('角色: ' + escapeHtml(item.Role));
            }
            var details = line.join('，');
            if (item.Source) {
                details += '<br>出處: ' + escapeHtml(item.Source);
                if (item.Pages) {
                    details += '，頁 ' + escapeHtml(item.Pages);
                }
            }
            if (item.Notes) {
                details += '<br>註: ' + escapeHtml(item.Notes);
            }
            html += '<div>' + details + '</div>';
        });
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

        var html = '<hr><div><b>' + title + '</b>:</div>';

        items.forEach(function (item) {
            var segments = [];
            Object.keys(item).forEach(function (key) {
                var value = item[key];
                if (value === null || value === undefined || value === '') {
                    return;
                }
                var label = key;
                segments.push(escapeHtml(label) + ': ' + escapeHtml(value));
            });
            html += '<div>' + (segments.length ? '▪ ' + segments.join('； ') : '<span class="empty">（空）</span>') + '</div>';
        });

        html += '';
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
        var link = '<a href="' + escapeHtml(href) + '">跳轉</a>';
        return '<hr><div><b>' + title + '</b>: ' + link + '</div>';
    }

    function renderPerson(person) {
        if (!person) {
        return '找不到對應人物。';
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

        html += '<hr>需要原始 JSON？請使用 <code>?id=' + escapeHtml(String(personId)) + '&amp;o=json</code>';

        return html;
    }

    function requestPersonData() {
        var content = document.getElementById('person-content');

        if (!personId) {
            if (searchTermData && searchResultsData.length === 0) {
                content.innerHTML = '找不到符合條件的資料。';
            } else {
                content.innerHTML = '請選擇人物。';
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
                        content.innerHTML = escapeHtml(message);
                    }).catch(function () {
                        content.innerHTML = '載入失敗。';
                    });
                } else {
                    content.innerHTML = '載入失敗。';
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
        if (target && target.classList && target.classList.contains('person-search-result')) {
            event.preventDefault();
            loadPerson(target.getAttribute('data-person-id'));
        }
    });

    updateSearchResultHighlight();
    requestPersonData();
})();
</script>
</body>
</html>
