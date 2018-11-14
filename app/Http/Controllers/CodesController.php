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
            'page_description' => '编码表',
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
                $id_name = $this->getIdName($table_name);
                $data = DB::table($table_name)->where($id_name, $id)->first();
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
        $id_name = $this->getIdName($table_name);
        DB::table($table_name)->where($id_name, $id)->update($data);
        flash('Update success @ '.Carbon::now(), 'success');
        //建安新增,修改後使用新的序號.20181108
        //return redirect()->route('codes.edit', ['table_name' => $table_name, 'id' => $id]);
        return redirect()->route('codes.show', ['table_name' => $table_name]);
    }

    public function create($table_name)
    {
//        dd($table_name);
        $data = Schema::getColumnListing($table_name);
        $id_ = $data[0];
        $data = array_splice($data, 1);
        $id = DB::table($table_name)->max($id_) + 1;
        return view('codes.create',[
            'page_title' => 'Codes',
            'page_description' => $table_name,
            'page_url' => '/codes',
            'archer' => "<li><a href='/codes/".$table_name."'>".$table_name."</a></li>",
            'row' => $data,
            'id' => $id, 'table' => $table_name]);
    }

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
        $id_ = $this->getIdName($table_name);
        $id = DB::table($table_name)->max($id_) + 1;
        $data[$id_] = $id;
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
        $id_name = $this->getIdName($table_name);
        $row = DB::table($table_name)->where($id_name, $id)->first();
        $op = [
            'op_type' => 4,
            'resource' => $table_name,
            'resource_id' => $id,
            'resource_data' => json_encode((array)$row)
        ];
        $this->operationRepository->store($op);
        DB::table($table_name)->where($id_name, $id)->delete();
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('codes.show', ['table_name' => $table_name]);
    }

    protected function getIdName($table_name)
    {
        return $columns = Schema::getColumnListing($table_name)[0];
    }
}
