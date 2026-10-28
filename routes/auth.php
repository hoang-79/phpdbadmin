<?php

use App\Http\Controllers\Account\PasskeyController;
use App\Http\Controllers\Auth\ConfirmController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;

/*
 * Anmeldung ohne Passwort (ADR-008): Code per Mail und Passkey. Fortify registriert keine eigenen Routen
 * (FortifyServiceProvider); die Passkey-Wege stehen hier mit denselben Namen wie bei Fortify.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login/code', [LoginController::class, 'requestCode'])->middleware('throttle:login-code')->name('login.code');
    Route::get('login/code', [LoginController::class, 'showCode'])->name('login.code.show');
    Route::post('login/code/verify', [LoginController::class, 'verifyCode'])->middleware('throttle:login-verify')->name('login.code.verify');
    Route::post('login/code/resend', [LoginController::class, 'resendCode'])->middleware('throttle:login-code')->name('login.code.resend');

    Route::get('passkeys/login/options', [PasskeyLoginController::class, 'index'])->middleware('throttle:passkeys')->name('passkey.login-options');
    Route::post('passkeys/login', [PasskeyLoginController::class, 'store'])->middleware('throttle:passkeys')->name('passkey.login');

    Route::get('invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('invitation/{token}', [InvitationController::class, 'accept'])->middleware('throttle:login-verify')->name('invitation.accept');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');

    // Bestätigen vor heiklen Schritten (Passkeys verwalten): per Passkey oder per Code an die eigene Adresse.
    Route::get('account/confirm', [ConfirmController::class, 'show'])->name('password.confirm');
    Route::post('account/confirm/code', [ConfirmController::class, 'sendCode'])->middleware('throttle:login-code')->name('confirm.code');
    Route::post('account/confirm/code/verify', [ConfirmController::class, 'verifyCode'])->middleware('throttle:login-verify')->name('confirm.code.verify');
    Route::get('passkeys/confirm/options', [PasskeyConfirmationController::class, 'index'])->middleware('throttle:passkeys')->name('passkey.confirm-options');
    Route::post('passkeys/confirm', [PasskeyConfirmationController::class, 'store'])->middleware('throttle:passkeys')->name('passkey.confirm');

    Route::middleware('password.confirm')->group(function () {
        Route::get('account/passkeys/options', [PasskeyRegistrationController::class, 'index'])->middleware('throttle:passkeys')->name('passkey.registration-options');
        Route::post('account/passkeys', [PasskeyRegistrationController::class, 'store'])->middleware('throttle:passkeys')->name('passkey.store');
        Route::patch('account/passkeys/{passkey}', [PasskeyController::class, 'update'])->name('passkey.update');
        Route::delete('account/passkeys/{passkey}', [PasskeyRegistrationController::class, 'destroy'])->name('passkey.destroy');
    });
});
