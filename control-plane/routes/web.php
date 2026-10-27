<?php
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
Route::get('/', fn () => response()->json(['service'=>'cloudtunes-control-plane','status'=>'ok','phase'=>'foundation']));
Route::middleware('auth')->get('/api/v1/stations', function (Request $request) {
 $stations=Station::query()->where('user_id',$request->user()->getAuthIdentifier())->orderBy('id')->get();
 return response()->json(['data'=>$stations]);
});
