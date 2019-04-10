<?php

namespace App\Http\Controllers;

use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ManagementController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (Auth::user()->is_admin != 1){
            return redirect('/home');
        }
        $data = User::all()->where('confirmation_token', '!=', '-')->where('remember_token', '!=', '-')->where('password', '!=', '-');
        return view('manage.index',['data' => $data, 'page_title' => 'Management', 'page_description' => '审核用户']);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $id)
    {
        $type = $request['type'];
        if (Auth::user()->is_admin != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        if($type == 1) {
            $user = User::find($id);
            $user->is_active = 1 - $user->is_active;
            $user->save();
            flash('修改成功 @ '.Carbon::now(), 'success');
            return redirect()->route('manage.index');
        }
        if($type == 2) {
            $user = User::find($id);
            if($user->is_admin == 1) { $user->is_admin = 2; }
            elseif($user->is_admin == 2) { $user->is_admin = 0; }
            elseif($user->is_admin == 0) { $user->is_admin = 1; }
            $user->save();
            flash('修改成功 @ '.Carbon::now(), 'success');
            return redirect()->route('manage.index');
        }
        if($type == 3) {
            $user = User::find($id);
            $email = $user->email;
            $user->email = $email.'-'.Carbon::now();
            $user->password = '-';
            $user->confirmation_token = '-';
            $user->remember_token = '-';
            $user->updated_at = Carbon::now();
            $user->save();
            flash('刪除成功 @ '.Carbon::now(), 'danger');
            return redirect()->route('manage.index');
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

}
