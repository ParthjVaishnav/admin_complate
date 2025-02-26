<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\LoginHistory;
use App\Mail\ResetOtpMail;
use App\Mail\LoginAlertMail;
use Jenssegers\Agent\Agent; 



class AuthController extends Controller
{
    public function showRegisterForm()
    {
        return view('session.register');
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6|confirmed'
        ]);

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        return redirect()->route('login.form')->with('success', 'Registration successful! Please log in.');
    }

    public function showLoginForm()
    {
        return view("session.login-session");
    }

    public function login(Request $request)
{
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required'
    ]);

    if (Auth::attempt($credentials)) {
        $agent = new Agent();
        LoginHistory::create([
            'user_id' => Auth::id(),
            'ip_address' => $request->ip(),
            'device' => $agent->device(),
            'browser' => $agent->browser(),
            'platform' => $agent->platform(),
            'device_info' => $agent->isDesktop() ? 'Desktop' : 'Mobile',
        ]);

        return redirect()->route('2fa.setup')->with('success', 'Login successful!');
    }

    return back()->withErrors(['email' => 'Invalid credentials.']);
}
    public function logout()
    {
        Auth::logout();
        return redirect()->route('login.form')->with('success', 'Logged out successfully.');
    }

    public function showForgotPassword()
    {
        return view('session.forgot_password');
    }

    public function sendResetOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Generate OTP
        $otp = rand(100000, 999999);
        $hashedOtp = Hash::make($otp);
        Session::put('reset_otp', $hashedOtp);
        Session::put('reset_email', $request->email);

        // Debugging: Log OTP (Only for development, remove in production)
        Log::info('Generated OTP: ' . $otp . ' for email: ' . $request->email);

        // Send OTP via email
        try {
            Mail::to($request->email)->send(new ResetOtpMail($otp));
        } catch (\Exception $e) {
            Log::error("Mail sending failed: " . $e->getMessage());
            return response()->json(['error' => 'Failed to send OTP, please try again later.'], 500);
        }

        return response()->json(['success' => 'OTP sent successfully.']);
    }

    public function verifyResetOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required|numeric',
            'password' => 'required|min:6|confirmed',
        ]);

        $hashedOtp = session('reset_otp');
        if (!$hashedOtp || !Hash::check($request->otp, $hashedOtp)) {
            return response()->json(['error' => 'Invalid OTP. Try again.'], 400);
        }

        $user = User::where('email', session('reset_email'))->first();
        if (!$user) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        // Update password and clear session
        $user->password = Hash::make($request->password);
        $user->save();

        session()->forget(['reset_otp', 'reset_email']);

        return response()->json(['success' => 'Password reset successful. Please log in.']);
    }
}
