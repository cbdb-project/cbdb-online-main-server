#!/usr/bin/env python3
"""
API Token 功能測試腳本

用法:
    python3 test_api_token.py <valid_token> [endpoint]

參數:
    valid_token: 有效的 API token (從 /profile 頁面創建)
    endpoint: 可選，要測試的 API endpoint (預設: /api/select/search/biog)

範例:
    python3 test_api_token.py "1|abc123def456..."
    python3 test_api_token.py "1|abc123def456..." /api/name
"""

import sys
import requests
from typing import Optional


class Colors:
    """終端機顏色"""
    GREEN = '\033[92m'
    RED = '\033[91m'
    YELLOW = '\033[93m'
    BLUE = '\033[94m'
    CYAN = '\033[96m'
    BOLD = '\033[1m'
    END = '\033[0m'


def print_header(text: str):
    """打印標題"""
    print(f"\n{Colors.BOLD}{Colors.CYAN}{'=' * 70}{Colors.END}")
    print(f"{Colors.BOLD}{Colors.CYAN}{text:^70}{Colors.END}")
    print(f"{Colors.BOLD}{Colors.CYAN}{'=' * 70}{Colors.END}\n")


def print_result(test_name: str, success: bool, message: str, details: Optional[dict] = None):
    """打印測試結果"""
    status = f"{Colors.GREEN}✓ PASS{Colors.END}" if success else f"{Colors.RED}✗ FAIL{Colors.END}"
    print(f"{Colors.BOLD}{test_name}{Colors.END}: {status}")
    print(f"  訊息: {message}")

    if details:
        for key, value in details.items():
            print(f"  {key}: {value}")
    print()


def test_api_request(
    base_url: str,
    endpoint: str,
    token: Optional[str],
    test_name: str,
    expected_status: int
) -> dict:
    """
    測試 API 請求

    Args:
        base_url: 基礎 URL
        endpoint: API endpoint
        token: API token (None 表示不帶 token)
        test_name: 測試名稱
        expected_status: 期望的 HTTP 狀態碼

    Returns:
        測試結果字典
    """
    url = f"{base_url}{endpoint}"
    headers = {
        'Accept': 'application/json',
        'User-Agent': 'CBDB-API-Token-Test/1.0'
    }

    if token:
        headers['Authorization'] = f'Bearer {token}'

    # 添加測試查詢參數
    params = {}
    if '/biog' in endpoint or '/name' in endpoint:
        params['q'] = '李'
        params['num'] = 5

    try:
        print(f"{Colors.BLUE}發送請求到: {url}{Colors.END}")
        if token:
            # 只顯示 token 的前後幾個字符
            token_preview = f"{token[:10]}...{token[-10:]}" if len(token) > 20 else token
            print(f"{Colors.YELLOW}使用 Token: {token_preview}{Colors.END}")
        else:
            print(f"{Colors.YELLOW}無 Token{Colors.END}")

        response = requests.get(url, headers=headers, params=params, timeout=10)

        result = {
            'status_code': response.status_code,
            'success': response.status_code == expected_status,
            'headers': dict(response.headers),
            'body': None
        }

        # 嘗試解析 JSON
        try:
            result['body'] = response.json()
        except:
            result['body'] = response.text[:200]  # 只取前 200 字符

        # 準備詳細信息
        details = {
            'HTTP 狀態': f"{response.status_code} ({response.reason})",
            'Content-Type': response.headers.get('Content-Type', 'N/A'),
        }

        if result['success']:
            # 成功的請求
            if isinstance(result['body'], dict):
                data_count = len(result['body'].get('data', []))
                details['回傳資料數'] = data_count
                message = f"請求成功，符合預期 (HTTP {expected_status})"
            else:
                message = f"請求成功，符合預期 (HTTP {expected_status})"

            print_result(test_name, True, message, details)
        else:
            # 失敗的請求
            error_msg = "未知錯誤"
            if isinstance(result['body'], dict):
                error_msg = result['body'].get('message', str(result['body']))
            elif isinstance(result['body'], str):
                error_msg = result['body']

            details['錯誤訊息'] = error_msg
            message = f"狀態碼不符預期 (預期: {expected_status}, 實際: {response.status_code})"

            print_result(test_name, False, message, details)

        return result

    except requests.exceptions.Timeout:
        print_result(test_name, False, "請求超時", {'錯誤': '連線超過 10 秒'})
        return {'success': False, 'error': 'timeout'}

    except requests.exceptions.ConnectionError as e:
        print_result(test_name, False, "連線失敗", {'錯誤': str(e)})
        return {'success': False, 'error': 'connection_error'}

    except Exception as e:
        print_result(test_name, False, "發生異常", {'錯誤': str(e)})
        return {'success': False, 'error': str(e)}


