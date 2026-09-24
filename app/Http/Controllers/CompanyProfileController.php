<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Company;
use App\Models\Notification;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CompanyProfileController extends Controller
{
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::select('id','name','email')
            ->where('email', $request->email)
            ->first();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'User not found.',
            ], 404);
        }

        PasswordResetToken::where('user_id', $user->id)->delete();

        $resetOtp = str_pad(
            random_int(0, 999999),
            6,
            '0',
            STR_PAD_LEFT
        );

        $token = Str::random(64);

        $expiresAt = now()->addMinutes(10);

        PasswordResetToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'reset_otp'  => $resetOtp,
            'expires_at' => $expiresAt,
        ]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(30)
                ->post(
                    rtrim(config('services.termii.base_url'), '/') . '/api/templates/send-email',
                    [
                        'api_key' => config('services.termii.api_key'),
                        'email' => $user->email,
                        'subject' => 'Payfleet - Password Reset Code',
                        'email_configuration_id' => config('services.termii.email_configuration_id'),
                        'template_id' => config('services.termii.forget_template'),
                        'variables' => [
                            'first_name' => $user->first_name,
                            'last_name'  => $user->last_name,
                            'otp'        => (string) $resetOtp,
                        ],
                    ]
                );

            Log::info('Termii Forgot Password Template Response', [
                'user_id'     => $user->id,
                'email'       => $user->email,
                'template_id' => config('services.termii.forget_template'),
                'status'      => $response->status(),
                'body'        => $response->body(),
                'json'        => $response->json(),
            ]);

            if (!$response->successful()) {

                Log::error('Termii Forgot Password Email Failed', [
                    'user_id'     => $user->id,
                    'email'       => $user->email,
                    'template_id' => config('services.termii.forget_template'),
                    'status'      => $response->status(),
                    'body'        => $response->body(),
                ]);

                // Delete the reset record because email was not sent
                PasswordResetToken::where('user_id', $user->id)->delete();

                return response()->json([
                    'status'  => false,
                    'message' => 'We could not send the password reset code. Please try again.',
                ], 500);
            }

        } catch (\Throwable $e) {

            Log::error('Termii Forgot Password Template Exception', [
                'user_id'     => $user->id,
                'email'       => $user->email,
                'template_id' => config('services.termii.forget_template'),
                'error'       => $e->getMessage(),
            ]);

            // Delete the reset record because email was not sent
            PasswordResetToken::where('user_id', $user->id)->delete();

            return response()->json([
                'status'  => false,
                'message' => 'We could not send the password reset code. Please try again.',
            ], 500);
        }

        ActivityLog::create([
            'user_id' => $user->id,
            'action'  => 'Forgot Password',
            'details' => 'User requested a password reset code.',
            'type'    => 'security',
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title'   => 'Password Reset',
            'message' => 'A password reset code has been sent to your email.',
            'type'    => 'security',
        ]);


        return response()->json([
            'status'  => true,
            'message' => 'Password reset code sent successfully.',
            'data' => [
                'token'      => $token,
                'expires_at' => $expiresAt->toDateTimeString(),
            ],
        ], 200);
    }

    public function verifyResetOtp(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'reset_otp' => ['required', 'digits:6']
        ]);

        $reset = PasswordResetToken::where(
            'token',
            $request->token
        )->first();

        if (!$reset) {

            return response()->json([
                'status' => false,
                'message' => 'Invalid token.'
            ], 404);
        }

        if (now()->gt($reset->expires_at)) {

            $reset->delete();

            return response()->json([
                'status' => false,
                'message' => 'OTP has expired.'
            ], 422);
        }

        if ($reset->reset_otp !== $request->reset_otp) {

            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP.'
            ], 422);
        }

        $reset->update([
            'is_verified' => true
        ]);

        return response()->json([
            'status' => true,
            'message' => 'OTP verified successfully.'
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'min:8']
        ]);

        $reset = PasswordResetToken::where(
            'token',
            $request->token
        )->first();

        if (!$reset) {

            return response()->json([
                'status' => false,
                'message' => 'Invalid token.'
            ], 404);
        }

        if (!$reset->is_verified) {

            return response()->json([
                'status' => false,
                'message' => 'Please verify your OTP first.'
            ], 422);
        }

        if (now()->gt($reset->expires_at)) {

            $reset->delete();

            return response()->json([
                'status' => false,
                'message' => 'Token has expired.'
            ], 422);
        }

        $user = User::find($reset->user_id);

        if (!$user) {

            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ], 404);
        }

        $user->update([
            'password' => Hash::make($request->password)
        ]);

        $user->tokens()->delete();

        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'Password Reset',
            'details' => 'User successfully reset account password.',
            'type' => 'security'
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Password Reset',
            'message' => 'Your password has been reset successfully.',
            'type' => 'security'
        ]);

        $reset->delete();

        return response()->json([
            'status' => true,
            'message' => 'Password reset successfully.'
        ]);
    }
}
