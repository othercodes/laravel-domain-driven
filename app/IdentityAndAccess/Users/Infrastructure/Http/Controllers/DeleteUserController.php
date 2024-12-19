<?php

namespace App\IdentityAndAccess\Users\Infrastructure\Http\Controllers;

use App\IdentityAndAccess\Users\Application\DeleteUser;
use App\IdentityAndAccess\Users\Domain\Contracts\UserRepository;
use App\Shared\Domain\Contracts\ServiceBus\EventBus;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Laravel\Fortify\Actions\ConfirmPassword;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class DeleteUserController
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class DeleteUserController extends Controller
{
    private DeleteUser $deleteUser;

    private ConfirmPassword $confirmation;

    public function __construct(UserRepository $repository, EventBus $eventBus, ConfirmPassword $confirmation)
    {
        $this->deleteUser = new DeleteUser($repository, $eventBus);
        $this->confirmation = $confirmation;
    }

    public function destroy(Request $request, StatefulGuard $guard): Response
    {
        $confirmation = $this->confirmation;

        if (! $confirmation($guard, $request->user(), $request->password)) {
            return back(303)->withErrors([
                'Invalid Password' => __('The password is incorrect.'),
            ]);
        }

        $this->deleteUser->delete($request->user()->id);

        $guard->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location(url('/'));
    }
}
