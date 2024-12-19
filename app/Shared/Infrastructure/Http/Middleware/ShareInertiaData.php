<?php

namespace App\Shared\Infrastructure\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Laravel\Fortify\Features;

class ShareInertiaData
{
    /**
     * Handle the incoming request.
     *
     * @param  Request  $request
     * @param  callable  $next
     * @return Response
     */
    public function handle($request, $next)
    {
        Inertia::share(array_filter([
            'jetstream' => function () use ($request) {
                $user = $request->user();

                return [
                    'canCreateTeams' => false,
                    'canManageTwoFactorAuthentication' => Features::canManageTwoFactorAuthentication(),
                    'canUpdatePassword' => Features::enabled(Features::updatePasswords()),
                    'canUpdateProfileInformation' => Features::canUpdateProfileInformation(),
                    'hasEmailVerification' => Features::enabled(Features::emailVerification()),
                    'flash' => $request->session()->get('flash', []),
                    'hasAccountDeletionFeatures' => true,
                    'hasApiFeatures' => false,
                    'hasTeamFeatures' => false,
                    'hasTermsAndPrivacyPolicyFeature' => true,
                    'managesProfilePhotos' => true,
                ];
            },
            'auth' => [
                'user' => function () use ($request) {
                    if (! $user = $request->user()) {
                        return;
                    }

                    return array_merge($user->toArray(), [
                        'two_factor_enabled' => Features::enabled(Features::twoFactorAuthentication()) && ! is_null($user->two_factor_secret),
                    ]);
                },
            ],
            'errorBags' => function () {
                return collect(optional(Session::get('errors'))->getBags() ?: [])->mapWithKeys(function ($bag, $key) {
                    return [$key => $bag->messages()];
                })->all();
            },
        ]));

        return $next($request);
    }
}
