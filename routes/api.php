<?php

use App\Http\Controllers\AdminActionsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CompanyFundController;
use App\Http\Controllers\CompanyProfileController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\TierController;
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
    Route::get('admin-activity-log', [AdminActionsController::class, 'adminActivityLogs']);
    Route::get('admin-notifications', [AdminActionsController::class, 'adminNotifications']);
    Route::get('each-admin-activity/{id}', [AdminActionsController::class, 'getSingleActivity']);
    Route::get('each-admin-notification/{id}', [AdminActionsController::class, 'getSingleNotification']);
    Route::get('company-stats', [AuthController::class , 'companyStats']);
    Route::get('staff-stats', [AuthController::class, 'staffStats']);
    Route::get('deposit-stats', [AuthController::class, 'depositStats']);
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
    Route::put('update-company-details', [AdminActionsController::class, 'UpdateCompanyProfile']);
    Route::get('company-deposit', [CompanyFundController::class, 'getCompanyDeposit']);
    Route::get('each-company-deposit/{id}', [CompanyFundController::class, 'getCompanyDepositDetails']);
    Route::get('company-activity-logs', [CompanyProfileController::class, 'activityLogs']);
    Route::get('company-notifications', [CompanyProfileController::class, 'notifications']);
    Route::patch('read-notification/{id}', [CompanyProfileController::class, 'markAsRead']);
    Route::get('my-company-stats',  [CompanyProfileController::class, 'myCompanyStats']);
    Route::post('move-tier', [CompanyProfileController::class, 'moveTier']);
    Route::get('tier-requests', [CompanyProfileController::class, 'tierUpgradeRequests']);
    Route::get('review-tier-upgrade/{id}', [CompanyProfileController::class, 'reviewTierUpgrade']);


    // funding
    Route::post('company-funding', [CompanyFundController::class, 'Deposit']);
    Route::post('create-account', [AdminActionsController::class, 'createAccount']);
    Route::get('get-account', [AdminActionsController::class, 'getAccount']);
    Route::get('all-banks', [CompanyFundController::class, 'listBanks']);
    Route::post('resolve-account', [CompanyFundController::class, 'resolveBankAccount']);
}); 

// finance
Route::middleware('auth:sanctum')->group(function () {
    Route::get('all-deposit', [CompanyFundController::class, 'getDeposit']);
    Route::get('each-deposit/{id}', [CompanyFundController::class, 'eachDeposit']);
    Route::post('confirm-deposit/{id}',[AdminActionsController::class, 'confirmDeposit']);
    Route::put('update-staff/{id}', [AdminActionsController::class , 'updateOfficer']);
    Route::put('decline-deposit/{id}',[AdminActionsController::class, 'declineDeposit']);
});

// tier
Route::middleware('auth:sanctum')->group(function () {
    Route::post('create-tier', [TierController::class, 'createTier']);
    Route::get('all-tiers', [TierController::class, 'getTier']);
    Route::get('each-tiers/{id}', [TierController::class, 'eachTier']);
    Route::delete('delete-tiers/{id}', [TierController::class, 'deleteTier']);
    Route::put('update-tier/{id}', [TierController::class, 'updateTier']);
});

// customer support
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/support/messages',[ChatController::class, 'getUserMessages']);
    Route::post('/support/messages',[ChatController::class, 'sendMessageToAdmin']);
    Route::post('/support/messages/read',[ChatController::class, 'markAsRead']);
    Route::get( '/support/unread-count',[ChatController::class, 'unreadCount']);

    // Admin / Support
    Route::get('/admin/support/conversations',[ChatController::class, 'getConversations']);
    Route::get('/admin/support/conversations/{userId}',[ChatController::class, 'getAdminMessages']);
    Route::post('/admin/support/conversations/{userId}/messages',[ChatController::class, 'sendMessageToUser']);
});