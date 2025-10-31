<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateViewAddressesView extends Migration
{
    public function up()
    {
        Schema::table('ADDR_BELONGS_DATA', function (Blueprint $table) {
            $table->index('c_belongs_to', 'idx_belongs_to');
            $table->index(['c_addr_id', 'c_firstyear', 'c_lastyear'], 'idx_addr_year');
        });
        
        DB::statement("
          CREATE OR REPLACE VIEW View_Address AS
          SELECT
            a0.c_addr_id,
            a0.c_name,
            a0.c_name_chn,
            a0.c_admin_type,
            a0.x_coord,
            a0.y_coord,
            
            -- 使用交集的时间段：所有层级的时间交集
            GREATEST(
              COALESCE(abd1.c_firstyear, 0),
              COALESCE(abd2.c_firstyear, 0),
              COALESCE(abd3.c_firstyear, 0),
              COALESCE(abd4.c_firstyear, 0),
              COALESCE(abd5.c_firstyear, 0)
            ) AS c_firstyear,
            
            LEAST(
              COALESCE(abd1.c_lastyear, 9999),
              COALESCE(abd2.c_lastyear, 9999),
              COALESCE(abd3.c_lastyear, 9999),
              COALESCE(abd4.c_lastyear, 9999),
              COALESCE(abd5.c_lastyear, 9999)
            ) AS c_lastyear,
            
            -- Level 1
            abd1.c_belongs_to AS belongs1_ID,
            a1.c_name_chn AS belongs1_Name,
            a1.c_admin_type AS belongs1_Type,
            abd1.c_firstyear AS belongs1_FirstYear,
            abd1.c_lastyear AS belongs1_LastYear,
            
            -- Level 2
            abd2.c_belongs_to AS belongs2_ID,
            a2.c_name_chn AS belongs2_Name,
            a2.c_admin_type AS belongs2_Type,
            abd2.c_firstyear AS belongs2_FirstYear,
            abd2.c_lastyear AS belongs2_LastYear,
            
            -- Level 3
            abd3.c_belongs_to AS belongs3_ID,
            a3.c_name_chn AS belongs3_Name,
            a3.c_admin_type AS belongs3_Type,
            abd3.c_firstyear AS belongs3_FirstYear,
            abd3.c_lastyear AS belongs3_LastYear,
            
            -- Level 4
            abd4.c_belongs_to AS belongs4_ID,
            a4.c_name_chn AS belongs4_Name,
            a4.c_admin_type AS belongs4_Type,
            abd4.c_firstyear AS belongs4_FirstYear,
            abd4.c_lastyear AS belongs4_LastYear,
            
            -- Level 5
            abd5.c_belongs_to AS belongs5_ID,
            a5.c_name_chn AS belongs5_Name,
            a5.c_admin_type AS belongs5_Type,
            abd5.c_firstyear AS belongs5_FirstYear,
            abd5.c_lastyear AS belongs5_LastYear
            
          FROM ADDR_CODES a0
          
          -- Level 1
          INNER JOIN ADDR_BELONGS_DATA abd1 ON a0.c_addr_id = abd1.c_addr_id
          LEFT JOIN ADDR_CODES a1 ON abd1.c_belongs_to = a1.c_addr_id
          
          -- Level 2: 时间必须重叠
          LEFT JOIN ADDR_BELONGS_DATA abd2 ON a1.c_addr_id = abd2.c_addr_id
            AND (abd2.c_firstyear IS NULL OR abd1.c_lastyear IS NULL 
                 OR abd2.c_firstyear <= abd1.c_lastyear)
            AND (abd2.c_lastyear IS NULL OR abd1.c_firstyear IS NULL 
                 OR abd2.c_lastyear >= abd1.c_firstyear)
          LEFT JOIN ADDR_CODES a2 ON abd2.c_belongs_to = a2.c_addr_id
          
          -- Level 3: 时间必须重叠
          LEFT JOIN ADDR_BELONGS_DATA abd3 ON a2.c_addr_id = abd3.c_addr_id
            AND (abd3.c_firstyear IS NULL OR abd1.c_lastyear IS NULL 
                 OR abd3.c_firstyear <= abd1.c_lastyear)
            AND (abd3.c_lastyear IS NULL OR abd1.c_firstyear IS NULL 
                 OR abd3.c_lastyear >= abd1.c_firstyear)
            AND (abd3.c_firstyear IS NULL OR abd2.c_lastyear IS NULL 
                 OR abd3.c_firstyear <= abd2.c_lastyear)
            AND (abd3.c_lastyear IS NULL OR abd2.c_firstyear IS NULL 
                 OR abd3.c_lastyear >= abd2.c_firstyear)
          LEFT JOIN ADDR_CODES a3 ON abd3.c_belongs_to = a3.c_addr_id
          
          -- Level 4: 时间必须重叠
          LEFT JOIN ADDR_BELONGS_DATA abd4 ON a3.c_addr_id = abd4.c_addr_id
            AND (abd4.c_firstyear IS NULL OR abd1.c_lastyear IS NULL 
                 OR abd4.c_firstyear <= abd1.c_lastyear)
            AND (abd4.c_lastyear IS NULL OR abd1.c_firstyear IS NULL 
                 OR abd4.c_lastyear >= abd1.c_firstyear)
            AND (abd4.c_firstyear IS NULL OR abd2.c_lastyear IS NULL 
                 OR abd4.c_firstyear <= abd2.c_lastyear)
            AND (abd4.c_lastyear IS NULL OR abd2.c_firstyear IS NULL 
                 OR abd4.c_lastyear >= abd2.c_firstyear)
            AND (abd4.c_firstyear IS NULL OR abd3.c_lastyear IS NULL 
                 OR abd4.c_firstyear <= abd3.c_lastyear)
            AND (abd4.c_lastyear IS NULL OR abd3.c_firstyear IS NULL 
                 OR abd4.c_lastyear >= abd3.c_firstyear)
          LEFT JOIN ADDR_CODES a4 ON abd4.c_belongs_to = a4.c_addr_id
          
          -- Level 5: 时间必须重叠
          LEFT JOIN ADDR_BELONGS_DATA abd5 ON a4.c_addr_id = abd5.c_addr_id
            AND (abd5.c_firstyear IS NULL OR abd1.c_lastyear IS NULL 
                 OR abd5.c_firstyear <= abd1.c_lastyear)
            AND (abd5.c_lastyear IS NULL OR abd1.c_firstyear IS NULL 
                 OR abd5.c_lastyear >= abd1.c_firstyear)
            AND (abd5.c_firstyear IS NULL OR abd2.c_lastyear IS NULL 
                 OR abd5.c_firstyear <= abd2.c_lastyear)
            AND (abd5.c_lastyear IS NULL OR abd2.c_firstyear IS NULL 
                 OR abd5.c_lastyear >= abd2.c_firstyear)
            AND (abd5.c_firstyear IS NULL OR abd3.c_lastyear IS NULL 
                 OR abd5.c_firstyear <= abd3.c_lastyear)
            AND (abd5.c_lastyear IS NULL OR abd3.c_firstyear IS NULL 
                 OR abd5.c_lastyear >= abd3.c_firstyear)
            AND (abd5.c_firstyear IS NULL OR abd4.c_lastyear IS NULL 
                 OR abd5.c_firstyear <= abd4.c_lastyear)
            AND (abd5.c_lastyear IS NULL OR abd4.c_firstyear IS NULL 
                 OR abd5.c_lastyear >= abd4.c_firstyear)
          LEFT JOIN ADDR_CODES a5 ON abd5.c_belongs_to = a5.c_addr_id
          
          -- 过滤掉无效的时间段（结束年早于开始年）
          HAVING c_lastyear >= c_firstyear OR c_lastyear = 9999
        ");
    }

    public function down()
    {
        DB::statement("DROP VIEW IF EXISTS View_Address");
        
        Schema::table('ADDR_BELONGS_DATA', function (Blueprint $table) {
            $table->dropIndex('idx_belongs_to');
            $table->dropIndex('idx_addr_year');
        });
    }
}
