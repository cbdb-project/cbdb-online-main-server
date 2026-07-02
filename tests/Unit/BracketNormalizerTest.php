<?php

namespace Tests\Unit;

use App\Services\BracketNormalizer;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class BracketNormalizerTest extends TestCase {
    // ========================================================
    // 中文欄位：僅全角→半角，不加空格
    // ========================================================

    /**
     * 中文欄位：全角括號轉半角
     */
    public function test_chinese_converts_fullwidth_to_halfwidth(): void {
        $this->assertSame('升卿(一作陞卿)', BracketNormalizer::normalizeChineseField('升卿（一作陞卿）'));
        $this->assertSame('庇民(芘民)', BracketNormalizer::normalizeChineseField('庇民（芘民）'));
    }

    /**
     * 中文欄位：半角括號保持原樣，不加空格
     */
    public function test_chinese_does_not_add_spaces(): void {
        $this->assertSame('升卿(一作陞卿)', BracketNormalizer::normalizeChineseField('升卿(一作陞卿)'));
        $this->assertSame('孟(夢)開', BracketNormalizer::normalizeChineseField('孟(夢)開'));
    }

    /**
     * 中文欄位：移除半角括號前後緊鄰的空白
     *
     * 使用者若輸入「李白 (青蓮)」（半角括號帶空格），
     * 應標準化為「李白(青蓮)」，避免搜尋索引殘留空白。
     */
    public function test_chinese_removes_spaces_around_brackets(): void {
        $this->assertSame('李白(青蓮)', BracketNormalizer::normalizeChineseField('李白 (青蓮)'));
        $this->assertSame('李白(青蓮)', BracketNormalizer::normalizeChineseField('李白（青蓮）'));
        $this->assertSame('李白(青蓮)', BracketNormalizer::normalizeChineseField('李白 （青蓮）'));
        $this->assertSame('李白(青蓮)', BracketNormalizer::normalizeChineseField('李白( 青蓮 )'));
        $this->assertSame('李白(青蓮)', BracketNormalizer::normalizeChineseField('李白 ( 青蓮 ) '));
        // 全形空白 U+3000
        $this->assertSame('李白(青蓮)', BracketNormalizer::normalizeChineseField('李白　(青蓮)'));
        // 括號後仍有內容
        $this->assertSame('升卿(一作陞卿)號', BracketNormalizer::normalizeChineseField('升卿 (一作陞卿) 號'));
    }

    /**
     * 中文欄位：空值與空字串
     */
    public function test_chinese_null_and_empty(): void {
        $this->assertNull(BracketNormalizer::normalizeChineseField(null));
        $this->assertSame('', BracketNormalizer::normalizeChineseField(''));
    }

    /**
     * 中文欄位：不含括號的字串不受影響
     */
    public function test_chinese_no_brackets_unchanged(): void {
        $this->assertSame('張三', BracketNormalizer::normalizeChineseField('張三'));
    }

    // ========================================================
    // 拼音／外文欄位：全角→半角 + 空格
    // ========================================================

    /**
     * 拼音欄位：全角括號轉半角並加空格
     */
    public function test_pinyin_converts_fullwidth_with_spaces(): void {
        $this->assertSame('Huanzhi (Zihuan)', BracketNormalizer::normalizePinyinField('Huanzhi（Zihuan）'));
    }

    /**
     * 拼音欄位：半角括號前後補空格
     */
    public function test_pinyin_adds_spaces_around_brackets(): void {
        $this->assertSame('Huanzhi (Zihuan)', BracketNormalizer::normalizePinyinField('Huanzhi(Zihuan)'));
        $this->assertSame('Youlai (Youshen)', BracketNormalizer::normalizePinyinField('Youlai(Youshen)'));
        $this->assertSame('Zheng (1) Ansi', BracketNormalizer::normalizePinyinField('Zheng(1) Ansi'));
    }

    /**
     * 拼音欄位：已有空格不產生多餘空格
     */
    public function test_pinyin_no_extra_spaces(): void {
        $this->assertSame('Zhang (1) Test', BracketNormalizer::normalizePinyinField('Zhang (1) Test'));
        $this->assertSame('Zheng (1) Ansi', BracketNormalizer::normalizePinyinField('Zheng (1) Ansi'));
    }

    /**
     * 拼音欄位：括號在開頭或結尾
     */
    public function test_pinyin_brackets_at_edges(): void {
        $this->assertSame('(test) value', BracketNormalizer::normalizePinyinField('(test)value'));
        $this->assertSame('value (test)', BracketNormalizer::normalizePinyinField('value(test)'));
        $this->assertSame('(test)', BracketNormalizer::normalizePinyinField('(test)'));
    }

    /**
     * 拼音欄位：空值與空字串
     */
    public function test_pinyin_null_and_empty(): void {
        $this->assertNull(BracketNormalizer::normalizePinyinField(null));
        $this->assertSame('', BracketNormalizer::normalizePinyinField(''));
    }

    /**
     * 拼音欄位：多重括號
     */
    public function test_pinyin_multiple_brackets(): void {
        $this->assertSame('A (B) C (D) E', BracketNormalizer::normalizePinyinField('A(B)C(D)E'));
    }

    // ========================================================
    // Issue #913 實際案例
    // ========================================================

    /**
     * Issue #913 中文欄位案例：僅全角→半角
     */
    public function test_issue_913_chinese_cases(): void {
        $this->assertSame('升卿(一作陞卿)', BracketNormalizer::normalizeChineseField('升卿(一作陞卿)'));
        $this->assertSame('子政(正)', BracketNormalizer::normalizeChineseField('子政(正)'));
        $this->assertSame('孟(夢)開', BracketNormalizer::normalizeChineseField('孟(夢)開'));
        $this->assertSame('耶律阿保謹(阿布機)', BracketNormalizer::normalizeChineseField('耶律阿保謹(阿布機)'));
        // 全角案例
        $this->assertSame('深父(甫)', BracketNormalizer::normalizeChineseField('深父（甫）'));
        $this->assertSame('德陽(暘)', BracketNormalizer::normalizeChineseField('德陽（暘）'));
    }

    /**
     * Issue #913 拼音欄位案例：全角→半角 + 空格
     */
    public function test_issue_913_pinyin_cases(): void {
        $this->assertSame('Zheng (1) Ansi', BracketNormalizer::normalizePinyinField('Zheng(1) Ansi'));
        $this->assertSame('Wen (2) Ji', BracketNormalizer::normalizePinyinField('Wen(2) Ji'));
        $this->assertSame('Liu (2) Wei', BracketNormalizer::normalizePinyinField('Liu(2) Wei'));
        $this->assertSame('Huanzhi (Zihuan)', BracketNormalizer::normalizePinyinField('Huanzhi(Zihuan)'));
        $this->assertSame('Chengzhi (Cangzhi)', BracketNormalizer::normalizePinyinField('Chengzhi(Cangzhi)'));
    }

    // ========================================================
    // 批量欄位處理
    // ========================================================

    /**
     * normalizeBiogMain：中文欄位僅轉半角，拼音欄位加空格
     */
    public function test_normalize_biog_main(): void {
        $data = [
            'c_surname_chn' => '張',
            'c_mingzi_chn' => '三（四）',
            'c_name_chn' => '張三（四）',
            'c_surname' => 'Zhang',
            'c_mingzi' => 'San(Si)',
            'c_name' => 'Zhang San(Si)',
        ];

        $result = BracketNormalizer::normalizeBiogMain($data);

        // 中文：僅全角→半角
        $this->assertSame('張', $result['c_surname_chn']);
        $this->assertSame('三(四)', $result['c_mingzi_chn']);
        $this->assertSame('張三(四)', $result['c_name_chn']);
        // 拼音：全角→半角 + 空格
        $this->assertSame('Zhang', $result['c_surname']);
        $this->assertSame('San (Si)', $result['c_mingzi']);
        $this->assertSame('Zhang San (Si)', $result['c_name']);
    }

    /**
     * normalizeAltname：中文僅轉半角，拼音加空格
     */
    public function test_normalize_altname(): void {
        $data = [
            'c_alt_name_chn' => '升卿（一作陞卿）',
            'c_alt_name' => 'Huanzhi(Zihuan)',
            'c_source' => '123',
        ];

        $result = BracketNormalizer::normalizeAltname($data);

        $this->assertSame('升卿(一作陞卿)', $result['c_alt_name_chn']);
        $this->assertSame('Huanzhi (Zihuan)', $result['c_alt_name']);
        $this->assertSame('123', $result['c_source']);
    }

    /**
     * normalizeRequest：中文 + 拼音分開處理
     */
    public function test_normalize_request(): void {
        $request = new Request([
            'c_alt_name_chn' => '庇民（芘民）',
            'c_alt_name' => 'Bimin(Pimin)',
        ]);

        BracketNormalizer::normalizeRequest(
            $request,
            BracketNormalizer::ALTNAME_CHN_FIELDS,
            BracketNormalizer::ALTNAME_PINYIN_FIELDS
        );

        $this->assertSame('庇民(芘民)', $request->input('c_alt_name_chn'));
        $this->assertSame('Bimin (Pimin)', $request->input('c_alt_name'));
    }

    // ========================================================
    // 搜尋索引相容性驗證
    // ========================================================

    /**
     * 驗證中文正規化不會在字之間插入空格，
     * 確保 NameSearchIndexService 的 normalizeName() 結果不受影響。
     *
     * normalizeName() 移除括號字元後拼接內容：
     * 「宗氏(李白妻)」→「宗氏李白妻」（連續 token）
     * 如果我們在中文欄位加空格：「宗氏 (李白妻)」→「宗氏 李白妻」（破壞搜尋）
     */
    public function test_chinese_normalization_preserves_search_index_compatibility(): void {
        // 模擬 normalizeName() 的行為
        $strip = fn ($s) => preg_replace('/[()（）]/u', '', $s);

        // 原始值（全角括號）
        $original = '宗氏（李白妻）';
        $originalIndexed = $strip($original); // 宗氏李白妻

        // 正規化後（半角括號，無空格）
        $normalized = BracketNormalizer::normalizeChineseField($original);
        $normalizedIndexed = $strip($normalized); // 宗氏李白妻

        // 搜尋索引結果應完全一致
        $this->assertSame($originalIndexed, $normalizedIndexed);
        $this->assertSame('宗氏李白妻', $normalizedIndexed);
    }

    /**
     * 驗證使用者輸入帶空白的半角括號時，正規化後不會破壞搜尋索引。
     *
     * 若未移除空白：「李白 (青蓮)」→ 去括號後「李白 青蓮」（殘留空白，破壞搜尋）。
     * 正規化後：「李白(青蓮)」→ 去括號後「李白青蓮」（連續 token，可正常搜尋）。
     */
    public function test_chinese_space_removal_preserves_search_index_compatibility(): void {
        $strip = fn ($s) => preg_replace('/[()（）]/u', '', $s);

        $normalized = BracketNormalizer::normalizeChineseField('李白 (青蓮)');
        $indexed = $strip($normalized);

        $this->assertSame('李白(青蓮)', $normalized);
        $this->assertSame('李白青蓮', $indexed);
    }
}
