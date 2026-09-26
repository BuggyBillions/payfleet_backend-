<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpParser\Node\Stmt\Return_;

class PaymentController extends Controller
{
    private function isCompany(User $user) : bool
    {
        return $user->role === 'company';
    } 

    public function trigeerPayroll(Request $request)
    {
        $admin = $request->user();
        $user =  $request->user();

        if(!$admin){
            return response()->json([
                'status'    =>   false,
                'messsage'  =>   'unauthenticated',
            ]);
        }

        if(!$this->isCompany($user)){
            return response()->json([
                'status'    =>  false,
                'message'   =>  'Unauthorized to access the endpoint.'
            ], 403);
        }

        $company  =  Company::where('user_id', $user->id)->first();

        $validated  = $request->validate([
            'pin'         =>  ['required'],
            'company_id'  => ['required', 'exists:companies,id']
        ]);

        DB::beginTransaction();
        try {
            
        } catch (\Exception $e) {
            DB::rollBack();
            return  response()->json([
                'status'    =>   false,
                'message'   =>   'company payment failed.',
                'error'     =>$e->getMessage(),
            ], 500);
        } 

    }
}
