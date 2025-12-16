<!doctype html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link href="https://fonts.googleapis.com/css?family=Raleway:100,600" rel="stylesheet" type="text/css">

        <!-- Styles -->
        <style>
            html, body {
                background-color: #fff;
                color: #636b6f;
                font-family: 'Raleway', sans-serif;
                font-weight: 100;
                height: 100vh;
                margin: 0;
            }

            .full-height {
                height: 100vh;
            }

            .flex-center {
                align-items: center;
                display: flex;
                justify-content: center;
            }

            .position-ref {
                position: relative;
            }

            .top-right {
                position: absolute;
                right: 10px;
                top: 18px;
            }

            .content {
                text-align: center;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                height: 100%;
            }

            .title {
                font-size: 84px;
                margin-top: -40px;
            }

            .links > a {
                color: #636b6f;
                padding: 0 25px;
                font-size: 12px;
                font-weight: 600;
                letter-spacing: .1rem;
                text-decoration: none;
                text-transform: uppercase;
            }

            .m-b-md {
                margin-bottom: 30px;
            }

            .search-box {
                width: min(640px, 90vw);
                max-width: 640px;
                margin: 24px auto 0;
                text-align: left;
                position: relative;
            }

            .search-box form {
                margin: 0;
            }

            .search-label {
                display: block;
                margin-bottom: 6px;
                font-size: 15px;
                color: #888;
            }

            .search-row {
                display: grid;
                grid-template-columns: 1fr auto;
                column-gap: 8px;
                align-items: stretch;
            }

            .search-box input[type="text"] {
                width: 100%;
                padding: 12px 14px;
                font-size: 16px;
                border: 1px solid #dcdcdc;
                border-radius: 6px;
                box-sizing: border-box;
            }

            .search-actions {
                display: flex;
                justify-content: flex-end;
                align-items: stretch;
            }

            .search-actions button {
                padding: 10px 16px;
                font-size: 15px;
                cursor: pointer;
                height: 100%;
                white-space: nowrap;
            }

            .suggestions {
                position: absolute;
                left: 0;
                right: 0;
                top: 100%;
                margin-top: 6px;
                border: 1px solid #dcdcdc;
                border-radius: 6px;
                max-height: 240px;
                overflow-y: auto;
                box-shadow: 0 4px 12px rgba(0,0,0,0.12);
                background: #fff;
                z-index: 10;
                display: none;
            }

            .suggestions button {
                display: block;
                width: 100%;
                padding: 10px 12px;
                border: none;
                background: #fff;
                text-align: left;
                cursor: pointer;
                font-size: 14px;
                border-bottom: 1px solid #f0f0f0;
            }

            .suggestions button:last-child {
                border-bottom: none;
            }

            .suggestions button:hover,
            .suggestions button:focus {
                background: #f5f5f5;
                outline: none;
            }

            @media (max-width: 768px) {
                .title {
                    font-size: 52px;
                    line-height: 1.1;
                    padding: 0 12px;
                }

                .search-box {
                    max-width: 90%;
                    margin-top: 15vh;
                }

                .search-row {
                    grid-template-columns: 1fr auto;
                }

                .search-actions button {
                    width: auto;
                }
            }
        </style>
    </head>
    <body>
        <div class="flex-center position-ref full-height">
            @if (Route::has('login'))
                <div class="top-right links">
                    @auth
                        <a href="{{ url('home') }}">Home</a>
                    @else
                        <a href="{{ url('home') }}">Guest</a>
                        <a href="{{ url('login') }}">Login</a>
                        <a href="{{ url('register') }}">Register</a>
                    @endauth
                </div>
            @endif

            <div class="content">
                <div class="title m-b-md">
                    中國歷代人物傳記 录入系统
                </div>

                <div class="search-box">
                    <form id="person-search-form">
                        <label for="person-search" class="search-label">
                            搜尋人物（可輸入人物 ID 或姓名）
                        </label>
                        <div class="search-row">
                            <input type="text" id="person-search" name="q" placeholder="例如：3144 或 張孝祥" autocomplete="off">
                            <div class="search-actions">
                                <button type="submit">搜尋</button>
                            </div>
                        </div>
                    </form>
                    <div id="person-suggestions" class="suggestions"></div>
                </div>
            </div>
        </div>

        <script>
            (function() {
                const input = document.getElementById('person-search');
                const form = document.getElementById('person-search-form');
                const suggestions = document.getElementById('person-suggestions');
                let abortController = null;

                const buildUrl = (term) => {
                    const isNumeric = /^\d+$/.test(term);
                    return isNumeric
                        ? `/cbdbapi/person?id=${encodeURIComponent(term)}`
                        : `/cbdbapi/person?name=${encodeURIComponent(term)}`;
                };

                const navigate = (term) => {
                    if (!term) return;
                    window.location.href = buildUrl(term);
                };

                const renderSuggestions = (rows) => {
                    if (!rows || rows.length === 0) {
                        suggestions.style.display = 'none';
                        suggestions.innerHTML = '';
                        return;
                    }
                    suggestions.innerHTML = rows.map((item) => {
                        const labelParts = [];
                        if (item.c_personid) {
                            labelParts.push(item.c_personid);
                        }
                        if (item.c_name_chn) {
                            labelParts.push(item.c_name_chn);
                        }
                        if (item.c_name) {
                            labelParts.push(item.c_name);
                        }
                        const dynasty = item.c_dynasty_chn ? `（${item.c_dynasty_chn}）` : '';
                        return `<button type="button" data-id="${item.c_personid}" data-name="${item.c_name_chn || item.c_name || ''}">
                                    ${labelParts.join(' - ')}${dynasty}
                                </button>`;
                    }).join('');
                    suggestions.style.display = 'block';
                };

                input.addEventListener('input', (e) => {
                    const term = e.target.value.trim();
                    if (term.length === 0) {
                        renderSuggestions([]);
                        return;
                    }

                    if (abortController) {
                        abortController.abort();
                    }
                    abortController = new AbortController();

                    fetch(`/api/name?q=${encodeURIComponent(term)}&num=8`, {
                        signal: abortController.signal,
                    }).then((res) => {
                        if (!res.ok) throw new Error('Network error');
                        return res.json();
                    }).then((data) => {
                        const rows = Array.isArray(data?.data) ? data.data : [];
                        renderSuggestions(rows);
                    }).catch((err) => {
                        if (err.name === 'AbortError') return;
                        renderSuggestions([]);
                    });
                });

                suggestions.addEventListener('click', (e) => {
                    if (e.target && e.target.tagName === 'BUTTON') {
                        const id = e.target.getAttribute('data-id');
                        if (id) {
                            navigate(id);
                        }
                    }
                });

                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    const term = input.value.trim();
                    navigate(term);
                });
            })();
        </script>
    </body>
</html>
