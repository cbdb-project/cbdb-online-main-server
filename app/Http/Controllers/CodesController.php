<?php

namespace App\Http\Controllers;

use App\Repositories\CodesRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use phpDocumentor\Reflection\Types\Null_;

class CodesController extends Controller
{
    protected $codesrepostory;

    public function __construct(CodesRepository $codesRepository)
    {
        $this->middleware('auth');
        $this->codesrepostory = $codesRepository;
    }

    public function index(Request $request)
    {
        $table_name = $request->q;
        if($table_name) {
            try{
                $data = DB::table($table_name)->paginate(10);
                $thead = array();
                $count = 0;
                foreach($data[0] as $key => $value){
                    if ($count >2){
                        break;
                    }
                    if(str_contains($key, 'name') or str_contains($key, 'desc') or str_contains($key, 'code') or str_contains($key, 'id') or str_contains($key, 'sequence')){
                        array_push($thead, $key);
                        $count++;
                    }
                }
//                dd($data);
                return view('codes.show', ['page_title' => 'Codes', 'page_description' => 'Codes tables','q' => $table_name, 'thead' => $thead, 'data' => $data]);
            }catch (\PDOException $e){
                flash('找不到该数据表', 'warning');
                return redirect()->back();
            }
        }
        $data = $this->codesrepostory->codes();
        return view('codes.index',['page_title' => 'Codes', 'page_description' => 'Codes tables', 'data' => $data]);
    }

    public function edit(Request $request, $id)
    {
        $table_name = $request->q;

        if($table_name){
            try{
                $id_name = $this->getIdName($table_name);
                $data = DB::table($table_name)->where($id_name, $id)->first();
                return view('codes.edit', ['page_title' => 'Codes', 'page_description' => 'Codes tables', 'id' => $id, 'row' => $data, 'table' => $table_name]);
            }catch (\PDOException $e) {
                flash('找不到该数据表', 'warning');
                return redirect()->back();
            }

        }
        $data = $this->codesrepostory->codes();
        return view('codes.index',['page_title' => 'Codes', 'page_description' => 'Codes tables', 'data' => $data]);
    }

    public function update(Request $request, $id)
    {
        $data = $request->all();
        $table_name = $data['_table'];
        $data = array_except($data, ['_table', '_method', '_token']);
//        dd($data);
        $id_name = $this->getIdName($table_name);
        DB::table($table_name)->where($id_name, $id)->update($data);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->back();
    }

    protected function getIdName($table_name)
    {
        return $columns = Schema::getColumnListing($table_name)[0];
    }
}
