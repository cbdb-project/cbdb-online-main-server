<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SecurityAuditLogger;
use App\Support\ApiTokenAbilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ApiTokenController extends Controller {
    public function __construct(private SecurityAuditLogger $securityAudit) {
    }

    /**
     * 顯示使用者的 API tokens
     */
    public function index(Request $request) {
        return response()->json(
            $request->user()->tokens->map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ];
            })
        );
    }

    /**
     * 創建新的 API token
     */
    public function store(Request $request) {
        ApiTokenAbilities::assertMcpAbilityIsIssuable();

        // 先去重再驗證：重複元素對授權毫無意義，不該因此被 422 擋下；但 max 規則要看的是
        // 去重後的筆數，否則「上千個重複值」會先撐爆 TEXT 欄位（見下方 max 註解）。
        // SORT_REGULAR：元素可能不是字串（巢狀陣列會由 abilities.* 的 string 規則擋下），
        // 預設的 SORT_STRING 會先觸發 Array to string conversion。
        if (is_array($request->input('abilities'))) {
            $request->merge([
                'abilities' => array_values(array_unique($request->input('abilities'), SORT_REGULAR)),
            ]);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // min:1：顯式傳空陣列會簽出一個「能認證但永遠進不了 MCP」的 token，
            //   使用者完全看不出原因，寧可當場擋下。
            // max:count(allowed)：abilities 是 TEXT 欄位而生產 sql_mode 沒有
            //   STRICT_TRANS_TABLES，數千個重複元素會被靜默截斷到 65535 bytes，
            //   之後 json_decode 回 null、Sanctum 的 can() 直接拋 TypeError，該 token 永久壞掉。
            'abilities' => ['sometimes', 'array', 'min:1', 'max:'.count(ApiTokenAbilities::allowed())],
            // 只接受登記過的能力；通配 '*' 不在清單內，會被這條擋下。
            'abilities.*' => ['string', Rule::in(ApiTokenAbilities::allowed())],
            'expires_in' => ['sometimes', 'integer', 'min:1', 'max:3650'],
        ], [
            // Rule::in 的預設訊息（「所選的 abilities.0 無效」）看不出為什麼，
            // 而請求通配是最可能的誤用，所以給它一句明確的說明。
            'abilities.*.in' => '不支援的 token 能力。允許的能力：'
                .implode('、', ApiTokenAbilities::allowed())
                .'。通配能力（*）已停用——它等於自動授予日後新增的每一種能力。',
        ]);

        // 未指定時給「能用的最小權限」，不再是通配（見 ApiTokenAbilities 註解）。
        // 有指定時已在驗證前去重並通過允許清單，直接用。
        $abilities = $request->has('abilities')
            ? array_values($request->input('abilities'))
            : ApiTokenAbilities::default();

        $expiresAt = $request->has('expires_in') && $request->input('expires_in')
            ? now()->addDays($request->input('expires_in'))
            : null;

        // 與 AccountAccessRevoker 序列化：管理員停用帳號會在同一把 users 列鎖下撤銷 token。
        // 沒有這把鎖時，兩者可能交錯成「撤銷讀到舊清單 → 建立寫入新 token → 停用完成但仍留
        // 一個有效憑證」，等帳號日後重新啟用就復活。鎖內重讀 is_active，停用先提交就直接 403。
        $token = DB::transaction(function () use ($request, $abilities, $expiresAt) {
            $locked = DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            abort_if(
                !$locked || (int) $locked->is_active !== User::STATUS_ACTIVE,
                403,
                __('auth.account_inactive')
            );

            return $request->user()->createToken(
                $request->input('name'),
                $abilities,
                $expiresAt
            );
        });

        // 簽發長期憑證是安全敏感操作，記應用層審計（含 IP／UA）。入侵調查最想知道的
        // 就是「這個 token 是誰在什麼時候從哪裡簽的」。**不記 token 明文或雜湊**。
        $this->securityAudit->record(
            table: 'personal_access_tokens',
            operation: 'INSERT',
            rowPk: ['id' => (int) $token->accessToken->id],
            event: 'api_token_created',
            after: [
                'name' => $token->accessToken->name,
                'abilities' => $token->accessToken->abilities,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'tokenable_id' => (int) $request->user()->id,
            ]
        );

        // If this is a JSON request, return JSON response
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Token 創建成功',
                'token' => [
                    'id' => $token->accessToken->id,
                    'name' => $token->accessToken->name,
                    'abilities' => $token->accessToken->abilities,
                    'created_at' => $token->accessToken->created_at->toIso8601String(),
                    'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                    // 只在創建時返回完整 token（之後無法再次查看）
                    'plainTextToken' => $token->plainTextToken,
                ],
            ]);
        }

        // Otherwise, redirect back with the token in session flash
        return redirect()->route('profile.edit')
            ->with('token', $token->plainTextToken)
            ->with('success', 'API Token 創建成功，請妥善保存');
    }

    /**
     * 撤銷 API token
     */
    public function destroy(Request $request, $tokenId) {
        $token = $request->user()->tokens()->findOrFail($tokenId);
        $snapshot = [
            'name' => $token->name,
            'abilities' => $token->abilities,
            'last_used_at' => optional($token->last_used_at)->toIso8601String(),
            'tokenable_id' => (int) $request->user()->id,
        ];
        $token->delete();

        // 使用者自行撤銷也要留紀錄：管理員停用時的撤銷已由 AccountAccessRevoker 記錄，
        // 但「憑證何時失效」在事件時序重建時同樣關鍵（例如判斷某次呼叫是否還在有效期內）。
        $this->securityAudit->record(
            table: 'personal_access_tokens',
            operation: 'DELETE',
            rowPk: ['id' => (int) $tokenId],
            event: 'api_token_revoked_by_owner',
            before: $snapshot
        );

        return response()->json([
            'message' => 'Token 已撤銷',
        ]);
    }

    /**
     * 撤銷所有 tokens
     */
    public function destroyAll(Request $request) {
        // 鎖 users 列後才讀快照＋刪除，與 AccountAccessRevoker 同一手法：無鎖的
        // read-then-delete 若在兩步之間被 store() 插入一個新 token，那個 token 會被
        // tokens()->delete() 一併刪掉卻不在審計快照裡——審計會聲稱撤銷 2 個、實際銷毀 3 個。
        $snapshot = DB::transaction(function () use ($request) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();

            $rows = $request->user()->tokens()->get(['id', 'name', 'abilities', 'last_used_at'])
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                ])->all();

            $request->user()->tokens()->delete();

            return $rows;
        });

        if ($snapshot !== []) {
            $this->securityAudit->record(
                table: 'personal_access_tokens',
                operation: 'DELETE',
                rowPk: ['tokenable_id' => (int) $request->user()->id],
                event: 'api_tokens_revoked_by_owner',
                before: ['tokens' => $snapshot]
            );
        }

        return response()->json([
            'message' => '所有 Tokens 已撤銷',
        ]);
    }
}
