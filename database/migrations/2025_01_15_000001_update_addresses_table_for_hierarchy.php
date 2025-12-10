<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateAddressesTableForHierarchy extends Migration {
    public function up(): void {
        Schema::table('ADDRESSES', function (Blueprint $table) {
            if (!Schema::hasColumn('ADDRESSES', 'c_belongs_firstyear')) {
                $table->smallInteger('c_belongs_firstyear')
                    ->nullable()
                    ->after('c_lastyear');
            }

            if (!Schema::hasColumn('ADDRESSES', 'c_belongs_lastyear')) {
                $table->smallInteger('c_belongs_lastyear')
                    ->nullable()
                    ->after('c_belongs_firstyear');
            }

            for ($level = 1; $level <= 5; $level++) {
                $column = "belongs{$level}_Name_chn";
                if (!Schema::hasColumn('ADDRESSES', $column)) {
                    $table->string($column, 255)
                        ->nullable()
                        ->after("belongs{$level}_Name");
                }
            }
        });
    }

    public function down(): void {
        Schema::table('ADDRESSES', function (Blueprint $table) {
            if (Schema::hasColumn('ADDRESSES', 'c_belongs_firstyear')) {
                $table->dropColumn('c_belongs_firstyear');
            }

            if (Schema::hasColumn('ADDRESSES', 'c_belongs_lastyear')) {
                $table->dropColumn('c_belongs_lastyear');
            }

            for ($level = 1; $level <= 5; $level++) {
                $column = "belongs{$level}_Name_chn";
                if (Schema::hasColumn('ADDRESSES', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
