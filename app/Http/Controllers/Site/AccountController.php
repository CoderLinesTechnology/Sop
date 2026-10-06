<?php

namespace App\Http\Controllers\Site;

use App\Domain\Email\TransactionalMailer;
use App\Enums\EmailTemplateKey;
use App\Http\Controllers\Controller;
use App\Models\CustomerLoginToken;
use App\Models\Order;
use App\Models\User;
use App\Support\Seo;
use App\Support\SpamGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Optional, passwordless customer accounts. Signing in with a one-time email
 * link proves ownership of the address and shows every order placed with it.
 */
class AccountController extends Controller
{
    private const LINK_MINUTES = 20;

    public function login(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('account.index');
        }

        return view('site.account.login', ['seo' => Seo::make('Sign in', index: false)]);
    }

    public function sendLink(Request $request, TransactionalMailer $mailer): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);

        if (! SpamGuard::isBot($request)) {
            $email = Str::lower(trim($data['email']));
            $token = Str::random(64);

            CustomerLoginToken::query()->create([
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::LINK_MINUTES),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            $mailer->send(EmailTemplateKey::MagicLink, $email, [
                'login_link' => route('account.verify', $token),
                'expires_minutes' => (string) self::LINK_MINUTES,
            ]);
        }

        return back()->with('status', 'Check your inbox — if that address can sign in, we have sent a link that expires in '.self::LINK_MINUTES.' minutes.');
    }

    public function verify(Request $request, string $token): RedirectResponse
    {
        $user = DB::transaction(function () use ($token) {
            $record = CustomerLoginToken::query()
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return null;
            }

            $record->forceFill(['used_at' => now()])->save();

            $user = User::query()->firstOrCreate(['email' => $record->email]);
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'last_login_at' => now()])->save();

            Order::query()->where('email', $record->email)->whereNull('user_id')->update(['user_id' => $user->id]);

            return $user;
        });

        if (! $user) {
            return redirect()->route('account.login')->withErrors(['email' => 'This sign-in link has expired or was already used. Request a new one below.']);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('account.index');
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('site.account.index', [
            'user' => $user,
            'orders' => $user->ordersByEmail()->submitted()->with('service')->latest()->paginate(15),
            'seo' => Seo::make('Your orders', index: false),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
