<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOperationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid');
            $table->smallInteger('op_type')->comment('1.Popst(Create) 2.Put(Update 全部信息) 3. Patch(Update 部分属性) 4.Delete(Delete)');
            $table->string('resource');
            $table->string('resource_id');
            $table->json('resource_data');
            $table->json('biog')->nullable();
            $table->timestamps();
            $table->smallInteger('crowdsourcing_status')->comment('0.專業用戶修改紀錄 1.crowdsourcing記錄並已插入數據庫 2.crowdsourcing記錄還沒有被處理 3.crowdsourcing記錄reject 4.crowdsourcing處理失敗');
            $table->smallInteger('rate')->comment('記錄處理次數');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('operations');
    }
}
