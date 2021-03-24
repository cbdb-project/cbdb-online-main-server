<?php

namespace App\Http\Controllers;

use App\Repositories\CodesRepository;
use App\Repositories\OperationRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use phpDocumentor\Reflection\Types\Null_;

class CodesController extends Controller
{
    protected $codesrepostory;
    protected $operationRepository;

    public function __construct(CodesRepository $codesRepository, OperationRepository $operationRepository)
    {
        $this->codesrepostory = $codesRepository;
        $this->operationRepository = $operationRepository;
    }

    public function index()
    {
        $data = $this->codesrepostory->codes();
        return view('codes.index',[
            'page_title' => 'Codes',
            'page_description' => '代碼表',
            'page_url' => '/codes',
            'data' => $data]);
    }

    public function show($table_name)
    {
        try{
//            $data = DB::table($table_name)->paginate(1000);
            $data = DB::table($table_name)->get();
            $thead = array();
            $count = 0;
            foreach($data[0] as $key => $value){
                if ($count >2){
//                    break;
                }
                if(str_contains($key, 'name') or str_contains($key, 'desc') or str_contains($key, 'code') or str_contains($key, 'id') or str_contains($key, 'sequence') or str_contains($key, 'chn') or str_contains($key, 'dy')){
                    array_push($thead, $key);
                    $count++;
                }
            }
//                dd($data);
            return view('codes.show', [
                'page_title' => $table_name,
                'page_description' => $table_name,
                'page_url' => '/codes',
                'archer' => "<li><a href=''>".$table_name."</a></li>",
                'q' => $table_name,
                'thead' => $thead,
                'data' => $data]);
        }catch (\PDOException $e){
            flash('找不到该数据表', 'warning');
            return redirect()->back();
        }
    }

    public function edit($table_name,$id)
    {
//        dd($table_name);
        if($table_name){
            try{
                //20210323修改聯合主鍵的邏輯
                $temp = explode("_._", $id);
                $id_name = $this->getIdName($table_name);
                $id_name_1 = $this->getIdName_1($table_name);
                $id_name_2 = $this->getIdName_2($table_name);
                //$data = DB::table($table_name)->where($id_name, $id)->first();
                $data = DB::table($table_name)->where($id_name, $temp[0])->where($id_name_1, $temp[1])->first();
                //$data = DB::table($table_name)->where($id_name, $temp[0])->where($id_name_1, $temp[1])->where($id_name_2, $temp[2])->first();
                //修改結束
                return view('codes.edit', [
                    'page_title' => 'Codes',
                    'page_description' => $table_name,
                    'page_url' => '/codes',
                    'archer' => "<li><a href='/codes/".$table_name."'>".$table_name."</a></li>",
                    'id' => $id, 'row' => $data,
                    'table' => $table_name]);
            }catch (\PDOException $e) {
                flash('找不到该数据表', 'warning');
                return redirect()->back();
            }

        }
        return redirect()->route('codes.index');
    }

    public function update(Request $request, $table_name, $id)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
//        dd($data);
        //20210323修改聯合主鍵的邏輯
        $id_name = $this->getIdName($table_name);
        $id_name_1 = $this->getIdName_1($table_name);
        $id_name_2 = $this->getIdName_2($table_name);
        $temp = explode("_._", $id);
        //DB::table($table_name)->where($id_name, $id)->update($data);
        DB::table($table_name)->where($id_name, $temp[0])->where($id_name_1, $temp[1])->update($data);
        //DB::table($table_name)->where($id_name, $temp[0])->where($id_name_1, $temp[1])->where($id_name_2, $temp[2])->update($data);
        //修改結束
        flash('Update success @ '.Carbon::now(), 'success');

