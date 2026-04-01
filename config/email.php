<?php
/**
 * DARN Dashboard - Email (Gmail SMTP) Configuration
 *
 * To set up:
 * 1. Go to https://myaccount.google.com/security
 * 2. Enable 2-Step Verification (if not already)
 * 3. Go to https://myaccount.google.com/apppasswords
 * 4. Create an App Password for "Mail" → "Other (Custom name)" → "DARN Dashboard"
 * 5. Copy the 16-character password and paste it below
 */
return [
    'gmail_email' => getenv('GMAIL_EMAIL') ?: '',
    'gmail_app_password' => getenv('GMAIL_APP_PASSWORD') ?: '',
    'sender_name' => 'Darn Group L.L.C',
];
