<?php
require_once __DIR__ . '/config.php';

function generateDeliveryOTP(PDO $pdo, int $parcelId): string
{
    // Generate a secure random 6-digit OTP
    $otp = (string) random_int(100000, 999999);

    // Store only the hashed OTP
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);

    // OTP expires after 10 minutes
    $expiresAt = date('Y-m-d H:i:s', time() + 600);

    // Save OTP for this parcel
    $stmt = $pdo->prepare("
        INSERT INTO parcel_delivery_otps
            (parcel_id, otp_hash, expires_at, attempts)
        VALUES (?, ?, ?, 0)
        ON DUPLICATE KEY UPDATE
            otp_hash = VALUES(otp_hash),
            expires_at = VALUES(expires_at),
            attempts = 0,
            verified_at = NULL
    ");

    $stmt->execute([
        $parcelId,
        $otpHash,
        $expiresAt
    ]);

    return $otp;
}