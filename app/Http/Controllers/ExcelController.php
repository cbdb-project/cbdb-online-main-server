<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;

use Excel;

class ExcelController extends Controller
{
    // Excel 文件到處功能
    public function export() {
        $cellData = [
            ['ID','name','scort'],
            ['10001','AAAAA','99'],
            ['10002','BBBBB','92'],
            ['10003','CCCCC','95'],
            ['10004','DDDDD','89'],
            ['10005','EEEEE','96'],
        ];

        Excel::create('calendar',function ($excel) use ($cellData){
            $excel->sheet('id', function ($sheet) use ($cellData){
                $sheet->rows($cellData);
            });
        })->export('xlsx');
    }

    public function import() {
        $filePath = 'public/abc.xls';
        Excel::load($filePath, function($reader) {
            $data = $reader->all();
            dd($data);
        });
    }

}
