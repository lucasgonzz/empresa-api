<?php

namespace App\Exceptions;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Mail\ErrorHandler;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            $owner_user = UserHelper::getFullModel();
            $auth_user = UserHelper::getFullModel(false);
            Mail::to('lucasgonzalez5500@gmail.com')->send(new ErrorHandler($owner_user, $auth_user, $e));
        });
    }
}
