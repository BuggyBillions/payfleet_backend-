<?php

use App\Http\Controllers\AdminActionsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyFundController;
use App\Http\Controllers\CompanyProfileController;
use App\Http\Controllers\EmployeeController;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Authentication
Route::post('/register', [AuthController::class,'Register']);
Route::post('/verify-otp',[AuthController::class,'verify']);
Route::post('/resend-otp',[AuthController::class,'resendotp']);
Route::post('/login',[AuthController::class,'login']);
Route::middleware('auth:sanctum')->get('/me',[AuthController::class,'me']);

// profile
Route::post('forgot-password',[CompanyProfileController::class,'forgotPassword']);
Route::post('/verify-forgot-otp',[CompanyProfileController::class,'verifyResetOtp']);
Route::post('/reset-password',[CompanyProfileController::class,'resetPassword']);

// Admin Actions
Route::middleware('auth:sanctum')->group(function () {
    Route::get('all-companies',[AdminActionsController::class,'getCompanies']);
    Route::post('create-finance',[AdminActionsController::class, 'createUser']);
    Route::post('create-support',[AdminActionsController::class, 'createSupportUser']);
    Route::get('all-staffs',[AdminActionsController::class, 'getStaff']);
    Route::get('each-staffs/{id}',[AdminActionsController::class, 'eachStaff']);
    Route::get('each-company/{id}',[AdminActionsController::class, 'eachCompanies']);
    Route::patch('deactivate-users/{id}', [AdminActionsController::class, 'deactivateUser']);
    Route::patch('activate-users/{id}', [AdminActionsController::class, 'activateUser']);
    Route::delete('delete-users/{id}',[AdminActionsController::class, 'deleteUser']);
});

// companies
Route::middleware('auth:sanctum')->group(function () {
    Route::post('create-employee', [EmployeeController::class, 'createEmployee']);
    Route::get('my-employees', [EmployeeController::class, 'myEmployees']);
    Route::get('each-company-employee/{id}', [EmployeeController::class , 'myEmployee']);
    Route::put('update-employee/{id}', [EmployeeController::class , 'updateEmployee']);
    Route::delete('delete-employee/{id}', [EmployeeController::class, 'destroy']);
    Route::put('single-paying/{id}', [EmployeeController::class, 'togglePaying']);
    Route::put('multiple-paying', [EmployeeController::class, 'updateMultiplePaying']);
    Route::put('update-company-details', [AdminActionsController::class, 'UpdateProfile']);


    // funding
    Route::post('company-funding', [CompanyFundController::class, 'Deposit']);
    Route::post('create-account', [AdminActionsController::class, 'createAccount']);
    Route::get('get-account', [AdminActionsController::class, 'getAccount']);
    Route::get('all-banks', [CompanyFundController::class, 'listBanks']);
    Route::post('resolve-account', [CompanyFundController::class, 'resolveBankAccount']);
});