def main():
    """主函數"""
    # 檢查參數
    if len(sys.argv) < 2:
        print(f"{Colors.RED}錯誤: 缺少 API token 參數{Colors.END}\n")
        print(__doc__)
        sys.exit(1)

    valid_token = sys.argv[1]
    endpoint = sys.argv[2] if len(sys.argv) > 2 else '/api/select/search/biog'
    base_url = 'https://input.cbdb.fas.harvard.edu'

    print_header("CBDB API Token 功能測試")

    print(f"{Colors.BOLD}測試配置:{Colors.END}")
    print(f"  基礎 URL: {base_url}")
    print(f"  Endpoint: {endpoint}")
    print(f"  有效 Token: {valid_token[:15]}...{valid_token[-10:]}")
    print()

    # 測試 1: 使用有效的 token
    print_header("測試 1: 使用有效的 API Token")
    result1 = test_api_request(
        base_url=base_url,
        endpoint=endpoint,
        token=valid_token,
        test_name="有效 Token 測試",
        expected_status=200
    )

    # 測試 2: 使用錯誤的 token
    print_header("測試 2: 使用錯誤的 API Token")
    invalid_token = "999|invalidtokenstring123456789"
    result2 = test_api_request(
        base_url=base_url,
        endpoint=endpoint,
        token=invalid_token,
        test_name="錯誤 Token 測試",
        expected_status=401
    )

    # 測試 3: 不帶 token
    print_header("測試 3: 不帶 API Token")
    result3 = test_api_request(
        base_url=base_url,
        endpoint=endpoint,
        token=None,
        test_name="無 Token 測試",
        expected_status=401
    )

    # 測試 4: 格式錯誤的 token
    print_header("測試 4: 格式錯誤的 Token")
    malformed_token = "this-is-not-a-valid-token-format"
    result4 = test_api_request(
        base_url=base_url,
        endpoint=endpoint,
        token=malformed_token,
        test_name="格式錯誤 Token 測試",
        expected_status=401
    )

    # 總結
    print_header("測試總結")

    results = [
        ("有效 Token", result1.get('success', False)),
        ("錯誤 Token", result2.get('success', False)),
        ("無 Token", result3.get('success', False)),
        ("格式錯誤 Token", result4.get('success', False)),
    ]

    passed = sum(1 for _, success in results if success)
    total = len(results)

    for name, success in results:
        status = f"{Colors.GREEN}✓{Colors.END}" if success else f"{Colors.RED}✗{Colors.END}"
        print(f"{status} {name}")

    print(f"\n{Colors.BOLD}通過率: {passed}/{total} ({passed*100//total}%){Colors.END}")

    if passed == total:
        print(f"\n{Colors.GREEN}{Colors.BOLD}🎉 所有測試通過！API Token 功能正常運作。{Colors.END}")
        sys.exit(0)
    else:
        print(f"\n{Colors.RED}{Colors.BOLD}⚠️  部分測試失敗，請檢查 API Token 配置。{Colors.END}")
        sys.exit(1)


if __name__ == '__main__':
    main()
