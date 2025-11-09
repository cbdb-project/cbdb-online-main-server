<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddFulltextIndexToCbdbNameListTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 添加全文索引到 CBDB_NAME_LIST.name 字段
        // 使用 ngram 解析器支持中文分词（token 大小为 2，适合中文姓名）
        DB::statement('ALTER TABLE CBDB_NAME_LIST ADD FULLTEXT INDEX idx_name_fulltext (name) WITH PARSER ngram');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // 删除全文索引
        Schema::table('CBDB_NAME_LIST', function (Blueprint $table) {
            $table->dropIndex('idx_name_fulltext');
        });
    }
}
