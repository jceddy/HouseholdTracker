<?php

declare(strict_types=1);

namespace HouseholdTracker\Mail;

use HouseholdTracker\Config;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    /**
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public function sendVerificationEmail(string $toEmail, string $toName, string $verificationUrl): void
    {
        $mail = $this->smtpMailer();
        $mail->addAddress($toEmail, $toName);

        $mail->Subject = 'Verify your HouseholdTracker account';
        $mail->Body = "Hi {$toName},\n\n"
            . "Please verify your email address by visiting the link below:\n\n"
            . "{$verificationUrl}\n\n"
            . "If you didn't create this account, you can ignore this email.";

        $mail->send();
    }

    /**
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public function sendPasswordResetEmail(string $toEmail, string $toName, string $resetUrl): void
    {
        $mail = $this->smtpMailer();
        $mail->addAddress($toEmail, $toName);

        $mail->Subject = 'Reset your HouseholdTracker password';
        $mail->Body = "Hi {$toName},\n\n"
            . "We received a request to reset your HouseholdTracker password. Visit the link below to choose a new one:\n\n"
            . "{$resetUrl}\n\n"
            . "This link expires in one hour. If you didn't request this, you can ignore this email.";

        $mail->send();
    }

    /**
     * sendHouseholdInviteEmail(...) - invites someone with no account yet
     * (issue #33): distinct wording from sendVerificationEmail() since this
     * may be the recipient's first contact with the app at all, rather than
     * a follow-up to something they already started.
     *
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public function sendHouseholdInviteEmail(string $toEmail, string $householdName, string $inviterUsername, string $registerUrl): void
    {
        $mail = $this->smtpMailer();
        $mail->addAddress($toEmail);

        $mail->Subject = "You're invited to join {$householdName} on HouseholdTracker";
        $mail->Body = "Hi,\n\n"
            . "{$inviterUsername} has invited you to join their household, \"{$householdName}\", on HouseholdTracker.\n\n"
            . "Create an account to get started:\n\n"
            . "{$registerUrl}\n\n"
            . "Once you register and verify your email, you'll see this invite waiting for you.\n\n"
            . "If you weren't expecting this, you can ignore this email.";

        $mail->send();
    }

    private function smtpMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = Config::get('SMTP_HOST', '');
        $mail->Port = (int) Config::get('SMTP_PORT', '587');
        $mail->SMTPAuth = true;
        $mail->Username = Config::get('SMTP_USERNAME', '');
        $mail->Password = Config::get('SMTP_PASSWORD', '');
        $mail->SMTPSecure = Config::get('SMTP_ENCRYPTION', PHPMailer::ENCRYPTION_STARTTLS);
        // Encryption is explicit via SMTP_ENCRYPTION above; disable opportunistic
        // auto-STARTTLS so behavior doesn't depend on what the server advertises.
        $mail->SMTPAutoTLS = false;

        $mail->setFrom(Config::get('SMTP_FROM_ADDRESS', ''), Config::get('SMTP_FROM_NAME', 'HouseholdTracker'));

        return $mail;
    }
}
