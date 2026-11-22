<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The signed-in user. Every route that calls this sits behind the auth
     * middleware; should one ever not, the answer is 401 rather than a crash.
     */
    protected function currentUser(Request $request): User
    {
        return $request->user() ?? abort(401);
    }
}
