<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $otp,
        public string $type = 'register'   // 'register' or 'resend'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        $resend = $this->type === 'resend';

        return new Content(
            view: 'emails.otp',
            with: [
                'name'        => $this->name,
                'otp'         => $this->otp,
                'subjectLine' => $this->subjectLine(),
                'badge'       => $resend ? 'New Verification Code' : 'Account Activation',
                'headline'    => $resend ? 'Here’s your new code,' : 'Welcome to Payfleet,',
                'preheader'   => $resend
                    ? 'Here is your new 6-digit Payfleet OTP code. It is valid for 20 minutes.'
                    : 'Welcome to Payfleet! Activate your account with your 6-digit OTP code.',
                'intro'       => $resend
                    ? 'You requested a new verification code. Use the 6-digit OTP below. Any earlier code no longer works.'
                    : 'Welcome to Payfleet! To verify and activate your account, use your 6-digit OTP code below:',
                'ignoreNote'  => $resend
                    ? 'If you did not request a new code, you can safely ignore this email.'
                    : 'If you did not sign up for a Payfleet account, you can safely ignore this email.',
            ],
        );
    }

    private function subjectLine(): string
    {
        return $this->type === 'resend'
            ? 'Your New Payfleet Verification Code'
            : 'Welcome to Payfleet - Verify Your Account';
    }
}