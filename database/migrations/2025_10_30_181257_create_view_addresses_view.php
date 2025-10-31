<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateViewAddressesView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ADDR_BELONGS_DATA', function (Blueprint $table) {
            $table->index('c_belongs_to', 'idx_belongs_to');
        });

        // Using CREATE OR REPLACE is safer for re-running migrations
        DB::statement("
          CREATE OR REPLACE VIEW View_Address AS
          SELECT
            a0.c_addr_id,
            a0.c_name,
            a0.c_name_chn,
            a0.c_admin_type,
            a0.c_firstyear,
            a0.c_lastyear,
            a0.x_coord,
            a0.y_coord,
            
            -- Level 1 belongs to
            abd1.c_belongs_to AS belongs1_ID,
            a1.c_name AS belongs1_Name,
            
            -- Level 2 belongs to  
            abd2.c_belongs_to AS belongs2_ID,
            a2.c_name AS belongs2_Name,
            
            -- Level 3 belongs to
            abd3.c_belongs_to AS belongs3_ID,
            a3.c_name AS belongs3_Name,
            
            -- Level 4 belongs to
            abd4.c_belongs_to AS belongs4_ID,
            a4.c_name AS belongs4_Name,
            
            -- Level 5 belongs to
            abd5.c_belongs_to AS belongs5_ID,
            a5.c_name AS belongs5_Name

          FROM ADDR_CODES a0

          -- Join level 1
          LEFT JOIN ADDR_BELONGS_DATA abd1 ON a0.c_addr_id = abd1.c_addr_id
          LEFT JOIN ADDR_CODES a1 ON abd1.c_belongs_to = a1.c_addr_id

          -- Join level 2  
          LEFT JOIN ADDR_BELONGS_DATA abd2 ON a1.c_addr_id = abd2.c_addr_id
          LEFT JOIN ADDR_CODES a2 ON abd2.c_belongs_to = a2.c_addr_id

          -- Join level 3
          LEFT JOIN ADDR_BELONGS_DATA abd3 ON a2.c_addr_id = abd3.c_addr_id  
          LEFT JOIN ADDR_CODES a3 ON abd3.c_belongs_to = a3.c_addr_id

          -- Join level 4
          LEFT JOIN ADDR_BELONGS_DATA abd4 ON a3.c_addr_id = abd4.c_addr_id
          LEFT JOIN ADDR_CODES a4 ON abd4.c_belongs_to = a4.c_addr_id

          -- Join level 5  
          LEFT JOIN ADDR_BELONGS_DATA abd5 ON a4.c_addr_id = abd5.c_addr_id
          LEFT JOIN ADDR_CODES a5 ON abd5.c_belongs_to = a5.c_addr_id
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement("DROP VIEW IF EXISTS View_Address");
        Schema::table('ADDR_BELONGS_DATA', function (Blueprint $table) {
            $table->dropIndex('idx_belongs_to');
        });
    }
}