        //20210323組合新的id，避免修改聯合主鍵的值。 
        $id = $data[$id_name].'_._'.$data[$id_name_1];
        //$id = $data[$id_name].'_._'.$data[$id_name_1].'_._'.$data[$id_name_2];
        //return redirect()->route('codes.show', ['table_name' => $table_name]);
        return redirect()->route('codes.edit', ['table_name' => $table_name, 'id' => $id]);
    }

    //20210315增加table_name等於SOCIAL_INSTITUTION_CODES的例外判斷式，將預設遮除的第1個欄位呈現。
    public function create($table_name)
    {
//        dd($table_name);
        $data = Schema::getColumnListing($table_name);
        $id_ = $data[0];
        //20210323遮除「第一欄預設隱藏」
        //if($table_name != 'SOCIAL_INSTITUTION_CODES') {
            //$data = array_splice($data, 1);
        //}
        $id = DB::table($table_name)->max($id_) + 1;
        return view('codes.create',[
            'page_title' => 'Codes',
            'page_description' => $table_name,
            'page_url' => '/codes',
            'archer' => "<li><a href='/codes/".$table_name."'>".$table_name."</a></li>",
            'row' => $data,
            'id' => $id, 'table' => $table_name]);
    }

    //20210315增加table_name等於SOCIAL_INSTITUTION_CODES的例外判斷式，將預設自動增加的$id遮除。
    public function store(Request $request, $table_name)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $data = $request->all();
        $data = array_except($data, ['_token']);
        //20210323遮除「第一欄預設隱藏」
        //$id_ = $this->getIdName($table_name);
        //if($table_name != 'SOCIAL_INSTITUTION_CODES') {
            //$id = DB::table($table_name)->max($id_) + 1;
            //$data[$id_] = $id;
        //}
        //else {
            //當資料表等於SOCIAL_INSTITUTION_CODES，$id從表單取值。
            //$id = $data[$id_];
        //}
        //20210323插入聯合主鍵的邏輯
        $id_name = $this->getIdName($table_name);
        $id_name_1 = $this->getIdName_1($table_name);
        $id_name_2 = $this->getIdName_2($table_name);
        $id = $data[$id_name].'_._'.$data[$id_name_1];
        //$id = $data[$id_name].'_._'.$data[$id_name_1].'_._'.$data[$id_name_2];
        //修改結束
        DB::table($table_name)->insert($data);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('codes.edit', ['table_name' => $table_name, 'id' => $id]);
    }

    public function destroy($table_name, $id)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        //20210323修改聯合主鍵的邏輯
        $temp = explode("_._", $id);
        $id_name = $this->getIdName($table_name);
        $id_name_1 = $this->getIdName_1($table_name);
        $id_name_2 = $this->getIdName_2($table_name);

        $row = DB::table($table_name)->where($id_name, $temp[0])->where($id_name_1, $temp[1])->first();
        //$row = DB::table($table_name)->where($id_name, $temp[0])->where($id_name_1, $temp[1])->where($id_name_2, $temp[2])->first();
        //修改結束
        $op = [
            'op_type' => 4,
            'resource' => $table_name,
            'resource_id' => $id,
            'resource_data' => json_encode((array)$row)
        ];
        //$this->operationRepository->store($op);
        //20181207建安修改片段
        $row2 = json_encode((array)$row);
        $this->operationRepository->store(Auth::id(), '', 4, $table_name, $id, $row2);
        //修改結束
        DB::table($table_name)->where($id_name, $temp[0])->where($id_name_1, $temp[1])->delete();
        //DB::table($table_name)->where($id_name, $temp[0])->where($id_name_1, $temp[1])->where($id_name_2, $temp[2])->delete();
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('codes.show', ['table_name' => $table_name]);
    }

    protected function getIdName($table_name)
    {
        return $columns = Schema::getColumnListing($table_name)[0];
    }

    protected function getIdName_1($table_name)
    {
        return $columns = Schema::getColumnListing($table_name)[1];
    }

    protected function getIdName_2($table_name)
    {
        return $columns = Schema::getColumnListing($table_name)[2];
    }
}
